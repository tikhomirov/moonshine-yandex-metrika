<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika;

use Illuminate\Support\ServiceProvider;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;

class MoonshineYandexMetrikaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/moonshine-yandex-metrika.php',
            'moonshine-yandex-metrika'
        );

        $this->app->singleton(MetrikaService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/moonshine-yandex-metrika.php' => config_path('moonshine-yandex-metrika.php'),
        ], 'moonshine-yandex-metrika-config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'moonshine-yandex-metrika');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/moonshine-yandex-metrika'),
        ], 'moonshine-yandex-metrika-views');

        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'moonshine-yandex-metrika');

        $this->publishes([
            __DIR__ . '/../resources/lang' => lang_path('vendor/moonshine-yandex-metrika'),
        ], 'moonshine-yandex-metrika-lang');
    }
}
