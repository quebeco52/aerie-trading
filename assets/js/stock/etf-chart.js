import { SERIES, THEME_COLORS, withAlpha } from '../utils/colors.js';
import { destroyChartInstance } from '../utils/chart-config.js';
import { CHART_FONT_MONO } from '../utils/fonts.js';

/** Up to eight holdings each take a series slot; past that, seven do and the rest share one grey "Other". */
const PALETTE = Object.values(SERIES);
const OTHER_COLOR = withAlpha(THEME_COLORS.textMuted, 0.35);
const OTHER_LABEL = 'Other';

let etfPieChart = null;
let etfComponents = [];
/** Tickers folded into "Other", fixed when the chart is built. */
let otherTickers = new Set();

const sumOf = (values) => [...values].reduce((sum, v) => sum + v, 0);

/**
 * Ranks the holdings once, by value at load. Each named holding keeps its colour and place for the life of
 * the chart, so a live price move reweights the slices without repainting or reordering them.
 */
export function prepareEtfData(pieLabels, pieData) {
    if (!Array.isArray(pieLabels)) return [];
    const ranked = pieLabels
        .map((ticker, i) => ({ ticker, value: (pieData && pieData[i]) || 0 }))
        .sort((a, b) => b.value - a.value);

    const named = ranked.length <= PALETTE.length ? ranked : ranked.slice(0, PALETTE.length - 1);
    const rest = ranked.slice(named.length);
    otherTickers = new Set(rest.map(c => c.ticker));

    const slices = named.map((c, i) => ({ ...c, color: PALETTE[i] }));
    if (rest.length > 0) {
        const members = new Map(rest.map(c => [c.ticker, c.value]));
        slices.push({ ticker: OTHER_LABEL, value: sumOf(members.values()), color: OTHER_COLOR, members });
    }
    return slices;
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
                // A surface-coloured gap between slices, so neighbours read as separate marks.
                borderColor: THEME_COLORS.surface,
                borderWidth: 2,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '75%',
            onHover: (e, el) => {
                if (e.native?.target) {
                    const named = el.length > 0 && etfPieChart?.data.labels[el[0].index] !== OTHER_LABEL;
                e.native.target.style.cursor = named ? 'pointer' : 'default';
                }
            },
            onClick: (e, el) => {
                const ticker = el.length > 0 && etfPieChart ? etfPieChart.data.labels[el[0].index] : null;
                if (ticker && ticker !== OTHER_LABEL) {
                    window.location.href = '/stock/' + ticker;
                }
            },
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 8,
                        usePointStyle: true,
                        color: THEME_COLORS.textMuted,
                        font: { family: CHART_FONT_MONO, size: 10 }
                    }
                },
                tooltip: {
                    backgroundColor: withAlpha(THEME_COLORS.surfaceRaised, 0.96),
                    titleColor: THEME_COLORS.textPrimary,
                    bodyColor: THEME_COLORS.textMuted,
                    borderColor: THEME_COLORS.grid,
                    borderWidth: 1,
                    padding: 12,
                    callbacks: {
                        label: function (context) {
                            const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
                            const value = context.raw;
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(2) : 0;
                            const other = etfComponents[context.dataIndex]?.members;
                            const label = other ? `Other (${other.size} holdings)` : context.label;
                            return ` ${label}: ${percentage}%`;
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
    const other = etfComponents.find(c => c.members);
    let updated = false;

    payload.stocks.forEach(stock => {
        const shares = sharesMap[stock.ticker] || 0;
        const value = parseFloat(stock.price) * shares;
        if (otherTickers.has(stock.ticker) && other) {
            other.members.set(stock.ticker, value);
            updated = true;
            return;
        }
        const comp = etfComponents.find(c => c.ticker === stock.ticker);
        if (comp) {
            comp.value = value;
            updated = true;
        }
    });

    if (updated) {
        if (other) other.value = sumOf(other.members.values());
        etfPieChart.data.datasets[0].data = etfComponents.map(c => c.value);
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
    otherTickers = new Set();
}
