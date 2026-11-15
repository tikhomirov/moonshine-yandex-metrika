<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Pages;

use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Laravel\Pages\Page;
use MoonShine\Support\Enums\Color;
use MoonShine\UI\Components\Alert;
use MoonShine\UI\Components\FlexibleRender;
use MoonShine\UI\Components\Layout\Box;
use MoonShine\UI\Components\Layout\Column;
use MoonShine\UI\Components\Layout\Div;
use MoonShine\UI\Components\Layout\Divider;
use MoonShine\UI\Components\Layout\Grid;
use MoonShine\UI\Components\Metrics\Wrapped\ValueMetric;
use MoonShine\UI\Components\Table\TableBuilder;
use MoonShine\UI\Fields\Number;
use MoonShine\UI\Fields\Text;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

class MetrikaDashboardPage extends Page
{
    public const TAB_METRIKA = 'metrika';

    public const TAB_WEBMASTER = 'webmaster';

    protected string $pageIcon = 'presentation-chart-bar';

    protected ?MetrikaService $metrika = null;

    protected ?WebmasterService $webmaster = null;

    protected ?YandexAccountService $account = null;

    protected function booted(): void
    {
        parent::booted();

        $this->metrika = app(MetrikaService::class);
        $this->webmaster = app(WebmasterService::class);
        $this->account = app(YandexAccountService::class);
    }

    public function getBreadcrumbs(): array
    {
        return ['#' => $this->getTitle()];
    }

    public function getTitle(): string
    {
        return (string) config('moonshine-yandex-metrika.page_title', 'Яндекс.Метрика');
    }

    /**
     * @return list<ComponentContract>
     */
    protected function components(): iterable
    {
        $probe = app(ConnectionProbe::class)->check();

        if (! $probe->isOk()) {
            return [
                Alert::make('exclamation-triangle', 'warning')
                    ->content($probe->message),
                ...$this->extraComponents(DatePeriod::today()),
            ];
        }

        $period = $this->resolvePeriod();
        $tab = $this->resolveTab();
        $widgets = config('moonshine-yandex-metrika.widgets', []);
        $maxRes = (int) config('moonshine-yandex-metrika.max_results', 10);

        // MoonShine рендерит components() без .space-elements — соседние Alert/Box/FlexibleRender
        // «липнут». Оборачиваем контент вкладки в Div.space-elements (см. common.css).
        return [
            ...$this->beforeComponents($period),
            $this->buildToolbar($period, $tab),
            Div::make($this->buildTabContent($tab, $period, $widgets, $maxRes))
                ->class('space-elements'),
            ...$this->extraComponents($period),
        ];
    }

    /**
     * Расширяемый список вкладок дашборда (Метрика, Вебмастер, …).
     *
     * @return list<array{id: string, label: string, icon: string, enabled: bool}>
     */
    protected function analyticsTabs(): array
    {
        $widgets = config('moonshine-yandex-metrika.widgets', []);

        return [
            [
                'id' => self::TAB_METRIKA,
                'label' => (string) __('moonshine-yandex-metrika::metrics.tab_metrika'),
                'icon' => 'chart-bar',
                'enabled' => true,
            ],
            [
                'id' => self::TAB_WEBMASTER,
                'label' => (string) __('moonshine-yandex-metrika::metrics.tab_webmaster'),
                'icon' => 'magnifying-glass',
                'enabled' => ($widgets['webmaster_summary'] ?? true)
                    || ($widgets['webmaster_queries'] ?? true),
            ],
        ];
    }

    protected function resolveTab(): string
    {
        $requested = (string) request()->query('tab', self::TAB_METRIKA);
        $enabled = collect($this->analyticsTabs())
            ->filter(static fn (array $tab): bool => $tab['enabled'])
            ->pluck('id')
            ->all();

        if (in_array($requested, $enabled, true)) {
            return $requested;
        }

        return $enabled[0] ?? self::TAB_METRIKA;
    }

