<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\UI\Components\FlexibleRender;
use Tikhomirov\MoonshineYandexMetrika\Console\Commands\ConnectionClearCommand;
use Tikhomirov\MoonshineYandexMetrika\Console\Commands\ConnectionSetCommand;
use Tikhomirov\MoonshineYandexMetrika\Contracts\AnalyticsDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Pages\YandexConnectionPage;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbeResult;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;
use Tikhomirov\MoonshineYandexMetrika\Support\AnalyticsTabRegistry;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;
use Tikhomirov\MoonshineYandexMetrika\Support\PackageConfig;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthFlow;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

covers(
    ConnectionClearCommand::class,
    ConnectionSetCommand::class,
    ConnectionProbe::class,
    MetrikaDashboardPage::class,
    YandexConnectionPage::class,
    MetrikaService::class,
    WebmasterService::class,
    YandexAccountService::class,
    PackageConfig::class,
    YandexOAuthFlow::class,
    YandexSettings::class,
);

test('connection clear without settings table and set without table', function (): void {
    Schema::dropIfExists('yandex_metrika_settings');
    app(YandexSettings::class)->refresh();

    $this->artisan('yandex-metrika:connection:clear', ['--force' => true])
        ->expectsOutputToContain('Settings table does not exist')
        ->assertSuccessful();

    $this->artisan('yandex-metrika:connection:set', ['--token' => 'x'])
        ->assertFailed();
});

test('connection clear cancelled without force aborts', function (): void {
    migrateSettingsTable();

    $this->artisan('yandex-metrika:connection:clear')
        ->expectsConfirmation('Delete Yandex settings from the database?', 'no')
        ->assertFailed();
});

test('connection set handles ask prompts invalid options and empty payload', function (): void {
    migrateSettingsTable();

    $this->artisan('yandex-metrika:connection:set', [
        '--ask-secret' => true,
        '--ask-token' => true,
        '--client-id' => 'cid',
    ])
        ->expectsQuestion('Client secret', 'secret-value')
        ->expectsQuestion('OAuth token', 'token-value')
        ->assertSuccessful();

    expect(app(YandexSettings::class)->clientId())->toBe('cid');

    $this->artisan('yandex-metrika:connection:set')
        ->expectsOutputToContain('Nothing to save')
        ->assertExitCode(2);
});

test('package config int returns default for non numeric values', function (): void {
    config()->set('moonshine-yandex-metrika.max_results', 'not-a-number');

    expect(PackageConfig::int('moonshine-yandex-metrika.max_results', 7))->toBe(7);
});

test('yandex account profile is null without token', function (): void {
    config()->set('moonshine-yandex-metrika.token', '');
    app()->forgetInstance(YandexAccountService::class);

    expect(app(YandexAccountService::class)->profile())->toBeNull();
});

test('metrika service cache hit and siteKey edge cases', function (): void {
    config()->set('moonshine-yandex-metrika.cache_ttl', 3600);
    Cache::flush();

    $history = [];
    $service = new MetrikaService;
    $params = ['metrics' => 'ym:s:visits'];
    $date1Prop = new ReflectionProperty(MetrikaService::class, 'date1');
    $date1Prop->setAccessible(true);
    $date2Prop = new ReflectionProperty(MetrikaService::class, 'date2');
    $date2Prop->setAccessible(true);
    $query = array_merge([
        'ids' => $service->counterId(),
        'date1' => $date1Prop->getValue($service),
        'date2' => $date2Prop->getValue($service),
    ], $params);
    $cacheKey = 'yandex_metrika_'.md5(serialize($query));
    Cache::put($cacheKey, ['data' => [], 'totals' => [99]], 3600);
    mockServiceHttp($service, [], $history);

    expect($service->request($params)['totals'][0] ?? null)->toBe(99)
        ->and($history)->toBeEmpty()
        ->and(MetrikaService::matchCounterByUrl([
            ['id' => '1', 'sites' => ['']],
        ], 'https://example.com'))->toBeNull()
        ->and(MetrikaService::matchCounterByUrl([
            ['id' => '2', 'sites' => ['not:a:valid:url:broken']],
        ], 'https://example.com'))->toBeNull();

    config()->set('moonshine-yandex-metrika.cache_ttl', 0);
});

test('metrika extractRows and metric totals skip invalid payloads', function (): void {
    $service = new MetrikaService;
    $extract = new ReflectionMethod(MetrikaService::class, 'extractRows');
    $extract->setAccessible(true);

    $intTotal = new ReflectionMethod(MetrikaService::class, 'intMetricTotal');
    $intTotal->setAccessible(true);
    $floatTotal = new ReflectionMethod(MetrikaService::class, 'floatMetricTotal');
    $floatTotal->setAccessible(true);

    expect($extract->invoke($service, null))->toBe([])
        ->and($extract->invoke($service, [
            'data' => [['dimensions' => 'broken', 'metrics' => 'n/a'], 123],
        ]))->toHaveCount(1)
        ->and($intTotal->invoke($service, null, 0))->toBe(0)
        ->and($floatTotal->invoke($service, null, 0))->toBe(0.0)
        ->and(MetrikaService::matchCounterByUrl([], ''))->toBeNull();
});

