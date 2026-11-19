<?php

declare(strict_types=1);

test('configuration defaults are loaded', function () {
    expect(config('moonshine-yandex-metrika.days'))->toBe(0);
    expect(config('moonshine-yandex-metrika.default_preset'))->toBe('today');
    expect(config('moonshine-yandex-metrika.page_title'))->toBe('Яндекс.Метрика');
    expect(config('moonshine-yandex-metrika.webmaster.host_id'))->toBeNull();
});

test('summary_metrics default to the current kpi card order', function () {
    expect(config('moonshine-yandex-metrika.summary_metrics'))->toBe([
        'visits',
        'pageviews',
        'unique_visitors',
        'bounce_rate',
    ]);
});

