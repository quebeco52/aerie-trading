/**
 * The figures under the cursor, in the chart's top-left corner: the price, or a candle's open, high, low and close, and
 * the change since the first bar in view (in points for a yield). Hidden while the cursor is off the chart, where the
 * scale prints the last price; the crosshair's own label prints the date.
 */

import { formatPercent, formatPoints, signedClass, SIGNED_CLASSES } from '../utils/formatters.js';

/** The month and year the change is counted from, as the time axis dates its bars. */
const SINCE_FORMAT = new Intl.DateTimeFormat('en-GB', { month: 'short', year: 'numeric', timeZone: 'UTC' });

function figure(label, value) {
    const span = document.createElement('span');
    if (label) {
        const name = document.createElement('span');
        name.className = 'text-on-surface-variant';
        name.textContent = `${label} `;
        span.append(name);
    }
    const number = document.createElement('span');
    number.className = 'font-mono text-on-surface';
    number.textContent = value;
    span.append(number);
    return span;
}

/**
 * Draws the readout inside `host`, a positioned element around the chart. `drawnSeries()` returns the rendering of the
 * price now shown, a line or candles, and `drawnField()` what it plots: 'price', or a bond's 'yield'.
 */
export function attachChartReadout(chart, host, drawnSeries, drawnField) {
    const box = document.createElement('div');
    box.className = 'hidden absolute left-3 top-2 z-30 pointer-events-none flex flex-wrap items-baseline gap-x-3 gap-y-1 rounded-md bg-surface-container/80 px-2 py-1 text-xs';
    const figures = document.createElement('span');
    figures.className = 'flex items-baseline gap-x-3';
    const change = document.createElement('span');
    change.className = 'font-mono';
    const since = document.createElement('span');
    since.className = 'text-on-surface-variant';
    box.append(figures, change, since);
    host.appendChild(box);

    function onCrosshairMove(param) {
        const series = drawnSeries();
        const bar = param.point && series ? param.seriesData.get(series) : null;
        const range = chart.timeScale().getVisibleLogicalRange();
        const start = range ? series.dataByIndex(Math.max(0, Math.ceil(range.from)), LightweightCharts.MismatchDirection.NearestRight) : null;
        if (!bar || !start) {
            box.classList.add('hidden');
            return;
        }
        const format = value => series.priceFormatter().format(value);
        const close = bar.value ?? bar.close;
        figures.replaceChildren(...(bar.close === undefined
            ? [figure('', format(close))]
            : [figure('O', format(bar.open)), figure('H', format(bar.high)), figure('L', format(bar.low)), figure('C', format(close))]));

        const base = start.value ?? start.close;
        const isYield = drawnField() === 'yield';
        const move = isYield ? close - base : (base > 0 ? close / base - 1 : null);
        change.classList.remove(...SIGNED_CLASSES);
        change.classList.add(signedClass(move));
        change.textContent = isYield ? formatPoints(move, 2, true) : formatPercent(move, 2, false, true);
        since.textContent = `since ${SINCE_FORMAT.format(new Date(start.time * 1000))}`;
        box.classList.remove('hidden');
    }
    chart.subscribeCrosshairMove(onCrosshairMove);

    return {
        destroy() {
            chart.unsubscribeCrosshairMove(onCrosshairMove);
            box.remove();
        },
    };
}
