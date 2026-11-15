# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- KPI cards, the period picker, and the visits chart follow MoonShine light and dark color tokens instead of hardcoded white surfaces

## [1.2.0] - 2026-11-15

### Added

- Tab registry (`AnalyticsTabRegistry`) and `AnalyticsDashboardTab` contract for third-party tabs (e.g. Google Analytics)
- Built-in Metrika / Webmaster tabs as registry entries
- Legacy `additionalAnalyticsTabs()` keys: `uses_period`, `requires_yandex`, `export_csv_url`, `export_csv_label`
- `requiresYandexConnection()` so non-Yandex tabs work when OAuth probe fails
- `MetrikaDashboardTabHost` DI binding for built-in tab rendering (Octane-safe)
- Compact KPI cards (icon left, value + label)
- Period presets, CSV exports, Webmaster summary/queries, Yandex account block
- Agent skill under `skills/moonshine-yandex-metrika/` (included in Packagist dist)
- `.gitattributes` `export-ignore` for a slim Packagist dist (tests/docs/CI out; `skills/` in)

### Changed

- README rewritten for tabs, Webmaster, exports, and extension points
- `composer.json` production metadata (authors, homepage, support, stable minimum-stability)

## [1.1.1] - 2026-11

- MoonShine 5 compatibility notes and LineChartComponent `make()` fix

## [1.1.0] - 2026-11

- MoonShine 5 support

## [1.0.0] - 2026-10

- Initial Metrika dashboard release
