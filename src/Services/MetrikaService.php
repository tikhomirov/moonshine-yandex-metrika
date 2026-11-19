<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Services;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

class MetrikaService
{
    protected Client $http;

    protected string $token;

    protected string $counterId;

    protected int $days;

    protected string $date1;

    protected string $date2;

    protected int $cacheTtl;

    protected ?string $cacheStore;

    protected string $apiUrl;

    public function __construct()
    {
        $settings = app(YandexSettings::class);
        $this->token = $settings->token();
        $this->counterId = $settings->counterId();
        $this->days = (int) config('moonshine-yandex-metrika.days', 0);
        $this->cacheTtl = (int) config('moonshine-yandex-metrika.cache_ttl', 3600);
        $this->cacheStore = config('moonshine-yandex-metrika.cache_store');
        $this->apiUrl = (string) config('moonshine-yandex-metrika.api_url', 'https://api-metrika.yandex.net/stat/v1/data');

        $defaultPeriod = $this->days > 0
            ? new DatePeriod(
                Carbon::today()->subDays($this->days),
                Carbon::today(),
                DatePeriod::PRESET_CUSTOM,
            )
            : DatePeriod::today();

        $this->date1 = $defaultPeriod->date1();
        $this->date2 = $defaultPeriod->date2();

        $this->http = new Client([
            'base_uri' => $this->apiUrl,
            'headers' => [
                'Authorization' => "OAuth {$this->token}",
                'Accept' => 'application/json',
            ],
            'timeout' => 15,
        ]);
    }

    /**
     * Raw API request with caching.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    public function request(array $params): ?array
    {
        $query = array_merge([
            'ids' => $this->counterId,
            'date1' => $this->date1,
            'date2' => $this->date2,
        ], $params);

        $cacheKey = 'yandex_metrika_'.md5(serialize($query));

        $store = $this->cacheTtl > 0
            ? ($this->cacheStore ? Cache::store($this->cacheStore) : Cache::store())
            : null;

        if ($store && $store->has($cacheKey)) {
            /** @var array<string, mixed> $cached */
            $cached = $store->get($cacheKey);

            return $cached;
        }

        try {
            $response = $this->http->get('', [
                'query' => $query,
            ]);

            /** @var array<string, mixed>|null $data */
            $data = json_decode($response->getBody()->getContents(), true);

            if (! is_array($data)) {
                return null;
            }

            if ($store) {
                $store->put($cacheKey, $data, $this->cacheTtl);
            }

            return $data;
        } catch (GuzzleException $e) {
            logger()->channel('single')->error('[YandexMetrika] API error: '.$e->getMessage());

            return null;
        }
    }

    public function totalVisits(?int $days = null): int
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:s:visits',
        ]);

        return (int) ($data['totals'][0] ?? 0);
    }

    public function totalPageViews(?int $days = null): int
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:s:pageviews',
        ]);

        return (int) ($data['totals'][0] ?? 0);
    }

    public function uniqueVisitors(?int $days = null): int
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:s:users',
        ]);

        return (int) ($data['totals'][0] ?? 0);
    }

    public function bounceRate(?int $days = null): float
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:s:bounceRate',
        ]);

        return round((float) ($data['totals'][0] ?? 0), 2);
    }

    /**
     * @return list<array{dimension: string, metrics: list<float|int>}>
     */
    public function topPages(?int $days = null, int $maxResults = 10): array
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:pv:pageviews',
            'dimensions' => 'ym:pv:URL',
            'limit' => $maxResults,
            'sort' => '-ym:pv:pageviews',
        ]);

        return $this->extractRows($data);
    }

    /**
     * @return list<array{dimension: string, metrics: list<float|int>}>
     */
    public function trafficSources(?int $days = null, int $maxResults = 10): array
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:s:visits,ym:s:users',
            'dimensions' => 'ym:s:trafficSourceName',
            'limit' => $maxResults,
            'sort' => '-ym:s:visits',
        ]);

        return $this->extractRows($data);
    }

    /**
     * @return list<array{dimension: string, metrics: list<float|int>}>
     */
    public function searchPhrases(?int $days = null, int $maxResults = 10): array
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:s:visits',
            'dimensions' => 'ym:s:searchPhrase',
            'filters' => "ym:s:searchPhrase!='(not set)'",
            'limit' => $maxResults,
            'sort' => '-ym:s:visits',
        ]);

        return $this->extractRows($data);
    }

    /**
     * @return list<array{dimension: string, metrics: list<float|int>}>
     */
    public function geoCountries(?int $days = null, int $maxResults = 20): array
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:s:visits',
            'dimensions' => 'ym:s:regionCountry',
            'limit' => $maxResults,
            'sort' => '-ym:s:visits',
        ]);

        return $this->extractRows($data);
    }

    /**
     * @return list<array{dimension: string, metrics: list<float|int>}>
     */
    public function browsers(?int $days = null, int $maxResults = 10): array
    {
        $data = $this->forDays($days)->request([
            'metrics' => 'ym:s:visits',
            'dimensions' => 'ym:s:browser',
            'limit' => $maxResults,
            'sort' => '-ym:s:visits',
        ]);

        return $this->extractRows($data);
    }

    /**
     * @return list<array{dimension: string, metrics: list<float|int>}>
     */
    public function visitsByDay(?int $days = null): array
    {
        $scoped = $this->forDays($days);
        $limit = max(1, Carbon::parse($scoped->date1)->diffInDays(Carbon::parse($scoped->date2)) + 1);

        $data = $scoped->request([
            'metrics' => 'ym:s:visits',
            'dimensions' => 'ym:s:date',
            'sort' => 'ym:s:date',
            'limit' => $limit,
        ]);

        return $this->extractRows($data);
    }

    /**
     * Задать абсолютный период (fluent, clone).
     */
    public function forPeriod(DatePeriod $period): static
    {
        $clone = clone $this;
        $clone->date1 = $period->date1();
        $clone->date2 = $period->date2();
        $clone->days = $period->dayCount();

        return $clone;
    }

    /**
     * Set period in days ending today (fluent, returns clone). Kept for BC.
     */
    public function forDays(?int $days): static
    {
        if ($days === null) {
            return $this;
        }

        $period = new DatePeriod(
            Carbon::today()->subDays($days),
            Carbon::today(),
            DatePeriod::PRESET_CUSTOM,
        );

        return $this->forPeriod($period);
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->counterId !== '';
    }

    /**
     * @return list<array{dimension: string, metrics: list<float|int>}>
     */
    protected function extractRows(?array $data): array
    {
        if (empty($data['data'])) {
            return [];
        }

        return array_map(function (array $row) {
            $dimensions = array_map(fn ($d) => $d['name'] ?? $d['id'] ?? '', $row['dimensions'] ?? []);
            $metrics = $row['metrics'] ?? [];

            return [
                'dimension' => implode(' / ', $dimensions),
                'metrics' => $metrics,
            ];
        }, $data['data']);
    }
}
