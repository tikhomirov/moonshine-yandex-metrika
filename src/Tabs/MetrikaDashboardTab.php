<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Tabs;

use MoonShine\Contracts\UI\ComponentContract;
use Tikhomirov\MoonshineYandexMetrika\Contracts\AnalyticsDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Contracts\MetrikaDashboardTabHost;
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

final class MetrikaDashboardTab implements AnalyticsDashboardTab
{
    public function id(): string
    {
        return MetrikaDashboardPage::TAB_METRIKA;
    }

    public function label(): string
    {
        return (string) __('moonshine-yandex-metrika::metrics.tab_metrika');
    }

    public function icon(): string
    {
        return 'chart-bar';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function usesPeriod(): bool
    {
        return true;
    }

    public function requiresYandexConnection(): bool
    {
        return true;
    }

    /**
     * @param  array<string, bool>  $widgets
     * @return list<ComponentContract>
     */
    public function render(DatePeriod $period, array $widgets, int $maxResults): array
    {
        if (! app()->bound(MetrikaDashboardTabHost::class)) {
            return [];
        }

        return app(MetrikaDashboardTabHost::class)->renderMetrikaTab($period, $widgets, $maxResults);
    }

    public function exportCsvUrl(DatePeriod $period): ?string
    {
        if (! config('moonshine-yandex-metrika.widgets.export', true)) {
            return null;
        }

        return route('moonshine-yandex-metrika.export.metrika', $period->toQuery());
    }
}