    /**
     * Контент активной вкладки. Переопределяйте для своих табов (GSC и т.п.).
     *
     * @param  array<string, bool>  $widgets
     * @return list<ComponentContract>
     */
    protected function buildTabContent(
        string $tab,
        DatePeriod $period,
        array $widgets,
        int $maxRes,
    ): array {
        return match ($tab) {
            self::TAB_WEBMASTER => $this->buildWebmasterSection(
                $this->webmaster->forPeriod($period),
                $period,
                $widgets,
                $maxRes,
            ),
            self::TAB_METRIKA => $this->buildMetrikaSection(
                $this->metrika->forPeriod($period),
                $widgets,
                $maxRes,
            ),
            default => $this->buildCustomTabContent($tab, $period, $widgets, $maxRes),
        };
    }

    /**
     * Хук для дополнительных вкладок в наследниках пакета.
     *
     * @param  array<string, bool>  $widgets
     * @return list<ComponentContract>
     */
    protected function buildCustomTabContent(
        string $tab,
        DatePeriod $period,
        array $widgets,
        int $maxRes,
    ): array {
        return [];
    }

    /**
     * Hook for host apps before main widgets.
     *
     * @return list<ComponentContract>
     */
    protected function beforeComponents(DatePeriod $period): array
    {
        return [];
    }

    /**
     * Hook for host apps after main widgets.
     *
     * @return list<ComponentContract>
     */
    protected function extraComponents(DatePeriod $period): array
    {
        return [];
    }

    protected function resolvePeriod(): DatePeriod
    {
        $request = request();
        $defaultPreset = (string) config('moonshine-yandex-metrika.default_preset', DatePeriod::PRESET_TODAY);

        return DatePeriod::fromRequest(
            $request->query('preset', $defaultPreset),
            $request->query('date_from'),
            $request->query('date_to'),
        );
    }

