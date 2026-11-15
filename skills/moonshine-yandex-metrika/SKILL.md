---
name: moonshine-yandex-metrika
description: >-
  Configure, extend, debug, and release the tikhomirov/moonshine-yandex-metrika
  MoonShine dashboard (Yandex Metrika + Webmaster tabs, CSV, tab registry).
  Use when adding/editing Metrika menu pages, OAuth/env, widgets, Webmaster
  host_id, AnalyticsTabRegistry / Google Analytics tabs, exports, or packaging
  this Composer library.
---

# MoonShine Yandex Metrika package

Agent playbook for `tikhomirov/moonshine-yandex-metrika`.

## When to use

- Host app needs Metrika/Webmaster dashboard in MoonShine admin
- Wire OAuth token, counter_id, webmaster host_id
- Toggle widgets / period / CSV
- Add a third-party tab (e.g. Google Analytics) via registry
- Prepare Packagist release (slim dist via `.gitattributes`)

## Package layout (source repo)

| Path | Role |
|---|---|
| `src/Pages/MetrikaDashboardPage.php` | MoonShine page + tab host |
| `src/Tabs/*` | Built-in Metrika / Webmaster tabs |
| `src/Support/AnalyticsTabRegistry.php` | Tab registry |
| `src/Contracts/AnalyticsDashboardTab.php` | Tab contract |
| `src/Services/*` | Metrika, Webmaster, Account, Export, Probe |
| `config/moonshine-yandex-metrika.php` | Config keys |
| `routes/web.php` | CSV export routes |
| `resources/lang`, `resources/views` | i18n + Blade |
| `docs/` | OAuth screenshots, release checklist (not in Packagist dist) |
| `skills/` | This skill (**shipped in Packagist dist**) |

Composer auto-discovers `MoonshineYandexMetrikaServiceProvider`.

## Host-app setup (checklist)

1. `composer require tikhomirov/moonshine-yandex-metrika`
2. `php artisan vendor:publish --tag=moonshine-yandex-metrika-config`
3. Set env (never commit secrets):

```env
YANDEX_METRIKA_TOKEN=...
YANDEX_METRIKA_COUNTER_ID=...
YANDEX_METRIKA_DEFAULT_PRESET=today
YANDEX_WEBMASTER_HOST_ID=https:example.com:443
```

4. Register the page in the MoonShine 5 Layout / menu and custom page list:
   - `MenuItem::make(MetrikaDashboardPage::class, 'Яндекс.Метрика')`
5. Prefer thin subclass in host app if you need local overrides:

```php
final class MetrikaDashboardPage extends \Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage {}
```

6. Smoke: open admin page, switch Metrika ↔ Webmaster, change period, download CSV.

## Period behaviour (do not “fix” blindly)

- Metrika tab: period drives all widgets.
- Webmaster tab: period drives **search queries**; summary/indexation cards are not a full date-series reload of every KPI.
- Custom tabs: set `usesPeriod(): true` only if your `render()` uses `DatePeriod`.

## Extending with a new tab (preferred)

Implement `AnalyticsDashboardTab`, register in your ServiceProvider `boot()`:

```php
app(AnalyticsTabRegistry::class)->register(new GoogleAnalyticsTab());
```

Contract essentials:

- `id()` → query `?tab=`
- `requiresYandexConnection(): false` for non-Yandex sources so OAuth probe failure does not hide the tab
- `render(DatePeriod, widgets, maxResults): array` of MoonShine components
- Optional `exportCsvUrl(DatePeriod): ?string`

Legacy BC on subclass: `additionalAnalyticsTabs()` + `buildCustomTabContent()` with keys `uses_period`, `requires_yandex`, `export_csv_url`, `export_csv_label`.

Built-in tabs bind via `MetrikaDashboardTabHost` for Octane-safe rendering — do not replace that pattern casually.

## Widgets / config knobs

Config key prefix: `moonshine-yandex-metrika.*`

Toggle under `widgets.*` (`visits`, `pageviews`, `webmaster_summary`, `webmaster_queries`, `export`, `period_filter`, `account`, …).

Cache: `cache_ttl`, `cache_store`. Rate-limit friendly default is 3600s.

## CSV / routes

Loaded from package `routes/web.php` under MoonShine auth. Do not expose export routes publicly. Controllers: `ExportController` + `AnalyticsExportService`.

## Debugging

1. Empty KPIs → check token scopes (`metrika:read`), counter_id, cache flush
2. Webmaster empty → `YANDEX_WEBMASTER_HOST_ID` format `https:host:443`, scopes `webmaster:verify` / `hostinfo`
3. Alert only on Yandex tabs → expected if probe fails; non-Yandex tabs with `requiresYandexConnection(): false` stay usable
4. Stale PHP in Docker → recreate app container / clear opcache; config:clear does not reload `.env` into running containers

## Packaging / release (maintainers)

Dist must stay slim via `.gitattributes` `export-ignore`:

- Exclude: `tests/`, `docs/`, `.github/`, `phpunit.xml.dist`, `.env.example`, `composer.lock`
- Keep: `src/`, `config/`, `resources/`, `routes/`, `skills/`, `composer.json`, `LICENSE`, `README.md`, `CHANGELOG.md`

Before tag:

```bash
composer validate --strict
composer test
git archive --format=tar HEAD | tar -t | head -100   # no tests/docs/skills
```

See `docs/PRODUCTION_CHECKLIST.md`. Bump `CHANGELOG.md`, tag `vX.Y.Z`, push tags for Packagist.

## Do / don't

- **Do** put business overrides in host subclass or registry tabs — not by editing `vendor/`
- **Do** keep Russian UI strings consistent with existing lang files when adding widgets
- **Don't** commit OAuth tokens or put secrets in README examples as real values
- **Don't** ship docs/tests/CI inside the Composer dist; **do** ship `skills/`
- **Don't** assume Webmaster summary respects the same period semantics as Metrika visits

## Related reading

- Package `README.md` — install + OAuth + extension examples
- `docs/PRODUCTION_CHECKLIST.md` — release gate
- Host app (FamFi): `docs/ADMIN_PANEL.md`, `App\MoonShine\Pages\MetrikaDashboardPage`
