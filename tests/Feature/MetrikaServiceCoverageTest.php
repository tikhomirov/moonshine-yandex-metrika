<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

covers(MetrikaService::class);

/**
 * @return array<string, mixed>
 */
function metrikaTotalsResponse(int|float ...$totals): array
{
    return ['data' => [], 'totals' => $totals];
}

/**
 * @param  list<array<string, mixed>>  $dataRows
 * @return array<string, mixed>
 */
function metrikaDataResponse(array $dataRows): array
{
    return ['data' => $dataRows, 'totals' => []];
}

test('metrika service applies configured days period and forDays scope', function (): void {
    Carbon::setTestNow('2026-11-15');
    config()->set('moonshine-yandex-metrika.days', 7);
    config()->set('moonshine-yandex-metrika.cache_ttl', 0);

    $history = [];
    $service = new MetrikaService;

    mockServiceHttp($service, [
        apiJson(metrikaTotalsResponse(10)),
        apiJson(metrikaTotalsResponse(10)),
    ], $history);

    expect($service->forDays(3)->totalVisits())->toBe(10);

    Carbon::setTestNow();
});

test('metrika service exposes all metrics and normalizes row payloads', function (): void {
    config()->set('moonshine-yandex-metrika.cache_ttl', 0);

    $history = [];
    $service = new MetrikaService;
    $rows = metrikaDataResponse([
        [
            'dimensions' => [
                ['id' => '1', 'name' => 'Alpha'],
                ['name' => 'Bravo'],
                'invalid',
            ],
            'metrics' => [5, 2],
        ],
        ['dimensions' => 'broken', 'metrics' => 'n/a'],
        ['dimensions' => [], 'metrics' => [1]],
    ]);

    mockServiceHttp($service, [
        apiJson(metrikaTotalsResponse(1)),
        apiJson(metrikaTotalsResponse(2)),
        apiJson(metrikaTotalsResponse(3)),
        apiJson(metrikaTotalsResponse(4.5)),
        apiJson($rows),
        apiJson($rows),
        apiJson($rows),
        apiJson($rows),
        apiJson($rows),
        apiJson($rows),
        apiJson(metrikaDataResponse([
            ['dimensions' => [['name' => '2026-11-14']], 'metrics' => [7]],
            ['dimensions' => [['name' => '2026-11-15']], 'metrics' => [9]],
        ])),
    ], $history);

    $scoped = $service->forPeriod(DatePeriod::fromPreset(DatePeriod::PRESET_WEEK));

    expect($scoped->totalPageViews())->toBe(2)
        ->and($scoped->uniqueVisitors())->toBe(3)
        ->and($scoped->bounceRate())->toBe(4.5)
        ->and($scoped->topPages()[0]['dimension'])->toBe('Alpha / Bravo / ')
        ->and($scoped->trafficSources())->not->toBeEmpty()
        ->and($scoped->searchPhrases())->not->toBeEmpty()
        ->and($scoped->geoCountries())->not->toBeEmpty()
        ->and($scoped->browsers())->not->toBeEmpty()
        ->and($scoped->visitsByDay())->toHaveCount(3)
        ->and($scoped->request(['metrics' => 'ym:s:visits']))->not->toBeNull()
        ->and(MetrikaService::matchCounterByUrl([], 'https://example.com'))->toBeNull()
        ->and(MetrikaService::matchCounterByUrl([
            ['id' => '9', 'sites' => ['example.com:8080']],
        ], 'https://example.com:8080/path'))->toBe('9');
});

test('metrika counter resolution prefers db auto env and single counter', function (): void {
    migrateSettingsTable();
    $settings = app(YandexSettings::class);
    $settings->save(['counter_id' => '55555555']);

    $dbService = new MetrikaService;
    expect($dbService->counterId())->toBe('55555555')
        ->and($dbService->counterSource())->toBe('db');

    $settings->clear();
    config()->set('app.url', 'https://demo.example');
    config()->set('moonshine-yandex-metrika.counter_id', '');

    $history = [];
    $auto = new MetrikaService;
    mockServiceHttp($auto, [apiJson(fixtureJson('metrika/counters.json'))], $history, 'https://api.example.test/management/v1/');

    expect($auto->counterSource())->toBe('auto');

    config()->set('app.url', 'https://no-match.example');
    config()->set('moonshine-yandex-metrika.counter_id', '77777777');
    app(YandexSettings::class)->refresh();
    $env = new MetrikaService;
    mockServiceHttp($env, [apiJson(['counters' => [
        ['id' => 1, 'name' => 'A', 'site' => 'other.test'],
        ['id' => 2, 'name' => 'B', 'site' => 'another.test'],
    ]])], $history, 'https://api.example.test/management/v1/');

    expect($env->counterSource())->toBe('env');

    app(YandexSettings::class)->clear();
    config()->set('moonshine-yandex-metrika.counter_id', '');
    app(YandexSettings::class)->refresh();
    Cache::flush();
    $single = new MetrikaService;
    mockServiceHttp($single, [apiJson(['counters' => [
        ['id' => 333, 'name' => 'Only', 'site' => 'only.test'],
    ]])], $history, 'https://api.example.test/management/v1/');

    expect($single->counterId())->toBe('333')
        ->and($single->counterSource())->toBe('auto');

});

test('metrika counters from response skips invalid items and sites', function (): void {
    $parsed = MetrikaService::countersFromResponse([
        'counters' => [
            ['id' => 'bad'],
            ['id' => 10, 'name' => 123, 'site2' => ['site' => 'a.test', 'domain' => 'b.test'], 'site' => 'c.test'],
        ],
    ]);

    expect($parsed)->toHaveCount(1)
        ->and($parsed[0]['sites'])->toBe(['a.test', 'b.test', 'c.test']);
});
