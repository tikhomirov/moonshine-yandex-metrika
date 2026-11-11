# MoonShine 5: CI и релиз v1.1.0

## Резюме

Поддержка MoonShine 5 при сохранении совместимости с MoonShine 4. Код страницы и сервиса менять не пришлось. Constraint: `moonshine/moonshine` `^4.0 || ^5.0`.

CI-матрица: MS4 (`^4.0`) на PHP 8.2–8.4 × Laravel 11–13 и MS5 (`5.x-dev`) на PHP 8.4 + Laravel 12/13.

Локально Pest на залоченном MoonShine 4.21.0: 3 passed. Браузерный смоук `/admin/page/metrika-dashboard-page` на MoonShine 4.19.5 и 5.x-dev — страница открывается (в local без токена показывается штатное предупреждение о `YANDEX_METRIKA_TOKEN` / `YANDEX_METRIKA_COUNTER_ID`).

## План

1. Починить CI: матрица Laravel 11 падала, потому что Composer 2.9+ блокирует пакеты с security advisories, а все релизы Laravel 11 (EOL) ими помечены (`--no-blocking` только в джобах 11.*).
2. Дождаться зелёного CI, влить PR в `main` (squash, линейная история репо).
3. Выпустить GitHub Release `v1.1.0` (предыдущий релиз — `v1.0.0`).

## Коммиты

- `3af2588` — feat: support MoonShine 5
- (этот коммит) — fix(ci)
