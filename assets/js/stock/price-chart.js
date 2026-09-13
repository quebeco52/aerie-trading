import { THEME_COLORS } from '../utils/colors.js';
import { CHART_FONT_MONO } from '../utils/fonts.js';

let lwChart = null;
let areaSeries = null;
let candleSeries = null;
let volumeSeries = null;
let chartStyle = 'area';
let lastBar = null;
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
            fontFamily: CHART_FONT_MONO
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

    // Candles are created up front and left empty until asked for. Adding a series after the chart has
    // data forces a full relayout, which is visible as a jump when toggling.
    candleSeries = lwChart.addSeries(LightweightCharts.CandlestickSeries, {
        upColor: THEME_COLORS.positive,
        downColor: THEME_COLORS.negative,
        borderVisible: false,
        wickUpColor: THEME_COLORS.positive,
        wickDownColor: THEME_COLORS.negative,
        priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
        visible: false
    });

    // Volume sits in the bottom fifth of the same pane on its own scale, so a mega-cap's share count
    // cannot flatten the price axis it shares.
    volumeSeries = lwChart.addSeries(LightweightCharts.HistogramSeries, {
        priceFormat: { type: 'volume' },
        priceScaleId: 'volume',
        color: 'rgba(173, 198, 255, 0.35)'
    });
    lwChart.priceScale('volume').applyOptions({
        scaleMargins: { top: 0.82, bottom: 0.0 }
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
    setupChartStyleButtons();
    setChartStyle(chartStyle);
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
            ? 'range-btn px-4 py-1.5 text-xs font-bold rounded-md bg-primary text-on-primary shadow-lg shadow-primary/20 transition-colors'
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

        const chartData = [];
        const candleData = [];
        const volumeData = [];

        data.forEach((d, i) => {
            const close = parseFloat(d.price);
            if (isNaN(close)) return;

            const time = anchorTime - (((data.length - 1) - i) * currentStepSize);
            chartData.push({ time, value: close });

            // The server aggregates history rows into the bars this range renders, so a bar arrives whole.
            // The fallback covers a payload from an older deploy, where a flat candle is the honest reading.
            const open = d.open_price != null ? parseFloat(d.open_price) : close;
            const high = d.high_price != null ? parseFloat(d.high_price) : close;
            const low = d.low_price != null ? parseFloat(d.low_price) : close;

            candleData.push({ time, open, high, low, close });

            if (d.volume != null) {
                volumeData.push({
                    time,
                    value: parseFloat(d.volume),
                    color: close >= open ? 'rgba(78, 222, 163, 0.35)' : 'rgba(255, 179, 173, 0.35)'
                });
            }
        });

        chartData.sort((a, b) => a.time - b.time);
        candleData.sort((a, b) => a.time - b.time);
        volumeData.sort((a, b) => a.time - b.time);

        if (chartData.length === 0) return;

        areaSeries.setData(chartData);
        if (candleSeries) candleSeries.setData(candleData);
        if (volumeSeries) volumeSeries.setData(volumeData);

        lastBar = candleData.length > 0 ? { ...candleData[candleData.length - 1] } : null;
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

export function updateLivePricePoint(newPrice, volume = 0) {
    if (document.visibilityState !== 'visible' || isNaN(newPrice) || currentSimTime <= 0 || !areaSeries) return;

    currentSimTime += secondsPerTick;

    const openedNewBar = currentSimTime >= lastChartPointTime + currentStepSize;
    if (openedNewBar) {
        lastChartPointTime += currentStepSize;
    }

    areaSeries.update({ time: lastChartPointTime, value: newPrice });

    if (!candleSeries) return;

    // The live bar is extended tick by tick rather than replaced, so its high and low accumulate the way
    // the server's do. Replacing it each tick would draw every bar as a doji.
    if (!lastBar || openedNewBar || lastBar.time !== lastChartPointTime) {
        lastBar = {
            time: lastChartPointTime,
            open: newPrice,
            high: newPrice,
            low: newPrice,
            close: newPrice,
            volume: 0
        };
    } else {
        lastBar.high = Math.max(lastBar.high, newPrice);
        lastBar.low = Math.min(lastBar.low, newPrice);
        lastBar.close = newPrice;
    }

    lastBar.volume = (lastBar.volume || 0) + (Number(volume) || 0);
    candleSeries.update(lastBar);

    if (volumeSeries && lastBar.volume > 0) {
        volumeSeries.update({
            time: lastBar.time,
            value: lastBar.volume,
            color: lastBar.close >= lastBar.open ? 'rgba(78, 222, 163, 0.35)' : 'rgba(255, 179, 173, 0.35)'
        });
    }
}

/** Switches between the line and candle renderings of the same series. */
export function setChartStyle(style) {
    chartStyle = style === 'candles' ? 'candles' : 'area';

    if (areaSeries) areaSeries.applyOptions({ visible: chartStyle === 'area' });
    if (candleSeries) candleSeries.applyOptions({ visible: chartStyle === 'candles' });

    document.querySelectorAll('.chart-style-btn').forEach(btn => {
        const active = btn.dataset.style === chartStyle;
        btn.classList.toggle('bg-primary', active);
        btn.classList.toggle('text-on-primary', active);
        btn.classList.toggle('bg-surface-container', !active);
        btn.classList.toggle('text-on-surface-variant', !active);
    });
}

export function setupChartStyleButtons() {
    document.querySelectorAll('.chart-style-btn').forEach(btn => {
        btn.onclick = (e) => setChartStyle(e.currentTarget.dataset.style);
    });
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
        candleSeries = null;
        volumeSeries = null;
        lastBar = null;
    }
}
