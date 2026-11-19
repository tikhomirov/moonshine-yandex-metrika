<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Console\Commands;

use Illuminate\Console\Command;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthApp;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

final class ConnectionStatusCommand extends Command
{
    protected $signature = 'yandex-metrika:connection:status';

    protected $description = 'Show Yandex connection settings and their source (secrets are masked)';

    public function handle(YandexSettings $settings): int
    {
        $rows = [];

        foreach (array_keys(YandexSettings::CONFIG_KEYS) as $key) {
            $value = $settings->get($key);

            $rows[] = [
                $key,
                in_array($key, YandexSettings::SECRET_KEYS, true) ? YandexSettings::mask($value) : $value,
                $settings->source($key) ?? '-',
            ];
        }

        $this->table(['Key', 'Value', 'Source'], $rows);

        $app = new YandexOAuthApp($settings->clientId());
        $scopes = $settings->scopes();
        $missing = $settings->missingScopes();

        $this->line('Settings table: '.($settings->tableExists() ? 'yes' : 'no (env only)'));
        $this->line('OAuth app: '.($app->isKnown() ? $app->appUrl() : '-'));
        $this->line('Scopes: '.($scopes === null ? 'unknown' : implode(' ', $scopes)));
        $this->line('Missing scopes: '.($missing === null ? 'unknown' : ($missing === [] ? 'none' : implode(' ', $missing))));
        $this->line('Token obtained at: '.($settings->tokenObtainedAt()?->toDateTimeString() ?? '-'));

        return self::SUCCESS;
    }
}
