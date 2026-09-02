import { BRAND_COLORS, FALLBACK_PALETTE, THEME_COLORS } from '../utils/colors.js';
import { destroyChartInstance } from '../utils/chart-config.js';

let etfPieChart = null;
let etfComponents = [];

export function prepareEtfData(pieLabels, pieData) {
    if (!Array.isArray(pieLabels)) return [];
    let components = [];
    let fIndex = 0;
    pieLabels.forEach((ticker, i) => {
        let color = BRAND_COLORS[ticker] || FALLBACK_PALETTE[fIndex++ % FALLBACK_PALETTE.length];
        let val = (pieData && pieData[i]) || 0;
        components.push({ ticker, value: val, color });
    });
    return components.sort((a, b) => b.value - a.value);
}

export function initEtfChart(canvasId = 'etfPieChart', pieLabels = [], pieData = []) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return null;

    destroyEtfChart();
    etfComponents = prepareEtfData(pieLabels, pieData);

    const ctx = canvas.getContext('2d');
    etfPieChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: etfComponents.map(c => c.ticker),
            datasets: [{
                data: etfComponents.map(c => c.value),
                backgroundColor: etfComponents.map(c => c.color),
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '75%',
            onHover: (e, el) => {
                if (e.native?.target) {
                    e.native.target.style.cursor = el.length ? 'pointer' : 'default';
                }
            },
            onClick: (e, el) => {
                if (el.length > 0 && etfPieChart) {
                    window.location.href = '/stock/' + etfPieChart.data.labels[el[0].index];
                }
            },
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 8,
                        usePointStyle: true,
                        color: THEME_COLORS.textMuted,
                        font: { family: '"Courier Prime", monospace', size: 10 }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(19, 27, 46, 0.9)',
                    titleColor: '#dae2fd',
                    bodyColor: '#c2c6d6',
                    borderColor: '#424754',
                    borderWidth: 1,
                    padding: 12,
                    callbacks: {
                        label: function (context) {
                            const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
                            const value = context.raw;
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(2) : 0;
                            return ` ${context.label}: ${percentage}%`;
                        }
                    }
                }
            }
        }
    });

    return etfPieChart;
}

export function updateEtfPie(payload, sharesMap = {}) {
    if (!etfPieChart || !Array.isArray(payload?.stocks)) return;
    let updated = false;

    payload.stocks.forEach(stock => {
        let comp = etfComponents.find(c => c.ticker === stock.ticker);
        if (comp) {
            const shares = sharesMap[stock.ticker] || 0;
            comp.value = parseFloat(stock.price) * shares;
            updated = true;
        }
    });

    if (updated) {
        etfComponents.sort((a, b) => b.value - a.value);
        etfPieChart.data.labels = etfComponents.map(c => c.ticker);
        etfPieChart.data.datasets[0].data = etfComponents.map(c => c.value);
        etfPieChart.data.datasets[0].backgroundColor = etfComponents.map(c => c.color);
        etfPieChart.update('none');
    }
}

export function resizeEtfChart() {
    if (etfPieChart) {
        try { etfPieChart.resize(); } catch (e) {}
    }
}

export function destroyEtfChart() {
    etfPieChart = destroyChartInstance(etfPieChart);
    etfComponents = [];
}
