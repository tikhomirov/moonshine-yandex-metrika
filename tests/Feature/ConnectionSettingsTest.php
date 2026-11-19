<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tikhomirov\MoonshineYandexMetrika\Http\Controllers\ConnectionController;
use Tikhomirov\MoonshineYandexMetrika\Pages\YandexConnectionPage;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthFlow;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

const YM_CLIENT_ID = '4760187d81bc4b7799476b42b5103713';

beforeEach(function () {
    // Никаких реальных запросов к Яндексу из тестов.
    config()->set('moonshine-yandex-metrika.webmaster.api_url', 'http://127.0.0.1:1/v4');
    config()->set('moonshine-yandex-metrika.login_info_url', 'http://127.0.0.1:1/info');
    config()->set('moonshine-yandex-metrika.api_url', 'http://127.0.0.1:1/stat/v1/data');
});

/**
 * @param  list<array{host_id: string, url: string, verified: bool}>|null  $hosts
 */
function bindWebmasterHosts(?array $hosts): WebmasterService
{
    $service = new class($hosts) extends WebmasterService
    {
        public function __construct(private readonly ?array $fakeHosts)
        {
            parent::__construct();
        }

        public function hosts(): ?array
        {
            return $this->fakeHosts;
        }
    };

    app()->instance(WebmasterService::class, $service);

    return $service;
}

/**
 * @param  list<array{id: string, name: string, site: string, sites: list<string>}>|null  $counters
 */
function bindMetrikaCounters(?array $counters): MetrikaService
{
    $service = new class($counters) extends MetrikaService
    {
        public function __construct(private readonly ?array $fakeCounters)
        {
            parent::__construct();
        }

        public function counters(): ?array
        {
            return $this->fakeCounters;
        }
    };

    app()->instance(MetrikaService::class, $service);

    return $service;
}

function demoCounters(): array
{
    return [
        ['id' => '11111111', 'name' => 'Shop', 'site' => 'shop.example', 'sites' => ['shop.example']],
        ['id' => '22222222', 'name' => 'Demo', 'site' => 'demo.example', 'sites' => ['demo.example', 'www.demo.example']],
    ];
}

function bindAccountProfile(): void
{
    app()->instance(YandexAccountService::class, new class extends YandexAccountService
    {
        public function __construct() {}

        public function profile(): ?array
        {
            return ['id' => '1', 'login' => 'owner', 'email' => 'owner@example.com', 'avatar_url' => null, 'client_id' => null];
        }
    });
}

function demoHosts(): array
{
    return [
        ['host_id' => 'https:other.example:443', 'url' => 'https://other.example', 'verified' => true],
        ['host_id' => 'https:demo.example:443', 'url' => 'https://demo.example', 'verified' => true],
    ];
}

function migrateYandexSettings(): void
{
    test()->artisan('migrate', ['--force' => true])->run();
    app(YandexSettings::class)->refresh();
}

