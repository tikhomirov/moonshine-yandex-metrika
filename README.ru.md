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
