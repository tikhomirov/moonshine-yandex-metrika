<?php

declare(strict_types=1);

use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use Tikhomirov\MoonshineYandexMetrika\Contracts\AnalyticsDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbeResult;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;
use Tikhomirov\MoonshineYandexMetrika\Support\AnalyticsTabRegistry;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

covers(WebmasterService::class, MetrikaDashboardPage::class);

test('webmaster returns early when token or host is missing', function (): void {
    config()->set('moonshine-yandex-metrika.cache_ttl', 0);
    config()->set('moonshine-yandex-metrika.token', '');
    config()->set('moonshine-yandex-metrika.webmaster.host_id', '');

    $offline = new WebmasterService;

    expect($offline->summary())->toBeNull()
        ->and($offline->externalLinksTotal())->toBeNull()
        ->and($offline->popularQueriesReport()['rows'])->toBe([])
        ->and($offline->resolveUserId())->toBeNull()
        ->and(WebmasterService::matchHostByUrl(['javascript:void(0)'], 'https://demo.example'))->toBeNull();
});

test('metrika dashboard resolves unknown tabs registered export and toolbar branches', function (): void {
    app()->instance(ConnectionProbe::class, new class
    {
        public function check(): ConnectionProbeResult
        {
            return ConnectionProbeResult::ok();
        }
    });

    app()->instance(MetrikaService::class, new class extends MetrikaService
    {
        public function __construct() {}

        public function totalVisits(?int $days = null): int
        {
            return 1;
        }

        public function totalPageViews(?int $days = null): int
        {
            return 2;
        }

        public function uniqueVisitors(?int $days = null): int
        {
            return 3;
        }

        public function bounceRate(?int $days = null): float
        {
            return 1.5;
        }
    });

    app()->instance(YandexAccountService::class, new class extends YandexAccountService
    {
        public function __construct() {}

        public function profile(): ?array
        {
            return [
                'id' => '1',
                'login' => null,
                'email' => 'demo@example.com',
                'avatar_url' => 'https://avatars.example/ava.jpg',
                'client_id' => null,
            ];
        }
    });

    $core = app(MetrikaDashboardPage::class)->getCore();
    $page = new class($core) extends MetrikaDashboardPage
    {
        public function __construct(CoreContract $core)
        {
            parent::__construct($core);
        }
    };

    $resolve = new ReflectionMethod(MetrikaDashboardPage::class, 'resolveTabAttributes');
    $resolve->setAccessible(true);

    expect($resolve->invoke($page, 'no-such-tab', DatePeriod::today())->exportCsvUrl)->toBeNull();

    app(AnalyticsTabRegistry::class)->register(new class implements AnalyticsDashboardTab
    {
        public function id(): string
        {
            return 'extra-export';
        }

        public function label(): string
        {
            return 'Extra';
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
            return '/extra.csv';
        }
    });

    expect($resolve->invoke($page, 'extra-export', DatePeriod::today())->exportCsvLabel)->toBe('Extra CSV');

    config()->set('moonshine-yandex-metrika.widgets', ['account' => true]);
    config()->set('moonshine-yandex-metrika.summary_metrics', 'invalid-order');

    $toolbar = new ReflectionMethod(MetrikaDashboardPage::class, 'buildToolbar');
    $toolbar->setAccessible(true);
    $toolbarHtml = (string) $toolbar->invoke(
        $page,
        DatePeriod::today(),
        MetrikaDashboardPage::TAB_METRIKA,
        ConnectionProbeResult::ok(),
    );

    expect($toolbarHtml)->toContain('avatars.example/ava.jpg');

    $webmasterToolbar = (string) $toolbar->invoke(
        $page,
        DatePeriod::today(),
        MetrikaDashboardPage::TAB_WEBMASTER,
        ConnectionProbeResult::ok(),
    );

    expect($webmasterToolbar)->not->toContain('name="date_from"');

    $summaryKpis = new ReflectionMethod(MetrikaDashboardPage::class, 'buildSummaryMetrics');
    $summaryKpis->setAccessible(true);
    $metrika = app(MetrikaService::class);

    expect($summaryKpis->invoke($page, $metrika, ['visits' => true, 'pageviews' => true]))->toHaveCount(4);

    config()->set('moonshine-yandex-metrika.summary_metrics', ['visits', 7, 'pageviews']);

    expect($summaryKpis->invoke($page, $metrika, ['visits' => true, 'pageviews' => true]))->toHaveCount(2);
});