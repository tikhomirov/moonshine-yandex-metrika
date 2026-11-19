<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use Tikhomirov\MoonshineYandexMetrika\Contracts\AnalyticsDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Pages\YandexConnectionPage;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbeResult;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Support\AnalyticsTabRegistry;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

covers(
    MetrikaDashboardPage::class,
    YandexConnectionPage::class,
    MetrikaService::class,
    WebmasterService::class,
    YandexSettings::class,
);

test('yandex settings tableExists returns false when schema throws', function (): void {
    Schema::shouldReceive('hasTable')->andThrow(new RuntimeException('db unavailable'));

    expect(app(YandexSettings::class)->tableExists())->toBeFalse();
});

test('yandex settings handles non scalar saves unknown config keys and decrypt errors', function (): void {
    migrateSettingsTable();
    $settings = app(YandexSettings::class);

    expect($settings->configValue('no_such_field'))->toBe('');
    $settings->save(['client_id' => ['nested']]);

    expect($settings->clientId())->toBe('');

});

test('metrika siteKey and matchCounter cover port and invalid url branches', function (): void {
    $siteKey = new ReflectionMethod(MetrikaService::class, 'siteKey');
    $siteKey->setAccessible(true);

    expect($siteKey->invoke(null, ''))->toBeNull()
        ->and($siteKey->invoke(null, 'http://'))->toBeNull()
        ->and(MetrikaService::matchCounterByUrl([], ':::invalid:::'))->toBeNull()
        ->and(MetrikaService::matchCounterByUrl([
            ['id' => '9', 'sites' => ['demo.example']],
        ], 'https://demo.example:8080/'))->toBe('9');
});

test('webmaster get returns cached payload without http', function (): void {
    config()->set('moonshine-yandex-metrika.cache_ttl', 3600);
    config()->set('moonshine-yandex-metrika.webmaster.host_id', 'https:demo.example:443');
    Cache::flush();

    $service = new WebmasterService;
    $tokenProp = new ReflectionProperty(WebmasterService::class, 'token');
    $tokenProp->setAccessible(true);
    $token = (string) $tokenProp->getValue($service);
    $path = 'user';
    $cacheKey = 'yandex_webmaster_'.md5($path.'|'.serialize([]).'|'.sha1($token));
    Cache::put($cacheKey, fixtureJson('webmaster/user.json'), 3600);

    $history = [];
    mockServiceHttp($service, [], $history);

    $expected = fixtureJson('webmaster/user.json')['user_id'];

    expect($service->resolveUserId())->toBe(is_scalar($expected) ? (string) $expected : null)
        ->and(count($history))->toBeLessThanOrEqual(1);

    config()->set('moonshine-yandex-metrika.cache_ttl', 0);
});

test('webmaster single host auto resolve and displayHost fallback', function (): void {
    config()->set('moonshine-yandex-metrika.cache_ttl', 0);
    config()->set('moonshine-yandex-metrika.webmaster.host_id', '');
    config()->set('app.url', 'https://unique-auto.example');

    migrateSettingsTable();
    app(YandexSettings::class)->clear();
    app(YandexSettings::class)->refresh();

    $history = [];
    $service = new WebmasterService;
    mockServiceHttp($service, [
        apiJson(fixtureJson('webmaster/user.json')),
        apiJson(['hosts' => [['host_id' => 'https:only-one.example:443', 'unicode_host_url' => 'https://only-one.example/', 'verified' => true]]]),
    ], $history);

    expect($service->hostId())->toBe('https:only-one.example:443')
        ->and($service->displayHost())->toBe('only-one.example');

    config()->set('moonshine-yandex-metrika.webmaster.host_id', '');
    $empty = new WebmasterService;
    $hostProp = new ReflectionProperty(WebmasterService::class, 'hostId');
    $hostProp->setAccessible(true);
    $hostProp->setValue($empty, '');

    expect($empty->displayHost())->toBe('site');
});

test('connection page withAuto adds stored host and metrika dashboard toolbar uses avatar export labels', function (): void {
    migrateSettingsTable();
    app(YandexSettings::class)->save([
        'token' => 'test-token',
        'host_id' => 'orphan-host-not-in-list',
    ]);

    app()->instance(WebmasterService::class, new class extends WebmasterService
    {
        public function __construct() {}

        public function hosts(): ?array
        {
            return [
                ['host_id' => 'https:listed.example:443', 'url' => 'https://listed.example/', 'verified' => true],
            ];
        }
    });

    $html = collect(app(YandexConnectionPage::class)->getComponents())
        ->map(fn ($c) => (string) $c->render())
        ->implode('');

    expect($html)->toContain('orphan-host-not-in-list');

    app()->instance(ConnectionProbe::class, new class
    {
        public function check(): ConnectionProbeResult
        {
            return ConnectionProbeResult::ok();
        }
    });

    config()->set('moonshine-yandex-metrika.widgets', ['account' => true]);
    config()->set('moonshine-yandex-metrika.summary_metrics', ['visits', 42, 'unknown', 'pageviews']);

    $core = app(MetrikaDashboardPage::class)->getCore();
    $page = new class($core) extends MetrikaDashboardPage
    {
        public function __construct(CoreContract $core)
        {
            parent::__construct($core);
        }

        protected function additionalAnalyticsTabs(): array
        {
            return [
                [
                    'id' => 'legacy-export',
                    'label' => 'Legacy Exp',
                    'icon' => 'chart-pie',
                    'enabled' => true,
                    'export_csv_url' => '/legacy/export.csv',
                ],
                [
                    'id' => 'legacy-blank',
                    'label' => 'Legacy Blank',
                    'icon' => 'chart-pie',
                    'enabled' => true,
                    'export_csv_url' => '',
                ],
            ];
        }
    };

    app(AnalyticsTabRegistry::class)->register(new class implements AnalyticsDashboardTab
    {
        public function id(): string
        {
            return 'partner-tab';
        }

        public function label(): string
        {
            return 'Partner';
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
            return [];
        }

        public function exportCsvUrl(DatePeriod $period): ?string
        {
            return '/partner.csv';
        }
    });

    $resolve = new ReflectionMethod(MetrikaDashboardPage::class, 'resolveTabAttributes');
    $resolve->setAccessible(true);

    expect($resolve->invoke($page, 'legacy-export', DatePeriod::today())->exportCsvLabel)
        ->toContain('Legacy Exp CSV')
        ->and($resolve->invoke($page, 'legacy-blank', DatePeriod::today())->exportCsvUrl)->toBeNull()
        ->and($resolve->invoke($page, 'partner-tab', DatePeriod::today())->exportCsvLabel)
        ->toBe('Partner CSV');

    $buildCustom = new ReflectionMethod(MetrikaDashboardPage::class, 'buildCustomTabContent');
    $buildCustom->setAccessible(true);

    expect($buildCustom->invoke($page, 'missing-tab', DatePeriod::today(), [], 10))->toBe([]);
});
