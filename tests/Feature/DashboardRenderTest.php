<?php

declare(strict_types=1);

use Tikhomirov\MoonshineYandexMetrika\Components\LineChartComponent;
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbeResult;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

beforeEach(function () {
    $this->app->instance(MetrikaService::class, new class extends MetrikaService
    {
        public function __construct()
        {
            // Skip HTTP client bootstrap; stub methods below.
        }

        public function forPeriod(DatePeriod $period): static
        {
            return $this;
        }

        public function totalVisits(?int $days = null): int
        {
            return 42;
        }

        public function totalPageViews(?int $days = null): int
        {
            return 42;
        }

        public function uniqueVisitors(?int $days = null): int
        {
            return 42;
        }

        public function bounceRate(?int $days = null): float
        {
            return 12.5;
        }

        public function visitsByDay(?int $days = null): array
        {
            return [
                ['dimension' => '2026-11-01', 'metrics' => [10]],
                ['dimension' => '2026-11-02', 'metrics' => [15]],
            ];
        }

        public function topPages(?int $days = null, int $maxResults = 10): array
        {
            return [['dimension' => 'item', 'metrics' => [5]]];
        }

        public function trafficSources(?int $days = null, int $maxResults = 10): array
        {
            return [['dimension' => 'item', 'metrics' => [5, 3]]];
        }

        public function searchPhrases(?int $days = null, int $maxResults = 10): array
        {
            return [['dimension' => 'item', 'metrics' => [5]]];
        }

        public function geoCountries(?int $days = null, int $maxResults = 20): array
        {
            return [['dimension' => 'item', 'metrics' => [5]]];
        }

        public function browsers(?int $days = null, int $maxResults = 10): array
        {
            return [['dimension' => 'item', 'metrics' => [5]]];
        }
    });

    $this->app->instance(ConnectionProbe::class, new class
    {
        public function check(): ConnectionProbeResult
        {
            return ConnectionProbeResult::ok();
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

test('line chart component is created via inherited make() and renders', function () {
    $chart = LineChartComponent::make([
        ['dimension' => '2026-11-01', 'metrics' => [10]],
        ['dimension' => '2026-11-02', 'metrics' => [15]],
    ]);

    expect($chart)->toBeInstanceOf(LineChartComponent::class)
        ->and($chart->getLabels())->toBe(['2026-11-01', '2026-11-02'])
        ->and($chart->getValues())->toBe([10, 15]);

    $html = (string) $chart->render();

    expect($html)->toContain('<canvas id="metrika_chart_')
        ->and($html)->toContain('["2026-11-01","2026-11-02"]');
});

test('line chart component accepts empty data', function () {
    expect((string) LineChartComponent::make()->render())->toContain('<canvas');
});

test('metrika dashboard renders all default widgets including visits chart', function () {
    $page = app(MetrikaDashboardPage::class);

    $html = collect($page->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');

    expect($html)->toContain('ym-tabs')
        ->and($html)->toContain('<canvas id="metrika_chart_')
        ->and($html)->toContain('2026-11-02')
        ->and($html)->toContain('42');
});
