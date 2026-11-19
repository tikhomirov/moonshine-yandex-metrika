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
        ->and($html)->toContain('["2026-11-01","2026-11-02"]')
        ->and($html)->toContain('ymMetrikaChartColors')
        ->and($html)->toContain('--ms-cm-base-text');
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
        ->and($html)->toContain('ym-stats')
        ->and($html)->toContain('ym-stat__value')
        ->and($html)->toContain('background:var(--ms-cm-base-default')
        ->and($html)->toContain('color:var(--ms-cm-base-text')
        ->and($html)->toContain('color-scheme:dark')
        ->and($html)->not->toContain('background:#fff')
        ->and($html)->not->toContain('background:#1e293b')
        ->and($html)->toContain('class="btn btn-primary btn-sm">')
        ->and($html)->not->toContain('ym-apply')
        ->and($html)->toContain('ymMetrikaChartColors')
        ->and($html)->toContain('<canvas id="metrika_chart_')
        ->and($html)->toContain('2026-11-02')
        ->and($html)->toContain('42');
});

test('metrika dashboard wraps all page components in one space-elements container', function () {
    $page = app(MetrikaDashboardPage::class);
    $components = $page->getComponents();

    expect($components)->toHaveCount(1);

    $root = $components->first();

    expect($root)->toBeInstanceOf(\MoonShine\UI\Components\Layout\Div::class)
        ->and((string) $root->getAttribute('class'))->toContain('space-elements');
});

/**
 * @return list<string>
 */
function metrikaSummaryLabels(): array
{
    $html = collect(app(MetrikaDashboardPage::class)->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');

    preg_match_all('/<div class="ym-stat__label">([^<]*)<\/div>/', $html, $matches);

    return $matches[1];
}

test('metrika kpi cards follow the default summary_metrics order', function () {
    expect(metrikaSummaryLabels())->toBe([
        'Visits',
        'Page Views',
        'Unique Visitors',
        'Bounce Rate',
    ]);
});

test('metrika kpi cards follow a custom summary_metrics order and skip unknown keys', function () {
    config()->set('moonshine-yandex-metrika.summary_metrics', [
        'unique_visitors',
        'unknown_metric',
        'visits',
        'pageviews',
        'bounce_rate',
    ]);

    expect(metrikaSummaryLabels())->toBe([
        'Unique Visitors',
        'Visits',
        'Page Views',
        'Bounce Rate',
    ]);
});

test('disabled widget flag still hides the matching kpi card', function () {
    config()->set('moonshine-yandex-metrika.widgets.unique_visitors', false);

    expect(metrikaSummaryLabels())->toBe([
        'Visits',
        'Page Views',
        'Bounce Rate',
    ]);
});
