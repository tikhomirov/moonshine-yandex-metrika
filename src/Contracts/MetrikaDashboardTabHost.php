<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Contracts;

use MoonShine\Contracts\UI\ComponentContract;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

/**
 * Рендер встроенных вкладок Яндекса (привязка к текущему экземпляру страницы на время запроса).
 */
interface MetrikaDashboardTabHost
{
    /**
     * @param  array<string, bool>  $widgets
     * @return list<ComponentContract>
     */
    public function renderMetrikaTab(DatePeriod $period, array $widgets, int $maxResults): array;

    /**
     * @param  array<string, bool>  $widgets
     * @return list<ComponentContract>
     */
    public function renderWebmasterTab(DatePeriod $period, array $widgets, int $maxResults): array;
}
