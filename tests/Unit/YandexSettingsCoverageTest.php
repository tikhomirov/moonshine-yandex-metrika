<?php

declare(strict_types=1);

use Carbon\Carbon;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

covers(YandexSettings::class);

test('yandex settings mask formatDate and save normalizes scopes', function (): void {
    migrateSettingsTable();
    $settings = app(YandexSettings::class);

    expect(YandexSettings::mask(''))->toBe('')
        ->and(YandexSettings::mask('short'))->toBe('••••')
        ->and(YandexSettings::mask('very-long-secret-value'))->toBe('••••alue')
        ->and(YandexSettings::formatDate(Carbon::parse('2026-01-15 12:00:00', 'UTC')))->toContain('UTC');

    $settings->save([
        'token' => 'manual-token-value',
        'client_id' => 12345678,
        'scopes' => ['metrika:read', 99],
    ]);

    expect($settings->token())->toBe('manual-token-value')
        ->and($settings->storedValue('unknown'))->toBe('');
});
