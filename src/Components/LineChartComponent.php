<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Components;

use MoonShine\UI\Components\MoonShineComponent;

/**
 * Lightweight Chart.js line chart component.
 * Renders visits-by-day data using Canvas + Chart.js CDN.
 */
class LineChartComponent extends MoonShineComponent
{
    protected string $view = 'moonshine-yandex-metrika::components.line-chart';

    /**
     * @var list<array{dimension: string, metrics: list<float|int>}>
     */
    protected array $chartData = [];

    /**
     * Use the inherited MoonShine `make(...$arguments)`: `LineChartComponent::make($chartData)`.
     * A narrower `make(array $chartData)` override is incompatible with the parent
     * signature and causes a fatal error when the class is loaded.
     *
     * @param  list<array{dimension: string, metrics: list<float|int>}>  $chartData
     */
    final public function __construct(array $chartData = [])
    {
        parent::__construct();
        $this->chartData = $chartData;
    }

    /**
     * @return list<string>
     */
    public function getLabels(): array
    {
        return array_column($this->chartData, 'dimension');
    }

    /**
     * @return list<float|int>
     */
    public function getValues(): array
    {
        return array_map(fn (array $row) => $row['metrics'][0] ?? 0, $this->chartData);
    }

    protected function viewData(): array
    {
        return [
            'labels' => json_encode($this->getLabels()),
            'values' => json_encode($this->getValues()),
            'chartId' => 'metrika_chart_'.uniqid(),
        ];
    }
}
