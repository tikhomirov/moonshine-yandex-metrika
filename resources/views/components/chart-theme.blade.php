<script>
if (typeof window.ymMetrikaChartColors !== 'function') {
    window.ymMetrikaChartColors = function () {
        var root = document.documentElement;
        var css = window.getComputedStyle(root);
        var dark = root.classList.contains('dark');
        var text = (css.getPropertyValue('--ms-cm-base-text') || '').trim();
        var grid = (css.getPropertyValue('--ms-cm-base-stroke') || '').trim();

        return {
            text: ymCanvasColor(text, dark ? '#e2e8f0' : '#334155'),
            grid: ymCanvasColor(grid, dark ? 'rgba(255,255,255,0.12)' : 'rgba(15,23,42,0.08)'),
            fill: dark ? 'rgba(99, 102, 241, 0.22)' : 'rgba(99, 102, 241, 0.08)',
        };
    };

    function ymCanvasColor(value, fallback) {
        var canvas = document.createElement('canvas');
        var context = canvas.getContext('2d');
        if (!context) {
            return fallback;
        }
        context.fillStyle = '#010203';
        if (value) {
            context.fillStyle = value;
        }
        if (!value || context.fillStyle === '#010203') {
            context.fillStyle = fallback;
        }
        return context.fillStyle;
    }

    window.ymMetrikaChartScales = function (colors) {
        return {
            x: {
                grid: { display: false },
                ticks: { color: colors.text },
                border: { color: colors.grid },
            },
            y: {
                beginAtZero: true,
                grid: { color: colors.grid },
                ticks: { color: colors.text },
                border: { display: false },
            },
        };
    };

    window.ymWatchMetrikaChartTheme = function (chart) {
        if (typeof MutationObserver === 'undefined' || !chart) {
            return;
        }

        new MutationObserver(function () {
            var colors = window.ymMetrikaChartColors();
            chart.options.scales = window.ymMetrikaChartScales(colors);
            if (chart.data.datasets[0]) {
                chart.data.datasets[0].backgroundColor = colors.fill;
            }
            chart.update();
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    };
}
</script>
