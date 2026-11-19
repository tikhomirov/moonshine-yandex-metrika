<?php

declare(strict_types=1);

use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use MoonShine\UI\Components\FlexibleRender;
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbeResult;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

beforeEach(function () {
    $this->app->instance(ConnectionProbe::class, new class
    {
        public function check(): ConnectionProbeResult
        {
            return ConnectionProbeResult::ok();
        }
    });

    $this->app->instance(MetrikaService::class, new class extends MetrikaService
    {
        public function __construct() {}

        public function forPeriod(DatePeriod $period): static
        {
            return $this;
        }

        public function totalVisits(?int $days = null): int
        {
            return 0;
        }

        public function totalPageViews(?int $days = null): int
        {
            return 0;
        }

        public function uniqueVisitors(?int $days = null): int
        {
            return 0;
        }

        public function bounceRate(?int $days = null): float
        {
            return 0.0;
        }

        public function visitsByDay(?int $days = null): array
        {
            return [];
        }

        public function topPages(?int $days = null, int $maxResults = 10): array
        {
            return [];
        }

        public function trafficSources(?int $days = null, int $maxResults = 10): array
        {
            return [];
        }

        public function searchPhrases(?int $days = null, int $maxResults = 10): array
        {
            return [];
        }

        public function geoCountries(?int $days = null, int $maxResults = 20): array
        {
            return [];
        }

        public function browsers(?int $days = null, int $maxResults = 10): array
        {
            return [];
        }
    });

    $this->app->instance(YandexAccountService::class, new class extends YandexAccountService
    {
        public function __construct() {}

        public function profile(): ?array
        {
            return null;
        }
    });

    $this->app->instance(WebmasterService::class, new class extends WebmasterService
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
});

test('additionalAnalyticsTabs may define uses_period and export for toolbar', function () {
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
                    'id' => 'legacy',
                    'label' => 'Legacy',
                    'icon' => 'chart-pie',
                    'enabled' => true,
                    'uses_period' => true,
                    'export_csv_url' => '/export/legacy.csv',
                    'export_csv_label' => 'Legacy CSV',
                ],
            ];
        }

        protected function buildCustomTabContent(
            string $tab,
            DatePeriod $period,
            array $widgets,
            int $maxRes,
        ): array {
            if ($tab === 'legacy') {
                return [
                    FlexibleRender::make('<p class="legacy-tab-body">legacy</p>'),
                ];
            }

            return parent::buildCustomTabContent($tab, $period, $widgets, $maxRes);
        }
    };

    request()->query->set('tab', 'legacy');

    $html = collect($page->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');

    expect($html)->toContain('Legacy CSV')
        ->and($html)->toContain('/export/legacy.csv')
        ->and($html)->toContain('<div class="ym-period">')
        ->and($html)->toContain('legacy-tab-body');
});