function renderConnectionPage(): string
{
    return collect(app(YandexConnectionPage::class)->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');
}

function arraySession(): Store
{
    return new Store('test', new ArraySessionHandler(10));
}

test('without the settings table everything comes from env as before', function () {
    $settings = app(YandexSettings::class);

    expect($settings->tableExists())->toBeFalse()
        ->and($settings->token())->toBe('test-token')
        ->and($settings->counterId())->toBe('12345678')
        ->and($settings->source('token'))->toBe('env')
        ->and($settings->source('client_secret'))->toBeNull()
        ->and($settings->scopes())->toBeNull();

    expect(renderConnectionPage())->toContain('php artisan migrate')
        ->and(renderConnectionPage())->not->toContain('test-token');
});

test('database values take priority over env and secrets are encrypted at rest', function () {
    migrateYandexSettings();
    $settings = app(YandexSettings::class);

    $settings->save([
        'client_id' => YM_CLIENT_ID,
        'client_secret' => 'super-secret-value',
        'token' => 'db-token-value-1234',
        'host_id' => 'https:example.com:443',
    ]);

    expect($settings->token())->toBe('db-token-value-1234')
        ->and($settings->source('token'))->toBe('db')
        ->and($settings->counterId())->toBe('12345678')
        ->and($settings->source('counter_id'))->toBe('env')
        ->and($settings->hostId())->toBe('https:example.com:443')
        ->and($settings->tokenObtainedAt())->not->toBeNull();

    $raw = DB::table('yandex_metrika_settings')->first();

    expect($raw->token)->not->toContain('db-token-value-1234')
        ->and($raw->client_secret)->not->toContain('super-secret-value');

    // Сервисы читают через резолвер.
    expect(app(WebmasterService::class)->isConfigured())->toBeTrue()
        ->and(app(WebmasterService::class)->displayHost())->toBe('example.com');

    $settings->clear();

    expect($settings->token())->toBe('test-token')
        ->and($settings->source('token'))->toBe('env')
        ->and($settings->hostId())->toBe('');
});

test('missing scopes are computed from the stored token scopes', function () {
    migrateYandexSettings();
    $settings = app(YandexSettings::class);

    $settings->save(['token' => 'db-token-value-1234', 'scopes' => ['metrika:read', 'webmaster:verify']]);

    expect($settings->missingScopes())->toBe(['webmaster:hostinfo']);

    // Ручной токен: права неизвестны.
    $settings->save(['token' => 'manual-token-5678']);

    expect($settings->scopes())->toBeNull()
        ->and($settings->missingScopes())->toBeNull();
});

test('connection page shows app, sources, masked secrets, missing scopes and redirect uri', function () {
    migrateYandexSettings();
    app(YandexSettings::class)->save([
        'client_id' => YM_CLIENT_ID,
        'client_secret' => 'super-secret-value',
        'token' => 'db-token-value-1234',
        'scopes' => ['metrika:read', 'webmaster:verify'],
    ]);

    $html = renderConnectionPage();

    expect($html)->toContain('href="https://oauth.yandex.ru/client/'.YM_CLIENT_ID.'"')
        ->and($html)->toContain('href="https://oauth.yandex.ru/client/edit/'.YM_CLIENT_ID.'"')
        ->and($html)->toContain('••••1234')
        ->and($html)->not->toContain('db-token-value-1234')
        ->and($html)->not->toContain('super-secret-value')
        ->and($html)->toContain('badge-success')
        ->and($html)->toContain('badge-info')
        ->and($html)->toContain('webmaster:hostinfo')
        ->and($html)->toContain('/admin/yandex-analytics/oauth/callback')
        ->and($html)->toContain('/admin/yandex-analytics/oauth/redirect')
        ->and($html)->toContain('Reconnect Yandex')
        ->and($html)->toContain('name="client_id"')
        ->and($html)->toContain('/admin/yandex-analytics/connection/clear')
        ->and($html)->toMatch('#<a href="[^"]*/admin/page/metrika-dashboard-page"[^>]*ym-back-link#')
        ->and($html)->toContain('Back to')
        ->and(yandexLinksWithoutBlankTarget($html))->toBe([]);
});

test('token obtained date is shown in the app timezone', function () {
    config()->set('app.timezone', 'Europe/Moscow');

    expect(YandexSettings::formatDate(\Illuminate\Support\Carbon::parse('2026-11-19 18:59:37', 'UTC')))
        ->toBe('19.11.2026 21:59 (Europe/Moscow)');
});

test('save action keeps secrets when the password fields are empty', function () {
    migrateYandexSettings();
    $settings = app(YandexSettings::class);
    $settings->save(['client_secret' => 'super-secret-value', 'token' => 'db-token-value-1234']);

    $request = Request::create('/admin/yandex-analytics/connection', 'POST', [
        'client_id' => YM_CLIENT_ID,
        'client_secret' => null,
        'token' => null,
        'counter_id' => '999',
        'host_id' => null,
    ]);
    $request->setLaravelSession(arraySession());

    $response = app(ConnectionController::class)->save($request, $settings);

    expect($response->getStatusCode())->toBe(302)
        ->and($settings->clientSecret())->toBe('super-secret-value')
        ->and($settings->token())->toBe('db-token-value-1234')
        ->and($settings->clientId())->toBe(YM_CLIENT_ID)
        ->and($settings->counterId())->toBe('999');
});

test('oauth flow builds the authorize url with state and pkce', function () {
    migrateYandexSettings();
    app(YandexSettings::class)->save(['client_id' => YM_CLIENT_ID, 'client_secret' => 'super-secret-value']);
    $session = arraySession();

    $url = app(YandexOAuthFlow::class)->authorizationUrl($session);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://oauth.yandex.ru/authorize?')
        ->and($query['response_type'])->toBe('code')
        ->and($query['client_id'])->toBe(YM_CLIENT_ID)
        ->and($query['redirect_uri'])->toEndWith('/admin/yandex-analytics/oauth/callback')
        ->and($query['scope'])->toBe('metrika:read webmaster:verify webmaster:hostinfo')
        ->and($query['state'])->toBe($session->get('moonshine-yandex-metrika.oauth')['state'])
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($url)->not->toContain('super-secret-value');
});

test('oauth callback rejects a wrong state', function () {
    migrateYandexSettings();
    app(YandexSettings::class)->save(['client_id' => YM_CLIENT_ID, 'client_secret' => 'super-secret-value']);
    $session = arraySession();
    $flow = app(YandexOAuthFlow::class);
    $flow->authorizationUrl($session);
    Http::fake();

    expect(fn () => $flow->complete($session, 'wrong-state', 'code'))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
    expect(app(YandexSettings::class)->source('token'))->toBe('env');
});

test('oauth callback exchanges the code and stores the token with scopes', function () {
    migrateYandexSettings();
    $settings = app(YandexSettings::class);
    $settings->save(['client_id' => YM_CLIENT_ID, 'client_secret' => 'super-secret-value']);
    $session = arraySession();
    $flow = app(YandexOAuthFlow::class);
    $flow->authorizationUrl($session);
    $pending = $session->get('moonshine-yandex-metrika.oauth');

    Http::fake([
        'oauth.yandex.ru/token' => Http::response([
            'access_token' => 'new-oauth-token-9999',
            'token_type' => 'bearer',
            'expires_in' => 31536000,
            'scope' => 'metrika:read webmaster:verify',
        ]),
    ]);

    $scopes = $flow->complete($session, $pending['state'], 'auth-code');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://oauth.yandex.ru/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['code_verifier'] === $pending['verifier']
        && $request->hasHeader('Authorization', 'Basic '.base64_encode(YM_CLIENT_ID.':super-secret-value')));

    expect($scopes)->toBe(['metrika:read', 'webmaster:verify'])
        ->and($settings->token())->toBe('new-oauth-token-9999')
        ->and($settings->missingScopes())->toBe(['webmaster:hostinfo'])
        ->and($session->has('moonshine-yandex-metrika.oauth'))->toBeFalse();
});

