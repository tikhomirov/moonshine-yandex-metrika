<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tikhomirov\MoonshineYandexMetrika\Http\Controllers\ExportController;
use Tikhomirov\MoonshineYandexMetrika\Services\AnalyticsExportService;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

covers(ExportController::class, AnalyticsExportService::class);

function seedExportHttpMocks(): void
{
    config()->set('moonshine-yandex-metrika.cache_ttl', 0);

    app()->forgetInstance(MetrikaService::class);
    app()->forgetInstance(WebmasterService::class);
    app()->forgetInstance(AnalyticsExportService::class);

    $rows = fixtureJson('metrika/stats-rows.json');

    $metrika = app(MetrikaService::class);
    $metrikaHistory = [];
    mockServiceHttp($metrika, array_fill(0, 30, apiJson($rows)), $metrikaHistory);

    $webmaster = app(WebmasterService::class);
    $webHistory = [];
    mockServiceHttp($webmaster, [
        apiJson(fixtureJson('webmaster/user.json')),
        apiJson(fixtureJson('webmaster/hosts.json')),
        apiJson(fixtureJson('webmaster/summary.json')),
        apiJson(fixtureJson('webmaster/links.json')),
        apiJson(fixtureJson('webmaster/popular-queries.json')),
    ], $webHistory);
}

test('analytics export service builds metrika and webmaster csv with bom', function (): void {
    seedExportHttpMocks();

    $period = DatePeriod::fromPreset(DatePeriod::PRESET_WEEK);
    $export = app(AnalyticsExportService::class);

    $metrikaCsv = $export->metrikaCsv($period, 5);
    $webmasterCsv = $export->webmasterCsv($period, 5);

    expect($metrikaCsv)->toStartWith("\xEF\xBB\xBF")
        ->and($metrikaCsv)->toContain('"summary","visits"')
        ->and($webmasterCsv)->toStartWith("\xEF\xBB\xBF")
        ->and($webmasterCsv)->toContain('popular_queries');
});

test('export controller streams csv downloads for metrika and webmaster', function (): void {
    seedExportHttpMocks();

    $controller = app(ExportController::class);
    $export = app(AnalyticsExportService::class);

    $metrika = $controller->metrika(Request::create('/admin/yandex-analytics/export/metrika', 'GET', ['preset' => 'week']), $export);
    $webmaster = $controller->webmaster(Request::create('/admin/yandex-analytics/export/webmaster', 'GET', ['preset' => 'week']), $export);

    expect($metrika)->toBeInstanceOf(StreamedResponse::class)
        ->and($metrika->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($webmaster)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $metrika->sendContent();
    $body = (string) ob_get_clean();

    expect($body)->toStartWith("\xEF\xBB\xBF");
});
