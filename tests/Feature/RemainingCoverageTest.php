<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MoonShine\UI\Components\FlexibleRender;
use MoonShine\UI\Components\Layout\Div;
use Tikhomirov\MoonshineYandexMetrika\Contracts\AnalyticsDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Contracts\MetrikaDashboardTabHost;
use Tikhomirov\MoonshineYandexMetrika\Http\Controllers\ConnectionController;
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbeResult;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Support\AnalyticsTabRegistry;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;
use Tikhomirov\MoonshineYandexMetrika\Support\PackageConfig;
use Tikhomirov\MoonshineYandexMetrika\Support\WrapsPageComponentsInSpaceElements;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthFlow;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;
use Tikhomirov\MoonshineYandexMetrika\Tabs\MetrikaDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Tabs\WebmasterDashboardTab;

covers(
    ConnectionProbe::class,
    ConnectionController::class,
    MetrikaDashboardPage::class,
    PackageConfig::class,
    WebmasterService::class,
    YandexOAuthFlow::class,
    MetrikaDashboardTab::class,
    WebmasterDashboardTab::class,
    AnalyticsTabRegistry::class,
    WrapsPageComponentsInSpaceElements::class,
);

test('package config handles numeric strings and non-array widget maps', function (): void {
    config()->set('moonshine-yandex-metrika.max_results', '12');
    config()->set('moonshine-yandex-metrika.widgets', 'n/a');

    expect(PackageConfig::int('moonshine-yandex-metrika.max_results', 1))->toBe(12)
        ->and(PackageConfig::boolMap('moonshine-yandex-metrika.widgets', ['x' => true]))->toBe(['x' => true]);
});

test('connection probe reports missing env keys and maps http failures', function (): void {
    config()->set('moonshine-yandex-metrika.token', 'tok');
    config()->set('moonshine-yandex-metrika.counter_id', '');
    app()->forgetInstance(ConnectionProbe::class);
    app()->forgetInstance(MetrikaService::class);

    $missingCounter = app(ConnectionProbe::class)->check();
    expect($missingCounter->message)->toContain('YANDEX_METRIKA_COUNTER_ID');

    config()->set('moonshine-yandex-metrika.counter_id', '12345678');
    app()->forgetInstance(ConnectionProbe::class);

    $explain = new ReflectionMethod(ConnectionProbe::class, 'explain');
    $explain->setAccessible(true);
    $probe = app(ConnectionProbe::class);

    expect($explain->invoke($probe, 401, 'x'))->toContain('401')
        ->and($explain->invoke($probe, 403, 'x'))->toContain('403')
        ->and($explain->invoke($probe, 404, 'x'))->toContain('404')
        ->and($explain->invoke($probe, 0, 'x'))->toContain('недоступен');

    $requestMetrika = new ReflectionMethod(ConnectionProbe::class, 'requestMetrika');
    $requestMetrika->setAccessible(true);
    $apiUrl = config('moonshine-yandex-metrika.api_url');

    $ok = $requestMetrika->invoke($probe, 'tok', '12345678');
    expect($ok->isOk())->toBeFalse();

    config()->set('moonshine-yandex-metrika.api_url', 'http://127.0.0.1:9/mock-stat');
    app()->forgetInstance(ConnectionProbe::class);
    $probe2 = app(ConnectionProbe::class);
    $bad = $requestMetrika->invoke($probe2, 'tok', '12345678');
    expect($bad->isOk())->toBeFalse();

    config()->set('moonshine-yandex-metrika.api_url', $apiUrl);
});

test('connection controller check returns success toast when probe is ok', function (): void {
    app()->instance(ConnectionProbe::class, new class
    {
        public function fresh(): ConnectionProbeResult
        {
            return ConnectionProbeResult::ok();
        }
    });

    expect(app(ConnectionController::class)->check()->isRedirect())->toBeTrue();
});

test('connection controller check returns error toast when probe fails', function (): void {
    app()->instance(ConnectionProbe::class, new class
    {
        public function fresh(): ConnectionProbeResult
        {
            return ConnectionProbeResult::failed('probe failed');
        }
    });

    $response = app(ConnectionController::class)->check();

    expect($response->isRedirect())->toBeTrue();
});

