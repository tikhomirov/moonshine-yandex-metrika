<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

if (! defined('LARAVEL_VERSION')) {
    $manifest = __DIR__.'/vendor/composer/installed.php';

    if (is_file($manifest)) {
        /** @var array{versions?: array<string, array{pretty_version?: string}>} $installed */
        $installed = require $manifest;

        foreach ($installed['versions'] ?? [] as $name => $meta) {
            if ($name === 'laravel/framework' || $name === 'illuminate/support') {
                define('LARAVEL_VERSION', $meta['pretty_version'] ?? '12.0.0');

                break;
            }
        }
    }

    if (! defined('LARAVEL_VERSION')) {
        define('LARAVEL_VERSION', '12.0.0');
    }
}
