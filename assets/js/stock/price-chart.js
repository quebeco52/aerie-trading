import { THEME_COLORS } from '../utils/colors.js';
import { CHART_FONT_MONO } from '../utils/fonts.js';
import { fillTickGaps } from '../utils/tick-gaps.js';

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
let chartData = [];
let candleData = [];
let volumeData = [];

/**
 * Points the live series are allowed to reach before the oldest are dropped.
 *
 * Paint cost is set by the VISIBLE slots, not by the total, so this is a memory bound rather than a frame
 * rate one: on a one-year range the tail appends around thirty points a second and would otherwise grow
 * without limit for as long as the tab stays open.
 */
const MAX_SERIES_POINTS = 20000;

/** Points dropped in one trim. Dropping a batch makes the rebuild rare enough that the hitch is not seen. */
const TRIM_BATCH = 8000;

/**
 * Most repaints a second while a wire frame's ticks play out. Every paint redraws the whole canvas; at thirty a
 * one-year line already scrolls about a pixel per paint, so a higher rate buys sub-pixel steps.
 */
const LIVE_PAINTS_PER_SECOND = 30;

/** Timestamp jitter tolerated when holding a paint to LIVE_PAINTS_PER_SECOND. */
const PAINT_JITTER_MS = 2;

/** Wire frame spacing assumed until frames have been timed: WireFrame::FRAMES_PER_SECOND is ten. */
const INITIAL_FRAME_INTERVAL_MS = 100;

/** Gain of the frame spacing estimate: the RFC 3550 interarrival jitter filter's 1/16. */
const FRAME_INTERVAL_GAIN = 1 / 16;

/** A gap between frames this long is a stalled or reconnecting feed, not the frame rate, and is not timed. */
const MAX_FRAME_GAP_MS = 1000;

/** @type {Array<{due: number, price: number, volume: number}>} Ticks received and not yet drawn, in tick order. */
let pendingPoints = [];
let playoutRaf = null;
let lastPaintAt = 0;
let lastFrameArrival = 0;
let frameIntervalMs = INITIAL_FRAME_INTERVAL_MS;
/** @type {{tick: number, price: number}|null} The last point queued, for filling the ticks a series skips. */
let lastQueuedPoint = null;

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
        // The bar grid belongs to the rendering, not the range: a line is served four times as many slots
        // as a candle (PriceBarAggregator::LINE_TARGET_BARS), which is what keeps the live tail moving.
        const style = chartStyle === 'candles' ? 'candles' : 'line';
        const res = await fetch(`/api/history?ticker=${encodeURIComponent(currentTicker)}&range=${encodeURIComponent(range)}&style=${style}`);
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

        // A slot shorter than a tick cannot be advanced one tick at a time: the live clock would outrun the
        // grid and the tail would fall further behind the price on every point. A buffered line is served one
        // tick per slot, and a week over its whole number of ticks comes out a little under one; this puts the
        // slot back on the tick.
        currentStepSize = Math.max(
            secondsPerTick,
            Math.floor((rangeSpans[range] || 31536000) / data.length)
        );

        chartData = [];
        candleData = [];
        volumeData = [];

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

        clearPendingPoints();
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

/**
 * Plays one wire frame's ticks into the live series, spread across the time until the next frame is due.
 *
 * Applied together, a frame's ticks land in one animation frame and cost one paint, which held the chart to the
 * wire's frame rate however fast the simulation ticked. Nothing is interpolated: every state drawn is a tick the
 * server published, drawn up to one wire frame and one paint late. A frame that arrives while the last is still playing does not
 * wait for it: the older ticks are already due, so the next paint applies them first.
 *
 * A series sent only on some ticks — a bond, on history bars — has the ticks between restored at its last price
 * first (utils/tick-gaps.js), since the clock below advances one tick per point.
 *
 * @param {Array<[number, number, (number|null)]>} received `[price, volume, tick]` per tick, in tick order (market-stream.js tickPoints()).
 */
export function queueLivePricePoints(received) {
    if (received.length === 0 || !areaSeries) return;

    const { points, last } = fillTickGaps(received, lastQueuedPoint);
    lastQueuedPoint = last;

    const now = performance.now();
    const gap = now - lastFrameArrival;
    if (lastFrameArrival > 0 && gap < MAX_FRAME_GAP_MS) {
        frameIntervalMs += (gap - frameIntervalMs) * FRAME_INTERVAL_GAIN;
    }
    lastFrameArrival = now;

    const spacing = frameIntervalMs / points.length;
    points.forEach(([price, volume], i) => {
        pendingPoints.push({ due: now + i * spacing, price, volume });
    });

    if (playoutRaf === null) playoutRaf = requestAnimationFrame(playOutPendingPoints);
}

