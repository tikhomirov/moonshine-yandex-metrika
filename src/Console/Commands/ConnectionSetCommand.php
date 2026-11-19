<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Console\Commands;

use Illuminate\Console\Command;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

final class ConnectionSetCommand extends Command
{
    protected $signature = 'yandex-metrika:connection:set
        {--client-id= : OAuth app ClientID}
        {--client-secret= : OAuth app Client secret (prefer --ask-secret)}
        {--token= : OAuth token (prefer --ask-token)}
        {--counter-id= : Metrika counter ID}
        {--host-id= : Webmaster host_id, e.g. https:example.com:443}
        {--ask-secret : Prompt for the Client secret without echo}
        {--ask-token : Prompt for the OAuth token without echo}';

    protected $description = 'Save Yandex connection settings to the database (they take priority over env)';

    public function handle(YandexSettings $settings): int
    {
        if (! $settings->tableExists()) {
            $this->error((string) __('moonshine-yandex-metrika::metrics.connection_no_table'));

            return self::FAILURE;
        }

        $values = [];

        foreach (['client_id', 'client_secret', 'token', 'counter_id', 'host_id'] as $key) {
            $option = $this->option(str_replace('_', '-', $key));

            if (is_string($option)) {
                $values[$key] = $option;
            }
        }

        if ($this->option('ask-secret')) {
            $values['client_secret'] = (string) $this->secret('Client secret');
        }

        if ($this->option('ask-token')) {
            $values['token'] = (string) $this->secret('OAuth token');
        }

        if ($values === []) {
            $this->warn('Nothing to save. Pass at least one option, see --help.');

            return self::INVALID;
        }

        $settings->save($values);

        $this->info('Saved: '.implode(', ', array_keys($values)).'.');

        return self::SUCCESS;
    }
}
