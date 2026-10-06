<?php

declare(strict_types=1);

test('configuration defaults are loaded', function () {
    expect(config('moonshine-yandex-metrika.days'))->toBe(30);
    expect(config('moonshine-yandex-metrika.page_title'))->toBe('Яндекс.Метрика');
});