test('artisan commands set, show status without secrets and clear settings', function () {
    migrateYandexSettings();

    $this->artisan('yandex-metrika:connection:set', [
        '--client-id' => YM_CLIENT_ID,
        '--client-secret' => 'super-secret-value',
        '--token' => 'db-token-value-1234',
        '--host-id' => 'https:example.com:443',
    ])->assertSuccessful();

    expect(app(YandexSettings::class)->token())->toBe('db-token-value-1234');

    $this->artisan('yandex-metrika:connection:status')
        ->expectsOutputToContain('••••1234')
        ->expectsOutputToContain('https://oauth.yandex.ru/client/'.YM_CLIENT_ID)
        ->doesntExpectOutputToContain('super-secret-value')
        ->doesntExpectOutputToContain('db-token-value-1234')
        ->assertSuccessful();

    $this->artisan('yandex-metrika:connection:clear', ['--force' => true])->assertSuccessful();

    expect(app(YandexSettings::class)->source('token'))->toBe('env');
});

test('routes for the connection page and oauth are registered', function () {
    expect(moonshine()->getPages()->findByClass(YandexConnectionPage::class))->not->toBeNull();

    expect(route('moonshine-yandex-metrika.oauth.callback'))->toEndWith('/admin/yandex-analytics/oauth/callback')
        ->and(route('moonshine-yandex-metrika.oauth.redirect'))->toEndWith('/admin/yandex-analytics/oauth/redirect')
        ->and(route('moonshine-yandex-metrika.connection.save'))->toEndWith('/admin/yandex-analytics/connection')
        ->and(route('moonshine-yandex-metrika.connection.clear'))->toEndWith('/admin/yandex-analytics/connection/clear');
});

