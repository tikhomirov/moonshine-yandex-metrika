# Настройки подключения Яндекса в БД и OAuth flow

## Резюме

Раньше пакет брал токен, счётчик и хост только из env. Теперь:

- **Хранение.** Появилась необязательная миграция `yandex_metrika_settings` (одна строка). В ней хранятся `client_id`, `client_secret` и `token` (последние два через `encrypted` cast), `counter_id`, `host_id`, `scopes` и `token_obtained_at`.
- **Резолвер.** Все настройки читает `Support\YandexSettings`: сначала БД, потом env/config. Через него работают `MetrikaService`, `WebmasterService`, `YandexAccountService` и `ConnectionProbe`. Если таблицы нет или APP_KEY не расшифровывает секрет, используется env. При сохранении сбрасываются кэши probe и профиля и инстансы сервисов. Ключ кэша Вебмастера теперь учитывает хэш токена.
- **Страница «Подключение Яндекса»** (`YandexConnectionPage`) регистрируется автоматически после boot приложения. Что на ней:
  - ClientID со ссылкой на `oauth.yandex.ru/client/<id>`;
  - источник значения (БД или env);
  - маски секретов (`••••1234`);
  - выданные и недостающие права и Redirect URI;
  - форма настроек (пустые поля секретов значения не меняют);
  - кнопка «Получить токен» или «Переподключить» и сброс к env.
- **OAuth.** `Support\YandexOAuthFlow` реализует authorization code flow с `state` (`hash_equals`, одноразовый, хранится в сессии) и PKCE S256. Запрашиваются права из `oauth_scopes`. Code обменивается на токен через `oauth.yandex.ru/token` с Basic auth. Роуты лежат под `moonshine` + `Authenticate`, callback: `/{prefix}/yandex-analytics/oauth/callback`.
- **Дашборд.** Если Client secret сохранён, кнопка «Переподключить Яндекс» под алертами ведёт в flow пакета. Если нет, остаётся прежний выпуск токена на стороне Яндекса с подсказкой про env. Добавлена ссылка на страницу подключения.
- **Artisan:** `yandex-metrika:connection:set` (`--ask-secret` и `--ask-token` спрашивают без эха), `:status` (без секретов), `:clear`.
- **Обратная совместимость.** Без `migrate` и сохранённых настроек всё работает по env. Новые ключи конфига необязательные.

## План

1. Миграция и модель `YandexMetrikaSetting` с encrypted cast.
2. Резолвер `YandexSettings`, перевод на него четырёх сервисов.
3. `YandexOAuthFlow` и `ConnectionController` (save/clear/redirect/callback), роуты.
4. `YandexConnectionPage`, автозагрузка страницы, обновление действий на дашборде.
5. Три artisan-команды.
6. Тесты:
   - работа по env без таблицы;
   - приоритет БД и шифрование;
   - недостающие права;
   - страница без утечки секретов;
   - сохранение формы;
   - authorize URL;
   - неверный state;
   - обмен code (Http::fake);
   - команды и роуты.
7. README (EN/RU) с Redirect URI, CHANGELOG.

## Правки по ревью

- Страницы подключения нет в меню. Вход через кнопку «Настройки подключения» в шапке дашборда (рядом с CSV) и через кнопку в алертах. На странице есть «← Назад к Яндекс.Метрике» и хлебные крошки через дашборд.
- Все ссылки на oauth.yandex.ru и кнопки «Получить токен» / «Переподключить» открываются с `target="_blank" rel="noopener noreferrer"`. После OAuth сообщение предлагает закрыть вкладку. В тестах есть проверка, что ни одна такая ссылка не открывается в текущей вкладке.
- client_id в шапке стал видимой ссылкой (`ym-ext-link`, цвет `--ms-cm-primary`, подчёркивание, иконка внешней ссылки).
- «Токен получен» показывается в `app.timezone` в формате `d.m.Y H:i (пояс)`, это `YandexSettings::formatDate()`.
- `guzzlehttp/guzzle: ^7.0 || ^8.0`. Пакет использует Guzzle напрямую (`Client`, `GuzzleException`, `Psr7\Query`). Полный набор тестов проходит на Guzzle 8.5.2 + Laravel 13. Добавлен тест на реальных клиентах с ошибкой соединения и job в CI: Laravel 13 + Guzzle 8.

## Коммиты

- `1e3fa61` — feat: Yandex connection settings in the database with OAuth code flow
- `ee7cd98` — feat: connection settings button, Yandex links in new tab, guzzle 8 support
