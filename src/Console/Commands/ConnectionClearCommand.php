<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Console\Commands;

use Illuminate\Console\Command;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

final class ConnectionClearCommand extends Command
{
    protected $signature = 'yandex-metrika:connection:clear {--force : Do not ask for confirmation}';

    protected $description = 'Delete Yandex connection settings from the database and fall back to env';

    public function handle(YandexSettings $settings): int
    {
        if (! $settings->tableExists()) {
            $this->info('Settings table does not exist, env is already used.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete Yandex settings from the database?')) {
            return self::FAILURE;
        }

        $settings->clear();
        $this->info('Cleared. Settings are read from env now.');

        return self::SUCCESS;
    }
}
