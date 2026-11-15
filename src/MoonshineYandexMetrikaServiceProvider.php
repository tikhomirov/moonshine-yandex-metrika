<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika;

use Illuminate\Support\ServiceProvider;
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Services\AnalyticsExportService;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;
use Tikhomirov\MoonshineYandexMetrika\Support\AnalyticsTabRegistry;
use Tikhomirov\MoonshineYandexMetrika\Tabs\MetrikaDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Tabs\WebmasterDashboardTab;

class MoonshineYandexMetrikaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/moonshine-yandex-metrika.php',
            'moonshine-yandex-metrika',
        );

        $this->app->singleton(MetrikaService::class);
        $this->app->singleton(WebmasterService::class);
        $this->app->singleton(YandexAccountService::class);
        $this->app->singleton(ConnectionProbe::class);
        $this->app->singleton(AnalyticsExportService::class);
        $this->app->singleton(AnalyticsTabRegistry::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/moonshine-yandex-metrika.php' => config_path('moonshine-yandex-metrika.php'),
        ], 'moonshine-yandex-metrika-config');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'moonshine-yandex-metrika');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/moonshine-yandex-metrika'),
        ], 'moonshine-yandex-metrika-views');

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'moonshine-yandex-metrika');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/moonshine-yandex-metrika'),
        ], 'moonshine-yandex-metrika-lang');

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $registry = $this->app->make(AnalyticsTabRegistry::class);

        if (! $registry->has(MetrikaDashboardPage::TAB_METRIKA)) {
            $registry->register(new MetrikaDashboardTab());
        }

        if (! $registry->has(MetrikaDashboardPage::TAB_WEBMASTER)) {
            $registry->register(new WebmasterDashboardTab());
        }
    }
}
