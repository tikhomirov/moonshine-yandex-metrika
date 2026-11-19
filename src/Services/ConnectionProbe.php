<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

/**
 * Проверяет доступ к API и объясняет ошибку (пакет иначе глотает исключения).
 */
final class ConnectionProbe
{
    private const string CACHE_KEY = 'yandex_metrika_package_probe';

    private const int FAILURE_TTL = 60;

    private const int SUCCESS_TTL = 300;

    public function check(): ConnectionProbeResult
    {
        $settings = app(YandexSettings::class);
        $token = $settings->token();
        $counterId = $settings->counterId();

        if ($token === '' || $counterId === '') {
            $missing = [];

            if ($token === '') {
                $missing[] = 'YANDEX_METRIKA_TOKEN';
            }

            if ($counterId === '') {
                $missing[] = 'YANDEX_METRIKA_COUNTER_ID';
            }

            return ConnectionProbeResult::notConfigured(
                'Не заданы: '.implode(', ', $missing).'.',
            );
        }

        $store = Cache::store(config('moonshine-yandex-metrika.cache_store'));

        /** @var ConnectionProbeResult|null $cached */
        $cached = $store->get(self::CACHE_KEY);

        if ($cached instanceof ConnectionProbeResult) {
            return $cached;
        }

        $result = $this->requestMetrika($token, $counterId);
        $store->put(self::CACHE_KEY, $result, $result->isOk() ? self::SUCCESS_TTL : self::FAILURE_TTL);

        return $result;
    }

    private function requestMetrika(string $token, string $counterId): ConnectionProbeResult
    {
        $apiUrl = (string) config('moonshine-yandex-metrika.api_url', 'https://api-metrika.yandex.net/stat/v1/data');
        $period = DatePeriod::today();

        try {
            $client = new Client([
                'headers' => [
                    'Authorization' => "OAuth {$token}",
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            $response = $client->get($apiUrl, [
                'query' => [
                    'ids' => $counterId,
                    'date1' => $period->date1(),
                    'date2' => $period->date2(),
                    'metrics' => 'ym:s:visits',
                ],
            ]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return ConnectionProbeResult::ok();
            }

            return ConnectionProbeResult::failed('API Яндекс.Метрики вернул HTTP '.$response->getStatusCode().'.');
        } catch (GuzzleException $e) {
            $status = method_exists($e, 'getResponse') && $e->getResponse()
                ? $e->getResponse()->getStatusCode()
                : 0;

            return ConnectionProbeResult::failed($this->explain($status, $e->getMessage()));
        }
    }

    private function explain(int $status, string $message): string
    {
        logger()->channel('single')->warning('[YandexMetrika] probe failed: '.$message);

        return match ($status) {
            401 => 'Токен не принят Яндексом (401).',
            403 => 'Нет доступа к счётчику (403).',
            404 => 'Счётчик не найден (404).',
            default => 'API Яндекс.Метрики недоступен.',
        };
    }
}
