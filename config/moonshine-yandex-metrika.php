<?php

return [
    /*
     * Yandex Metrika OAuth token.
     * Get it at: https://oauth.yandex.ru/
     * Register app → select "Yandex.Metrika" → "Read statistics" → copy token.
     */
    'token' => env('YANDEX_METRIKA_TOKEN'),

    /*
     * Yandex Metrika counter ID (the number from your counter settings).
     */
    'counter_id' => env('YANDEX_METRIKA_COUNTER_ID'),

    /*
     * Default number of days for statistics (used in all widgets by default).
     */
    'days' => env('YANDEX_METRIKA_DAYS', 30),

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
     * Widgets to display on the dashboard page.
     * You can reorder, enable/disable any widget here.
     */
    'widgets' => [
        'visits'          => true,   // Total visits
        'pageviews'       => true,   // Total page views
        'unique_visitors' => true,   // Unique visitors
        'bounce_rate'     => true,   // Bounce rate (%)
        'top_pages'       => true,   // Most viewed pages table
        'traffic_sources' => true,   // Traffic sources table
        'search_phrases'  => true,   // Search phrases table
        'geo'             => true,   // Countries chart
        'browsers'        => true,   // Browsers chart
    ],

    /*
     * Default max results for table widgets (top_pages, traffic_sources, etc.).
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
     * Yandex Metrika API base URL. Change only if Yandex changes it.
     */
    'api_url' => 'https://api-metrika.yandex.net/stat/v1/data',
];
