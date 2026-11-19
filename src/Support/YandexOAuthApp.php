<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Support;

/**
 * OAuth-приложение Яндекса, которым выпущен токен: ссылки для UI.
 * Только client_id (он публичный), client_secret и токен сюда не попадают.
 */
final readonly class YandexOAuthApp
{
    public const string BASE_URL = 'https://oauth.yandex.ru';

    public ?string $clientId;

    public function __construct(?string $clientId)
    {
        $clientId = $clientId === null ? '' : mb_trim($clientId);

        $this->clientId = preg_match('/^[A-Za-z0-9]{8,64}$/', $clientId) === 1 ? $clientId : null;
    }

    public function isKnown(): bool
    {
        return $this->clientId !== null;
    }

    /**
     * Карточка приложения или список приложений, если client_id неизвестен.
     */
    public function appUrl(): string
    {
        return $this->clientId === null
            ? self::BASE_URL.'/'
            : self::BASE_URL.'/client/'.$this->clientId;
    }

    /**
     * Страница редактирования приложения (там меняются права).
     */
    public function editUrl(): string
    {
        return $this->clientId === null
            ? self::BASE_URL.'/'
            : self::BASE_URL.'/client/edit/'.$this->clientId;
    }

    /**
     * Выпуск нового токена с текущими правами приложения.
     * С redirect URI https://oauth.yandex.ru/verification_code Яндекс покажет токен на странице.
     */
    public function tokenUrl(): ?string
    {
        if ($this->clientId === null) {
            return null;
        }

        return self::BASE_URL.'/authorize?'.http_build_query([
            'response_type' => 'token',
            'client_id' => $this->clientId,
            'force_confirm' => 'yes',
        ]);
    }
}
