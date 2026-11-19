<?php

declare(strict_types=1);

use Tikhomirov\MoonshineYandexMetrika\Http\Controllers\ConnectionController;
use Tikhomirov\MoonshineYandexMetrika\Pages\YandexConnectionPage;
use Tikhomirov\MoonshineYandexMetrika\Services\AnalyticsExportService;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;
use Tikhomirov\MoonshineYandexMetrika\Tabs\MetrikaDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Tabs\WebmasterDashboardTab;

covers(
    AnalyticsExportService::class,
    YandexConnectionPage::class,
    MetrikaDashboardTab::class,
    WebmasterDashboardTab::class,
    ConnectionController::class,
);

function sampleRows(): array
{
    return [['dimension' => 'row', 'metrics' => [1, 2]]];
}

test('analytics export service csv rows are built from stubbed metric services', function (): void {
    $row = sampleRows();
    $metrika = new class extends MetrikaService
    {
        public function __construct() {}

        public function forPeriod(DatePeriod $period): static
        {
            return $this;
        }

        public function totalVisits(?int $days = null): int
        {
            return 10;
        }

        public function totalPageViews(?int $days = null): int
        {
            return 10;
        }

        public function uniqueVisitors(?int $days = null): int
        {
            return 10;
        }

        public function bounceRate(?int $days = null): float
        {
            return 3.3;
        }

        public function visitsByDay(?int $days = null): array
        {
            return sampleRows();
        }

        public function topPages(?int $days = null, int $maxResults = 10): array
        {
            return sampleRows();
        }

        public function trafficSources(?int $days = null, int $maxResults = 10): array
        {
            return sampleRows();
        }

        public function searchPhrases(?int $days = null, int $maxResults = 10): array
        {
            return sampleRows();
        }

        public function geoCountries(?int $days = null, int $maxResults = 20): array
        {
            return sampleRows();
        }

        public function browsers(?int $days = null, int $maxResults = 10): array
        {
            return sampleRows();
        }
    };

    $webmaster = new class extends WebmasterService
    {
        public function __construct() {}

        public function forPeriod(DatePeriod $period): static
        {
            return $this;
        }

        public function hostId(): string
        {
            return 'https:demo.example:443';
        }

        public function displayHost(): string
        {
            return 'demo.example';
        }

        public function summary(): ?array
        {
            return [
                'searchable_pages_count' => 1,
                'excluded_pages_count' => 2,
                'site_problems' => [
                    'FATAL' => 0,
                    'CRITICAL' => 0,
                    'POSSIBLE_PROBLEM' => 0,
                    'RECOMMENDATION' => 0,
                ],
                'problems_total' => 0,
                'sqi' => null,
            ];
        }

        public function externalLinksTotal(): ?int
        {
            return null;
        }

        public function popularQueries(int $limit = 10): array
        {
            return [[
                'query' => 'q',
                'shows' => 1,
                'clicks' => 2,
                'avg_show_position' => 1.0,
                'avg_click_position' => null,
            ]];
        }
    };

    $export = new AnalyticsExportService($metrika, $webmaster);
    $period = DatePeriod::fromPreset(DatePeriod::PRESET_WEEK);

    $csv = $export->metrikaCsv($period);
    expect($csv)->toContain('search_phrases')
        ->and($csv)->toContain('browsers')
        ->and($export->webmasterCsv($period))->toContain('n/a');
});

test('connection controller check returns toast response', function (): void {
    $response = app(ConnectionController::class)->check();

    expect($response->isRedirect())->toBeTrue();
});

test('metrika and webmaster tabs cover export disabled branch', function (): void {
    config()->set('moonshine-yandex-metrika.widgets', ['export' => false]);
    $period = DatePeriod::today();

    expect(app(MetrikaDashboardTab::class)->exportCsvUrl($period))->toBeNull()
        ->and(app(WebmasterDashboardTab::class)->exportCsvUrl($period))->toBeNull();
});

test('yandex connection page renders when oauth client exists', function (): void {
    migrateSettingsTable();
    app(YandexSettings::class)
        ->save(['client_id' => '4760187d81bc4b7799476b42b5103713', 'client_secret' => 'super-secret-value']);

    $html = collect(app(YandexConnectionPage::class)->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');

    expect($html)->toContain('ym-step');
});
