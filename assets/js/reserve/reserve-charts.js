import { THEME_COLORS } from '../utils/colors.js';
import { destroyChartInstance } from '../utils/chart-config.js';
import { renderWhenVisible, resetLazyCharts } from '../utils/lazy-chart.js';

/** The sleeve colours the page's allocation bar uses, so a sleeve reads the same in every panel. */
const SLEEVE_COLORS = { district: '#0284c7', equities: '#059669' };
/** The two flows on the money chart; validated as a pair against the dark surface. */
const FLOW_COLORS = { draw: '#8b5cf6', duty: '#0891b2' };
const REFERENCE_COLOR = THEME_COLORS.textMuted;
const GRID_COLOR = 'rgba(255, 255, 255, 0.05)';

const charts = {};

/** A macro_report column as a percentage, or null where the quarter has no reading. */
function pct(report, column) {
    const raw = report[column];
    if (raw === null || raw === undefined) return null;
    const value = parseFloat(raw);
    return Number.isFinite(value) ? value * 100 : null;
}

function percentTooltip(ctx) {
    return `${ctx.dataset.label}: ${ctx.raw === null || ctx.raw === undefined ? 'N/A' : ctx.raw.toFixed(2) + '%'}`;
}

function lineDataset(label, data, color, extra = {}) {
    return {
        type: 'line',
        label,
        data,
        borderColor: color,
        backgroundColor: color,
        borderWidth: 2,
        tension: 0.2,
        fill: false,
        spanGaps: false,
        pointRadius: data.length > 50 ? 0 : 2,
        pointHoverRadius: 4,
        ...extra
    };
}

/**
 * A weight against its policy and band: the band is two edges filled between, the policy a dashed reference line.
 * The lower edge is drawn but left out of the legend and the tooltip, which name the band once.
 */
function bandDatasets(weightLabel, weightData, policy, band, color, fillColor) {
    const n = weightData.length;
    return [
        lineDataset(weightLabel, weightData, color),
        lineDataset('Policy', Array(n).fill(policy * 100), REFERENCE_COLOR, { borderDash: [5, 4], borderWidth: 1.5, pointRadius: 0, tension: 0 }),
        lineDataset('Band', Array(n).fill((policy + band) * 100), fillColor, { borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, tension: 0, fill: '+1', backgroundColor: fillColor, isBandEdge: true }),
        lineDataset('Band lower', Array(n).fill((policy - band) * 100), fillColor, { borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, tension: 0, isBandEdge: true, hideFromLegend: true })
    ];
}

function chartOptions({ legend = true } = {}) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                display: legend,
                position: 'bottom',
                labels: { boxWidth: 8, usePointStyle: true, filter: (item, data) => !data.datasets[item.datasetIndex].hideFromLegend }
            },
            tooltip: {
                filter: (item) => !item.dataset.isBandEdge,
                callbacks: { label: percentTooltip }
            }
        },
        scales: {
            y: {
                grid: { color: GRID_COLOR },
                ticks: { callback: (val) => val.toFixed(1) + '%' }
            },
            x: {
                grid: { color: GRID_COLOR },
                ticks: { maxTicksLimit: 8 }
            }
        }
    };
}

function draw(id, datasets, labels, options) {
    renderWhenVisible(id, () => {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        charts[id] = destroyChartInstance(charts[id]);
        charts[id] = new Chart(canvas.getContext('2d'), { data: { labels, datasets }, options });
    });
}

/**
 * Draws the fund's quarterly history from /api/macro-reports.
 *
 * Quarters before the fund opened carry a zero size and are left out, so the history starts at inception. The policy
 * weights and bands are the fund's own, set once at inception, and come from the page rather than being recomputed.
 *
 * @param {Array<Object>} reports  macro_report rows in chronological order
 * @param {{domesticPolicy: number, domesticBand: number, equityPolicy: number, equityBand: number}} bands
 * @returns {number} the number of quarters drawn
 */
export function renderReserveCharts(reports, bands) {
    if (typeof Chart === 'undefined') return 0;

    const rows = reports.filter(r => parseFloat(r.sovereign_fund_to_gdp ?? 0) > 0);
    if (rows.length === 0) return 0;

    const labels = rows.map((_, i) => {
        const back = rows.length - i - 1;
        return back === 0 ? 'Now' : `-${back}Q`;
    });

    const size = rows.map(r => pct(r, 'sovereign_fund_to_gdp'));
    const weight = rows.map(r => pct(r, 'sovereign_fund_domestic_weight'));
    const equity = rows.map(r => pct(r, 'sovereign_fund_equity_share'));
    const drawToGdp = rows.map(r => pct(r, 'sovereign_fund_draw_to_gdp'));
    // Zero until the fund's first budget year closes: a gap, not a reading.
    const duty = rows.map(r => {
        const v = pct(r, 'sovereign_fund_stamp_duty_to_gdp');
        return v === null || v <= 0 ? null : v;
    });

    draw('reserveSizeChart', [lineDataset('Fund size', size, THEME_COLORS.primary)], labels, chartOptions({ legend: false }));
    draw('reserveWeightChart', bandDatasets('District weight', weight, bands.domesticPolicy, bands.domesticBand, SLEEVE_COLORS.district, 'rgba(2, 132, 199, 0.15)'), labels, chartOptions());
    draw('reserveEquityChart', bandDatasets('Equity share', equity, bands.equityPolicy, bands.equityBand, SLEEVE_COLORS.equities, 'rgba(5, 150, 105, 0.15)'), labels, chartOptions());
    draw('reserveFlowsChart', [
        lineDataset('Budget draw', drawToGdp, FLOW_COLORS.draw),
        lineDataset('Stamp duty', duty, FLOW_COLORS.duty)
    ], labels, chartOptions());

    return rows.length;
}

export function resizeReserveCharts() {
    Object.values(charts).forEach(c => {
        if (c) {
            try { c.resize(); } catch (e) { }
        }
    });
}

export function destroyReserveCharts() {
    resetLazyCharts();
    Object.keys(charts).forEach(id => {
        charts[id] = destroyChartInstance(charts[id]);
    });
}
