# Обновление README и обложка

## Резюме

- Бейджи в одном стиле (shields.io, `for-the-badge`, высота 28 px): Packagist, PHP, Laravel, MoonShine, Tests (`github/actions/workflow/status`, `tests.yml`, ветка main).
- Обложка 1280×640 для GitHub social preview: `.github/cover.png`, исходник `.github/cover.svg` (тёмный фон, название, подзаголовок, столбики графика, без логотипа Яндекса). Вставлена вверху README вместо заголовка.
- README (EN/RU) переписан под v1.4.0 короче: подключение в 3 шага с OAuth, Redirect URI, права, настройки в БД с fallback на env, автовыбор счётчика и сайта по `APP_URL`, artisan-команды, `summary_metrics`. Убраны устаревшие разделы (строка OAuth-приложения в шапке, ручная выдача токена через `verification_code` как основной путь, длинные пояснения).
- Скрины по демо-стенду: `art/dashboard-preview.jpg` (обновлён) и `art/connection-steps.jpg` (новый). На скрине подключения строки с токеном и плейсхолдер секрета убраны, масок нет.

## План

1. Бейджи → shields.io `for-the-badge`, проверить высоту.
2. SVG-обложка → PNG через headless Chromium, проверить размер.
3. Переписать README EN/RU, снять скрины со стенда без токенов.
4. Коммит в main, CI.

## Коммиты

- `381e9bc` — docs: refresh README for v1.4.0, unified badges and cover