test('webmaster hosts are parsed from the api response', function () {
    expect(WebmasterService::hostsFromResponse(['hosts' => [
        ['host_id' => 'https:demo.example:443', 'unicode_host_url' => 'https://demo.example/', 'verified' => true],
        ['host_id' => 'http:пример.рф:80', 'ascii_host_url' => 'http://xn--e1afmkfd.xn--p1ai/', 'unicode_host_url' => 'http://пример.рф/'],
        ['broken' => true],
    ]]))->toBe([
        ['host_id' => 'https:demo.example:443', 'url' => 'https://demo.example', 'verified' => true],
        ['host_id' => 'http:пример.рф:80', 'url' => 'http://пример.рф', 'verified' => false],
    ]);
});

test('host is matched to APP_URL by scheme, host and port ignoring www', function (string $appUrl, ?string $expected) {
    $ids = ['https:other.example:443', 'https:demo.example:443', 'http:demo.example:80', 'https:demo.example:8443'];

    expect(WebmasterService::matchHostByUrl($ids, $appUrl))->toBe($expected);
})->with([
    ['https://demo.example', 'https:demo.example:443'],
    ['https://www.demo.example/admin', 'https:demo.example:443'],
    ['HTTPS://Demo.Example:443', 'https:demo.example:443'],
    ['http://demo.example', 'http:demo.example:80'],
    ['https://demo.example:8443', 'https:demo.example:8443'],
    ['https://missing.example', null],
    ['not a url', null],
]);

test('host is auto selected by APP_URL without saving anything', function () {
    config()->set('app.url', 'https://www.demo.example');
    config()->set('moonshine-yandex-metrika.webmaster.host_id', 'https:env.example:443');

    $service = bindWebmasterHosts(demoHosts());

    expect($service->hostId())->toBe('https:demo.example:443')
        ->and($service->hostSource())->toBe('auto')
        ->and($service->isConfigured())->toBeTrue();
});

test('saved host wins, then env, then the only site of the account', function () {
    config()->set('app.url', 'https://nomatch.example');
    config()->set('moonshine-yandex-metrika.webmaster.host_id', 'https:env.example:443');

    expect(bindWebmasterHosts(demoHosts())->hostSource())->toBe('env');

    config()->set('moonshine-yandex-metrika.webmaster.host_id', null);
    $single = bindWebmasterHosts([demoHosts()[1]]);

    expect($single->hostId())->toBe('https:demo.example:443')
        ->and($single->hostSource())->toBe('auto');

    expect(bindWebmasterHosts(demoHosts())->hostId())->toBe('');
    expect(bindWebmasterHosts(null)->hostId())->toBe('');

    migrateYandexSettings();
    app(YandexSettings::class)->save(['host_id' => 'https:other.example:443']);
    $saved = bindWebmasterHosts(demoHosts());

    expect($saved->hostId())->toBe('https:other.example:443')
        ->and($saved->hostSource())->toBe('db');
});

test('connection page offers the account sites in a select', function () {
    migrateYandexSettings();
    config()->set('app.url', 'https://demo.example');
    bindWebmasterHosts(demoHosts());

    $html = renderConnectionPage();

    expect($html)->toMatch('#<select[^>]*name="host_id"#')
        ->and($html)->toContain('value="https:demo.example:443"')
        ->and($html)->toContain('https://other.example')
        ->and($html)->toContain('Auto (by APP_URL): https://demo.example')
        ->and($html)->toContain('badge-primary')
        ->and($html)->not->toContain('Could not load Webmaster sites');
});

