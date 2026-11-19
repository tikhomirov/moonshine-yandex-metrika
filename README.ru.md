<p align="center">
  <strong>Аналитика Яндекс.Метрики и Вебмастера прямо в MoonShine</strong><br>
  Посещаемость, поисковые запросы и индексация — в одном дашборде.
</p>

<p align="center">
  <a href="README.md">English version</a>
</p>

<h1 align="center">MoonShine Yandex Metrika</h1>

<p align="center">
  <a href="https://packagist.org/packages/tikhomirov/moonshine-yandex-metrika"><img src="https://img.shields.io/packagist/v/tikhomirov/moonshine-yandex-metrika?style=for-the-badge" alt="Версия Packagist"></a>
  <img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.4+">
  <img src="https://img.shields.io/badge/MoonShine-5.x-6C4AB6?style=for-the-badge" alt="MoonShine 5.x">
  <a href="https://github.com/tikhomirov/moonshine-yandex-metrika/actions/workflows/tests.yml"><img src="https://github.com/tikhomirov/moonshine-yandex-metrika/actions/workflows/tests.yml/badge.svg" alt="Тесты"></a>
</p>

<p align="center">
  <img src="https://raw.githubusercontent.com/tikhomirov/moonshine-yandex-metrika/main/art/dashboard-preview.jpg" alt="Дашборд MoonShine со вкладками Яндекс.Метрики и Вебмастера, фильтрами периода, KPI и графиком визитов" width="100%">
</p>

## Возможности

- **Яндекс.Метрика:** визиты, просмотры, уникальные посетители, отказы, график и популярные страницы.
- **Яндекс.Вебмастер:** сводка индексации, ошибки сайта, внешние ссылки и популярные поисковые запросы.
- **Гибкие периоды:** готовые пресеты и произвольный диапазон дат.
- **CSV-экспорт** статистики Метрики и запросов Вебмастера.
- Данные аккаунта Яндекса и кэширование ответов API.
- Реестр вкладок для подключения сторонней аналитики без форка пакета.
- Интерфейс на русском и английском языках.

## Требования

| Компонент | Версия |
|---|---|
| PHP | 8.4+ |
| Laravel | 12 или 13 |
| MoonShine | 5.x |

## Установка

```bash
composer require tikhomirov/moonshine-yandex-metrika
php artisan vendor:publish --tag=moonshine-yandex-metrika-config
```

Добавьте страницу в меню MoonShine и зарегистрируйте её как custom page в Layout / провайдере:

```php
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;

MenuItem::make(MetrikaDashboardPage::class, 'Яндекс.Метрика');
```

## Настройка Яндекса

