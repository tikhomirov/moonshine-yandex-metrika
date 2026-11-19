<?php

declare(strict_types=1);

use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;

covers(ConnectionProbe::class);

test('connection probe accepts successful metrika http responses', function (): void {
    $port = 19876;
    $fixtureDir = dirname(__DIR__).'/fixtures/http';
    $process = proc_open(
        ['php', '-S', '127.0.0.1:'.$port, '-t', $fixtureDir],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        skip('Cannot start local HTTP server for probe test.');
    }

    try {
        usleep(200_000);
        config()->set('moonshine-yandex-metrika.api_url', 'http://127.0.0.1:'.$port.'/metrika-ok.json');
        app()->forgetInstance(ConnectionProbe::class);

        $probe = app(ConnectionProbe::class);
        $method = new ReflectionMethod(ConnectionProbe::class, 'requestMetrika');
        $method->setAccessible(true);

        $result = $method->invoke($probe, 'test-token', '12345678');

        expect($result->isOk())->toBeTrue();
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
});
