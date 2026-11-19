<?php

declare(strict_types=1);

use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthApp;

test('oauth app builds view, edit and token urls from client_id', function () {
    $app = new YandexOAuthApp('4760187d81bc4b7799476b42b5103713');

    expect($app->isKnown())->toBeTrue()
        ->and($app->appUrl())->toBe('https://oauth.yandex.ru/client/4760187d81bc4b7799476b42b5103713')
        ->and($app->editUrl())->toBe('https://oauth.yandex.ru/client/edit/4760187d81bc4b7799476b42b5103713')
        ->and($app->tokenUrl())->toBe(
            'https://oauth.yandex.ru/authorize?response_type=token&client_id=4760187d81bc4b7799476b42b5103713&force_confirm=yes',
        );
});

test('oauth app ignores empty or malformed client_id', function (?string $clientId) {
    $app = new YandexOAuthApp($clientId);

    expect($app->isKnown())->toBeFalse()
        ->and($app->appUrl())->toBe('https://oauth.yandex.ru/')
        ->and($app->editUrl())->toBe('https://oauth.yandex.ru/')
        ->and($app->tokenUrl())->toBeNull();
})->with([null, '', '  ', 'abc', '"><script>', '../../evil']);

test('oauth client_id comes from config before the token profile', function () {
    $service = new class extends YandexAccountService
    {
        public function __construct() {}

        public function profile(): ?array
        {
            return ['id' => '1', 'login' => 'x', 'email' => null, 'avatar_url' => null, 'client_id' => 'fromprofile0000000000000000000ab'];
        }
    };

    expect($service->oauthApp()->clientId)->toBe('fromprofile0000000000000000000ab');

    config()->set('moonshine-yandex-metrika.oauth_client_id', 'fromconfig00000000000000000000ab');

    expect($service->oauthApp()->clientId)->toBe('fromconfig00000000000000000000ab');
});
