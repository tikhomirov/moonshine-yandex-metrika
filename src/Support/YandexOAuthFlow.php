<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Support;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OAuth authorization code flow Яндекса: state + PKCE (S256), обмен code на токен.
 */
class YandexOAuthFlow
{
    public const string AUTHORIZE_URL = 'https://oauth.yandex.ru/authorize';

    public const string TOKEN_URL = 'https://oauth.yandex.ru/token';

    private const string SESSION_KEY = 'moonshine-yandex-metrika.oauth';

    public function __construct(private readonly YandexSettings $settings) {}

    public function callbackUrl(): string
    {
        return route('moonshine-yandex-metrika.oauth.callback');
    }

    public function canStart(): bool
    {
        return $this->settings->clientId() !== '' && $this->settings->clientSecret() !== '';
    }

    /**
     * URL авторизации; state и code_verifier кладутся в сессию.
     */
    public function authorizationUrl(Session $session): string
    {
        $state = Str::random(40);
        $verifier = Str::random(64);

        $session->put(self::SESSION_KEY, ['state' => $state, 'verifier' => $verifier]);

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->settings->clientId(),
            'redirect_uri' => $this->callbackUrl(),
            'scope' => implode(' ', $this->settings->requiredScopes()),
            'force_confirm' => 'yes',
            'state' => $state,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Проверить state, обменять code на токен и сохранить его в БД.
     *
     * @return list<string> выданные права
     */
    public function complete(Session $session, string $state, string $code): array
    {
        /** @var array{state?: string, verifier?: string}|null $pending */
        $pending = $session->pull(self::SESSION_KEY);

        if (! is_array($pending) || ! isset($pending['state'], $pending['verifier'])
            || $state === '' || ! hash_equals($pending['state'], $state)) {
            throw new RuntimeException((string) __('moonshine-yandex-metrika::metrics.oauth_state_mismatch'));
        }

        if ($code === '') {
            throw new RuntimeException((string) __('moonshine-yandex-metrika::metrics.oauth_no_code'));
        }

        $response = Http::asForm()
            ->timeout(15)
            ->withBasicAuth($this->settings->clientId(), $this->settings->clientSecret())
            ->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'code_verifier' => $pending['verifier'],
            ]);

        $tokenValue = $response->json('access_token');
        $token = is_string($tokenValue) ? $tokenValue : '';

        if (! $response->successful() || $token === '') {
            $errorDescription = $response->json('error_description');
            $errorCode = $response->json('error');
            $error = is_string($errorDescription)
                ? $errorDescription
                : (is_string($errorCode) ? $errorCode : 'HTTP '.$response->status());

            throw new RuntimeException((string) __('moonshine-yandex-metrika::metrics.oauth_exchange_failed', ['error' => $error]));
        }

        $scopeValue = $response->json('scope', '');
        $scope = mb_trim(is_string($scopeValue) ? $scopeValue : '');
        // Если Яндекс не вернул scope, выданы ровно запрошенные права (иначе была бы ошибка invalid_scope).
        $scopes = $scope !== ''
            ? array_values(array_filter(preg_split('/\s+/', $scope) ?: []))
            : $this->settings->requiredScopes();

        $this->settings->save([
            'token' => $token,
            'scopes' => $scopes,
            'token_obtained_at' => now(),
        ]);

        return $scopes;
    }

    private static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
