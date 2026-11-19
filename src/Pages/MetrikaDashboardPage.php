<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Pages;

use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Laravel\Pages\Page;
use MoonShine\UI\Components\Alert;
use MoonShine\UI\Components\FlexibleRender;
use MoonShine\UI\Components\Layout\Box;
use MoonShine\UI\Components\Layout\Column;
use MoonShine\UI\Components\Layout\Divider;
use MoonShine\UI\Components\Layout\Grid;
use MoonShine\UI\Components\Table\TableBuilder;
use MoonShine\UI\Fields\Number;
use MoonShine\UI\Fields\Text;
use Tikhomirov\MoonshineYandexMetrika\Contracts\AnalyticsDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Contracts\MetrikaDashboardTabHost;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbe;
use Tikhomirov\MoonshineYandexMetrika\Services\ConnectionProbeResult;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;
use Tikhomirov\MoonshineYandexMetrika\Services\WebmasterService;
use Tikhomirov\MoonshineYandexMetrika\Services\YandexAccountService;
use Tikhomirov\MoonshineYandexMetrika\Support\AnalyticsTabAttributes;
use Tikhomirov\MoonshineYandexMetrika\Support\AnalyticsTabRegistry;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;
use Tikhomirov\MoonshineYandexMetrika\Support\WrapsPageComponentsInSpaceElements;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthApp;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexOAuthFlow;
use Tikhomirov\MoonshineYandexMetrika\Support\YandexSettings;

class MetrikaDashboardPage extends Page implements MetrikaDashboardTabHost
{
    use WrapsPageComponentsInSpaceElements;

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
        app()->instance(MetrikaDashboardTabHost::class, $this);

