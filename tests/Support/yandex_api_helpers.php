<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Psr\Http\Message\RequestInterface;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

/**
 * Подменяет Guzzle-клиент сервиса на MockHandler: без сети, с историей запросов.
 *
 * @param  list<Response|Throwable>  $responses
 * @param  list<array{request: RequestInterface}>  $history
 */
function mockServiceHttp(object $service, array $responses, array &$history, string $baseUri = 'https://api.example.test/v4/'): void
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $property = new ReflectionProperty($service, 'http');
    $property->setValue($service, new Client(['handler' => $stack, 'base_uri' => $baseUri]));
}

/**
 * @param  array<string, mixed>  $data
 */
function apiJson(array $data, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data, JSON_THROW_ON_ERROR));
}

function controllerRequest(string $uri, array $query = []): Request
{
    $request = Request::create($uri, 'GET', $query);
    $request->setLaravelSession(new Store('test', new ArraySessionHandler(10)));

    return $request;
}

function migrateSettingsTable(): void
{
    test()->artisan('migrate', ['--force' => true])->run();
    app(YandexSettings::class)->refresh();
}

/**
 * @return array<string, mixed>
 */
function fixtureJson(string $path): array
{
    $full = dirname(__DIR__).'/fixtures/'.$path;
    $json = file_get_contents($full);

    if ($json === false) {
        throw new RuntimeException('Fixture not found: '.$path);
    }

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}