    /**
     * @param  array<string, scalar|null>  $extra
     * @return array<string, scalar|null>
     */
    protected function pageQuery(DatePeriod $period, string $tab, array $extra = []): array
    {
        return array_filter([
            ...$period->toQuery(),
            'tab' => $tab,
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function buildToolbar(DatePeriod $period, string $tab): FlexibleRender
    {
        $account = config('moonshine-yandex-metrika.widgets.account', true)
            ? $this->account->profile()
            : null;

        $accountHtml = '';

        if ($account && ($account['avatar_url'] || $account['email'] || $account['login'])) {
            $avatar = $account['avatar_url']
                ? '<img src="'.e($account['avatar_url']).'" alt="" class="h-10 w-10 rounded-full object-cover" width="40" height="40">'
                : '';
            $name = e($account['email'] ?? $account['login'] ?? 'Yandex');
            $accountHtml = '<div class="flex items-center gap-3">'
                .$avatar
                .'<div class="leading-tight"><div class="text-sm font-medium">'.$name.'</div>'
                .'<div class="text-xs opacity-70">'.e(__('moonshine-yandex-metrika::metrics.account_source')).'</div>'
                .'</div></div>';
        }

        $exportHtml = '';

        if (config('moonshine-yandex-metrika.widgets.export', true)) {
            $query = $period->toQuery();

            if ($tab === self::TAB_WEBMASTER) {
                $exportUrl = e(route('moonshine-yandex-metrika.export.webmaster', $query));
                $exportLabel = e(__('moonshine-yandex-metrika::metrics.export_webmaster_csv'));
            } else {
                $exportUrl = e(route('moonshine-yandex-metrika.export.metrika', $query));
                $exportLabel = e(__('moonshine-yandex-metrika::metrics.export_metrika_csv'));
            }

            $exportHtml = '<a href="'.$exportUrl.'" class="btn btn-secondary btn-sm ym-export" title="'
                .$exportLabel.'">'
                .$this->tabIconSvg('arrow-down-tray')
                .'<span>'.$exportLabel.'</span>'
                .'</a>';
        }

        $tabsHtml = $this->renderTabsHtml($period, $tab);
        // Период влияет на всю Метрику; у Вебмастера — только на «Популярные запросы» (ниже).
        $periodHtml = $tab === self::TAB_METRIKA
            ? $this->renderPeriodControlsHtml($period, $tab)
            : '';

        $html = <<<HTML
<style>
  .ym-toolbar { display:flex; flex-direction:column; gap:.875rem; margin:0 0 1rem; }
  .ym-toolbar__top { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:.75rem; }
  .ym-toolbar__presets { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; }
  .ym-export {
    display:inline-flex !important;
    align-items:center;
    gap:.4rem;
  }
  .ym-export svg {
    width:1rem;
    height:1rem;
    flex:0 0 auto;
  }
  .ym-tabs {
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:.75rem;
    padding:.25rem 0 1rem;
    margin:0 0 .25rem;
    border-bottom:1px solid rgba(15,23,42,.12);
  }
  .ym-tabs .ym-tab {
    display:inline-flex !important;
    align-items:center;
    gap:.55rem;
    min-height:50px !important;
    height:auto !important;
    padding:.85rem 1.4rem !important;
    border-radius:12px !important;
    font-size:16px !important;
    font-weight:700 !important;
    line-height:1.2 !important;
  }
  .ym-tabs .ym-tab svg {
    width:1.35rem;
    height:1.35rem;
    flex:0 0 auto;
  }
  .ym-period { display:flex; flex-direction:column; gap:.75rem; }
  .ym-period__hint { font-size:13px; opacity:.7; margin:0; }
  .ym-toolbar__period {
    display:inline-flex;
    flex-wrap:wrap;
    align-items:center;
    gap:.5rem;
    width:fit-content;
    max-width:100%;
    padding:6px;
    border:1px solid rgba(15,23,42,.08);
    border-radius:12px;
    background:#fff;
    box-shadow:0 1px 2px rgba(15,23,42,.04);
  }
  @media (max-width: 420px) {
    .ym-toolbar__period input[type="date"].ym-date { width:132px; }
  }
  .ym-toolbar__period input[type="date"].ym-date {
    all: unset;
    box-sizing:border-box;
    display:inline-flex;
    align-items:center;
    width:150px;
    height:36px;
    padding:0 10px;
    border:1px solid rgba(15,23,42,.12);
    border-radius:8px;
    background:#fff;
    font: inherit;
    font-size:14px;
    line-height:36px;
    color: inherit;
  }
  .ym-toolbar__period input[type="date"].ym-date:focus {
    outline:2px solid rgba(37,99,235,.35);
    outline-offset:1px;
  }
  .ym-toolbar__sep {
    flex:0 0 auto;
    width:12px;
    text-align:center;
    opacity:.45;
    font-size:14px;
    line-height:36px;
    user-select:none;
  }
  .ym-toolbar__period .ym-apply {
    all: unset;
    box-sizing:border-box;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    height:36px;
    padding:0 14px;
    border-radius:8px;
    background:#1e293b;
    color:#fff;
    font: inherit;
    font-size:14px;
    font-weight:600;
    cursor:pointer;
    white-space:nowrap;
  }
  .ym-toolbar__period .ym-apply:hover { filter:brightness(1.08); }
  .ym-cabinet-links{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;}
  .ym-queries-period{display:flex;flex-direction:column;gap:.625rem;}
</style>
<div class="ym-toolbar">
  <div class="ym-toolbar__top">
    {$accountHtml}
    <div class="ym-toolbar__presets">{$exportHtml}</div>
  </div>
  <nav class="ym-tabs" aria-label="analytics tabs">{$tabsHtml}</nav>
  {$periodHtml}
</div>
HTML;

        return FlexibleRender::make($html);
    }

    /**
     * Пресеты + произвольный диапазон дат (сохраняет активную вкладку).
     */
    protected function renderPeriodControlsHtml(DatePeriod $period, string $tab): string
    {
        $pageUrl = e(request()->url());
        $presetLabels = [
            DatePeriod::PRESET_TODAY => __('moonshine-yandex-metrika::metrics.preset_today'),
            DatePeriod::PRESET_YESTERDAY => __('moonshine-yandex-metrika::metrics.preset_yesterday'),
            DatePeriod::PRESET_WEEK => __('moonshine-yandex-metrika::metrics.preset_week'),
            DatePeriod::PRESET_MONTH => __('moonshine-yandex-metrika::metrics.preset_month'),
            DatePeriod::PRESET_QUARTER => __('moonshine-yandex-metrika::metrics.preset_quarter'),
            DatePeriod::PRESET_HALF_YEAR => __('moonshine-yandex-metrika::metrics.preset_half_year'),
            DatePeriod::PRESET_YEAR => __('moonshine-yandex-metrika::metrics.preset_year'),
        ];

        $presetsHtml = '';

        foreach ($presetLabels as $preset => $label) {
            $active = $period->preset === $preset;
            $href = $pageUrl.'?'.http_build_query(['preset' => $preset, 'tab' => $tab]);
            $class = $active ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm';
            $presetsHtml .= '<a href="'.$href.'" class="'.$class.'">'.e($label).'</a>';
        }

        $applyLabel = e(__('moonshine-yandex-metrika::metrics.apply_period'));
        $date1 = e($period->date1());
        $date2 = e($period->date2());
        $tabValue = e($tab);

        return <<<HTML
<div class="ym-period">
  <div class="ym-toolbar__presets">{$presetsHtml}</div>
  <form method="get" action="{$pageUrl}" class="ym-toolbar__period">
    <input type="hidden" name="preset" value="custom">
    <input type="hidden" name="tab" value="{$tabValue}">
    <input type="date" name="date_from" value="{$date1}" class="ym-date" required aria-label="date from">
    <span class="ym-toolbar__sep">—</span>
    <input type="date" name="date_to" value="{$date2}" class="ym-date" required aria-label="date to">
    <button type="submit" class="ym-apply">{$applyLabel}</button>
  </form>
</div>
HTML;
    }

    protected function renderTabsHtml(DatePeriod $period, string $activeTab): string
    {
        $pageUrl = request()->url();
        $tabsHtml = '';

        foreach ($this->analyticsTabs() as $tab) {
            if (! $tab['enabled']) {
                continue;
            }

            $href = $pageUrl.'?'.http_build_query($this->pageQuery($period, $tab['id']));
            $tone = $tab['id'] === $activeTab ? 'btn-primary' : 'btn-secondary';
            $icon = $this->tabIconSvg($tab['icon'] ?? 'square-2-stack');
            $tabsHtml .= '<a href="'.e($href).'" class="btn ym-tab '.$tone.'">'
                .$icon
                .'<span>'.e($tab['label']).'</span>'
                .'</a>';
        }

        return $tabsHtml;
    }

    /**
     * Простые outline-иконки для вкладок (без зависимости от Heroicons CDN).
     */
    protected function tabIconSvg(string $name): string
    {
        $paths = [
            'chart-bar' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v6.75c0 .621-.504 1.125-1.125 1.125h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>',
            'magnifying-glass' => '<path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>',
            'arrow-down-tray' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>',
            'square-2-stack' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 8.25V6.75a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 3 6.75v9A2.25 2.25 0 0 0 5.25 18h1.5m1.5-3h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 6 5.25v9A2.25 2.25 0 0 0 8.25 16.5Z"/>',
        ];

        $path = $paths[$name] ?? $paths['square-2-stack'];

        return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">'
            .$path
            .'</svg>';
    }

    /**
     * @param  array<string, bool>  $widgets
     * @return list<ComponentContract>
     */
    protected function buildMetrikaSection(
        MetrikaService $metrika,
        array $widgets,
        int $maxRes,
    ): array {
        $components = [];
        $summaryMetrics = [];

        if ($widgets['visits'] ?? true) {
            $summaryMetrics[] = ValueMetric::make(__('moonshine-yandex-metrika::metrics.visits'))
                ->value(number_format($metrika->totalVisits(), 0, '.', ' '))
                ->icon('chart-bar')
                ->iconColor(Color::PRIMARY)
                ->columnSpan(3);
        }

        if ($widgets['pageviews'] ?? true) {
            $summaryMetrics[] = ValueMetric::make(__('moonshine-yandex-metrika::metrics.pageviews'))
                ->value(number_format($metrika->totalPageViews(), 0, '.', ' '))
                ->icon('document-chart-bar')
                ->iconColor(Color::SUCCESS)
                ->columnSpan(3);
        }

        if ($widgets['unique_visitors'] ?? true) {
            $summaryMetrics[] = ValueMetric::make(__('moonshine-yandex-metrika::metrics.unique_visitors'))
                ->value(number_format($metrika->uniqueVisitors(), 0, '.', ' '))
                ->icon('chart-pie')
                ->iconColor(Color::INFO)
                ->columnSpan(3);
        }

        if ($widgets['bounce_rate'] ?? true) {
            $summaryMetrics[] = ValueMetric::make(__('moonshine-yandex-metrika::metrics.bounce_rate'))
                ->value($metrika->bounceRate().'%')
                ->icon('presentation-chart-line')
                ->iconColor(Color::WARNING)
                ->columnSpan(3);
        }

        if ($summaryMetrics !== []) {
            $components[] = Grid::make($summaryMetrics);
            $components[] = Divider::make();
        }

        if ($widgets['visits'] ?? true) {
            $components[] = Box::make(
                __('moonshine-yandex-metrika::metrics.visits_by_day'),
                [$this->buildVisitsChart($metrika->visitsByDay())],
            );
            $components[] = Divider::make();
        }

        $tableColumns = [];

        if ($widgets['top_pages'] ?? true) {
            $tableColumns[] = Column::make([
                Box::make(
                    __('moonshine-yandex-metrika::metrics.top_pages'),
                    [$this->buildTopPagesTable($metrika, $maxRes)],
                ),
            ])->columnSpan(6);
        }

        if ($widgets['traffic_sources'] ?? true) {
            $tableColumns[] = Column::make([
                Box::make(
                    __('moonshine-yandex-metrika::metrics.traffic_sources'),
                    [$this->buildTrafficSourcesTable($metrika, $maxRes)],
                ),
            ])->columnSpan(6);
        }

        if ($tableColumns !== []) {
            $components[] = Grid::make($tableColumns);
            $components[] = Divider::make();
        }

        $bottomColumns = [];

        if ($widgets['search_phrases'] ?? true) {
            $bottomColumns[] = Column::make([
                Box::make(
                    __('moonshine-yandex-metrika::metrics.search_phrases'),
                    [$this->buildSearchPhrasesTable($metrika, $maxRes)],
                ),
            ])->columnSpan(4);
        }

        if ($widgets['geo'] ?? true) {
            $bottomColumns[] = Column::make([
                Box::make(
                    __('moonshine-yandex-metrika::metrics.geo'),
                    [$this->buildGeoTable($metrika, $maxRes)],
                ),
            ])->columnSpan(4);
        }

        if ($widgets['browsers'] ?? true) {
            $bottomColumns[] = Column::make([
                Box::make(
                    __('moonshine-yandex-metrika::metrics.browsers'),
                    [$this->buildBrowsersTable($metrika, $maxRes)],
                ),
            ])->columnSpan(4);
        }

        if ($bottomColumns !== []) {
            $components[] = Grid::make($bottomColumns);
        }

        return $components;
    }

    /**
     * @param  array<string, bool>  $widgets
     * @return list<ComponentContract>
     */
    protected function buildWebmasterSection(
        WebmasterService $webmaster,
        DatePeriod $period,
        array $widgets,
        int $maxRes,
    ): array {
        if (! $webmaster->isConfigured()) {
            return [
                Alert::make('information-circle', 'info')
                    ->content(__('moonshine-yandex-metrika::metrics.webmaster_not_configured')),
            ];
        }

        $host = e($webmaster->displayHost());
        $components = [
            FlexibleRender::make(
                '<div class="mb-1 text-sm opacity-70">'
                .e(__('moonshine-yandex-metrika::metrics.webmaster_host')).': <strong>'.$host.'</strong>'
                .'</div>'
                .'<div class="mb-3 text-sm opacity-70">'
                .e(__('moonshine-yandex-metrika::metrics.summary_current_state_hint'))
                .'</div>',
            ),
        ];

        if ($widgets['webmaster_summary'] ?? true) {
            $summary = $webmaster->summary();
            $linksTotal = $webmaster->externalLinksTotal();

            if ($summary === null) {
                $components[] = Alert::make('exclamation-triangle', 'warning')
                    ->content(__('moonshine-yandex-metrika::metrics.webmaster_fetch_failed'));
            } else {
                $components[] = Grid::make([
                    ValueMetric::make(__('moonshine-yandex-metrika::metrics.in_search'))
                        ->value(number_format($summary['searchable_pages_count'], 0, '.', ' '))
                        ->icon('chart-bar')
                        ->iconColor(Color::SUCCESS)
                        ->columnSpan(3),
                    ValueMetric::make(__('moonshine-yandex-metrika::metrics.excluded_pages'))
                        ->value(number_format($summary['excluded_pages_count'], 0, '.', ' '))
                        ->icon('chart-pie')
                        ->iconColor(Color::INFO)
                        ->columnSpan(3),
                    ValueMetric::make(__('moonshine-yandex-metrika::metrics.site_problems'))
                        ->value(number_format($summary['problems_total'], 0, '.', ' '))
                        ->icon('presentation-chart-line')
                        ->iconColor(Color::WARNING)
                        ->columnSpan(3),
                    ValueMetric::make(__('moonshine-yandex-metrika::metrics.external_links'))
                        ->value($linksTotal === null
                            ? __('moonshine-yandex-metrika::metrics.n_a')
                            : number_format($linksTotal, 0, '.', ' '))
                        ->icon('document-chart-bar')
                        ->iconColor(Color::PRIMARY)
                        ->columnSpan(3),
                ]);

                $components[] = Divider::make();
                $components[] = FlexibleRender::make(
                    '<div class="ym-cabinet-links">'
                    .'<a href="'.e($webmaster->cabinetSummaryUrl()).'" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">'
                    .e(__('moonshine-yandex-metrika::metrics.link_dashboard')).'</a>'
                    .'<a href="'.e($webmaster->cabinetIndexingUrl()).'" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">'
                    .e(__('moonshine-yandex-metrika::metrics.link_indexing')).'</a>'
                    .'<a href="'.e($webmaster->cabinetDiagnosticsUrl()).'" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">'
                    .e(__('moonshine-yandex-metrika::metrics.link_diagnostics')).'</a>'
                    .'<a href="'.e($webmaster->cabinetLinksUrl()).'" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">'
                    .e(__('moonshine-yandex-metrika::metrics.link_external')).'</a>'
                    .'<a href="'.e($webmaster->cabinetQueriesUrl()).'" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">'
                    .e(__('moonshine-yandex-metrika::metrics.link_queries')).'</a>'
                    .'</div>',
                );

                if ($linksTotal === null) {
                    $components[] = Alert::make('information-circle', 'info')
                        ->content(__('moonshine-yandex-metrika::metrics.external_links_scope_hint'));
                }
            }
        }

        if ($widgets['webmaster_queries'] ?? true) {
            $report = $webmaster->popularQueriesReport($maxRes);
            $titlePeriod = ($report['date_from'] && $report['date_to'])
                ? $report['date_from'].' — '.$report['date_to']
                : $period->date1().' — '.$period->date2();

            $components[] = Divider::make();
            $components[] = FlexibleRender::make(
                '<div class="ym-queries-period">'
                .'<div class="ym-period__hint">'
                .e(__('moonshine-yandex-metrika::metrics.queries_period_hint'))
                .'</div>'
                .$this->renderPeriodControlsHtml($period, self::TAB_WEBMASTER)
                .'</div>',
            );

            if ($report['used_fallback']) {
                $components[] = Alert::make('information-circle', 'info')
                    ->content(__('moonshine-yandex-metrika::metrics.queries_fallback_hint', [
                        'from' => $report['date_from'] ?? '',
                        'to' => $report['date_to'] ?? '',
                    ]));
            }

            $components[] = Box::make(
                __('moonshine-yandex-metrika::metrics.popular_queries').' ('.$titlePeriod.')',
                [$this->buildPopularQueriesTableFromRows($report['rows'])],
            );
        }

        return $components;
    }

    /**
     * @param  list<array{dimension: string, metrics: list<float|int>}>  $chartData
     */
    protected function buildVisitsChart(array $chartData): FlexibleRender
    {
        $chartId = 'metrika_chart_'.uniqid();
        $labels = json_encode(array_column($chartData, 'dimension'), JSON_UNESCAPED_UNICODE);
        $values = json_encode(array_map(
            static fn (array $row): float|int => $row['metrics'][0] ?? 0,
            $chartData,
        ));
        $label = e(__('moonshine-yandex-metrika::metrics.visits'));

        return FlexibleRender::make(<<<HTML
<div x-ignore style="position: relative; height: 300px;">
  <canvas id="{$chartId}"></canvas>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('{$chartId}');
  if (!el || typeof Chart === 'undefined') { return; }
  new Chart(el.getContext('2d'), {
    type: 'line',
    data: {
      labels: {$labels},
      datasets: [{
        label: '{$label}',
        data: {$values},
        borderColor: 'rgb(99, 102, 241)',
        backgroundColor: 'rgba(99, 102, 241, 0.08)',
        borderWidth: 2,
        pointRadius: 3,
        fill: true,
        tension: 0.3
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } }
      }
    }
  });
});
</script>
HTML);
    }

