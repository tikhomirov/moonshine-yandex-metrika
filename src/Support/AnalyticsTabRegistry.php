<?php

declare(strict_types=1);

namespace Tikhomirov\MoonshineYandexMetrika\Support;

use InvalidArgumentException;
use Tikhomirov\MoonshineYandexMetrika\Contracts\AnalyticsDashboardTab;

/**
 * Реестр вкладок дашборда. Сторонние пакеты регистрируют вкладки в boot() провайдера.
 */
final class AnalyticsTabRegistry
{
    /** @var array<string, AnalyticsDashboardTab> */
    private array $tabs = [];

    public function register(AnalyticsDashboardTab $tab): void
    {
        $id = $tab->id();

        if ($id === '') {
            throw new InvalidArgumentException('Analytics dashboard tab id must not be empty.');
        }

        $this->tabs[$id] = $tab;
    }

    public function has(string $id): bool
    {
        return isset($this->tabs[$id]);
    }

    public function find(string $id): ?AnalyticsDashboardTab
    {
        return $this->tabs[$id] ?? null;
    }

    /**
     * @return list<AnalyticsDashboardTab>
     */
    public function all(): array
    {
        return array_values($this->tabs);
    }

    /**
     * @return list<AnalyticsDashboardTab>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->tabs,
            static fn (AnalyticsDashboardTab $tab): bool => $tab->enabled(),
        ));
    }
}