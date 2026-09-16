<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>

<div style="position: relative; height: 300px;">
    <canvas id="{{ $chartId }}"></canvas>
</div>

<script>
(function () {
    const ctx = document.getElementById('{{ $chartId }}').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: {!! $labels !!},
            datasets: [{
                label: '{{ __("moonshine-yandex-metrika::metrics.visits") }}',
                data: {!! $values !!},
                borderColor: 'rgb(99, 102, 241)',
                backgroundColor: 'rgba(99, 102, 241, 0.08)',
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
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } }
            }
        }
    });
})();
</script>
