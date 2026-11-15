# moonshine-yandex-metrika

[![Packagist Version](https://img.shields.io/packagist/v/tikhomirov/moonshine-yandex-metrika)](https://packagist.org/packages/tikhomirov/moonshine-yandex-metrika)
[![PHP Version](https://img.shields.io/packagist/php-v/tikhomirov/moonshine-yandex-metrika)](https://packagist.org/packages/tikhomirov/moonshine-yandex-metrika)
[![License](https://img.shields.io/packagist/l/tikhomirov/moonshine-yandex-metrika)](LICENSE)

Пакет добавляет в [MoonShine](https://moonshine-laravel.com) 4.x и 5.x дашборд **Яндекс.Метрика + Вебмастер**: вкладки, посещаемость, фильтр периода, summary индексации/ошибок/внешних ссылок, топ поисковых запросов, блок аккаунта (email/аватар) и CSV-выгрузки.

![Dashboard preview](docs/preview.png)

---

## Требования

- PHP 8.2+. Для MoonShine 5 нужен PHP 8.4.
- Laravel 11, 12 или 13
- MoonShine 4.x или 5.x

---

## Установка пакета

```bash
composer require tikhomirov/moonshine-yandex-metrika
```

Публикуем конфиг:

```bash
php artisan vendor:publish --tag=moonshine-yandex-metrika-config
```

Добавляем в `.env`:

```env
YANDEX_METRIKA_TOKEN=ваш_токен
YANDEX_METRIKA_COUNTER_ID=ваш_номер_счётчика
YANDEX_METRIKA_DEFAULT_PRESET=today
# host_id формата https:example.com:443 (см. GET /v4/user/{id}/hosts)
YANDEX_WEBMASTER_HOST_ID=https:example.com:443
```

Регистрируем страницу в MoonShine провайдере:

```php
// app/Providers/MoonShineServiceProvider.php
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;

public function menu(): array
{
    return [
        MenuItem::make('Яндекс.Метрика', MetrikaDashboardPage::class)
            ->icon('presentation-chart-bar'),
    ];
}
```

---

## Получение OAuth-токена

Это самый трудоёмкий шаг — нужно зарегистрировать OAuth-приложение в Яндексе. Ниже подробная инструкция по каждому шагу.

### Шаг 1 — Создать приложение

1. Перейти на [oauth.yandex.ru](https://oauth.yandex.ru/) и нажать **«Зарегистрировать новое приложение»**
2. Заполнить форму:
   - **Название сервиса** — любое, например `Yandex.Metrika`
   - **Иконка** — обязательное поле, загрузить PNG/JPG до 1 МБ (можно использовать [`docs/icon.png`](docs/icon.png) из этого репозитория)
   - **Почта для связи** — ваша рабочая почта

---

### Шаг 2 — Платформы приложений

1. Отметить чекбокс **«Веб-сервисы»**
2. В поле **Redirect URI** ввести:
   ```
   https://oauth.yandex.ru/verification_code
   ```
   Это официальный адрес Яндекса для ручного получения токена — после авторизации он покажет токен прямо на странице.
3. Поле **Suggest Hostname** — оставить пустым
4. Чекбоксы **iOS** и **Android** — не трогать

---

### Шаг 3 — Права доступа

1. Раздел **«Основные»** — ничего не отмечать (это права на данные пользователей, нам не нужны)
2. Раздел **«Дополнительные»** / права приложения — добавить:
   - `metrika:read` — чтение статистики Метрики *(обязательно)*
   - `webmaster:verify` — Вебмастер: summary, индексация, запросы *(для блока Вебмастера)*
   - `webmaster:hostinfo` — внешние ссылки *(для счётчика ссылок)*
   - `login:info`, `login:email`, `login:avatar` — email и аватар источника данных
   - `metrika:write` — необязательно

---

### Шаг 4 — Завершение

После финального шага Яндекс покажет страницу с **Client ID** приложения. Скопируйте его.

---

### Получение токена

Откройте в браузере (залогинившись под нужным аккаунтом Яндекса):

```
https://oauth.yandex.ru/authorize?response_type=token&client_id=ВАШ_CLIENT_ID
```

Нажмите **«Разрешить»** → вас перенаправит на страницу с токеном в URL:

```
https://oauth.yandex.ru/verification_code#access_token=y0__wg...
```

Скопируйте значение `access_token` и вставьте в `.env`:

```env
YANDEX_METRIKA_TOKEN=y0__wg...
```

---

### Номер счётчика

Номер счётчика виден в URL при открытии Метрики:

```
https://metrika.yandex.ru/stat/visits?id=XXXXXXXX
```

Или в интерфейсе: **Метрика → Настройки счётчика → Номер счётчика**.

```env
YANDEX_METRIKA_COUNTER_ID=XXXXXXXX
```

---

## Конфигурация

Все настройки в `config/moonshine-yandex-metrika.php`:

```php
return [
    'token'      => env('YANDEX_METRIKA_TOKEN'),
    'counter_id' => env('YANDEX_METRIKA_COUNTER_ID'),

    // Legacy rolling-window для forDays(); дашборд использует default_preset
    'days' => 0,
    'default_preset' => 'today',

    // Кэш в секундах (0 = отключить)
    'cache_ttl' => 3600,
    'cache_store' => null,

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

    'max_results' => 10,
    'page_title' => 'Яндекс.Метрика',
    'page_icon'  => 'presentation-chart-bar',

    'webmaster' => [
        'host_id' => env('YANDEX_WEBMASTER_HOST_ID'),
        'user_id' => env('YANDEX_WEBMASTER_USER_ID'),
    ],
];
```

### Пресеты периода

| Пресет | Как считается |
|---|---|
| Сегодня / Вчера | один день |
| Неделя | сегодня − 7 … сегодня |
| Месяц / Квартал / Полгода / Год | с начала текущего календарного периода до сегодня |
| Произвольный | `date_from` + `date_to` |

CSV: `/admin/yandex-analytics/export/metrika` и `/admin/yandex-analytics/export/webmaster` (под префиксом MoonShine, только для авторизованного админа).

---

## Использование сервиса напрямую

`MetrikaService` можно инжектировать куда угодно:

```php
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;

class MyController extends Controller
{
    public function index(MetrikaService $metrika)
    {
        $visits  = $metrika->totalVisits(days: 7);
        $pages   = $metrika->topPages(days: 7, maxResults: 5);
        $sources = $metrika->trafficSources();
    }
}
```

### Доступные методы

| Метод | Описание |
|---|---|
| `totalVisits(?int $days)` | Всего визитов |
| `totalPageViews(?int $days)` | Всего просмотров |
| `uniqueVisitors(?int $days)` | Уникальных посетителей |
| `bounceRate(?int $days)` | Процент отказов |
| `topPages(?int $days, int $maxResults)` | Топ страниц по просмотрам |
| `trafficSources(?int $days, int $maxResults)` | Источники трафика |
| `searchPhrases(?int $days, int $maxResults)` | Поисковые фразы |
| `geoCountries(?int $days, int $maxResults)` | Визиты по странам |
| `browsers(?int $days, int $maxResults)` | Визиты по браузерам |
| `visitsByDay(?int $days)` | Визиты по дням (для графиков) |
| `forPeriod(DatePeriod $period)` | Абсолютный период date1/date2 |
| `request(array $params)` | Произвольный запрос к API |

Также доступны `WebmasterService`, `YandexAccountService`, `AnalyticsExportService`.

---

## Расширение

### Своя страница дашборда

```php
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;

class MyMetrikaPage extends MetrikaDashboardPage
{
    protected function extraComponents(DatePeriod $period): array
    {
        return [
            Box::make('Мой виджет', [...]),
        ];
    }
}
```


### Публикация шаблонов

```bash
php artisan vendor:publish --tag=moonshine-yandex-metrika-views
```

Затем редактируй файлы в `resources/views/vendor/moonshine-yandex-metrika/`.

---

## Локализация

Поставляется с переводами на **русский** и **английский**. Для публикации:

```bash
php artisan vendor:publish --tag=moonshine-yandex-metrika-lang
```

---

## Кэширование

```env
YANDEX_METRIKA_CACHE_TTL=3600      # секунд, 0 = без кэша
YANDEX_METRIKA_CACHE_STORE=redis   # опционально: конкретный драйвер
```

---

## Лицензия

MIT — см. [LICENSE](LICENSE).
