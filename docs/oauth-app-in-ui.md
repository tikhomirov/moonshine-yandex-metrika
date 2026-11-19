# OAuth-приложение и исправление прав из UI

## Резюме

Раньше при нехватке прав вкладка Вебмастера показывала только алерт «нужен scope webmaster:hostinfo». Было непонятно, какое OAuth-приложение используется, и исправить проблему из админки было нельзя.

Теперь:

- В шапке дашборда под аккаунтом выводится `OAuth-приложение: <client_id>` со ссылкой на `https://oauth.yandex.ru/client/<client_id>`.
- Под алертами о нехватке прав (внешние ссылки, сбой summary Вебмастера) появились кнопки:
  - «Открыть приложение и добавить право …» ведёт на `https://oauth.yandex.ru/client/edit/<client_id>`.
  - «Переподключить Яндекс» ведёт на `https://oauth.yandex.ru/authorize?response_type=token&client_id=<client_id>&force_confirm=yes`, где выпускается новый токен.
- Подсказка объясняет, что токен задаётся только в `YANDEX_METRIKA_TOKEN`. Новый токен нужно вставить в `.env` и выполнить `config:cache`. Своего OAuth callback в пакете нет, и он не добавлялся.
- `client_id` берётся из поля `client_id` ответа `login.yandex.ru/info` для текущего токена. Его можно переопределить через `YANDEX_OAUTH_CLIENT_ID`. Если `client_id` неизвестен, вместо этих кнопок показываются ссылка на список приложений и подсказка про env.
- Выводится только `client_id`, он публичный. Токен и `client_secret` не выводятся.

## План

1. `Support\YandexOAuthApp`: строит ссылки по `client_id` и отбрасывает некорректные значения.
2. `YandexAccountService`: добавлены `client_id` в `profile()` и метод `oauthApp()` (конфиг важнее профиля).
3. `MetrikaDashboardPage`: строка приложения в шапке и `buildOAuthScopeActions()` под алертами.
4. Конфиг `oauth_client_id`, строки ru/en, README (EN/RU), CHANGELOG.
5. Тесты: ссылки, приоритет конфига, шапка, алерты с известным и неизвестным `client_id`, токен не утекает в HTML.
6. PR без мержа и релиза.

## Коммиты

- см. PR `feat/oauth-app-in-ui`
