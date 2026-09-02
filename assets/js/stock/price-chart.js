import { THEME_COLORS } from '../utils/colors.js';

let lwChart = null;
let areaSeries = null;
let chartResizeObserver = null;
let currentRange = '1y';
let currentSimTime = 0;
let lastChartPointTime = 0;
let currentStepSize = 1;
let currentTicker = null;
let secondsPerTick = 600;

export function initPriceChart(container, ticker, ticksPerYear = 54000) {
    if (!container || typeof LightweightCharts === 'undefined') return null;

    destroyPriceChart();
    container.innerHTML = '';
    currentTicker = ticker;
    secondsPerTick = Math.round(31536000 / (ticksPerYear || 54000));

    lwChart = LightweightCharts.createChart(container, {
        layout: {
            background: { type: 'solid', color: 'transparent' },
            textColor: THEME_COLORS.textMuted,
            fontFamily: '"Courier Prime", monospace'
        },
        grid: {
            vertLines: { visible: false },
            horzLines: { color: THEME_COLORS.grid, style: 3 }
        },
        rightPriceScale: {
            borderVisible: false,
            autoScale: true,
            scaleMargins: { top: 0.1, bottom: 0.1 }
        },
        timeScale: {
            borderVisible: false,
            timeVisible: true,
            secondsVisible: true,
            fixLeftEdge: true,
            fixRightEdge: true
        },
        crosshair: { mode: 0 }
    });

    areaSeries = lwChart.addSeries(LightweightCharts.AreaSeries, {
        lineColor: THEME_COLORS.positive,
        topColor: 'rgba(78, 222, 163, 0.4)',
        bottomColor: 'rgba(78, 222, 163, 0.0)',
        lineWidth: 2,
        priceFormat: { type: 'price', precision: 2, minMove: 0.01 }
    });

    chartResizeObserver = new ResizeObserver(entries => {
        if (entries.length > 0 && entries[0].target === container && lwChart) {
            lwChart.applyOptions({
                height: entries[0].contentRect.height,
                width: entries[0].contentRect.width
            });
        }
    });
    chartResizeObserver.observe(container);

    setupRangeButtons();
    loadPriceHistory('1y');

    return lwChart;
}

export function setupRangeButtons() {
    document.querySelectorAll('.range-btn').forEach(btn => {
        btn.onclick = (e) => {
            const range = e.currentTarget.dataset.range;
            if (range) loadPriceHistory(range);
        };
    });
}

export async function loadPriceHistory(range) {
    if (!currentTicker || !areaSeries) return;
    currentRange = range;

    const spinner = document.getElementById('chart-spinner');
    if (spinner) {
        spinner.classList.remove('hidden');
        spinner.classList.add('flex');
    }

    document.querySelectorAll('.range-btn').forEach(btn => {
        btn.className = btn.dataset.range === range
            ? 'range-btn px-4 py-1.5 text-xs font-bold rounded-md bg-primary text-[#001a42] shadow-lg shadow-primary/20 transition-colors'
            : 'range-btn px-4 py-1.5 text-xs font-bold rounded-md bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-colors';
    });

    try {
        const res = await fetch(`/api/history?ticker=${encodeURIComponent(currentTicker)}&range=${encodeURIComponent(range)}`);
        if (!res.ok) return;
        const data = await res.json();
        if (!data || data.length === 0 || !areaSeries) return;

        const rangeSpans = {
            '1w': 604800,
            '1m': 2592000,
            '3m': 7776000,
            '6m': 15552000,
            '1y': 31536000,
            '3y': 94608000,
            '5y': 157680000,
            '10y': 315360000,
            'max': 630720000
        };

        const anchorTime = Math.floor(Date.now() / 1000);
        currentStepSize = Math.max(1, Math.floor((rangeSpans[range] || 31536000) / data.length));

        const chartData = data.map((d, i) => {
            const pointsFromEnd = (data.length - 1) - i;
            return {
                time: anchorTime - (pointsFromEnd * currentStepSize),
                value: parseFloat(d.price)
            };
        }).filter(d => !isNaN(d.value)).sort((a, b) => a.time - b.time);

        areaSeries.setData(chartData);
        if (lwChart) {
            setTimeout(() => {
                if (lwChart) lwChart.timeScale().fitContent();
            }, 50);
        }

        currentSimTime = chartData[chartData.length - 1].time;
        lastChartPointTime = currentSimTime;
    } catch (err) {
        console.error('Failed to load history:', err);
    } finally {
        if (spinner) {
            spinner.classList.add('hidden');
            spinner.classList.remove('flex');
        }
    }
}

export function updateLivePricePoint(newPrice) {
    if (document.visibilityState !== 'visible' || isNaN(newPrice) || currentSimTime <= 0 || !areaSeries) return;

    currentSimTime += secondsPerTick;
    if (currentSimTime >= lastChartPointTime + currentStepSize) {
        lastChartPointTime += currentStepSize;
    }

    areaSeries.update({ time: lastChartPointTime, value: newPrice });
}

export function resizePriceChart() {
    if (lwChart) {
        const cEl = document.getElementById('mainChartContainer');
        if (cEl && cEl.clientWidth > 0) {
            lwChart.applyOptions({ width: cEl.clientWidth });
        }
    }
}

export function destroyPriceChart() {
    if (chartResizeObserver) {
        try { chartResizeObserver.disconnect(); } catch (e) {}
        chartResizeObserver = null;
    }
    if (lwChart) {
        try { lwChart.remove(); } catch (e) {}
        lwChart = null;
        areaSeries = null;
    }
}