    protected function buildTopPagesTable(MetrikaService $metrika, int $maxResults): TableBuilder
    {
        $rows = collect($metrika->topPages(null, $maxResults))
            ->map(fn (array $r): array => [
                'page' => $r['dimension'],
                'views' => $r['metrics'][0] ?? 0,
            ]);

        return TableBuilder::make(fields: [
            Text::make(__('moonshine-yandex-metrika::metrics.page'), 'page'),
            Number::make(__('moonshine-yandex-metrika::metrics.views'), 'views'),
        ])->items($rows)->simple();
    }

    protected function buildTrafficSourcesTable(MetrikaService $metrika, int $maxResults): TableBuilder
    {
        $rows = collect($metrika->trafficSources(null, $maxResults))
            ->map(fn (array $r): array => [
                'source' => $r['dimension'],
                'visits' => $r['metrics'][0] ?? 0,
                'users' => $r['metrics'][1] ?? 0,
            ]);

        return TableBuilder::make(fields: [
            Text::make(__('moonshine-yandex-metrika::metrics.source'), 'source'),
            Number::make(__('moonshine-yandex-metrika::metrics.visits'), 'visits'),
            Number::make(__('moonshine-yandex-metrika::metrics.users'), 'users'),
        ])->items($rows)->simple();
    }