Создайте приложение в [Яндекс OAuth](https://oauth.yandex.ru/), укажите redirect URI `https://oauth.yandex.ru/verification_code` и выдайте необходимые права:

| Scope | Для чего нужен |
|---|---|
| `metrika:read` | Статистика Метрики |
| `webmaster:verify` | Индексация и поисковые запросы Вебмастера |
| `webmaster:hostinfo` | Данные о хосте и внешних ссылках |
| `login:info`, `login:email`, `login:avatar` | Email и аватар аккаунта Яндекса |

Добавьте настройки в `.env`:

```dotenv
YANDEX_METRIKA_TOKEN=ваш_oauth_токен
YANDEX_METRIKA_COUNTER_ID=номер_счётчика
YANDEX_METRIKA_DEFAULT_PRESET=today
YANDEX_WEBMASTER_HOST_ID=https:example.com:443
```

`YANDEX_WEBMASTER_HOST_ID` нужен для Вебмастера; укажите хост в формате из кабинета Яндекс.Вебмастера. Без этого параметра вкладка Метрики продолжит работать.

### OAuth-приложение в дашборде

В шапке дашборда виден `client_id` OAuth-приложения, которым выпущен токен, со ссылкой на его страницу в oauth.yandex.ru. `client_id` берётся из login.yandex.ru/info для текущего токена; переопределить можно через `YANDEX_OAUTH_CLIENT_ID`. Секреты не выводятся.

Если на вкладке Вебмастера не хватает прав, под алертом есть два действия:

- **Открыть приложение и добавить право**: открывает `https://oauth.yandex.ru/client/edit/<client_id>`.
- **Переподключить Яндекс**: открывает экран согласия Яндекса для этого приложения и выпускает новый токен. После смены прав Яндекс отзывает старые токены. Если сохранён Client secret (см. ниже), кнопка запускает OAuth flow пакета, и токен сохраняется сам. Без него кнопка открывает страницу выпуска токена на стороне Яндекса. Тогда вставьте новый токен в `YANDEX_METRIKA_TOKEN` и выполните `php artisan config:cache`. Яндекс покажет токен на странице, если у приложения указан redirect URI `https://oauth.yandex.ru/verification_code`.

### Страница «Подключение Яндекса» и настройки в БД

Это необязательно: без миграции всё работает из `.env`, как раньше.

```bash
php artisan migrate
```

Миграция создаёт таблицу `yandex_metrika_settings`. В ней хранятся ClientID, Client secret и токен (шифруются через `APP_KEY`), счётчик, хост Вебмастера, права токена и дата его получения. Значение из БД важнее env. Если в БД значение пустое, берётся env.

Страница **Подключение Яндекса** регистрируется в MoonShine автоматически. Отключить можно через `'connection_page' => false`. При желании добавьте её в меню:

```php
use Tikhomirov\MoonshineYandexMetrika\Pages\YandexConnectionPage;

MenuItem::make(YandexConnectionPage::class, 'Подключение Яндекса');
```

На странице видно:

- OAuth-приложение: ClientID со ссылкой на oauth.yandex.ru;
- источник каждого значения (БД или env);
- секреты под маской;
- выданные и недостающие права.

Там же есть форма настроек, кнопка **Получить токен** (или **Переподключить**, если токен уже есть) и кнопка **Очистить и вернуться к env**.

**Получить токен** работает через OAuth authorization code flow с `state` и PKCE. Кнопка запрашивает права из `oauth_scopes` и сохраняет токен в БД. Чтобы она работала:

1. Сохраните ClientID и Client secret на странице или задайте `YANDEX_OAUTH_CLIENT_ID` и `YANDEX_OAUTH_CLIENT_SECRET`.
2. В приложении на oauth.yandex.ru добавьте **Redirect URI** callback пакета (он же показан на странице):

   ```
   https://ваш-сайт/admin/yandex-analytics/oauth/callback
   ```

   `admin` — это ваш `moonshine.prefix`. Адрес `https://oauth.yandex.ru/verification_code` оставьте, если по-прежнему выпускаете токен вручную.
3. В приложении должны быть все права из `oauth_scopes` (по умолчанию `metrika:read`, `webmaster:verify`, `webmaster:hostinfo`). Иначе Яндекс вернёт `invalid_scope`.

Artisan:

```bash
php artisan yandex-metrika:connection:set --client-id=... --ask-secret --counter-id=... --host-id=https:example.com:443
php artisan yandex-metrika:connection:set --ask-token   # вставить токен вручную
php artisan yandex-metrika:connection:status            # значения и источники, секреты под маской
php artisan yandex-metrika:connection:clear --force     # удалить настройки из БД, вернуться к env
```

## Карточки метрик

На вкладке Метрики четыре сводные карточки. Набор и порядок задаёт `summary_metrics` в опубликованном конфиге. По умолчанию: визиты, просмотры, уникальные посетители, отказы. Неизвестные ключи игнорируются.

```php
'summary_metrics' => [
    'unique_visitors',
    'visits',
    'pageviews',
    'bounce_rate',
],
```

Карта `widgets` по-прежнему включает и выключает остальные блоки дашборда. Если виджет выставить в `false`, соответствующая карточка тоже скроется.

## Периоды и экспорт


Доступны пресеты «Сегодня», «Вчера», «Неделя», «Месяц», «Квартал», «Полгода», «Год» и произвольный диапазон дат. Сводка Вебмастера показывает текущее состояние сайта, выбранный период применяется к поисковым запросам.

CSV-экспорт доступен авторизованным администраторам MoonShine:

- `/admin/yandex-analytics/export/metrika`
- `/admin/yandex-analytics/export/webmaster`

## Skill для AI-агентов

В пакет включён готовый **skill для AI-агентов**: он помогает настраивать, расширять и диагностировать дашборд. Skill намеренно входит в Composer-дистрибутив по пути `vendor/tikhomirov/moonshine-yandex-metrika/skills/moonshine-yandex-metrika/SKILL.md`.

Установите [`llm/skills`](https://github.com/roxblnfk/skills) в host-приложение, чтобы обнаруживать skill в Composer-зависимостях и синхронизировать его в каталог навыков агента:

```bash
composer require --dev llm/skills
composer skills:init --quick
composer skills:show
```

Пакет `llm/skills` по умолчанию находит файлы `SKILL.md` в Composer-зависимостях. Поэтому каталог `skills/` включён в дистрибутив этого пакета — инструкции для работы с дашбордом будут доступны AI-агентам host-приложения.

## Локализация

В дашборд входят переводы на **русский и английский языки**; язык выбирается по локали Laravel-приложения. Чтобы опубликовать файлы переводов для изменения в проекте:

```bash
php artisan vendor:publish --tag=moonshine-yandex-metrika-lang
```

## Дополнительно

- [История изменений](CHANGELOG.md)
- [Чеклист настройки и релиза](https://github.com/tikhomirov/moonshine-yandex-metrika/blob/main/docs/PRODUCTION_CHECKLIST.md)
- [Skill для дашборда](https://github.com/tikhomirov/moonshine-yandex-metrika/blob/main/skills/moonshine-yandex-metrika/SKILL.md)
- [Сообщить об ошибке](https://github.com/tikhomirov/moonshine-yandex-metrika/issues)

## Лицензия

MIT. Подробности — в файле [LICENSE](LICENSE).
