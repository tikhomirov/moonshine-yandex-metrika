<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Query;
use Illuminate\Support\Facades\Cache;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

/**
 * Клиент API Яндекс.Вебмастера (summary, запросы, внешние ссылки).
 */
class WebmasterService
{
    private Client $http;

    private string $token;

    private ?string $userId = null;

    /** Выбранный host_id; null — ещё не определён (см. resolveHost()). */
    private ?string $hostId = null;

    private ?string $hostSource = null;

    /** Список сайтов кэшируется коротко: 5 минут. */
    private const int HOSTS_TTL = 300;

    private int $cacheTtl;

    private ?string $cacheStore;

    private string $apiUrl;

    private ?DatePeriod $period = null;

    public function __construct()
    {
        $settings = app(YandexSettings::class);
        $this->token = $settings->token();
        $userId = config('moonshine-yandex-metrika.webmaster.user_id');
        $this->userId = $userId !== null && $userId !== '' ? (string) $userId : null;
        $this->cacheTtl = (int) config('moonshine-yandex-metrika.cache_ttl', 3600);
        $this->cacheStore = config('moonshine-yandex-metrika.cache_store');
        $this->apiUrl = rtrim((string) config(
            'moonshine-yandex-metrika.webmaster.api_url',
            'https://api.webmaster.yandex.net/v4',
        ), '/');

        $this->http = new Client([
            'base_uri' => $this->apiUrl.'/',
            'headers' => [
                'Authorization' => "OAuth {$this->token}",
                'Accept' => 'application/json',
            ],
            'timeout' => 20,
        ]);
    }

    public function forPeriod(DatePeriod $period): static
    {
        $clone = clone $this;
        $clone->period = $period;

        return $clone;
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->hostId() !== '';
    }

    public function hostId(): string
    {
        if ($this->hostId === null) {
            [$this->hostId, $this->hostSource] = $this->resolveHost();
        }

        return $this->hostId;
    }

    /**
     * Откуда взят host_id: db, auto (по APP_URL или единственный сайт), env или null.
     */
    public function hostSource(): ?string
    {
        $this->hostId();

        return $this->hostSource;
    }

    /**
     * Сайты аккаунта по текущему токену. Null — нет токена или API вернул ошибку.
     *
     * @return list<array{host_id: string, url: string, verified: bool}>|null
     */
    public function hosts(): ?array
    {
        if ($this->token === '') {
            return null;
        }

        $userId = $this->resolveUserId();

        if ($userId === null) {
            return null;
        }

        $data = $this->get("user/{$userId}/hosts", [], self::HOSTS_TTL);

        return $data === null ? null : self::hostsFromResponse($data);
    }

    /**
     * @param  array<string, mixed>  $data  ответ GET /user/{user_id}/hosts
     * @return list<array{host_id: string, url: string, verified: bool}>
     */
    public static function hostsFromResponse(array $data): array
    {
        $hosts = [];

        foreach ((array) ($data['hosts'] ?? []) as $host) {
            if (! is_array($host) || ! isset($host['host_id']) || $host['host_id'] === '') {
                continue;
            }

            $hosts[] = [
                'host_id' => (string) $host['host_id'],
                'url' => rtrim((string) ($host['unicode_host_url'] ?? $host['ascii_host_url'] ?? $host['host_id']), '/'),
                'verified' => (bool) ($host['verified'] ?? false),
            ];
        }

        return $hosts;
    }

    /**
     * Сайт, совпадающий с URL приложения: схема, хост и порт; www не учитывается.
     *
     * @param  list<string>  $hostIds  host_id вида https:example.com:443
     */
    public static function matchHostByUrl(array $hostIds, string $url): ?string
    {
        $target = self::urlKey($url);

        if ($target === null) {
            return null;
        }

        foreach ($hostIds as $hostId) {
            if (preg_match('/^(https?):([^:]+):(\d+)$/i', $hostId, $m) === 1
                && $target === mb_strtolower($m[1]).'://'.self::bareHost($m[2]).':'.$m[3]) {
                return $hostId;
            }
        }

        return null;
    }

