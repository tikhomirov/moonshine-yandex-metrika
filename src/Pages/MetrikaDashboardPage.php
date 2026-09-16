<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Pages;

use MoonShine\Laravel\Pages\Page;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\UI\Components\Metrics\Wrapped\ValueMetric;
use MoonShine\UI\Components\Layout\Grid;
use MoonShine\UI\Components\Layout\Column;
use MoonShine\UI\Components\Layout\Box;
use MoonShine\UI\Components\Table\TableBuilder;
use MoonShine\UI\Fields\Text;
use MoonShine\UI\Fields\Number;
use MoonShine\Support\Enums\Color;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Components\LineChartComponent;

class MetrikaDashboardPage extends Page
{
    protected string $pageIcon = 'presentation-chart-bar';

    protected MetrikaService $metrika;

    public function __construct()
    {
        parent::__construct();
        $this->metrika = app(MetrikaService::class);
    }

    public function getBreadcrumbs(): array
    {
        return ['#' => $this->getTitle()];
    }

    public function getTitle(): string
    {
        return config('moonshine-yandex-metrika.page_title', 'Яндекс.Метрика');
    }

    /**
     * @return list<ComponentContract>
     */
    protected function components(): iterable
    {
        $widgets  = config('moonshine-yandex-metrika.widgets', []);
        $days     = (int) config('moonshine-yandex-metrika.days', 30);
        $maxRes   = (int) config('moonshine-yandex-metrika.max_results', 10);
        $components = [];

        // ── Summary value metrics ──────────────────────────────────────────
        $summaryMetrics = [];

        if ($widgets['visits'] ?? true) {
            $summaryMetrics[] = ValueMetric::make(__('moonshine-yandex-metrika::metrics.visits'))
                ->value($this->metrika->totalVisits($days))
                ->icon('chart-bar')
                ->iconColor(Color::PRIMARY)
                ->columnSpan(3);
        }

        if ($widgets['pageviews'] ?? true) {
            $summaryMetrics[] = ValueMetric::make(__('moonshine-yandex-metrika::metrics.pageviews'))
                ->value($this->metrika->totalPageViews($days))
                ->icon('document-chart-bar')
                ->iconColor(Color::SUCCESS)
                ->columnSpan(3);
        }

        if ($widgets['unique_visitors'] ?? true) {
            $summaryMetrics[] = ValueMetric::make(__('moonshine-yandex-metrika::metrics.unique_visitors'))
                ->value($this->metrika->uniqueVisitors($days))
                ->icon('chart-pie')
                ->iconColor(Color::INFO)
                ->columnSpan(3);
        }

        if ($widgets['bounce_rate'] ?? true) {
            $summaryMetrics[] = ValueMetric::make(__('moonshine-yandex-metrika::metrics.bounce_rate'))
                ->value($this->metrika->bounceRate($days) . '%')
                ->icon('presentation-chart-line')
                ->iconColor(Color::WARNING)
                ->columnSpan(3);
        }

        if ($summaryMetrics) {
            $components[] = Grid::make($summaryMetrics);
        }

        // ── Visits chart ───────────────────────────────────────────────────
        if ($widgets['visits'] ?? true) {
            $chartData = $this->metrika->visitsByDay($days);
            $components[] = Box::make(
                __('moonshine-yandex-metrika::metrics.visits_by_day'),
                [LineChartComponent::make($chartData)]
            );
        }

        // ── Tables grid ────────────────────────────────────────────────────
        $tableColumns = [];

        if ($widgets['top_pages'] ?? true) {
            $tableColumns[] = Column::make([
                Box::make(
                    __('moonshine-yandex-metrika::metrics.top_pages'),
                    [$this->buildTopPagesTable($maxRes, $days)]
                ),
            ])->columnSpan(6);
        }

        if ($widgets['traffic_sources'] ?? true) {
            $tableColumns[] = Column::make([
                Box::make(
                    __('moonshine-yandex-metrika::metrics.traffic_sources'),
                    [$this->buildTrafficSourcesTable($maxRes, $days)]
                ),
            ])->columnSpan(6);
        }

        if ($tableColumns) {
            $components[] = Grid::make($tableColumns);
        }

        // ── Search phrases & Geo & Browsers ───────────────────────────────
        $bottomColumns = [];

        if ($widgets['search_phrases'] ?? true) {
            $bottomColumns[] = Column::make([
                Box::make(
                    __('moonshine-yandex-metrika::metrics.search_phrases'),
                    [$this->buildSearchPhrasesTable($maxRes, $days)]
                ),
            ])->columnSpan(4);
        }

        if ($widgets['geo'] ?? true) {
            $bottomColumns[] = Column::make([
                Box::make(
                    __('moonshine-yandex-metrika::metrics.geo'),
                    [$this->buildGeoTable($maxRes, $days)]
                ),
            ])->columnSpan(4);
        }

        if ($widgets['browsers'] ?? true) {
            $bottomColumns[] = Column::make([
                Box::make(
                    __('moonshine-yandex-metrika::metrics.browsers'),
                    [$this->buildBrowsersTable($maxRes, $days)]
                ),
            ])->columnSpan(4);
        }

        if ($bottomColumns) {
            $components[] = Grid::make($bottomColumns);
        }

        return $components;
    }

