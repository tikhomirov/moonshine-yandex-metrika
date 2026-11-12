# Исправление фатала LineChartComponent (v1.1.1)

## Резюме

Дашборд Метрики падал с фатальной ошибкой при включённом виджете `visits` (включён по умолчанию):
`LineChartComponent::make(array $chartData)` несовместим с родительским `MoonShineComponent::make(...$arguments)`.
Ошибка воспроизводилась на MoonShine 4.21.0 и 5.0.0. Существующие тесты её не ловили — они только создавали страницу, но не рисовали её.

## План

1. Убрать собственный `make()`, использовать унаследованный `make(...$arguments)` из MoonShine; конструктор — `__construct(array $chartData = [])`. Вызов `LineChartComponent::make($chartData)` работает как раньше.
2. Добавить тест `tests/Feature/DashboardRenderTest.php`: рендер графика и всех компонентов дашборда с подменённым `MetrikaService` (без запросов к API).
3. Прогнать Pest и PHPStan (larastan, level 5) на MoonShine 5.0.0 и 4.21.0 + Laravel 13. На старом коде новый тест падает с тем же фаталом, на новом — 6 passed.
4. PR → squash в `main` → релиз `v1.1.1`.

## Коммиты

- 38758e6 — fix: LineChartComponent uses inherited make(), add dashboard render test
