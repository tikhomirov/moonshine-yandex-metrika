<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Contracts;

use MoonShine\Contracts\UI\ComponentContract;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

/**
 * Контракт вкладки дашборда аналитики (Метрика, Вебмастер, сторонние источники).
 */
interface AnalyticsDashboardTab
{
    public function id(): string;

    public function label(): string;

    public function icon(): string;

    public function enabled(): bool;

    /**
     * Показывать общий фильтр периода в тулбаре над контентом вкладки.
     */
    public function usesPeriod(): bool;

    /**
     * Контент вкладки требует рабочий OAuth/API Яндекс.Метрики (иначе показывается предупреждение probe).
     */
    public function requiresYandexConnection(): bool;

    /**
     * @param  array<string, bool>  $widgets
     * @return list<ComponentContract>
     */
    public function render(DatePeriod $period, array $widgets, int $maxResults): array;

    /**
     * URL CSV-выгрузки для активной вкладки (null — кнопка экспорта скрыта для этой вкладки).
     */
    public function exportCsvUrl(DatePeriod $period): ?string;
}
