<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Support;

/**
 * Типобезопасное чтение значений конфигурации пакета (PHPStan level 9).
 */
final class PackageConfig
{
    public static function string(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public static function nullableString(string $key): ?string
    {
        $value = config($key);

        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, bool>  $default
     * @return array<string, bool>
     */
    public static function boolMap(string $key, array $default = []): array
    {
        $value = config($key, $default);

        if (! is_array($value)) {
            return $default;
        }

        $map = [];

        foreach ($value as $name => $enabled) {
            if (is_string($name)) {
                $map[$name] = (bool) $enabled;
            }
        }

        return $map;
    }

    public static function cacheStore(): ?string
    {
        return self::nullableString('moonshine-yandex-metrika.cache_store');
    }
}
