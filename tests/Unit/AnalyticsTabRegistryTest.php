<?php

declare(strict_types=1);

use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\UI\Components\FlexibleRender;
use Tikhomirov\MoonshineYandexMetrika\Contracts\AnalyticsDashboardTab;
use Tikhomirov\MoonshineYandexMetrika\Support\AnalyticsTabRegistry;
use Tikhomirov\MoonshineYandexMetrika\Support\DatePeriod;

test('registry stores tabs by id and returns enabled subset', function () {
    $registry = new AnalyticsTabRegistry();

    $enabled = new class implements AnalyticsDashboardTab
    {
        public function id(): string
        {
            return 'alpha';
        }

        public function label(): string
        {
            return 'Alpha';
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
            return false;
        }

        public function requiresYandexConnection(): bool
        {
            return false;
        }

        public function render(DatePeriod $period, array $widgets, int $maxResults): array
        {
            return [];
        }

        public function exportCsvUrl(DatePeriod $period): ?string
        {
            return null;
        }
    };

    $disabled = new class implements AnalyticsDashboardTab
    {
        public function id(): string
        {
            return 'beta';
        }

        public function label(): string
        {
            return 'Beta';
        }

        public function icon(): string
        {
            return 'square-2-stack';
        }

        public function enabled(): bool
        {
            return false;
        }

        public function usesPeriod(): bool
        {
            return false;
        }

        public function requiresYandexConnection(): bool
        {
            return false;
        }

        public function render(DatePeriod $period, array $widgets, int $maxResults): array
        {
            return [];
        }

        public function exportCsvUrl(DatePeriod $period): ?string
        {
            return null;
        }
    };

    $registry->register($enabled);
    $registry->register($disabled);

    expect($registry->has('alpha'))->toBeTrue()
        ->and($registry->find('beta'))->toBe($disabled)
        ->and($registry->enabled())->toHaveCount(1)
        ->and($registry->enabled()[0]->id())->toBe('alpha');
});