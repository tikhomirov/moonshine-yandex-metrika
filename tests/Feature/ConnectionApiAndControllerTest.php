<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tikhomirov\MoonshineYandexMetrika\Http\Controllers\ConnectionController;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthFlow;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

/**
 * Подменяет Guzzle-клиент сервиса на MockHandler: без сети, с историей запросов.
 *
 * @param  list<Response|Throwable>  $responses
 * @param  array<int, array{request: RequestInterface}>  $history
 */
function mockServiceHttp(object $service, array $responses, array &$history): void
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $property = new ReflectionProperty($service, 'http');
    $property->setValue($service, new Client(['handler' => $stack, 'base_uri' => 'https://api.example.test/v4/']));
}

function apiJson(array $data): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($data));
}

function controllerRequest(string $uri, array $query = []): Request
{
    $request = Request::create($uri, 'GET', $query);
    $request->setLaravelSession(new Store('test', new ArraySessionHandler(10)));

    return $request;
}

function migrateSettingsTable(): void
{
    test()->artisan('migrate', ['--force' => true])->run();
    app(YandexSettings::class)->refresh();
}

test('metrika counters are loaded from the management api once and cached', function () {
    $history = [];
    $service = new MetrikaService;
    mockServiceHttp($service, [apiJson(['counters' => [
        ['id' => 22222222, 'name' => 'Demo', 'site2' => ['site' => 'demo.example', 'domain' => 'demo.example']],
    ]])], $history);

    $expected = [['id' => '22222222', 'name' => 'Demo', 'site' => 'demo.example', 'sites' => ['demo.example']]];

    expect($service->counters())->toBe($expected)
        ->and($service->counters())->toBe($expected)
        ->and($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri())->toContain('/management/v1/counters');
});

test('metrika counters are null on an api error, a bad payload or without a token', function () {
    $history = [];
    $failing = new MetrikaService;
    mockServiceHttp($failing, [new ConnectException('down', new PsrRequest('GET', 'counters'))], $history);

    $broken = new MetrikaService;
    mockServiceHttp($broken, [apiJson(['errors' => []])], $history);

    expect($failing->counters())->toBeNull()
        ->and($broken->counters())->toBeNull();

    config()->set('moonshine-yandex-metrika.token', null);
    app(YandexSettings::class)->refresh();

    expect((new MetrikaService)->counters())->toBeNull();
});

test('metrika requests use the resolved counter id', function () {
    $history = [];
    $service = new MetrikaService;
    mockServiceHttp($service, [
        apiJson(['counters' => [['id' => 99999999, 'name' => 'Other', 'site' => 'other.example']]]),
        apiJson(['data' => [], 'totals' => [42]]),
    ], $history);

    expect($service->totalVisits())->toBe(42)
        ->and($service->counterSource())->toBe('env')
        ->and((string) $history[1]['request']->getUri())->toContain('ids=12345678');
});

test('webmaster hosts are loaded via user id and cached', function () {
    $history = [];
    $service = new WebmasterService;
    mockServiceHttp($service, [
        apiJson(['user_id' => 777]),
        apiJson(['hosts' => [['host_id' => 'https:demo.example:443', 'unicode_host_url' => 'https://demo.example/', 'verified' => true]]]),
    ], $history);

    $expected = [['host_id' => 'https:demo.example:443', 'url' => 'https://demo.example', 'verified' => true]];

    expect($service->hosts())->toBe($expected)
        ->and($service->hosts())->toBe($expected)
        ->and($history)->toHaveCount(2)
        ->and((string) $history[1]['request']->getUri())->toContain('user/777/hosts');
});

test('webmaster hosts are null when the api fails', function () {
    $history = [];
    $service = new WebmasterService;
    mockServiceHttp($service, [
        new ConnectException('down', new PsrRequest('GET', 'user')),
        new ConnectException('down', new PsrRequest('GET', 'user')),
    ], $history);

    expect($service->hosts())->toBeNull()
        ->and($service->hostId())->toBe('')
        ->and($service->hostSource())->toBeNull();
});

