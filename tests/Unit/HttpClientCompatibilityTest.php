<?php

declare(strict_types=1);

use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;

// Реальные Guzzle-клиенты (7.x и 8.x): ошибка соединения ловится как GuzzleException.
beforeEach(function () {
    config()->set('moonshine-yandex-metrika.cache_ttl', 0);
    config()->set('moonshine-yandex-metrika.api_url', 'http://127.0.0.1:1/stat/v1/data');
    config()->set('moonshine-yandex-metrika.login_info_url', 'http://127.0.0.1:1/info');
    config()->set('moonshine-yandex-metrika.webmaster.api_url', 'http://127.0.0.1:1/v4');
    config()->set('moonshine-yandex-metrika.webmaster.user_id', '1');
    config()->set('moonshine-yandex-metrika.webmaster.host_id', 'https:example.com:443');
});

test('services survive connection errors with the installed guzzle', function () {
    expect((new MetrikaService)->request(['metrics' => 'ym:s:visits']))->toBeNull()
        ->and((new WebmasterService)->summary())->toBeNull()
        ->and((new WebmasterService)->popularQueriesReport(5)['rows'])->toBe([])
        ->and((new YandexAccountService)->profile())->toBeNull();
});
