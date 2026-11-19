<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Tikhomirov\MoonshineYandexMetrika\Support\PackageConfig;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthApp;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

/**
 * Профиль OAuth-пользователя Яндекса (email + аватар).
 */
class YandexAccountService
{
    private Client $http;

    private string $token;

    private int $cacheTtl;

    private ?string $cacheStore;

    private string $infoUrl;

    public function __construct()
    {
        $this->token = app(YandexSettings::class)->token();
        $this->cacheTtl = PackageConfig::int('moonshine-yandex-metrika.cache_ttl', 3600);
        $this->cacheStore = PackageConfig::cacheStore();
        $this->infoUrl = PackageConfig::string(
            'moonshine-yandex-metrika.login_info_url',
            'https://login.yandex.ru/info',
        );

        $this->http = new Client([
            'headers' => [
                'Authorization' => "OAuth {$this->token}",
                'Accept' => 'application/json',
            ],
            'timeout' => 15,
        ]);
    }

    /**
     * @return array{id: string|null, login: string|null, email: string|null, avatar_url: string|null, client_id: string|null}|null
     */
    public function profile(): ?array
    {
        if ($this->token === '') {
            return null;
        }

        $payload = $this->cached('yandex_account_profile', fn (): ?array => $this->fetchInfo());

        if ($payload === null) {
            return null;
        }

        $avatarId = $payload['default_avatar_id'] ?? null;
        $avatarEmpty = (bool) ($payload['is_avatar_empty'] ?? true);

        $emails = $payload['emails'] ?? null;
        $firstEmail = is_array($emails) && isset($emails[0]) && is_string($emails[0]) ? $emails[0] : null;
        $defaultEmail = $payload['default_email'] ?? null;

        return [
            'id' => isset($payload['id']) && is_scalar($payload['id']) ? (string) $payload['id'] : null,
            'login' => isset($payload['login']) && is_string($payload['login']) ? $payload['login'] : null,
            'email' => is_string($defaultEmail)
                ? $defaultEmail
                : $firstEmail,
            'avatar_url' => (! $avatarEmpty && is_string($avatarId) && $avatarId !== '')
                ? 'https://avatars.yandex.net/get-yapic/'.$avatarId.'/islands-200'
                : null,
            'client_id' => isset($payload['client_id']) && is_scalar($payload['client_id']) ? (string) $payload['client_id'] : null,
        ];
    }

    /**
     * OAuth-приложение токена: client_id из настроек (БД/env) или из ответа login.yandex.ru/info.
     */
    public function oauthApp(): YandexOAuthApp
    {
        $configured = app(YandexSettings::class)->clientId();

        if ($configured !== '') {
            return new YandexOAuthApp($configured);
        }

        $profile = $this->profile();

        return new YandexOAuthApp($profile['client_id'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchInfo(): ?array
    {
        try {
            $response = $this->http->get($this->infoUrl, [
                'query' => ['format' => 'json'],
            ]);

            /** @var array<string, mixed>|null $data */
            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) ? $data : null;
        } catch (GuzzleException $e) {
            logger()->channel('single')->error('[YandexAccount] API error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param  callable(): (?array<string, mixed>)  $callback
     * @return array<string, mixed>|null
     */
    private function cached(string $key, callable $callback): ?array
    {
        if ($this->cacheTtl <= 0) {
            return $callback();
        }

        $store = $this->cacheStore ? Cache::store($this->cacheStore) : Cache::store();

        return $store->remember($key, $this->cacheTtl, static function () use ($callback): ?array {
            /** @var array<string, mixed>|null $value */
            $value = $callback();

            return $value;
        });
    }
}
