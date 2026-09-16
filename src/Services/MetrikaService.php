<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class MetrikaService
{
    protected Client $http;

    protected string $token;

    protected string $counterId;

    protected int $days;

    protected int $cacheTtl;

    protected ?string $cacheStore;

    protected string $apiUrl;

    public function __construct()
    {
        $this->token      = (string) config('moonshine-yandex-metrika.token');
        $this->counterId  = (string) config('moonshine-yandex-metrika.counter_id');
        $this->days       = (int)    config('moonshine-yandex-metrika.days', 30);
        $this->cacheTtl   = (int)    config('moonshine-yandex-metrika.cache_ttl', 3600);
        $this->cacheStore = config('moonshine-yandex-metrika.cache_store');
        $this->apiUrl     = (string) config('moonshine-yandex-metrika.api_url', 'https://api-metrika.yandex.net/stat/v1/data');

        $this->http = new Client([
            'base_uri' => $this->apiUrl,
            'headers'  => [
                'Authorization' => "OAuth {$this->token}",
                'Accept'        => 'application/json',
            ],
            'timeout' => 15,
        ]);
    }

    /**
     * Raw API request with caching.
     */
    public function request(array $params): ?array
    {
        $cacheKey = 'yandex_metrika_' . md5(serialize($params));

        $store = $this->cacheTtl > 0
            ? ($this->cacheStore ? Cache::store($this->cacheStore) : Cache::store())
            : null;

        if ($store && $store->has($cacheKey)) {
            return $store->get($cacheKey);
        }

        try {
            $response = $this->http->get('', [
                'query' => array_merge([
                    'ids'    => $this->counterId,
                    'date1'  => Carbon::today()->subDays($this->days)->format('Y-m-d'),
                    'date2'  => Carbon::today()->format('Y-m-d'),
                ], $params),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if ($store) {
                $store->put($cacheKey, $data, $this->cacheTtl);
            }

            return $data;
        } catch (GuzzleException $e) {
            logger()->channel('single')->error('[YandexMetrika] API error: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Total visits for the period.
     */
    public function totalVisits(?int $days = null): int
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:s:visits',
        ]);

        return (int) ($data['totals'][0] ?? 0);
    }

    /**
     * Total page views for the period.
     */
    public function totalPageViews(?int $days = null): int
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:s:pageviews',
        ]);

        return (int) ($data['totals'][0] ?? 0);
    }

    /**
     * Unique visitors for the period.
     */
    public function uniqueVisitors(?int $days = null): int
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:s:users',
        ]);

        return (int) ($data['totals'][0] ?? 0);
    }

    /**
     * Bounce rate (%) for the period.
     */
    public function bounceRate(?int $days = null): float
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:s:bounceRate',
        ]);

        return round((float) ($data['totals'][0] ?? 0), 2);
    }

    /**
     * Most viewed pages.
     */
    public function topPages(?int $days = null, int $maxResults = 10): array
    {
        $data = $this->forDays($days)->request([
            'metrics'    => 'ym:pv:pageviews',
            'dimensions' => 'ym:pv:URL',
            'limit'      => $maxResults,
            'sort'       => '-ym:pv:pageviews',
        ]);

        return $this->extractRows($data);
    }

    /**
     * Traffic sources summary.
     */
    public function trafficSources(?int $days = null, int $maxResults = 10): array
    {
        $data = $this->forDays($days)->request([
            'metrics'    => 'ym:s:visits,ym:s:users',
            'dimensions' => 'ym:s:trafficSourceName',
            'limit'      => $maxResults,
            'sort'       => '-ym:s:visits',
        ]);

        return $this->extractRows($data);
    }

    /**
     * Top search phrases.
     */
    public function searchPhrases(?int $days = null, int $maxResults = 10): array
    {
        $data = $this->forDays($days)->request([
            'metrics'    => 'ym:s:visits',
            'dimensions' => 'ym:s:searchPhrase',
            'filters'    => "ym:s:searchPhrase!='(not set)'",
            'limit'      => $maxResults,
            'sort'       => '-ym:s:visits',
        ]);

        return $this->extractRows($data);
    }

    /**
     * Visits by country.
     */
    public function geoCountries(?int $days = null, int $maxResults = 20): array
    {
        $data = $this->forDays($days)->request([
            'metrics'    => 'ym:s:visits',
            'dimensions' => 'ym:s:regionCountry',
            'limit'      => $maxResults,
            'sort'       => '-ym:s:visits',
        ]);

        return $this->extractRows($data);
    }

    /**
     * Visits by browser.
     */
    public function browsers(?int $days = null, int $maxResults = 10): array
    {
        $data = $this->forDays($days)->request([
            'metrics'    => 'ym:s:visits',
            'dimensions' => 'ym:s:browser',
            'limit'      => $maxResults,
            'sort'       => '-ym:s:visits',
        ]);

        return $this->extractRows($data);
    }

    /**
     * Visits per day (for line chart).
     */
    public function visitsByDay(?int $days = null): array
    {
        $data = $this->forDays($days)->request([
            'metrics'    => 'ym:s:visits',
            'dimensions' => 'ym:s:date',
            'sort'       => 'ym:s:date',
            'limit'      => $days ?? $this->days,
        ]);

        return $this->extractRows($data);
    }

    /**
     * Set period in days for the next request (fluent, returns clone).
     */
    public function forDays(?int $days): static
    {
        if ($days === null) {
            return $this;
        }

        $clone       = clone $this;
        $clone->days = $days;

        return $clone;
    }

    // ------------------------------------------------- helpers

    protected function extractRows(?array $data): array
    {
        if (empty($data['data'])) {
            return [];
        }

        return array_map(function (array $row) {
            $dimensions = array_map(fn ($d) => $d['name'] ?? $d['id'] ?? '', $row['dimensions'] ?? []);
            $metrics    = $row['metrics'] ?? [];

            return [
                'dimension' => implode(' / ', $dimensions),
                'metrics'   => $metrics,
            ];
        }, $data['data']);
    }
}
