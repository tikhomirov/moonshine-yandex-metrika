<?php

declare(strict_types=1);

use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;

test('service provider registers metrika service singleton', function () {
    expect(app()->bound(MetrikaService::class))->toBeTrue();
    expect(app(MetrikaService::class))->toBeInstanceOf(MetrikaService::class);
});

test('metrika dashboard page instantiates successfully', function () {
    $page = app(MetrikaDashboardPage::class);

    expect($page->getTitle())->toBe('Яндекс.Метрика');
});