    // ─── Table builders ────────────────────────────────────────────────────

    protected function buildTopPagesTable(int $maxResults, int $days): TableBuilder
    {
        $rows = collect($this->metrika->topPages($days, $maxResults))
            ->map(fn ($r) => ['page' => $r['dimension'], 'views' => $r['metrics'][0] ?? 0]);

        return TableBuilder::make(fields: [
            Text::make(__('moonshine-yandex-metrika::metrics.page'), 'page'),
            Number::make(__('moonshine-yandex-metrika::metrics.views'), 'views'),
        ])->items($rows)->simple();
    }

    protected function buildTrafficSourcesTable(int $maxResults, int $days): TableBuilder
    {
        $rows = collect($this->metrika->trafficSources($days, $maxResults))
            ->map(fn ($r) => [
                'source'  => $r['dimension'],
                'visits'  => $r['metrics'][0] ?? 0,
                'users'   => $r['metrics'][1] ?? 0,
            ]);

        return TableBuilder::make(fields: [
            Text::make(__('moonshine-yandex-metrika::metrics.source'), 'source'),
            Number::make(__('moonshine-yandex-metrika::metrics.visits'), 'visits'),
            Number::make(__('moonshine-yandex-metrika::metrics.users'), 'users'),
        ])->items($rows)->simple();
    }

    protected function buildSearchPhrasesTable(int $maxResults, int $days): TableBuilder
    {
        $rows = collect($this->metrika->searchPhrases($days, $maxResults))
            ->map(fn ($r) => ['phrase' => $r['dimension'], 'visits' => $r['metrics'][0] ?? 0]);

        return TableBuilder::make(fields: [
            Text::make(__('moonshine-yandex-metrika::metrics.phrase'), 'phrase'),
            Number::make(__('moonshine-yandex-metrika::metrics.visits'), 'visits'),
        ])->items($rows)->simple();
    }

    protected function buildGeoTable(int $maxResults, int $days): TableBuilder
    {
        $rows = collect($this->metrika->geoCountries($days, $maxResults))
            ->map(fn ($r) => ['country' => $r['dimension'], 'visits' => $r['metrics'][0] ?? 0]);

        return TableBuilder::make(fields: [
            Text::make(__('moonshine-yandex-metrika::metrics.country'), 'country'),
            Number::make(__('moonshine-yandex-metrika::metrics.visits'), 'visits'),
        ])->items($rows)->simple();
    }

    protected function buildBrowsersTable(int $maxResults, int $days): TableBuilder
    {
        $rows = collect($this->metrika->browsers($days, $maxResults))
            ->map(fn ($r) => ['browser' => $r['dimension'], 'visits' => $r['metrics'][0] ?? 0]);

        return TableBuilder::make(fields: [
            Text::make(__('moonshine-yandex-metrika::metrics.browser'), 'browser'),
            Number::make(__('moonshine-yandex-metrika::metrics.visits'), 'visits'),
        ])->items($rows)->simple();
    }
}
