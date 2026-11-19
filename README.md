<p align="center">
  <strong>Yandex Metrika and Webmaster analytics inside MoonShine</strong><br>
  Traffic, search queries, and indexing status — in one dashboard.
</p>

<p align="center">
  <a href="README.ru.md">Русская версия</a>
</p>

<h1 align="center">MoonShine Yandex Metrika</h1>

<p align="center">
  <a href="https://packagist.org/packages/tikhomirov/moonshine-yandex-metrika"><img src="https://img.shields.io/packagist/v/tikhomirov/moonshine-yandex-metrika?style=for-the-badge" alt="Packagist version"></a>
  <img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.4+">
  <img src="https://img.shields.io/badge/MoonShine-5.x-6C4AB6?style=for-the-badge" alt="MoonShine 5.x">
  <a href="https://github.com/tikhomirov/moonshine-yandex-metrika/actions/workflows/tests.yml"><img src="https://github.com/tikhomirov/moonshine-yandex-metrika/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
</p>

<p align="center">
  <img src="https://raw.githubusercontent.com/tikhomirov/moonshine-yandex-metrika/main/art/dashboard-preview.jpg" alt="MoonShine dashboard with Yandex Metrika and Webmaster tabs, period filters, KPI cards, and a visits chart" width="100%">
</p>

## Features

- **Yandex Metrika:** visits, page views, unique visitors, bounce rate, daily chart, and top pages.
- **Yandex Webmaster:** indexing summary, site issues, external links, and popular search queries.
- **Flexible periods:** presets and custom date ranges.
- **CSV exports** for Metrika statistics and Webmaster queries.
- Yandex account information and API response caching.
- An extensible tab registry for adding third-party analytics without forking the package.
- Russian and English UI translations.

## Requirements

| Component | Version |
|---|---|
| PHP | 8.4+ |
| Laravel | 12 or 13 |
| MoonShine | 5.x |

## Installation

```bash
composer require tikhomirov/moonshine-yandex-metrika
php artisan vendor:publish --tag=moonshine-yandex-metrika-config
```

Register the page in your MoonShine menu and add it to the custom pages for your layout/provider:

```php
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;

MenuItem::make(MetrikaDashboardPage::class, 'Yandex Metrika');
```

## Yandex setup

Create an application at [Yandex OAuth](https://oauth.yandex.ru/), set the redirect URI to `https://oauth.yandex.ru/verification_code`, and grant the scopes you need:

| Scope | Purpose |
|---|---|
| `metrika:read` | Metrika statistics |
| `webmaster:verify` | Webmaster indexing and search queries |
| `webmaster:hostinfo` | Host details and external links |
| `login:info`, `login:email`, `login:avatar` | Yandex account email and avatar |

Add your settings to `.env`:

```dotenv
YANDEX_METRIKA_TOKEN=your_oauth_token
YANDEX_METRIKA_COUNTER_ID=your_counter_id
YANDEX_METRIKA_DEFAULT_PRESET=today
YANDEX_WEBMASTER_HOST_ID=https:example.com:443
```

`YANDEX_WEBMASTER_HOST_ID` is required for Webmaster and must use the host format shown in Yandex Webmaster. Metrika keeps working without it.

### OAuth app in the dashboard

The dashboard header shows the `client_id` of the OAuth app that issued the token, linked to its page on oauth.yandex.ru. The `client_id` is read from login.yandex.ru/info for the current token; set `YANDEX_OAUTH_CLIENT_ID` to override it. Secrets are never shown.

When a Webmaster scope is missing, the alert offers two actions:

- **Open the app and add the scope**: opens `https://oauth.yandex.ru/client/edit/<client_id>`.
- **Reconnect Yandex**: opens the Yandex consent screen for this app and issues a new token. Yandex revokes old tokens when app scopes change. The package reads the token only from `YANDEX_METRIKA_TOKEN`, so put the new token into `.env` and run `php artisan config:cache`. With the redirect URI `https://oauth.yandex.ru/verification_code`, Yandex shows the token on the page.

## KPI cards

The Metrika tab shows four summary cards. Their set and order are controlled by `summary_metrics` in the published config. The default is visits, page views, unique visitors, bounce rate. Unknown keys are ignored.

```php
'summary_metrics' => [
    'unique_visitors',
    'visits',
    'pageviews',
    'bounce_rate',
],
```

The `widgets` map still turns other dashboard blocks on or off. Setting a widget to `false` also hides the matching KPI card.

## Periods and exports


Available presets: today, yesterday, week, month, quarter, half year, and year, plus a custom date range. The Webmaster summary shows the site's current state; the selected period applies to search queries.

CSV export routes are available to authenticated MoonShine administrators:

- `/admin/yandex-analytics/export/metrika`
- `/admin/yandex-analytics/export/webmaster`

## AI agent skill

This package includes a ready-to-use **AI agent skill** for configuring, extending, and troubleshooting the dashboard. It is intentionally shipped in the Composer distribution at `vendor/tikhomirov/moonshine-yandex-metrika/skills/moonshine-yandex-metrika/SKILL.md`.

Install [`llm/skills`](https://github.com/roxblnfk/skills) in the host application to discover the skill from Composer dependencies and sync it into your agent's skills directory:

```bash
composer require --dev llm/skills
composer skills:init --quick
composer skills:show
```

The skill package auto-discovers `SKILL.md` files in Composer dependencies by default. Keeping this package's `skills/` directory in its distribution makes the dashboard instructions available to coding agents in the consuming application.

## Localization

The dashboard ships with **English and Russian translations** and follows the Laravel application's locale. To publish the translation files for customization:

```bash
php artisan vendor:publish --tag=moonshine-yandex-metrika-lang
```

## More information

- [Changelog](CHANGELOG.md)
- [Production checklist](https://github.com/tikhomirov/moonshine-yandex-metrika/blob/main/docs/PRODUCTION_CHECKLIST.md)
- [Dashboard skill](https://github.com/tikhomirov/moonshine-yandex-metrika/blob/main/skills/moonshine-yandex-metrika/SKILL.md)
- [Report an issue](https://github.com/tikhomirov/moonshine-yandex-metrika/issues)

## License

MIT. See [LICENSE](LICENSE).
