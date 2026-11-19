<?php

declare(strict_types=1);

use MoonShine\UI\Components\FlexibleRender;
use Tikhomirov\MoonshineYandexMetrika\Contracts\MetrikaDashboardTabHost;
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Support\AnalyticsTabAttributes;
use Tikhomirov\MoonshineYandexMetrika\Support\AnalyticsTabRegistry;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;
use Tikhomirov\MoonshineYandexMetrika\Support\PackageConfig;
use Tikhomirov\MoonshineYandexMetrika\Support\WrapsPageComponentsInSpaceElements;
use Tikhomirov\MoonshineYandexMetrika\Tabs\MetrikaDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Tabs\WebmasterDashboardTab;

covers(
    AnalyticsTabAttributes::class,
    PackageConfig::class,
    DatePeriod::class,
    MetrikaDashboardTab::class,
    WebmasterDashboardTab::class,
    WrapsPageComponentsInSpaceElements::class,
    AnalyticsTabRegistry::class,
);

test('package config coerces config values safely', function (): void {
    config()->set('moonshine-yandex-metrika.cache_store', ['bad']);
    config()->set('moonshine-yandex-metrika.widgets', ['export' => 1, 'account' => 'yes']);

    expect(PackageConfig::string('moonshine-yandex-metrika.page_title', 'x'))->toBeString()
        ->and(PackageConfig::int('moonshine-yandex-metrika.max_results', 3))->toBeInt()
        ->and(PackageConfig::nullableString('moonshine-yandex-metrika.cache_store'))->toBeNull()
        ->and(PackageConfig::boolMap('moonshine-yandex-metrika.widgets'))->toHaveKey('export');
});

test('date period rejects invalid ranges and custom query params', function (): void {
    expect(fn () => DatePeriod::custom('2026-02-01', '2026-01-01'))
        ->toThrow(InvalidArgumentException::class);

    $custom = DatePeriod::custom('2026-01-01', '2026-01-31');

    expect($custom->toQuery())->toBe([
        'preset' => DatePeriod::PRESET_CUSTOM,
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ]);
});

test('analytics tab attributes none and dashboard tabs export urls', function (): void {
    $none = AnalyticsTabAttributes::none();
    expect($none->usesPeriod)->toBeFalse()
        ->and($none->exportCsvUrl)->toBeNull();

    $period = DatePeriod::fromPreset(DatePeriod::PRESET_TODAY);
    $metrikaTab = app(MetrikaDashboardTab::class);
    $webmasterTab = app(WebmasterDashboardTab::class);

    expect($metrikaTab->exportCsvUrl($period))->toContain('export/metrika')
        ->and($webmasterTab->requiresYandexConnection())->toBeTrue();

    config()->set('moonshine-yandex-metrika.widgets', ['export' => false, 'webmaster_summary' => false, 'webmaster_queries' => false]);
    app()->forgetInstance(WebmasterDashboardTab::class);

    expect(app(WebmasterDashboardTab::class)->enabled())->toBeFalse()
        ->and(app(WebmasterDashboardTab::class)->exportCsvUrl($period))->toBeNull();

    app()->instance(MetrikaDashboardTabHost::class, new class implements MetrikaDashboardTabHost
    {
        public function renderMetrikaTab(DatePeriod $period, array $widgets, int $maxResults): array
        {
            return [];
        }

        public function renderWebmasterTab(DatePeriod $period, array $widgets, int $maxResults): array
        {
            return [FlexibleRender::make('<span>wm</span>')];
        }
    });

    expect(app(WebmasterDashboardTab::class)->render($period, [], 5))->toHaveCount(1);
});

test('registry find returns null for unknown tab', function (): void {
    expect(app(AnalyticsTabRegistry::class)->find('missing'))->toBeNull()
        ->and(app(AnalyticsTabRegistry::class)->has('missing'))->toBeFalse();
});

test('metrika dashboard page breadcrumbs use configured title', function (): void {
    config()->set('moonshine-yandex-metrika.page_title', 'Custom title');

    $page = app(MetrikaDashboardPage::class);

    expect($page->getBreadcrumbs())->toBe(['#' => 'Custom title']);
});
