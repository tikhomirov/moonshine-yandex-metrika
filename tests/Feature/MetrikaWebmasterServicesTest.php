<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbeResult;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

covers(
    MetrikaService::class,
    WebmasterService::class,
    YandexAccountService::class,
    ConnectionProbe::class,
    ConnectionProbeResult::class,
);

test('metrika service exposes metrics, rows and counter matchers', function (): void {
    config()->set('moonshine-yandex-metrika.cache_ttl', 0);

    $history = [];
    $service = new MetrikaService;
    $rows = fixtureJson('metrika/stats-rows.json');

    mockServiceHttp($service, [
        apiJson(fixtureJson('metrika/counters.json')),
        apiJson(['data' => [], 'totals' => [42]]),
        apiJson($rows),
    ], $history);

    expect($service->totalVisits())->toBe(42)
        ->and($service->topPages()[0]['dimension'])->toBe('/home')
        ->and($service->isConfigured())->toBeTrue()
        ->and(MetrikaService::matchCounterByUrl([
            ['id' => '1', 'sites' => ['demo.example']],
        ], 'https://www.demo.example'))->toBe('1')
        ->and(MetrikaService::countersFromResponse(fixtureJson('metrika/counters.json'))[0]['name'])->toBe('Demo');

});

test('metrika request returns null on invalid json and guzzle failures', function (): void {
    $history = [];
    $service = new MetrikaService;
    mockServiceHttp($service, [
        apiJson(fixtureJson('metrika/counters.json')),
        new Response(200, [], 'not-json'),
        new ConnectException('down', new PsrRequest('GET', '')),
    ], $history);

    expect($service->request(['metrics' => 'ym:s:visits']))->toBeNull()
        ->and($service->request(['metrics' => 'ym:s:pageviews']))->toBeNull();
});

test('webmaster service resolves summary, links, queries fallback and cabinet urls', function (): void {
    config()->set('app.url', 'https://demo.example');
    $history = [];
    $service = new WebmasterService;
    $emptyQueries = ['queries' => [], 'date_from' => null, 'date_to' => null];

    mockServiceHttp($service, [
        apiJson(fixtureJson('webmaster/user.json')),
        apiJson(fixtureJson('webmaster/hosts.json')),
        apiJson(fixtureJson('webmaster/summary.json')),
        apiJson(fixtureJson('webmaster/links.json')),
        apiJson($emptyQueries),
        apiJson(fixtureJson('webmaster/popular-queries.json')),
    ], $history);

    $period = DatePeriod::fromPreset(DatePeriod::PRESET_WEEK);
    $scoped = $service->forPeriod($period);

    expect($scoped->summary())->not->toBeNull()
        ->and($scoped->externalLinksTotal())->toBe(120)
        ->and($scoped->popularQueriesReport(5)['used_fallback'])->toBeTrue()
        ->and($scoped->cabinetSummaryUrl())->toContain('webmaster.yandex.ru')
        ->and($scoped->displayHost())->toBe('demo.example')
        ->and(WebmasterService::matchHostByUrl(['https:demo.example:443'], config('app.url')))->toBe('https:demo.example:443')
        ->and(WebmasterService::hostsFromResponse(fixtureJson('webmaster/hosts.json'))[0]['verified'])->toBeTrue();
});

test('webmaster returns empty report when not configured', function (): void {
    config()->set('moonshine-yandex-metrika.token', '');
    app()->forgetInstance(WebmasterService::class);

    $report = app(WebmasterService::class)->popularQueriesReport();

    expect($report['rows'])->toBe([])
        ->and($report['used_fallback'])->toBeFalse();
});

test('yandex account service loads profile and oauth app client id', function (): void {
    $history = [];
    $service = new YandexAccountService;
    mockServiceHttp($service, [apiJson(fixtureJson('yandex/login-info.json'))], $history, 'https://login.yandex.ru/info');

    $profile = $service->profile();

    expect($profile)->not->toBeNull()
        ->and($profile['email'])->toBe('demo@example.com')
        ->and($profile['avatar_url'])->toContain('avatars.yandex.net')
        ->and($service->oauthApp()->clientId)->toBe('4760187d81bc4b7799476b42b5103713');
});

test('connection probe handles missing config, cache and probe requests', function (): void {
    config()->set('moonshine-yandex-metrika.token', '');
    app()->forgetInstance(ConnectionProbe::class);
    app()->forgetInstance(MetrikaService::class);

    $missing = app(ConnectionProbe::class)->check();
    expect($missing->isOk())->toBeFalse();

    config()->set('moonshine-yandex-metrika.token', 'test-token');
    app()->forgetInstance(ConnectionProbe::class);

    Cache::flush();
    $first = app(ConnectionProbe::class)->check();
    $second = app(ConnectionProbe::class)->check();

    expect($second)->toBe($first)
        ->and(app(ConnectionProbe::class)->fresh())->toBeInstanceOf(ConnectionProbeResult::class);

    expect(ConnectionProbeResult::notConfigured('msg')->message)->toBe('msg')
        ->and(ConnectionProbeResult::failed('fail')->isOk())->toBeFalse()
        ->and(ConnectionProbeResult::ok()->isOk())->toBeTrue();
});
