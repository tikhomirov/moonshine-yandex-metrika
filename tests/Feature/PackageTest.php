<?php

declare(strict_types=1);

use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Services\AnalyticsExportService;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;

test('service provider registers package services', function () {
    expect(app()->bound(MetrikaService::class))->toBeTrue()
        ->and(app()->bound(WebmasterService::class))->toBeTrue()
        ->and(app()->bound(YandexAccountService::class))->toBeTrue()
        ->and(app()->bound(ConnectionProbe::class))->toBeTrue()
        ->and(app()->bound(AnalyticsExportService::class))->toBeTrue();
});

test('metrika dashboard page instantiates successfully', function () {
    $page = app(MetrikaDashboardPage::class);

    expect($page->getTitle())->toBe('Яндекс.Метрика');
});

test('export routes are registered', function () {
    expect(route('moonshine-yandex-metrika.export.metrika', absolute: false))
        ->toContain('yandex-analytics/export/metrika')
        ->and(route('moonshine-yandex-metrika.export.webmaster', absolute: false))
        ->toContain('yandex-analytics/export/webmaster');
});