test('connection page falls back to a text field and a short notice when sites are unavailable', function () {
    migrateYandexSettings();
    bindWebmasterHosts(null);

    $html = renderConnectionPage();

    expect($html)->toContain('Could not load Webmaster sites.')
        ->and($html)->toContain('href="https://webmaster.yandex.ru/sites/"')
        ->and($html)->toMatch('#<input[^>]*name="host_id"#')
        ->and(yandexLinksWithoutBlankTarget($html))->toBe([]);
});

test('metrika counters are parsed from the management api response', function () {
    expect(MetrikaService::countersFromResponse(['counters' => [
        ['id' => 22222222, 'name' => 'Demo', 'site' => 'demo.example', 'site2' => ['site' => 'www.demo.example', 'domain' => 'demo.example']],
        ['id' => 33333333, 'name' => '', 'site' => 'blog.example'],
        ['name' => 'broken'],
    ]]))->toBe([
        ['id' => '22222222', 'name' => 'Demo', 'site' => 'www.demo.example', 'sites' => ['www.demo.example', 'demo.example']],
        ['id' => '33333333', 'name' => '', 'site' => 'blog.example', 'sites' => ['blog.example']],
    ]);

    expect(YandexConnectionPage::counterLabel(['id' => '22222222', 'name' => 'Demo', 'site' => 'demo.example']))
        ->toBe('Demo — demo.example (22222222)')
        ->and(YandexConnectionPage::counterLabel(['id' => '3', 'name' => '', 'site' => 'blog.example']))
        ->toBe('blog.example (3)');
});

test('counter is matched to APP_URL by site ignoring www and scheme', function (string $appUrl, ?string $expected) {
    expect(MetrikaService::matchCounterByUrl(demoCounters(), $appUrl))->toBe($expected);
})->with([
    ['https://demo.example', '22222222'],
    ['http://www.demo.example/admin', '22222222'],
    ['https://WWW.Shop.Example', '11111111'],
    ['https://missing.example', null],
    ['not a url', null],
]);

test('counter is auto selected by APP_URL without saving anything', function () {
    config()->set('app.url', 'https://www.demo.example');

    $service = bindMetrikaCounters(demoCounters());

    expect($service->counterId())->toBe('22222222')
        ->and($service->counterSource())->toBe('auto')
        ->and($service->isConfigured())->toBeTrue();
});

test('saved counter wins, then env, then the only counter of the account', function () {
    config()->set('app.url', 'https://nomatch.example');

    expect(bindMetrikaCounters(demoCounters())->counterSource())->toBe('env');

    config()->set('moonshine-yandex-metrika.counter_id', null);
    $single = bindMetrikaCounters([demoCounters()[0]]);

    expect($single->counterId())->toBe('11111111')
        ->and($single->counterSource())->toBe('auto');

    expect(bindMetrikaCounters(demoCounters())->counterId())->toBe('');
    expect(bindMetrikaCounters(null)->counterId())->toBe('');

    migrateYandexSettings();
    app(YandexSettings::class)->save(['counter_id' => '11111111']);
    $saved = bindMetrikaCounters(demoCounters());

    expect($saved->counterId())->toBe('11111111')
        ->and($saved->counterSource())->toBe('db');
});

test('connection page offers the account counters in a select', function () {
    migrateYandexSettings();
    config()->set('app.url', 'https://demo.example');
    config()->set('moonshine-yandex-metrika.counter_id', null);
    bindMetrikaCounters(demoCounters());
    bindWebmasterHosts(demoHosts());

    $html = renderConnectionPage();

    expect($html)->toMatch('#<select[^>]*name="counter_id"#')
        ->and($html)->toContain('value="11111111"')
        ->and($html)->toContain('Shop — shop.example (11111111)')
        ->and($html)->toContain('Auto (by APP_URL): Demo — demo.example (22222222)')
        ->and($html)->not->toContain('Could not load Metrika counters');
});

