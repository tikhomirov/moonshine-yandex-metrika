<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Tabs;

use MoonShine\Contracts\UI\ComponentContract;
use Tikhomirov\MoonshineYandexMetrika\Contracts\AnalyticsDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Contracts\MetrikaDashboardTabHost;
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

final class WebmasterDashboardTab implements AnalyticsDashboardTab
{
    public function id(): string
    {
        return MetrikaDashboardPage::TAB_WEBMASTER;
    }

    public function label(): string
    {
        return (string) __('moonshine-yandex-metrika::metrics.tab_webmaster');
    }

    public function icon(): string
    {
        return 'magnifying-glass';
    }

    public function enabled(): bool
    {
        $widgets = config('moonshine-yandex-metrika.widgets', []);

        return ($widgets['webmaster_summary'] ?? true)
            || ($widgets['webmaster_queries'] ?? true);
    }

    public function usesPeriod(): bool
    {
        return false;
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

        return app(MetrikaDashboardTabHost::class)->renderWebmasterTab($period, $widgets, $maxResults);
    }

    public function exportCsvUrl(DatePeriod $period): ?string
    {
        if (! config('moonshine-yandex-metrika.widgets.export', true)) {
            return null;
        }

        return route('moonshine-yandex-metrika.export.webmaster', $period->toQuery());
    }
}
