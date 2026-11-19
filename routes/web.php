<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MoonShine\Laravel\Http\Middleware\Authenticate;
use Tikhomirov\MoonshineYandexMetrika\Http\Controllers\ConnectionController;
use Tikhomirov\MoonshineYandexMetrika\Http\Controllers\ExportController;
use Tikhomirov\MoonshineYandexMetrika\Support\PackageConfig;

$prefix = trim(PackageConfig::string('moonshine.prefix', 'admin'), '/');

Route::middleware(['moonshine', Authenticate::class])
    ->prefix($prefix)
    ->group(function (): void {
        Route::get('yandex-analytics/export/metrika', [ExportController::class, 'metrika'])
            ->name('moonshine-yandex-metrika.export.metrika');

        Route::get('yandex-analytics/export/webmaster', [ExportController::class, 'webmaster'])
            ->name('moonshine-yandex-metrika.export.webmaster');

        Route::post('yandex-analytics/connection', [ConnectionController::class, 'save'])
            ->name('moonshine-yandex-metrika.connection.save');

        Route::post('yandex-analytics/connection/clear', [ConnectionController::class, 'clear'])
            ->name('moonshine-yandex-metrika.connection.clear');

        Route::post('yandex-analytics/connection/check', [ConnectionController::class, 'check'])
            ->name('moonshine-yandex-metrika.connection.check');

        Route::get('yandex-analytics/oauth/redirect', [ConnectionController::class, 'redirect'])
            ->name('moonshine-yandex-metrika.oauth.redirect');

        // Этот адрес нужно добавить в Redirect URI приложения на oauth.yandex.ru.
        Route::get('yandex-analytics/oauth/callback', [ConnectionController::class, 'callback'])
            ->name('moonshine-yandex-metrika.oauth.callback');
    });