    private static function urlKey(string $url): ?string
    {
        $parts = parse_url(mb_trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = mb_strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.self::bareHost($parts['host']).':'.$port;
    }

    private static function bareHost(string $host): string
    {
        $host = mb_strtolower($host);

        return str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;
    }

    /**
     * Порядок: сохранённый в БД → сайт с адресом APP_URL → env → единственный сайт аккаунта.
     *
     * @return array{0: string, 1: string|null}
     */
    private function resolveHost(): array
    {
        $settings = app(YandexSettings::class);
        $stored = $settings->storedValue('host_id');

        if ($stored !== '') {
            return [$stored, YandexSettings::SOURCE_DB];
        }

        $hostIds = array_column($this->hosts() ?? [], 'host_id');
        $match = self::matchHostByUrl($hostIds, (string) config('app.url'));

        if ($match !== null) {
            return [$match, YandexSettings::SOURCE_AUTO];
        }

        $env = $settings->configValue('host_id');

        if ($env !== '') {
            return [$env, YandexSettings::SOURCE_ENV];
        }

        if (count($hostIds) === 1) {
            return [$hostIds[0], YandexSettings::SOURCE_AUTO];
        }

        return ['', null];
    }

    /**
     * @return array{
     *     searchable_pages_count: int,
     *     excluded_pages_count: int,
     *     site_problems: array{FATAL: int, CRITICAL: int, POSSIBLE_PROBLEM: int, RECOMMENDATION: int},
     *     problems_total: int,
     *     sqi: int|null
     * }|null
     */
    public function summary(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $userId = $this->resolveUserId();

        if ($userId === null) {
            return null;
        }

        $data = $this->get("user/{$userId}/hosts/{$this->encodedHostId()}/summary");

        if ($data === null) {
            return null;
        }

        $problems = [
            'FATAL' => (int) ($data['site_problems']['FATAL'] ?? 0),
            'CRITICAL' => (int) ($data['site_problems']['CRITICAL'] ?? 0),
            'POSSIBLE_PROBLEM' => (int) ($data['site_problems']['POSSIBLE_PROBLEM'] ?? 0),
            'RECOMMENDATION' => (int) ($data['site_problems']['RECOMMENDATION'] ?? 0),
        ];

        return [
            'searchable_pages_count' => (int) ($data['searchable_pages_count'] ?? 0),
            'excluded_pages_count' => (int) ($data['excluded_pages_count'] ?? 0),
            'site_problems' => $problems,
            'problems_total' => array_sum($problems),
            'sqi' => isset($data['sqi']) ? (int) $data['sqi'] : null,
        ];
    }

    /**
     * Число внешних ссылок. Null — нет scope EXTERNAL_LINKS / hostinfo.
     */
    public function externalLinksTotal(): ?int
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $userId = $this->resolveUserId();

        if ($userId === null) {
            return null;
        }

        $data = $this->get(
            "user/{$userId}/hosts/{$this->encodedHostId()}/links/external/history",
            ['indicator' => 'LINKS_TOTAL_COUNT'],
        );

        if ($data === null) {
            return null;
        }

        $series = $data['indicators']['LINKS_TOTAL_COUNT'] ?? [];

        if (! is_array($series) || $series === []) {
            return 0;
        }

        $last = end($series);

        return (int) ($last['value'] ?? 0);
    }

    /**
     * @return list<array{query: string, shows: int, clicks: int, avg_show_position: float|null, avg_click_position: float|null}>
     */
    public function popularQueries(int $limit = 10): array
    {
        return $this->popularQueriesReport($limit)['rows'];
    }

    /**
     * Популярные запросы за выбранный период.
     * Если за период пусто (у Вебмастера бывает задержка), берём последнее доступное окно API.
     *
     * @return array{
     *     rows: list<array{query: string, shows: int, clicks: int, avg_show_position: float|null, avg_click_position: float|null}>,
     *     date_from: string|null,
     *     date_to: string|null,
     *     used_fallback: bool
     * }
     */
    public function popularQueriesReport(int $limit = 10): array
    {
        $empty = [
            'rows' => [],
            'date_from' => null,
            'date_to' => null,
            'used_fallback' => false,
        ];

        if (! $this->isConfigured()) {
            return $empty;
        }

        $userId = $this->resolveUserId();

        if ($userId === null) {
            return $empty;
        }

        $period = $this->period ?? DatePeriod::fromPreset(DatePeriod::PRESET_WEEK);
        $limit = max(1, min(500, $limit));
        $path = "user/{$userId}/hosts/{$this->encodedHostId()}/search-queries/popular";

        $primary = $this->fetchPopularQueries($path, $limit, [
            'date_from' => $period->from->copy()->startOfDay()->format('Y-m-d\TH:i:sP'),
            'date_to' => $period->to->copy()->endOfDay()->format('Y-m-d\TH:i:sP'),
        ]);

        if ($primary['rows'] !== []) {
            return [
                ...$primary,
                'used_fallback' => false,
            ];
        }

        // API по умолчанию отдаёт последнее окно, где уже есть статистика.
        $fallback = $this->fetchPopularQueries($path, $limit, []);

        return [
            ...$fallback,
            'used_fallback' => $fallback['rows'] !== [],
        ];
    }

    public function cabinetSummaryUrl(): string
    {
        return $this->cabinetUrl('dashboard');
    }