        try {
            $probe = app(ConnectionProbe::class)->check();
            $period = $this->resolvePeriod();
            $tab = $this->resolveTab();
            $widgets = config('moonshine-yandex-metrika.widgets', []);
            $maxRes = (int) config('moonshine-yandex-metrika.max_results', 10);

            // Корень content.blade.php не добавляет .space-elements (см. moonshine#2108).
            // Оборачиваем весь components(), включая хуки и тулбар.
            return $this->wrapInSpaceElements([
                ...$this->beforeComponents($period),
                $this->buildToolbar($period, $tab, $probe),
                ...$this->buildTabContent($tab, $period, $widgets, $maxRes, $probe),
                ...$this->extraComponents($period),
            ]);
        } finally {
            app()->forgetInstance(MetrikaDashboardTabHost::class);
        }
    }

    /**
     * Список вкладок: {@see AnalyticsTabRegistry} + {@see additionalAnalyticsTabs()}.
     *
     * @return list<array{id: string, label: string, icon: string, enabled: bool}>
     */
    protected function analyticsTabs(): array
    {
        $tabs = [];

        foreach (app(AnalyticsTabRegistry::class)->all() as $registered) {
            $tabs[] = [
                'id' => $registered->id(),
                'label' => $registered->label(),
                'icon' => $registered->icon(),
                'enabled' => $registered->enabled(),
            ];
        }

        return [...$tabs, ...$this->additionalAnalyticsTabs()];
    }

    /**
     * Дополнительные вкладки в наследнике (BC: раньше переопределяли analyticsTabs() целиком).
     *
     * Для period/CSV в шапке предпочтительно {@see AnalyticsTabRegistry}; здесь — опциональные ключи
     * uses_period, requires_yandex, export_csv_url, export_csv_label.
     *
     * @return list<array{
     *     id: string,
     *     label: string,
     *     icon: string,
     *     enabled: bool,
     *     uses_period?: bool,
     *     requires_yandex?: bool,
     *     export_csv_url?: string|callable(DatePeriod): (?string),
     *     export_csv_label?: string,
     * }>
     */
    protected function additionalAnalyticsTabs(): array
    {
        return [];
    }

    /**
     * @param  list<array{
     *     id: string,
     *     label: string,
     *     icon: string,
     *     enabled: bool,
     *     uses_period?: bool,
     *     requires_yandex?: bool,
     *     export_csv_url?: string|callable(DatePeriod): (?string),
     *     export_csv_label?: string,
     * }>|null  $tabs
     */
    protected function findAdditionalAnalyticsTab(string $tabId, ?array $tabs = null): ?array
    {
        $tabs ??= $this->additionalAnalyticsTabs();

        foreach ($tabs as $tab) {
            if (($tab['id'] ?? '') === $tabId) {
                return $tab;
            }
        }

        return null;
    }

    protected function resolveTabAttributes(string $tabId, DatePeriod $period): AnalyticsTabAttributes
    {
        $registered = app(AnalyticsTabRegistry::class)->find($tabId);

        if ($registered instanceof AnalyticsDashboardTab) {
            $exportUrl = $registered->exportCsvUrl($period);
            $exportLabel = match ($tabId) {
                self::TAB_WEBMASTER => (string) __('moonshine-yandex-metrika::metrics.export_webmaster_csv'),
                self::TAB_METRIKA => (string) __('moonshine-yandex-metrika::metrics.export_metrika_csv'),
                default => $registered->label().' CSV',
            };

            return new AnalyticsTabAttributes(
                usesPeriod: $registered->usesPeriod(),
                requiresYandex: $registered->requiresYandexConnection(),
                exportCsvUrl: $exportUrl !== '' ? $exportUrl : null,
                exportCsvLabel: $exportUrl !== null && $exportUrl !== '' ? $exportLabel : null,
            );
        }

        $legacy = $this->findAdditionalAnalyticsTab($tabId);

        if ($legacy === null) {
            return AnalyticsTabAttributes::none();
        }

        $exportUrl = null;

        if (isset($legacy['export_csv_url'])) {
            $raw = $legacy['export_csv_url'];
            $exportUrl = is_callable($raw) ? $raw($period) : (string) $raw;
            if ($exportUrl === '') {
                $exportUrl = null;
            }
        }

        $exportLabel = $legacy['export_csv_label'] ?? ($exportUrl !== null ? (string) ($legacy['label'] ?? '').' CSV' : null);

        return new AnalyticsTabAttributes(
            usesPeriod: (bool) ($legacy['uses_period'] ?? false),
            requiresYandex: (bool) ($legacy['requires_yandex'] ?? false),
            exportCsvUrl: $exportUrl,
            exportCsvLabel: $exportLabel,
        );
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
        ConnectionProbeResult $probe,
    ): array {
        $attributes = $this->resolveTabAttributes($tab, $period);

        if ($attributes->requiresYandex && ! $probe->isOk()) {
            return [
                Alert::make('exclamation-triangle', 'warning')
                    ->content($probe->message),
            ];
        }

        $registered = app(AnalyticsTabRegistry::class)->find($tab);

        if ($registered !== null && $registered->enabled()) {
            return $registered->render($period, $widgets, $maxRes);
        }

        return $this->buildCustomTabContent($tab, $period, $widgets, $maxRes);
    }

    /**
     * @param  array<string, bool>  $widgets
     * @return list<ComponentContract>
     *
     * @internal Вызывается встроенной вкладкой Метрики из реестра.
     */
    public function renderMetrikaTab(DatePeriod $period, array $widgets, int $maxResults): array
    {
        return $this->buildMetrikaSection(
            $this->metrika->forPeriod($period),
            $widgets,
            $maxResults,
        );
    }

    /**
     * @param  array<string, bool>  $widgets
     * @return list<ComponentContract>
     *
     * @internal Вызывается встроенной вкладкой Вебмастера из реестра.
     */
    public function renderWebmasterTab(DatePeriod $period, array $widgets, int $maxResults): array
    {
        return $this->buildWebmasterSection(
            $this->webmaster->forPeriod($period),
            $period,
            $widgets,
            $maxResults,
        );
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

    protected function buildToolbar(
        DatePeriod $period,
        string $tab,
        ConnectionProbeResult $probe,
    ): FlexibleRender {
        $attributes = $this->resolveTabAttributes($tab, $period);
        $yandexReady = $probe->isOk();

        $account = $yandexReady && config('moonshine-yandex-metrika.widgets.account', true)
            ? $this->account->profile()
            : null;

        $oauthApp = $yandexReady && config('moonshine-yandex-metrika.widgets.account', true)
            ? $this->account->oauthApp()
            : new YandexOAuthApp(null);

        $accountHtml = '';

        if (($account && ($account['avatar_url'] || $account['email'] || $account['login'])) || $oauthApp->isKnown()) {
            $avatar = ($account['avatar_url'] ?? null)
                ? '<img src="'.e($account['avatar_url']).'" alt="" class="h-10 w-10 rounded-full object-cover" width="40" height="40">'
                : '';
            $name = e($account['email'] ?? $account['login'] ?? 'Yandex');
            $appHtml = $oauthApp->isKnown()
                ? '<div class="text-xs opacity-70 ym-oauth-app">'
                    .e(__('moonshine-yandex-metrika::metrics.oauth_app')).': '
                    .'<a href="'.e($oauthApp->appUrl()).'" target="_blank" rel="noopener noreferrer" class="underline">'
                    .e((string) $oauthApp->clientId).'</a></div>'
                : '';
            $accountHtml = '<div class="flex items-center gap-3">'
                .$avatar
                .'<div class="leading-tight"><div class="text-sm font-medium">'.$name.'</div>'
                .'<div class="text-xs opacity-70">'.e(__('moonshine-yandex-metrika::metrics.account_source')).'</div>'
                .$appHtml
                .'</div></div>';
        }

        $exportHtml = '';
        $exportUrl = $attributes->exportCsvUrl;

        if ($exportUrl !== null && $exportUrl !== '' && (! $attributes->requiresYandex || $yandexReady)) {
            $exportLabel = e($attributes->exportCsvLabel ?? 'CSV');
            $exportUrl = e($exportUrl);

            $exportHtml = '<a href="'.$exportUrl.'" class="btn btn-secondary btn-sm ym-export" title="'
                .$exportLabel.'">'
                .$this->tabIconSvg('arrow-down-tray')
                .'<span>'.$exportLabel.'</span>'
                .'</a>';
        }

        $tabsHtml = $this->renderTabsHtml($period, $tab);
        $periodHtml = $attributes->usesPeriod
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
    border-bottom:1px solid var(--ms-cm-base-stroke, rgba(15,23,42,.12));
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
    border:1px solid var(--ms-cm-base-stroke, rgba(15,23,42,.08));
    border-radius:12px;
    background:var(--ms-cm-base-default, #fff);
    color:var(--ms-cm-base-text, #0f172a);
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
    border:1px solid var(--ms-cm-base-stroke, rgba(15,23,42,.12));
    border-radius:8px;
    background:var(--ms-cm-base-default, #fff);
    font: inherit;
    font-size:14px;
    line-height:36px;
    color:var(--ms-cm-base-text, #0f172a);
    color-scheme:light;
  }
  :root.dark .ym-toolbar__period input[type="date"].ym-date {
    color-scheme:dark;
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
  /* "Apply" uses the stock MoonShine button so its colour follows the theme palette. */
  .ym-toolbar__period .btn {
    min-height:36px;
    height:36px;
    white-space:nowrap;
  }
  .ym-cabinet-links{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;}
  .ym-queries-period{display:flex;flex-direction:column;gap:.625rem;}
  .ym-stats {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:.75rem;
  }
  @media (max-width: 1100px) {
    .ym-stats { grid-template-columns:repeat(2,minmax(0,1fr)); }
  }
  @media (max-width: 560px) {
    .ym-stats { grid-template-columns:1fr; }
  }
  .ym-stat {
    display:flex;
    align-items:center;
    gap:1rem;
    min-height:5.25rem;
    padding:.9rem 1.1rem;
    border:1px solid var(--ms-cm-base-stroke, rgba(15,23,42,.08));
    border-radius:12px;
    background:var(--ms-cm-base-default, #fff);
    color:var(--ms-cm-base-text, #0f172a);
    box-shadow:0 1px 2px rgba(15,23,42,.03);
  }
  .ym-stat__icon {
    display:grid;
    place-items:center;
    width:4.75rem;
    height:4.75rem;
    border-radius:14px;
    flex:0 0 auto;
  }
  .ym-stat__icon svg { width:2.65rem; height:2.65rem; stroke-width:1.6; }
  .ym-stat__body { min-width:0; }
  .ym-stat__value {
    font-size:1.5rem;
    font-weight:800;
    letter-spacing:-.03em;
    line-height:1.15;
    color:inherit;
  }
  .ym-stat__label {
    margin-top:.25rem;
    font-size:.8rem;
    font-weight:600;
    line-height:1.2;
    opacity:.65;
  }
  .ym-stat--primary .ym-stat__icon { background:rgba(37,99,235,.12); color:#2563eb; }
  .ym-stat--success .ym-stat__icon { background:rgba(22,163,74,.12); color:#16a34a; }
  .ym-stat--info .ym-stat__icon { background:rgba(8,145,178,.12); color:#0891b2; }
  .ym-stat--warning .ym-stat__icon { background:rgba(217,119,6,.14); color:#d97706; }
  :root.dark .ym-stat--primary .ym-stat__icon { background:rgba(37,99,235,.22); }
  :root.dark .ym-stat--success .ym-stat__icon { background:rgba(22,163,74,.22); }
  :root.dark .ym-stat--info .ym-stat__icon { background:rgba(8,145,178,.22); }
  :root.dark .ym-stat--warning .ym-stat__icon { background:rgba(217,119,6,.24); }
  :root.dark .ym-stat,
  .dark .ym-stat {
    background:var(--mushrooms-panel, var(--ms-cm-base-default, #18181b));
    color:var(--mushrooms-ink, var(--ms-cm-base-text, #fafafa));
    border-color:var(--mushrooms-line-strong, var(--ms-cm-base-stroke, #3f3f46));
    box-shadow:none;
  }
  :root.dark .ym-toolbar__period,
  .dark .ym-toolbar__period {
    background:var(--mushrooms-panel, var(--ms-cm-base-default, #18181b));
    color:var(--mushrooms-ink, var(--ms-cm-base-text, #fafafa));
    border-color:var(--mushrooms-line-strong, var(--ms-cm-base-stroke, #3f3f46));
    box-shadow:none;
  }
  :root.dark .ym-toolbar__period input[type="date"].ym-date,
  .dark .ym-toolbar__period input[type="date"].ym-date {
    background:var(--mushrooms-surface-muted, var(--ms-cm-base-default, #1f1f23));
    border-color:var(--mushrooms-line-strong, var(--ms-cm-base-stroke, #3f3f46));
    color:var(--mushrooms-ink, var(--ms-cm-base-text, #fafafa));
  }
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
    <button type="submit" class="btn btn-primary btn-sm">{$applyLabel}</button>
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
     * Компактные KPI-карточки: иконка слева, значение и подпись справа.
     *
     * @param  list<array{label: string, value: string, icon: string, tone?: string}>  $items
     */
    protected function buildCompactStats(array $items): FlexibleRender
    {
        $cards = '';

        foreach ($items as $item) {
            $tone = $item['tone'] ?? 'primary';
            $cards .= '<div class="ym-stat ym-stat--'.e($tone).'">'
                .'<div class="ym-stat__icon">'.$this->tabIconSvg($item['icon']).'</div>'
                .'<div class="ym-stat__body">'
                .'<div class="ym-stat__value">'.e($item['value']).'</div>'
                .'<div class="ym-stat__label">'.e($item['label']).'</div>'
                .'</div>'
                .'</div>';
        }

        return FlexibleRender::make('<div class="ym-stats">'.$cards.'</div>');
    }

    /**
     * Простые outline-иконки для вкладок и KPI (без зависимости от Heroicons CDN).
     */
    protected function tabIconSvg(string $name): string
    {
        $paths = [
            'chart-bar' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v6.75c0 .621-.504 1.125-1.125 1.125h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>',
            'document-chart-bar' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>',
            'chart-pie' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 1 0 7.5 7.5h-7.5V6Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0 0 13.5 3v7.5Z"/>',
            'presentation-chart-line' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5M9 11.25l3 3 3.75-4.5"/>',
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
     * KPI cards for the Metrika tab. Order and set come from
     * config('moonshine-yandex-metrika.summary_metrics'); unknown keys are
     * skipped. A widget flag set to false still hides the matching card.
     *
     * @param  array<string, bool>  $widgets
     * @return list<array{label: string, value: string, icon: string, tone: string}>
     */
    protected function buildSummaryMetrics(MetrikaService $metrika, array $widgets): array
    {
        $definitions = [
            'visits' => fn (): array => [
                'label' => (string) __('moonshine-yandex-metrika::metrics.visits'),
                'value' => number_format($metrika->totalVisits(), 0, '.', ' '),
                'icon' => 'chart-bar',
                'tone' => 'primary',
            ],
            'pageviews' => fn (): array => [
                'label' => (string) __('moonshine-yandex-metrika::metrics.pageviews'),
                'value' => number_format($metrika->totalPageViews(), 0, '.', ' '),
                'icon' => 'document-chart-bar',
                'tone' => 'success',
            ],
            'unique_visitors' => fn (): array => [
                'label' => (string) __('moonshine-yandex-metrika::metrics.unique_visitors'),
                'value' => number_format($metrika->uniqueVisitors(), 0, '.', ' '),
                'icon' => 'chart-pie',
                'tone' => 'info',
            ],
            'bounce_rate' => fn (): array => [
                'label' => (string) __('moonshine-yandex-metrika::metrics.bounce_rate'),
                'value' => $metrika->bounceRate().'%',
                'icon' => 'presentation-chart-line',
                'tone' => 'warning',
            ],
        ];

        $order = config('moonshine-yandex-metrika.summary_metrics', array_keys($definitions));

        if (! is_array($order)) {
            $order = array_keys($definitions);
        }

        $metrics = [];

        foreach ($order as $key) {
            if (! is_string($key) || ! isset($definitions[$key])) {
                continue;
            }

            if (! ($widgets[$key] ?? true)) {
                continue;
            }

            $metrics[] = $definitions[$key]();
        }

        return $metrics;
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
        $summaryMetrics = $this->buildSummaryMetrics($metrika, $widgets);

        if ($summaryMetrics !== []) {
            $components[] = $this->buildCompactStats($summaryMetrics);
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
                $components[] = $this->buildOAuthScopeActions('webmaster:verify, webmaster:hostinfo');
            } else {
                $components[] = $this->buildCompactStats([
                    [
                        'label' => (string) __('moonshine-yandex-metrika::metrics.in_search'),
                        'value' => number_format($summary['searchable_pages_count'], 0, '.', ' '),
                        'icon' => 'chart-bar',
                        'tone' => 'success',
                    ],
                    [
                        'label' => (string) __('moonshine-yandex-metrika::metrics.excluded_pages'),
                        'value' => number_format($summary['excluded_pages_count'], 0, '.', ' '),
                        'icon' => 'chart-pie',
                        'tone' => 'info',
                    ],
                    [
                        'label' => (string) __('moonshine-yandex-metrika::metrics.site_problems'),
                        'value' => number_format($summary['problems_total'], 0, '.', ' '),
                        'icon' => 'presentation-chart-line',
                        'tone' => 'warning',
                    ],
                    [
                        'label' => (string) __('moonshine-yandex-metrika::metrics.external_links'),
                        'value' => $linksTotal === null
                            ? (string) __('moonshine-yandex-metrika::metrics.n_a')
                            : number_format($linksTotal, 0, '.', ' '),
                        'icon' => 'document-chart-bar',
                        'tone' => 'primary',
                    ],
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
                    $components[] = $this->buildOAuthScopeActions('webmaster:hostinfo');
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
     * Действия под алертом о нехватке прав: открыть OAuth-приложение и выпустить новый токен.
     * Токен задаётся в YANDEX_METRIKA_TOKEN, поэтому новый токен нужно вставить в .env.
     */
    protected function buildOAuthScopeActions(string $scope): FlexibleRender
    {
        $app = $this->account?->oauthApp() ?? new YandexOAuthApp(null);

        $label = $app->isKnown()
            ? __('moonshine-yandex-metrika::metrics.oauth_open_app_add_scope', ['scope' => $scope])
            : __('moonshine-yandex-metrika::metrics.oauth_open_apps');

        $links = '<a href="'.e($app->editUrl()).'" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">'
            .e($label).'</a>';

        // С Client secret и таблицей настроек: свой OAuth flow, токен сохранится в БД.
        // Иначе: выпуск токена на стороне Яндекса и подсказка про YANDEX_METRIKA_TOKEN.
        $flowReady = app(YandexSettings::class)->tableExists() && app(YandexOAuthFlow::class)->canStart();
        $tokenUrl = $flowReady ? route('moonshine-yandex-metrika.oauth.redirect') : $app->tokenUrl();

        if ($tokenUrl !== null) {
            $links .= '<a href="'.e($tokenUrl).'"'.($flowReady ? '' : ' target="_blank" rel="noopener noreferrer"')
                .' class="btn btn-primary btn-sm">'
                .e(__('moonshine-yandex-metrika::metrics.oauth_reconnect')).'</a>';
        }

        if (config('moonshine-yandex-metrika.connection_page', true)) {
            $links .= '<a href="'.e(app(YandexConnectionPage::class)->getUrl()).'" class="btn btn-secondary btn-sm">'
                .e(__('moonshine-yandex-metrika::metrics.oauth_open_connection')).'</a>';
        }

        $hint = $app->isKnown()
            ? __('moonshine-yandex-metrika::metrics.oauth_app').': '.$app->clientId.'. '
                .__($flowReady
                    ? 'moonshine-yandex-metrika::metrics.oauth_reconnect_flow_hint'
                    : 'moonshine-yandex-metrika::metrics.oauth_reconnect_hint')
            : __('moonshine-yandex-metrika::metrics.oauth_client_id_unknown_hint');

        return FlexibleRender::make(
            '<div class="ym-oauth-actions">'
            .'<div class="ym-cabinet-links">'.$links.'</div>'
            .'<div class="mt-2 text-xs opacity-70">'.e($hint).'</div>'
            .'</div>',
        );
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
        $theme = view('moonshine-yandex-metrika::components.chart-theme')->render();

        return FlexibleRender::make(<<<HTML
<div x-ignore style="position: relative; height: 300px;">
  <canvas id="{$chartId}"></canvas>
</div>
{$theme}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('{$chartId}');
  if (!el || typeof Chart === 'undefined') { return; }
  var colors = ymMetrikaChartColors();
  var chart = new Chart(el.getContext('2d'), {
    type: 'line',
    data: {
      labels: {$labels},
      datasets: [{
        label: '{$label}',
        data: {$values},
        borderColor: 'rgb(99, 102, 241)',
        backgroundColor: colors.fill,
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
      scales: ymMetrikaChartScales(colors)
    }
  });
  ymWatchMetrikaChartTheme(chart);
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