test('webmaster covers scheme mismatch env host cache and malformed query rows', function (): void {
    config()->set('moonshine-yandex-metrika.cache_ttl', 3600);
    config()->set('moonshine-yandex-metrika.webmaster.user_id', '');
    config()->set('moonshine-yandex-metrika.webmaster.host_id', '');
    Cache::flush();

    expect(WebmasterService::matchHostByUrl(['ftp:host:21'], 'https://demo.example'))->toBeNull();

    migrateSettingsTable();
    config()->set('app.url', 'https://solo.example');
    config()->set('moonshine-yandex-metrika.webmaster.host_id', '');
    app(YandexSettings::class)->save(['host_id' => '']);

    $history = [];
    $service = new WebmasterService;
    mockServiceHttp($service, [
        apiJson(fixtureJson('webmaster/user.json')),
        apiJson(['hosts' => [['host_id' => 'https:solo.example:443', 'unicode_host_url' => 'https://solo.example/', 'verified' => true]]]),
        apiJson(['searchable_pages_count' => 'bad', 'excluded_pages_count' => [], 'site_problems' => 'n/a', 'sqi' => '50']),
        apiJson(['indicators' => ['LINKS_TOTAL_COUNT' => [['value' => 5]]]]),
        apiJson(['queries' => [
            ['query_text' => 'q', 'indicators' => ['TOTAL_SHOWS' => 3, 'TOTAL_CLICKS' => 1, 'AVG_SHOW_POSITION' => 2.2, 'AVG_CLICK_POSITION' => 1.1]],
            'broken',
        ], 'date_from' => '2026-01-01', 'date_to' => '2026-01-07']),
        apiJson(fixtureJson('webmaster/user.json')),
        apiJson(['searchable_pages_count' => 1]),
    ], $history);

    $summary = $service->summary();
    expect($summary)->not->toBeNull()
        ->and($summary['searchable_pages_count'])->toBe(0)
        ->and($summary['sqi'])->toBe(50)
        ->and($service->externalLinksTotal())->toBe(5)
        ->and($service->popularQueries(2)[0]['query'])->toBe('q')
        ->and($service->displayHost())->toBe('solo.example');

    app(YandexSettings::class)->clear();
    config()->set('moonshine-yandex-metrika.webmaster.host_id', 'custom-host-id');
    config()->set('app.url', 'https://no-host-match.example');
    app(YandexSettings::class)->refresh();
    app()->forgetInstance(WebmasterService::class);
    Cache::flush();
    $envHost = new WebmasterService;
    mockServiceHttp($envHost, [apiJson(['hosts' => []])], $history);

    expect($envHost->hostId())->toBe('custom-host-id');

    config()->set('moonshine-yandex-metrika.cache_ttl', 0);
});

test('oauth flow uses configured scopes when token response omits scope', function (): void {
    migrateSettingsTable();
    app(YandexSettings::class)->save([
        'client_id' => '4760187d81bc4b7799476b42b5103713',
        'client_secret' => 'super-secret-value',
    ]);

    Http::fake([
        'oauth.yandex.ru/token' => Http::response(['access_token' => 'fresh-token-value']),
    ]);

    $session = controllerRequest('/x')->session();
    $flow = app(YandexOAuthFlow::class);
    $flow->authorizationUrl($session);
    $state = (string) data_get($session->get('moonshine-yandex-metrika.oauth'), 'state');

    $scopes = $flow->complete($session, $state, 'good-code');

    expect($scopes)->toBe(config('moonshine-yandex-metrika.oauth_scopes'))
        ->and(app(YandexSettings::class)->token())->toBe('fresh-token-value');
});

test('oauth exchange error prefers error_description string', function (): void {
    migrateSettingsTable();
    app(YandexSettings::class)->save([
        'client_id' => '4760187d81bc4b7799476b42b5103713',
        'client_secret' => 'super-secret-value',
    ]);

    Http::fake([
        'oauth.yandex.ru/token' => Http::response(['error' => 'invalid', 'error_description' => 'described'], 400),
    ]);

    $session = controllerRequest('/x')->session();
    $flow = app(YandexOAuthFlow::class);
    $flow->authorizationUrl($session);
    $state = (string) data_get($session->get('moonshine-yandex-metrika.oauth'), 'state');

    expect(fn () => $flow->complete($session, $state, 'bad'))
        ->toThrow(RuntimeException::class, 'described');
});

test('yandex settings save accepts scalars clears token metadata and handles corrupt row', function (): void {
    migrateSettingsTable();
    $settings = app(YandexSettings::class);

    $settings->save([
        'client_id' => 12345,
        'scopes' => 'not-array',
        'token' => 'manual',
    ]);

    expect($settings->clientId())->toBe('12345');

    $settings->save(['token' => 'other']);
    expect($settings->storedValue('client_id'))->toBe('12345');

    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    app(YandexSettings::class)->refresh();

    expect(app(YandexSettings::class)->storedValue('client_secret'))->toBe('');
});