    public function cabinetIndexingUrl(): string
    {
        return $this->cabinetUrl('indexing');
    }

    public function cabinetDiagnosticsUrl(): string
    {
        return $this->cabinetUrl('diagnostics');
    }

    public function cabinetLinksUrl(): string
    {
        return $this->cabinetUrl('links');
    }

    public function cabinetQueriesUrl(): string
    {
        return $this->cabinetUrl('search/?queryType=POPULAR');
    }

    private function cabinetUrl(string $path): string
    {
        $hostId = $this->hostId() !== '' ? $this->hostId() : $this->displayHost();

        return 'https://webmaster.yandex.ru/site/'.$hostId.'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $dates
     * @return array{
     *     rows: list<array{query: string, shows: int, clicks: int, avg_show_position: float|null, avg_click_position: float|null}>,
     *     date_from: string|null,
     *     date_to: string|null
     * }
     */
    private function fetchPopularQueries(string $path, int $limit, array $dates): array
    {
        $query = array_merge([
            'order_by' => 'TOTAL_SHOWS',
            'query_indicator' => [
                'TOTAL_SHOWS',
                'TOTAL_CLICKS',
                'AVG_SHOW_POSITION',
                'AVG_CLICK_POSITION',
            ],
            'device_type_indicator' => 'ALL',
            'limit' => $limit,
            'offset' => 0,
        ], $dates);

        $data = $this->get($path, $query);
        $rows = is_array($data['queries'] ?? null) ? $data['queries'] : [];
        $result = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $indicators = is_array($row['indicators'] ?? null) ? $row['indicators'] : [];

            $result[] = [
                'query' => (string) ($row['query_text'] ?? ''),
                'shows' => (int) ($indicators['TOTAL_SHOWS'] ?? 0),
                'clicks' => (int) ($indicators['TOTAL_CLICKS'] ?? 0),
                'avg_show_position' => isset($indicators['AVG_SHOW_POSITION'])
                    ? round((float) $indicators['AVG_SHOW_POSITION'], 1)
                    : null,
                'avg_click_position' => isset($indicators['AVG_CLICK_POSITION'])
                    ? round((float) $indicators['AVG_CLICK_POSITION'], 1)
                    : null,
            ];
        }

        return [
            'rows' => $result,
            'date_from' => isset($data['date_from']) ? substr((string) $data['date_from'], 0, 10) : null,
            'date_to' => isset($data['date_to']) ? substr((string) $data['date_to'], 0, 10) : null,
        ];
    }

    public function displayHost(): string
    {
        // host_id: https:example.com:443 → example.com
        if (preg_match('/^https?:([^:]+):\d+$/', $this->hostId(), $matches) === 1) {
            return $matches[1];
        }

        return $this->hostId() !== '' ? $this->hostId() : 'site';
    }

    public function resolveUserId(): ?string
    {
        if ($this->userId !== null) {
            return $this->userId;
        }

        if ($this->token === '') {
            return null;
        }

        $data = $this->get('user');

        if ($data === null || ! isset($data['user_id'])) {
            return null;
        }

        $this->userId = (string) $data['user_id'];

        return $this->userId;
    }

    private function encodedHostId(): string
    {
        return rawurlencode($this->hostId());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function get(string $path, array $query = [], ?int $ttl = null): ?array
    {
        // host_id уже есть в пути запросов по сайту.
        $cacheKey = 'yandex_webmaster_'.md5($path.'|'.serialize($query).'|'.sha1($this->token));
        $ttl ??= $this->cacheTtl;

        $store = $this->cacheTtl > 0
            ? ($this->cacheStore ? Cache::store($this->cacheStore) : Cache::store())
            : null;

        if ($store?->has($cacheKey)) {
            /** @var array<string, mixed> $cached */
            $cached = $store->get($cacheKey);

            return $cached;
        }

        try {
            $response = $this->http->get($path, [
                // Вебмастер ждёт query_indicator=A&query_indicator=B, а не query_indicator[0]=A.
                'query' => Query::build($this->flattenQuery($query)),
            ]);
            /** @var array<string, mixed>|null $data */
            $data = json_decode($response->getBody()->getContents(), true);

            if (! is_array($data)) {
                return null;
            }

            $store?->put($cacheKey, $data, min($ttl, $this->cacheTtl));

            return $data;
        } catch (GuzzleException $e) {
            logger()->channel('single')->error('[YandexWebmaster] API error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, string|list<string>>
     */
    private function flattenQuery(array $query): array
    {
        $flat = [];

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $flat[$key] = array_map(static fn (mixed $item): string => (string) $item, array_values($value));

                continue;
            }

            $flat[$key] = (string) $value;
        }

        return $flat;
    }
}