test('oauth redirect needs the table and the client, then goes to yandex', function () {
    $controller = app(ConnectionController::class);
    $settings = app(YandexSettings::class);

    $noTable = $controller->redirect(controllerRequest('/admin/yandex-analytics/oauth/redirect'), app(YandexOAuthFlow::class), $settings);
    expect($noTable->getTargetUrl())->toEndWith('/admin/page/yandex-connection-page');

    migrateSettingsTable();
    $noClient = $controller->redirect(controllerRequest('/admin/yandex-analytics/oauth/redirect'), app(YandexOAuthFlow::class), $settings);
    expect($noClient->getTargetUrl())->toEndWith('/admin/page/yandex-connection-page');

    $settings->save(['client_id' => '4760187d81bc4b7799476b42b5103713', 'client_secret' => 'super-secret-value']);
    $request = controllerRequest('/admin/yandex-analytics/oauth/redirect');
    $away = $controller->redirect($request, app(YandexOAuthFlow::class), $settings);

    expect($away->getTargetUrl())->toStartWith('https://oauth.yandex.ru/authorize?')
        ->and($away->getTargetUrl())->toContain('code_challenge_method=S256')
        ->and($away->getTargetUrl())->not->toContain('super-secret-value')
        ->and($request->session()->get('moonshine-yandex-metrika.oauth.state'))->not->toBeEmpty();
});

test('oauth callback with an error from yandex clears the state and returns to the page', function () {
    $request = controllerRequest('/admin/yandex-analytics/oauth/callback', ['error' => 'access_denied', 'error_description' => 'denied']);
    $request->session()->put('moonshine-yandex-metrika.oauth', ['state' => 'abc']);

    $response = app(ConnectionController::class)->callback($request, app(YandexOAuthFlow::class));

    expect($response->getTargetUrl())->toEndWith('/admin/page/yandex-connection-page')
        ->and($request->session()->has('moonshine-yandex-metrika.oauth'))->toBeFalse();
});

test('save and clear actions handle a missing table and clear the stored settings', function () {
    $controller = app(ConnectionController::class);
    $settings = app(YandexSettings::class);

    $request = Request::create('/admin/yandex-analytics/connection', 'POST', ['counter_id' => '1']);
    $request->setLaravelSession(new Store('test', new ArraySessionHandler(10)));

    expect($controller->save($request, $settings)->getTargetUrl())->toEndWith('/admin/page/yandex-connection-page');

    migrateSettingsTable();
    $settings->save(['counter_id' => '999']);

    expect($settings->counterId())->toBe('999');

    $controller->clear($settings);

    expect($settings->counterId())->toBe('12345678')
        ->and($settings->source('counter_id'))->toBe('env');
});

test('connection commands handle a missing table, confirmation and empty input', function () {
    $this->artisan('yandex-metrika:connection:set', ['--counter-id' => '1'])->assertExitCode(1);
    $this->artisan('yandex-metrika:connection:clear')->assertExitCode(0);

    migrateSettingsTable();

    $this->artisan('yandex-metrika:connection:set')->assertExitCode(2);
    $this->artisan('yandex-metrika:connection:set', ['--ask-token' => true, '--ask-secret' => true])
        ->expectsQuestion('Client secret', 'super-secret-value')
        ->expectsQuestion('OAuth token', 'db-token-value-1234')
        ->assertExitCode(0);

    expect(app(YandexSettings::class)->token())->toBe('db-token-value-1234');

    $this->artisan('yandex-metrika:connection:clear')
        ->expectsConfirmation('Delete Yandex settings from the database?', 'no')
        ->assertExitCode(1);

    expect(app(YandexSettings::class)->source('token'))->toBe('db');
});

test('oauth callback saves the token and rejects a wrong state through the controller', function () {
    migrateSettingsTable();
    $settings = app(YandexSettings::class);
    $settings->save(['client_id' => '4760187d81bc4b7799476b42b5103713', 'client_secret' => 'super-secret-value']);

    Http::fake([
        'oauth.yandex.ru/token' => Http::response([
            'access_token' => 'flow-token-value-5678',
            'scope' => 'metrika:read webmaster:verify webmaster:hostinfo',
        ]),
    ]);

    $request = controllerRequest('/admin/yandex-analytics/oauth/redirect');
    $flow = app(YandexOAuthFlow::class);
    parse_str((string) parse_url($flow->authorizationUrl($request->session()), PHP_URL_QUERY), $query);

    $bad = controllerRequest('/admin/yandex-analytics/oauth/callback', ['state' => 'wrong', 'code' => 'demo']);
    $bad->setLaravelSession($request->session());
    app(ConnectionController::class)->callback($bad, $flow);

    expect($settings->source('token'))->toBe('env');

    parse_str((string) parse_url($flow->authorizationUrl($request->session()), PHP_URL_QUERY), $query);
    $ok = controllerRequest('/admin/yandex-analytics/oauth/callback', ['state' => $query['state'], 'code' => 'demo']);
    $ok->setLaravelSession($request->session());

    $response = app(ConnectionController::class)->callback($ok, $flow);

    expect($response->getTargetUrl())->toEndWith('/admin/page/yandex-connection-page')
        ->and($settings->token())->toBe('flow-token-value-5678')
        ->and($settings->missingScopes())->toBe([]);
});
