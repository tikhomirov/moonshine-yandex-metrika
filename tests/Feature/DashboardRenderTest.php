<?php

declare(strict_types=1);

use Tikhomirov\MoonshineYandexMetrika\Components\LineChartComponent;
use Tikhomirov\MoonshineYandexMetrika\Pages\MetrikaDashboardPage;
use Tikhomirov\MoonshineYandexMetrika\Services\MetrikaService;

beforeEach(function () {
    // Fake API responses: no HTTP requests to Yandex in tests.
    $this->app->instance(MetrikaService::class, new class extends MetrikaService {
        public function request(array $params): ?array
        {
            if (($params['dimensions'] ?? null) === 'ym:s:date') {
                return ['data' => [
                    ['dimensions' => [['name' => '2026-11-01']], 'metrics' => [10]],
                    ['dimensions' => [['name' => '2026-11-02']], 'metrics' => [15]],
                ]];
            }

            return [
                'data' => [
                    ['dimensions' => [['name' => 'item']], 'metrics' => [5, 3]],
                ],
                'totals' => [42, 7],
            ];
        }
    });
});

test('line chart component is created via inherited make() and renders', function () {
    $chart = LineChartComponent::make([
        ['dimension' => '2026-11-01', 'metrics' => [10]],
        ['dimension' => '2026-11-02', 'metrics' => [15]],
    ]);

    expect($chart)->toBeInstanceOf(LineChartComponent::class)
        ->and($chart->getLabels())->toBe(['2026-11-01', '2026-11-02'])
        ->and($chart->getValues())->toBe([10, 15]);

    $html = (string) $chart->render();

    expect($html)->toContain('<canvas id="metrika_chart_')
        ->and($html)->toContain('["2026-11-01","2026-11-02"]');
});

test('line chart component accepts empty data', function () {
    expect((string) LineChartComponent::make()->render())->toContain('<canvas');
});

test('metrika dashboard renders all default widgets including visits chart', function () {
    $page = app(MetrikaDashboardPage::class);

    $html = collect($page->getComponents())
        ->map(fn ($component) => (string) $component->render())
        ->implode('');

    expect($html)->toContain('<canvas id="metrika_chart_')
        ->and($html)->toContain('2026-11-02')
        ->and($html)->toContain('42');
});