test('yandex connection page breadcrumbs scopes and withAuto branches', function (): void {
    migrateSettingsTable();
    config()->set('moonshine-yandex-metrika.oauth_scopes', 'bad');

    $page = app(YandexConnectionPage::class);
    expect($page->getBreadcrumbs())->not->toBeEmpty();

    app()->instance(WebmasterService::class, new class extends WebmasterService
    {
        public function __construct() {}

        public function hosts(): ?array
        {
            return [
                ['host_id' => 'https:stored.example:443', 'url' => 'https://stored.example/', 'verified' => true],
            ];
        }
    });
    app(YandexSettings::class)->save([
        'host_id' => 'orphan-host',
        'token' => 'test-token',
    ]);

    $html = collect($page->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');

    expect($html)->toContain('orphan-host');

    $method = new ReflectionMethod(YandexConnectionPage::class, 'renderToString');
    $method->setAccessible(true);

    expect($method->invoke($page, 'not-an-object'))->toBe('');
});

test('metrika dashboard breadcrumbs legacy export registered tab and account login', function (): void {
    app()->instance(ConnectionProbe::class, new class
    {
        public function check(): ConnectionProbeResult
        {
            return ConnectionProbeResult::ok();
        }
    });

    app()->instance(YandexAccountService::class, new class extends YandexAccountService
    {
        public function __construct() {}

        public function profile(): ?array
        {
            return [
                'id' => '1',
                'login' => 'demo-login',
                'email' => null,
                'avatar_url' => null,
                'client_id' => null,
            ];
        }
    });

    config()->set('moonshine-yandex-metrika.summary_metrics', 'invalid');

    $core = app(MetrikaDashboardPage::class)->getCore();
    $page = new class($core) extends MetrikaDashboardPage
    {
        public function __construct(CoreContract $core)
        {
            parent::__construct($core);
        }

        public function exposeBreadcrumbs(): array
        {
            return $this->getBreadcrumbs();
        }

        protected function additionalAnalyticsTabs(): array
        {
            return [
                [
                    'id' => 'legacy-callable',
                    'label' => 'Legacy Call',
                    'icon' => 'chart-pie',
                    'enabled' => true,
                    'uses_period' => true,
                    'requires_yandex' => false,
                    'export_csv_url' => static fn (DatePeriod $p): string => '/legacy/'.$p->date1(),
                    'export_csv_label' => 'Legacy',
                ],
                [
                    'id' => 'legacy-empty',
                    'label' => 'Legacy Empty',
                    'icon' => 'chart-pie',
                    'enabled' => false,
                    'export_csv_url' => '',
                ],
            ];
        }

        protected function buildCustomTabContent(
            string $tab,
            DatePeriod $period,
            array $widgets,
            int $maxRes,
        ): array {
            if ($tab === 'legacy-callable') {
                return [FlexibleRender::make('<p id="legacy-callable-tab">ok</p>')];
            }

            return parent::buildCustomTabContent($tab, $period, $widgets, $maxRes);
        }
    };

    expect($page->exposeBreadcrumbs())->not->toBeEmpty();

    $registry = app(AnalyticsTabRegistry::class);
    $registry->register(new class implements AnalyticsDashboardTab
    {
        public function id(): string
        {
            return 'registered-empty-export';
        }

        public function label(): string
        {
            return 'Registered';
        }

        public function icon(): string
        {
            return 'chart-bar';
        }

        public function enabled(): bool
        {
            return true;
        }

        public function usesPeriod(): bool
        {
            return true;
        }

        public function requiresYandexConnection(): bool
        {
            return false;
        }

        public function render(DatePeriod $period, array $widgets, int $maxResults): array
        {
            return [FlexibleRender::make('<p id="registered-tab">x</p>')];
        }

        public function exportCsvUrl(DatePeriod $period): ?string
        {
            return '';
        }
    });

    request()->query->set('tab', 'legacy-callable');
    $html = collect($page->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');

    expect($html)->toContain('demo-login')
        ->and($html)->toContain('legacy-callable-tab')
        ->and($html)->toContain('/legacy/');

    $resolveAttrs = new ReflectionMethod(MetrikaDashboardPage::class, 'resolveTabAttributes');
    $resolveAttrs->setAccessible(true);
    $attrs = $resolveAttrs->invoke($page, 'registered-empty-export', DatePeriod::today());

    expect($attrs->exportCsvUrl)->toBeNull()
        ->and($attrs->exportCsvLabel)->toBeNull();

    $findTab = new ReflectionMethod(MetrikaDashboardPage::class, 'findAdditionalAnalyticsTab');
    $findTab->setAccessible(true);

    expect($findTab->invoke($page, 'legacy-empty'))->not->toBeNull()
        ->and($findTab->invoke($page, 'missing'))->toBeNull();
});
