<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Tests;

use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Tikhomirov\MoonshineYandexMetrika\MoonshineYandexMetrikaServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            MoonshineYandexMetrikaServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('moonshine-yandex-metrika.token', 'test-token');
        $app['config']->set('moonshine-yandex-metrika.counter_id', '12345678');
    }
}