test('connection page falls back to a counter text field and a short notice when counters are unavailable', function () {
    migrateYandexSettings();
    bindMetrikaCounters(null);
    bindWebmasterHosts(demoHosts());

    $html = renderConnectionPage();

    expect($html)->toContain('Could not load Metrika counters.')
        ->and($html)->toContain('href="https://metrika.yandex.ru/list"')
        ->and($html)->toMatch('#<input[^>]*name="counter_id"#')
        ->and(yandexLinksWithoutBlankTarget($html))->toBe([]);
});

test('connection page in the empty state shows three steps to do and locks the token step', function () {
    migrateYandexSettings();
    config()->set('moonshine-yandex-metrika.token', null);
    config()->set('moonshine-yandex-metrika.counter_id', null);
    config()->set('moonshine-yandex-metrika.webmaster.host_id', null);
    config()->set('moonshine-yandex-metrika.oauth_client_id', null);

    $html = renderConnectionPage();

    expect(substr_count($html, 'data-status="todo"'))->toBe(3)
        ->and($html)->toContain('Yandex app')
        ->and($html)->toContain('href="https://oauth.yandex.ru/client/new"')
        ->and($html)->toContain('ym-copy')
        ->and($html)->toContain('data-copy="http://localhost/admin/yandex-analytics/oauth/callback"')
        ->and($html)->toContain('metrika:read')
        ->and($html)->toContain('name="client_secret"')
        ->and($html)->toMatch('#<button type="button" class="btn btn-primary btn-lg ym-oauth-button" disabled>Get token via Yandex</button>#')
        ->and($html)->toContain('ym-step ym-step-2 ym-step--locked')
        ->and($html)->toContain('ym-step ym-step-3 ym-step--locked')
        ->and($html)->toContain('<summary>Enter token manually</summary>')
        ->and($html)->toContain('Check connection')
        ->and($html)->toContain('/admin/yandex-analytics/connection/check')
        ->and($html)->toContain('/admin/yandex-analytics/connection/clear')
        ->and(yandexLinksWithoutBlankTarget($html))->toBe([]);
});

test('connection page with everything connected shows all steps done with account and scopes', function () {
    migrateYandexSettings();
    config()->set('app.url', 'https://demo.example');
    app(YandexSettings::class)->save([
        'client_id' => YM_CLIENT_ID,
        'client_secret' => 'super-secret-value',
        'token' => 'db-token-value-1234',
        'scopes' => ['metrika:read', 'webmaster:verify', 'webmaster:hostinfo'],
    ]);
    bindAccountProfile();
    bindMetrikaCounters(demoCounters());
    bindWebmasterHosts(demoHosts());

    $html = renderConnectionPage();

    expect(substr_count($html, 'data-status="done"'))->toBe(3)
        ->and($html)->not->toMatch('#class="ym-step [^"]*ym-step--locked#')
        ->and($html)->toContain('owner@example.com')
        ->and($html)->toContain('webmaster:hostinfo')
        ->and($html)->toContain('••••1234')
        ->and($html)->not->toContain('db-token-value-1234')
        ->and($html)->not->toContain('super-secret-value')
        ->and($html)->toMatch('#<a href="[^"]*/admin/yandex-analytics/oauth/redirect" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-lg ym-oauth-button">Reconnect Yandex</a>#')
        ->and($html)->not->toContain('Missing scopes')
        ->and(yandexLinksWithoutBlankTarget($html))->toBe([]);
});

test('env values do not lock the counter and site step without a token in the database', function () {
    migrateYandexSettings();
    config()->set('moonshine-yandex-metrika.token', null);

    $html = renderConnectionPage();

    expect($html)->toContain('ym-step ym-step-2 ym-step--locked')
        ->and($html)->not->toContain('ym-step ym-step-3 ym-step--locked')
        ->and($html)->toMatch('#<input[^>]*name="counter_id"#');
});

test('check connection action runs a fresh probe and returns to the connection page', function () {
    $response = app(ConnectionController::class)->check();

    expect($response->getStatusCode())->toBe(302)
        ->and($response->getTargetUrl())->toEndWith('/admin/page/yandex-connection-page')
        ->and(route('moonshine-yandex-metrika.connection.check'))->toEndWith('/admin/yandex-analytics/connection/check');
});
