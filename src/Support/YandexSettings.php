<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Tikhomirov\MoonshineYandexMetrika\Models\YandexMetrikaSetting;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;

/**
 * Единая точка чтения настроек подключения: сначала БД, потом env/config.
 * Без миграции или пустой строки в БД всё работает по env, как раньше.
 */
class YandexSettings
{
    public const string SOURCE_DB = 'db';

    public const string SOURCE_ENV = 'env';

    /** Ключ настройки => ключ в config/moonshine-yandex-metrika.php */
    public const array CONFIG_KEYS = [
        'client_id' => 'oauth_client_id',
        'client_secret' => 'oauth_client_secret',
        'token' => 'token',
        'counter_id' => 'counter_id',
        'host_id' => 'webmaster.host_id',
    ];

    public const array SECRET_KEYS = ['client_secret', 'token'];

    /** Кэши пакета, которые зависят от токена. */
    public const array TOKEN_CACHE_KEYS = [
        'yandex_metrika_package_probe',
        'yandex_account_profile',
    ];

    private bool $loaded = false;

    private ?YandexMetrikaSetting $record = null;

    public function token(): string
    {
        return $this->get('token');
    }

    public function clientId(): string
    {
        return $this->get('client_id');
    }

    public function clientSecret(): string
    {
        return $this->get('client_secret');
    }

    public function counterId(): string
    {
        return $this->get('counter_id');
    }

    public function hostId(): string
    {
        return $this->get('host_id');
    }

    public function get(string $key): string
    {
        $stored = $this->stored($key);

        return $stored !== '' ? $stored : $this->fromConfig($key);
    }

    /**
     * Откуда берётся значение: db, env или null (не задано).
     */
    public function source(string $key): ?string
    {
        if ($this->stored($key) !== '') {
            return self::SOURCE_DB;
        }

        return $this->fromConfig($key) !== '' ? self::SOURCE_ENV : null;
    }

    /**
     * Права, выданные токену из БД. Null, если неизвестны (токен из env или выдан вручную).
     *
     * @return list<string>|null
     */
    public function scopes(): ?array
    {
        if ($this->source('token') !== self::SOURCE_DB) {
            return null;
        }

        $scopes = $this->record()?->scopes;

        return is_array($scopes) && $scopes !== []
            ? array_values(array_map(strval(...), $scopes))
            : null;
    }

    /**
     * Обязательные права, которых нет у токена. Null, если права токена неизвестны.
     *
     * @return list<string>|null
     */
    public function missingScopes(): ?array
    {
        $granted = $this->scopes();

        if ($granted === null) {
            return null;
        }

        return array_values(array_diff($this->requiredScopes(), $granted));
    }

    /**
     * @return list<string>
     */
    public function requiredScopes(): array
    {
        /** @var list<string> $scopes */
        $scopes = (array) config('moonshine-yandex-metrika.oauth_scopes', [
            'metrika:read',
            'webmaster:verify',
            'webmaster:hostinfo',
        ]);

        return array_values(array_filter(array_map(strval(...), $scopes)));
    }

    public function tokenObtainedAt(): ?CarbonInterface
    {
        return $this->source('token') === self::SOURCE_DB
            ? $this->record()?->token_obtained_at
            : null;
    }

    public function tableExists(): bool
    {
        try {
            return Schema::hasTable((string) config('moonshine-yandex-metrika.settings_table', 'yandex_metrika_settings'));
        } catch (Throwable) {
            return false;
        }
    }

    public function record(): ?YandexMetrikaSetting
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $this->record = $this->tableExists() ? YandexMetrikaSetting::query()->oldest('id')->first() : null;
        }

        return $this->record;
    }

    /**
     * Сохранить значения. Отсутствующий ключ не меняется, null или '' сбрасывает к env.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $record = $this->record() ?? new YandexMetrikaSetting();
        $tokenChanged = false;

        foreach (array_keys(self::CONFIG_KEYS) as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key] === null ? '' : mb_trim((string) $values[$key]);
            $record->{$key} = $value === '' ? null : $value;

            $tokenChanged = $tokenChanged || $key === 'token';
        }

        if (array_key_exists('scopes', $values)) {
            $record->scopes = is_array($values['scopes']) ? array_values($values['scopes']) : null;
        } elseif ($tokenChanged) {
            // Ручной токен: права неизвестны.
            $record->scopes = null;
        }

        if (array_key_exists('token_obtained_at', $values)) {
            $record->token_obtained_at = $values['token_obtained_at'];
        } elseif ($tokenChanged) {
            $record->token_obtained_at = $record->token !== null ? now() : null;
        }

        $record->save();

        $this->record = $record;
        $this->loaded = true;
        $this->flush();
    }

    /**
     * Удалить настройки из БД: дальше всё читается из env.
     */
    public function clear(): void
    {
        if ($this->tableExists()) {
            YandexMetrikaSetting::query()->delete();
        }

        $this->record = null;
        $this->loaded = true;
        $this->flush();
    }

    public function refresh(): void
    {
        $this->loaded = false;
        $this->record = null;
    }

    /**
     * Маска для секретов: видно только последние 4 символа.
     */
    public static function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return mb_strlen($value) <= 8 ? '••••' : '••••'.mb_substr($value, -4);
    }

    private function stored(string $key): string
    {
        if (! array_key_exists($key, self::CONFIG_KEYS)) {
            return '';
        }

        try {
            $value = $this->record()?->{$key};
        } catch (Throwable) {
            // Например, сменился APP_KEY и секрет не расшифровать.
            return '';
        }

        return $value === null ? '' : mb_trim((string) $value);
    }

    private function fromConfig(string $key): string
    {
        $configKey = self::CONFIG_KEYS[$key] ?? null;

        return $configKey === null
            ? ''
            : mb_trim((string) config('moonshine-yandex-metrika.'.$configKey));
    }

    private function flush(): void
    {
        $storeName = config('moonshine-yandex-metrika.cache_store');
        $store = $storeName ? Cache::store($storeName) : Cache::store();

        foreach (self::TOKEN_CACHE_KEYS as $key) {
            $store->forget($key);
        }

        foreach ([MetrikaService::class, WebmasterService::class, YandexAccountService::class, ConnectionProbe::class] as $service) {
            app()->forgetInstance($service);
        }
    }
}
