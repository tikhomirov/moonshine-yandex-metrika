<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MoonShine\Laravel\Http\Middleware\Authenticate;
use Tikhomirov\MoonshineYandexMetrika\Http\Controllers\ExportController;

$prefix = trim((string) config('moonshine.prefix', 'admin'), '/');

Route::middleware(array_filter(['moonshine', Authenticate::class]))
    ->prefix($prefix)
    ->group(function (): void {
        Route::get('yandex-analytics/export/metrika', [ExportController::class, 'metrika'])
            ->name('moonshine-yandex-metrika.export.metrika');

        Route::get('yandex-analytics/export/webmaster', [ExportController::class, 'webmaster'])
            ->name('moonshine-yandex-metrika.export.webmaster');
    });