test('connection clear with force and registry rejects empty tab id', function (): void {
    migrateSettingsTable();
    app(YandexSettings::class)->save(['token' => 'db-token']);

    $this->artisan('yandex-metrika:connection:clear', ['--force' => true])->assertSuccessful();

    $registry = new AnalyticsTabRegistry;

    expect(fn () => $registry->register(new class implements AnalyticsDashboardTab
    {
        public function id(): string
        {
            return '';
        }

        public function label(): string
        {
            return '';
        }

        public function icon(): string
        {
            return '';
        }

        public function enabled(): bool
        {
            return true;
        }

        public function usesPeriod(): bool
        {
            return false;
        }

        public function requiresYandexConnection(): bool
        {
            return false;
        }

        public function render(DatePeriod $period, array $widgets, int $maxResults): array
        {
            return [];
        }

        public function exportCsvUrl(DatePeriod $period): ?string
        {
            return null;
        }
    }))->toThrow(InvalidArgumentException::class);
});

test('oauth flow fails on empty code and unsuccessful token exchange', function (): void {
    migrateSettingsTable();
    $settings = app(YandexSettings::class);
    $settings->save([
        'client_id' => '4760187d81bc4b7799476b42b5103713',
        'client_secret' => 'super-secret-value',
    ]);

    $session = controllerRequest('/x')->session();
    $flow = app(YandexOAuthFlow::class);
    $flow->authorizationUrl($session);

    expect(fn () => $flow->complete($session, (string) $session->get('moonshine-yandex-metrika.oauth.state'), ''))
        ->toThrow(RuntimeException::class);

    Http::fake([
        'oauth.yandex.ru/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $flow->authorizationUrl($session);
    $state = (string) data_get($session->get('moonshine-yandex-metrika.oauth'), 'state');

    expect(fn () => $flow->complete($session, $state, 'bad-code'))
        ->toThrow(RuntimeException::class);
});

test('dashboard tabs return empty render without host and expose export urls', function (): void {
    app()->forgetInstance(MetrikaDashboardTabHost::class);
    $period = DatePeriod::today();

    expect(app(MetrikaDashboardTab::class)->render($period, [], 5))->toBe([])
        ->and(app(WebmasterDashboardTab::class)->render($period, [], 5))->toBe([])
        ->and(app(WebmasterDashboardTab::class)->usesPeriod())->toBeFalse()
        ->and(app(WebmasterDashboardTab::class)->exportCsvUrl($period))->toContain('export/webmaster');
});

test('wrap in space elements returns an empty list unchanged', function (): void {
    $page = new class
    {
        use WrapsPageComponentsInSpaceElements;

        /**
         * @return list<mixed>
         */
        public function wrap(array $items): array
        {
            return $this->wrapInSpaceElements($items);
        }
    };

    $wrapped = $page->wrap([FlexibleRender::make('<span>x</span>')]);

    expect($page->wrap([]))->toBe([])
        ->and($wrapped[0])->toBeInstanceOf(Div::class);
});

test('webmaster service covers url matching links parsing and cabinet urls', function (): void {
    config()->set('moonshine-yandex-metrika.cache_ttl', 0);
    $history = [];
    $service = new WebmasterService;

    mockServiceHttp($service, [
        apiJson(fixtureJson('webmaster/user.json')),
        apiJson(fixtureJson('webmaster/hosts.json')),
        apiJson(['indicators' => ['LINKS_TOTAL_COUNT' => [[]]]]),
        apiJson(fixtureJson('webmaster/popular-queries.json')),
    ], $history);

    expect(WebmasterService::matchHostByUrl(['ftp:bad:21'], 'https://demo.example'))->toBeNull()
        ->and($service->externalLinksTotal())->toBe(0)
        ->and($service->popularQueries(3))->not->toBeEmpty()
        ->and($service->cabinetDiagnosticsUrl())->toContain('diagnostics');

    $emptyLinks = new WebmasterService;
    mockServiceHttp($emptyLinks, [
        apiJson(fixtureJson('webmaster/user.json')),
        apiJson(fixtureJson('webmaster/hosts.json')),
        apiJson(['indicators' => []]),
    ], $history);

    expect($emptyLinks->externalLinksTotal())->toBe(0);

    $badUser = new WebmasterService;
    mockServiceHttp($badUser, [apiJson(['user_id' => []])], $history);

    expect($badUser->resolveUserId())->toBeNull();

    migrateSettingsTable();
    app(YandexSettings::class)->save(['host_id' => 'plain-host']);
    $display = new WebmasterService;

    expect($display->displayHost())->toBe('plain-host');
});

test('metrika dashboard handles probe failure invalid tab and webmaster queries', function (): void {
    app()->instance(ConnectionProbe::class, new class
    {
        public function check(): ConnectionProbeResult
        {
            return ConnectionProbeResult::failed('offline');
        }
    });

    $html = collect(app(MetrikaDashboardPage::class)->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');

    expect($html)->toContain('offline');

    app()->instance(ConnectionProbe::class, new class
    {
        public function check(): ConnectionProbeResult
        {
            return ConnectionProbeResult::ok();
        }
    });

    app()->instance(WebmasterService::class, new class extends WebmasterService
    {
        public function __construct() {}

        public function forPeriod(DatePeriod $period): static
        {
            return $this;
        }

        public function isConfigured(): bool
        {
            return false;
        }
    });

    $notConfigured = collect(app(MetrikaDashboardPage::class)->renderWebmasterTab(
        DatePeriod::today(),
        ['webmaster_summary' => true, 'webmaster_queries' => true],
        5,
    ))->map(fn ($c) => (string) $c->render())->implode('');

    expect($notConfigured)->toContain('Webmaster');

    app()->instance(WebmasterService::class, new class extends WebmasterService
    {
        public function __construct() {}

        public function forPeriod(DatePeriod $period): static
        {
            return $this;
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function displayHost(): string
        {
            return 'demo.example';
        }

        public function summary(): ?array
        {
            return [
                'searchable_pages_count' => 3,
                'excluded_pages_count' => 1,
                'site_problems' => ['FATAL' => 0, 'CRITICAL' => 0, 'POSSIBLE_PROBLEM' => 0, 'RECOMMENDATION' => 0],
                'problems_total' => 0,
                'sqi' => 50,
            ];
        }

        public function externalLinksTotal(): ?int
        {
            return 42;
        }

        public function popularQueriesReport(int $limit = 10): array
        {
            return [
                'rows' => [],
                'date_from' => '2026-11-01',
                'date_to' => '2026-11-07',
                'used_fallback' => true,
            ];
        }

        public function cabinetSummaryUrl(): string
        {
            return 'https://webmaster.yandex.ru/site/x/dashboard';
        }

        public function cabinetIndexingUrl(): string
        {
            return 'https://webmaster.yandex.ru/site/x/indexing';
        }

        public function cabinetDiagnosticsUrl(): string
        {
            return 'https://webmaster.yandex.ru/site/x/diagnostics';
        }

        public function cabinetLinksUrl(): string
        {
            return 'https://webmaster.yandex.ru/site/x/links';
        }

        public function cabinetQueriesUrl(): string
        {
            return 'https://webmaster.yandex.ru/site/x/search';
        }
    });

    $withQueries = collect(app(MetrikaDashboardPage::class)->renderWebmasterTab(
        DatePeriod::today(),
        ['webmaster_summary' => true, 'webmaster_queries' => true],
        5,
    ))->map(fn ($c) => (string) $c->render())->implode('');

    expect($withQueries)->toContain('Popular queries')
        ->and($withQueries)->toContain(__('moonshine-yandex-metrika::metrics.queries_empty'))
        ->and($withQueries)->toContain(__('moonshine-yandex-metrika::metrics.queries_fallback_hint', [
            'from' => '2026-11-01',
            'to' => '2026-11-07',
        ]));

    app()->instance(WebmasterService::class, new class extends WebmasterService
    {
        public function __construct() {}

        public function forPeriod(DatePeriod $period): static
        {
            return $this;
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function displayHost(): string
        {
            return 'demo.example';
        }

        public function popularQueriesReport(int $limit = 10): array
        {
            return [
                'rows' => [[
                    'query' => 'famfi',
                    'shows' => 10,
                    'clicks' => 2,
                    'avg_show_position' => 3.5,
                    'avg_click_position' => null,
                ]],
                'date_from' => null,
                'date_to' => null,
                'used_fallback' => false,
            ];
        }
    });

    $table = collect(app(MetrikaDashboardPage::class)->renderWebmasterTab(
        DatePeriod::today(),
        ['webmaster_summary' => false, 'webmaster_queries' => true],
        5,
    ))->map(fn ($c) => (string) $c->render())->implode('');

    expect($table)->toContain('famfi');

    request()->query->set('tab', 'unknown-tab');
    config()->set('moonshine-yandex-metrika.connection_page', false);

    $pageHtml = collect(app(MetrikaDashboardPage::class)->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');

    expect($pageHtml)->not->toContain('ym-settings-link');
});
