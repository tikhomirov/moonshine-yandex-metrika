<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>

<div style="position: relative; height: 300px;">
    <canvas id="{{ $chartId }}"></canvas>
</div>

@include('moonshine-yandex-metrika::components.chart-theme')

<script>
(function () {
    const el = document.getElementById('{{ $chartId }}');
    if (!el || typeof Chart === 'undefined') { return; }
    const colors = ymMetrikaChartColors();
    const chart = new Chart(el.getContext('2d'), {
        type: 'line',
        data: {
            labels: {!! $labels !!},
            datasets: [{
                label: '{{ __("moonshine-yandex-metrika::metrics.visits") }}',
                data: {!! $values !!},
                borderColor: 'rgb(99, 102, 241)',
                backgroundColor: colors.fill,
                borderWidth: 2,
                pointRadius: 3,
                fill: true,
                tension: 0.3,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { mode: 'index', intersect: false }
            },
            scales: ymMetrikaChartScales(colors)
        }
    });
    ymWatchMetrikaChartTheme(chart);
})();
</script>