/** Draws every queued tick that has come due, at most LIVE_PAINTS_PER_SECOND times a second. */
function playOutPendingPoints(now) {
    playoutRaf = null;

    if (now - lastPaintAt >= 1000 / LIVE_PAINTS_PER_SECOND - PAINT_JITTER_MS) {
        let drawn = 0;
        while (drawn < pendingPoints.length && pendingPoints[drawn].due <= now) {
            updateLivePricePoint(pendingPoints[drawn].price, pendingPoints[drawn].volume);
            drawn++;
        }
        if (drawn > 0) {
            pendingPoints.splice(0, drawn);
            lastPaintAt = now;
        }
    }

    if (pendingPoints.length > 0) playoutRaf = requestAnimationFrame(playOutPendingPoints);
}

/** Drops ticks not yet drawn: the series they extend is being replaced. */
function clearPendingPoints() {
    if (playoutRaf !== null) cancelAnimationFrame(playoutRaf);
    playoutRaf = null;
    pendingPoints = [];
    lastFrameArrival = 0;
    lastQueuedPoint = null;
}

function updateLivePricePoint(newPrice, volume = 0) {
    if (document.visibilityState !== 'visible' || isNaN(newPrice) || currentSimTime <= 0 || !areaSeries) return;

    currentSimTime += secondsPerTick;

    const openedNewBar = currentSimTime >= lastChartPointTime + currentStepSize;
    if (openedNewBar) {
        lastChartPointTime += currentStepSize;
        trimSeriesIfNeeded();
    }

    const point = { time: lastChartPointTime, value: newPrice };
    areaSeries.update(point);

    // The series is mirrored here so the tail can be trimmed: lightweight-charts has no way to drop a
    // point, only to be handed the series again.
    if (openedNewBar || chartData.length === 0) {
        chartData.push(point);
    } else {
        chartData[chartData.length - 1] = point;
    }

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

    if (openedNewBar || candleData.length === 0) {
        candleData.push({ ...lastBar });
    } else {
        candleData[candleData.length - 1] = { ...lastBar };
    }

    if (volumeSeries && lastBar.volume > 0) {
        const volumePoint = {
            time: lastBar.time,
            value: lastBar.volume,
            color: lastBar.close >= lastBar.open ? 'rgba(78, 222, 163, 0.35)' : 'rgba(255, 179, 173, 0.35)'
        };
        volumeSeries.update(volumePoint);

        const lastVolume = volumeData[volumeData.length - 1];
        if (lastVolume && lastVolume.time === volumePoint.time) {
            volumeData[volumeData.length - 1] = volumePoint;
        } else {
            volumeData.push(volumePoint);
        }
    }
}

/**
 * Drops the oldest points once the live tail has grown past its bound.
 *
 * Every series shares one time scale, and that scale is indexed rather than measured: a slot exists
 * because some series has a point at it. Trimming one series and not the others would leave the dropped
 * slots in the index as gaps, so all three are cut to the same instant. The visible window is expressed in
 * those same indices, which shift under the cut, so it is put back where it was pointing.
 */
function trimSeriesIfNeeded() {
    if (chartData.length <= MAX_SERIES_POINTS || !lwChart || !areaSeries) return;

    const cutoff = chartData[TRIM_BATCH].time;
    const visible = lwChart.timeScale().getVisibleLogicalRange();

    chartData = chartData.slice(TRIM_BATCH);
    candleData = candleData.filter(bar => bar.time >= cutoff);
    volumeData = volumeData.filter(bar => bar.time >= cutoff);

    areaSeries.setData(chartData);
    if (candleSeries) candleSeries.setData(candleData);
    if (volumeSeries) volumeSeries.setData(volumeData);

    if (visible) {
        // Shifting the window by the whole batch would push it past the start of what is left and open a
        // gutter of empty slots, so it stops at the oldest point; the zoom level is carried across either way.
        const width = visible.to - visible.from;
        const from = Math.max(0, visible.from - TRIM_BATCH);
        lwChart.timeScale().setVisibleLogicalRange({ from, to: from + width });
    }
}

/** Switches between the line and candle renderings of the same series. */
export function setChartStyle(style) {
    const next = style === 'candles' ? 'candles' : 'area';
    const gridChanged = next !== chartStyle;
    chartStyle = next;

    if (areaSeries) areaSeries.applyOptions({ visible: chartStyle === 'area' });
    if (candleSeries) candleSeries.applyOptions({ visible: chartStyle === 'candles' });

    document.querySelectorAll('.chart-style-btn').forEach(btn => {
        const active = btn.dataset.style === chartStyle;
        btn.classList.toggle('bg-primary', active);
        btn.classList.toggle('text-on-primary', active);
        btn.classList.toggle('bg-surface-container', !active);
        btn.classList.toggle('text-on-surface-variant', !active);
    });

    // The two renderings are served on different bar grids and the time scale is shared by every series,
    // so they cannot both be on the chart at once: the range is re-read at the resolution now being drawn.
    if (gridChanged && lwChart && currentTicker && chartData.length > 0) {
        loadPriceHistory(currentRange);
    }
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
    clearPendingPoints();
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
        chartData = [];
        candleData = [];
        volumeData = [];
    }
}
