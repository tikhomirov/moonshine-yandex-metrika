<?php

declare(strict_types=1);

use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\UI\Components\FlexibleRender;
use Tikhomirov\MoonshineYandexMetrika\Contracts\AnalyticsDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbeResult;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;
use Tikhomirov\MoonshineYandexMetrika\Support\AnalyticsTabRegistry;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

beforeEach(function () {
    $this->app->instance(MetrikaService::class, new class extends MetrikaService
    {
        public function __construct() {}

        public function forPeriod(DatePeriod $period): static
        {
            return $this;
        }

        public function totalVisits(?int $days = null): int
        {
            return 1;
        }

        public function totalPageViews(?int $days = null): int
        {
            return 1;
        }

        public function uniqueVisitors(?int $days = null): int
        {
            return 1;
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

    app(AnalyticsTabRegistry::class)->register(new class implements AnalyticsDashboardTab
    {
        public function id(): string
        {
            return 'third_party';
        }

        public function label(): string
        {
            return 'Third Party Analytics';
        }

        public function icon(): string
        {
            return 'chart-pie';
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

        /**
         * @param  array<string, bool>  $widgets
         * @return list<ComponentContract>
         */
        public function render(DatePeriod $period, array $widgets, int $maxResults): array
        {
            return [
                FlexibleRender::make(
                    '<p class="ym-third-party-marker">'.e($period->date1()).'</p>',
                ),
            ];
        }

        public function exportCsvUrl(DatePeriod $period): ?string
        {
            return null;
        }
    });
});

test('registered third party tab appears in navigation and renders on ?tab=', function () {
    request()->query->set('tab', 'third_party');

    $page = app(MetrikaDashboardPage::class);

    $html = collect($page->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');

    expect($html)->toContain('tab=third_party')
        ->and($html)->toContain('Third Party Analytics')
        ->and($html)->toContain('ym-third-party-marker');
});

test('third party tab works when yandex connection probe fails', function () {
    app()->instance(ConnectionProbe::class, new class
    {
        public function check(): ConnectionProbeResult
        {
            return ConnectionProbeResult::failed('OAuth-токен отклонён Яндексом.');
        }
    });

    request()->query->set('tab', 'third_party');

    $page = app(MetrikaDashboardPage::class);

    $html = collect($page->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');

    expect($html)->toContain('ym-tabs')
        ->and($html)->toContain('ym-third-party-marker')
        ->and($html)->not->toContain('OAuth-токен отклонён');
});

test('metrika tab shows probe warning while navigation stays available', function () {
    app()->instance(ConnectionProbe::class, new class
    {
        public function check(): ConnectionProbeResult
        {
            return ConnectionProbeResult::failed('OAuth-токен отклонён Яндексом.');
        }
    });

    request()->query->set('tab', MetrikaDashboardPage::TAB_METRIKA);

    $page = app(MetrikaDashboardPage::class);

    $html = collect($page->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');

    expect($html)->toContain('ym-tabs')
        ->and($html)->toContain('OAuth-токен отклонён');
});