# Вертикальные отступы на дашборде Метрики

## Резюме

Кастомные страницы MoonShine рендерят `components()` без `.space-elements`, поэтому соседние Alert/Box/Grid «липнут» ([moonshine#2108](https://github.com/moonshine-software/moonshine/issues/2108)). Раньше обёртка `Div.space-elements` стояла только вокруг контента вкладки. Теперь весь результат `components()` (хуки `beforeComponents`/`extraComponents`, тулбар и вкладка) оборачивается один раз трейтом `WrapsPageComponentsInSpaceElements`. Класс на каждый блок вешать не нужно. Заголовок страницы остаётся в лейауте, снаружи обёртки.

Трейт можно удалить, если в ядре MoonShine появится opt-in на корне контента.

## План

1. Добавить трейт с `wrapInSpaceElements()`.
2. Поднять обёртку на весь `components()`, убрать внутреннюю вокруг вкладки.
3. Тест: `getComponents()` возвращает один `Div` с классом `space-elements`.
4. PR в пакет, без мержа и релиза.

## Коммиты

- (заполнится после коммита)
