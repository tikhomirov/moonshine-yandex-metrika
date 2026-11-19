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
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthFlow;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

const YM_CLIENT_ID = '4760187d81bc4b7799476b42b5103713';

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
        ->and($html)->toContain('/admin/yandex-analytics/connection/clear');
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
