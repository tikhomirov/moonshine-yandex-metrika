<?php

return [
    /*
     * Yandex OAuth token (Metrika + Webmaster + login scopes).
     * Get it at: https://oauth.yandex.ru/
     */
    'token' => env('YANDEX_METRIKA_TOKEN'),

    /*
     * Yandex Metrika counter ID (the number from your counter settings).
     */
    'counter_id' => env('YANDEX_METRIKA_COUNTER_ID'),

    /*
     * Legacy rolling-window default for MetrikaService::forDays() BC.
     * Dashboard default period is controlled by default_preset (today).
     * Set to 0 to use "today" as service default.
     */
    'days' => env('YANDEX_METRIKA_DAYS', 0),

    /*
     * Default dashboard preset: today|yesterday|week|month|quarter|half_year|year
     */
    'default_preset' => env('YANDEX_METRIKA_DEFAULT_PRESET', 'today'),

    /*
     * Cache time in seconds. Set to 0 to disable caching.
     * Recommended: 3600 (1 hour) to avoid API rate limits.
     */
    'cache_ttl' => env('YANDEX_METRIKA_CACHE_TTL', 3600),

    /*
     * Cache store to use. Null = use the default Laravel cache store.
     */
    'cache_store' => env('YANDEX_METRIKA_CACHE_STORE', null),

    /*
     * KPI card order on the Metrika tab. Known keys: visits, pageviews,
     * unique_visitors, bounce_rate. Unknown keys are ignored. A widget
     * set to false still hides the matching card; widgets also control
     * the other dashboard blocks (chart, tables, Webmaster).
     */
    'summary_metrics' => [
        'visits',
        'pageviews',
        'unique_visitors',
        'bounce_rate',
    ],

    /*
     * Widgets to display on the dashboard page.
     */
    'widgets' => [
        'account' => true,
        'period_filter' => true,
        'export' => true,
        'visits' => true,
        'pageviews' => true,
        'unique_visitors' => true,
        'bounce_rate' => true,
        'top_pages' => true,
        'traffic_sources' => true,
        'search_phrases' => true,
        'geo' => true,
        'browsers' => true,
        'webmaster_summary' => true,
        'webmaster_queries' => true,
    ],

    /*
     * Default max results for table widgets.
     */
    'max_results' => 10,

    /*
     * Dashboard page title (shown in MoonShine menu).
     */
    'page_title' => 'Яндекс.Метрика',

    /*
     * Dashboard page icon (any HeroIcon name supported by MoonShine).
     */
    'page_icon' => 'presentation-chart-bar',

    /*
     * Yandex Metrika API base URL.
     */
    'api_url' => 'https://api-metrika.yandex.net/stat/v1/data',

    /*
     * Yandex Login info endpoint (email + avatar).
     */
    'login_info_url' => 'https://login.yandex.ru/info',

    /*
     * Yandex Webmaster settings.
     * host_id format: https:example.com:443
     */
    'webmaster' => [
        'user_id' => env('YANDEX_WEBMASTER_USER_ID'),
        'host_id' => env('YANDEX_WEBMASTER_HOST_ID'),
        'api_url' => env('YANDEX_WEBMASTER_API_URL', 'https://api.webmaster.yandex.net/v4'),
    ],
];
