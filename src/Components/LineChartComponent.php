<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Components;

use MoonShine\UI\Components\MoonShineComponent;
use Illuminate\Contracts\View\View;

/**
 * Lightweight Chart.js line chart component.
 * Renders visits-by-day data using Canvas + Chart.js CDN.
 */
class LineChartComponent extends MoonShineComponent
{
    protected string $view = 'moonshine-yandex-metrika::components.line-chart';

    protected array $chartData = [];

    /**
     * Use the inherited MoonShine `make(...$arguments)`: `LineChartComponent::make($chartData)`.
     * A narrower `make(array $chartData)` override is incompatible with the parent
     * signature and causes a fatal error when the class is loaded.
     */
    final public function __construct(array $chartData = [])
    {
        parent::__construct();
        $this->chartData = $chartData;
    }

    public function getLabels(): array
    {
        return array_column($this->chartData, 'dimension');
    }

    public function getValues(): array
    {
        return array_map(fn ($row) => $row['metrics'][0] ?? 0, $this->chartData);
    }

    protected function viewData(): array
    {
        return [
            'labels' => json_encode($this->getLabels()),
            'values' => json_encode($this->getValues()),
            'chartId' => 'metrika_chart_' . uniqid(),
        ];
    }
}