    protected function buildSearchPhrasesTable(MetrikaService $metrika, int $maxResults): TableBuilder
    {
        $rows = collect($metrika->searchPhrases(null, $maxResults))
            ->map(fn (array $r): array => [
                'phrase' => $r['dimension'],
                'visits' => $r['metrics'][0] ?? 0,
            ]);

        return TableBuilder::make(fields: [
            Text::make(__('moonshine-yandex-metrika::metrics.phrase'), 'phrase'),
            Number::make(__('moonshine-yandex-metrika::metrics.visits'), 'visits'),
        ])->items($rows)->simple();
    }

    protected function buildGeoTable(MetrikaService $metrika, int $maxResults): TableBuilder
    {
        $rows = collect($metrika->geoCountries(null, $maxResults))
            ->map(fn (array $r): array => [
                'country' => $r['dimension'],
                'visits' => $r['metrics'][0] ?? 0,
            ]);

        return TableBuilder::make(fields: [
            Text::make(__('moonshine-yandex-metrika::metrics.country'), 'country'),
            Number::make(__('moonshine-yandex-metrika::metrics.visits'), 'visits'),
        ])->items($rows)->simple();
    }

    protected function buildBrowsersTable(MetrikaService $metrika, int $maxResults): TableBuilder
    {
        $rows = collect($metrika->browsers(null, $maxResults))
            ->map(fn (array $r): array => [
                'browser' => $r['dimension'],
                'visits' => $r['metrics'][0] ?? 0,
            ]);

        return TableBuilder::make(fields: [
            Text::make(__('moonshine-yandex-metrika::metrics.browser'), 'browser'),
            Number::make(__('moonshine-yandex-metrika::metrics.visits'), 'visits'),
        ])->items($rows)->simple();
    }

    /**
     * @param  list<array{query: string, shows: int, clicks: int, avg_show_position: float|null, avg_click_position: float|null}>  $queries
     */
    protected function buildPopularQueriesTableFromRows(array $queries): TableBuilder|FlexibleRender
    {
        if ($queries === []) {
            return FlexibleRender::make(
                '<p class="text-sm opacity-70 py-2">'
                .e(__('moonshine-yandex-metrika::metrics.queries_empty'))
                .'</p>',
            );
        }

        $rows = collect($queries)
            ->map(fn (array $r): array => [
                'query' => $r['query'],
                'shows' => $r['shows'],
                'clicks' => $r['clicks'],
                'position' => $r['avg_show_position'] ?? '—',
            ]);

        return TableBuilder::make(fields: [
            Text::make(__('moonshine-yandex-metrika::metrics.query'), 'query'),
            Number::make(__('moonshine-yandex-metrika::metrics.shows'), 'shows'),
            Number::make(__('moonshine-yandex-metrika::metrics.clicks'), 'clicks'),
            Text::make(__('moonshine-yandex-metrika::metrics.avg_position'), 'position'),
        ])->items($rows)->simple();
    }
}
