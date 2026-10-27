/**
 * Company stories pinned to the price line: good news and notices above the bar each story landed in, bad news and
 * cautions below it, each marked by tone and labelled by its kind of news. Hovering a marker opens a card with its
 * stories as the news list prints them and tints the crosshair's time line to the story's tone. Served by
 * /api/chart-events (ChartEventMarkers), dated by age behind the newest history row as the bars are.
 */

import { THEME_COLORS } from '../utils/colors.js';
import { sentenceCase } from '../utils/formatters.js';

/** Marker colour by a story's tone (App\Service\News\Tone). */
const TONE_COLORS = {
    up: THEME_COLORS.positive,
    down: THEME_COLORS.negative,
    warn: THEME_COLORS.warning,
    accent: THEME_COLORS.primary,
    neutral: THEME_COLORS.textMuted,
};

/** Marker shape by tone: an arrow for news that moves the price, a dot for a notice. */
const TONE_SHAPES = { up: 'arrowUp', down: 'arrowDown', warn: 'arrowDown', accent: 'circle', neutral: 'circle' };

/** Tones drawn under the line; the rest sit above it. */
const BELOW_TONES = new Set(['down', 'warn']);

/** Which tone leads a marker holding several stories: a fall outranks a warning, news outranks notices. */
const TONE_RANK = { down: 4, warn: 3, up: 2, accent: 1, neutral: 0 };

/** A routine story's one-letter label by its kind of news (App\Service\News\NewsCard); a headline prints its badge. */
const CARD_LETTERS = {
    earnings: 'E',
    shock: '!',
    split: 'S',
    debt: 'R',
    mna: 'D',
    bankruptcy: 'B',
    reorganization: 'R',
    governance: 'M',
    outlook: 'O',
};

/** How close, in pixels, the cursor has to come to a marker's bar for its card to open. */
const HOVER_PX = 8;

/** How far above or below the line, in pixels, the cursor may be and still be on a marker. */
const NEAR_LINE_PX = 40;

/** Gap between the card and the cursor, in pixels. */
const TIP_OFFSET_PX = 12;

/** A marker's size multiplier: a headline the news desk ran, or a routine story. */
const MAJOR_SIZE = 1.4;
const MINOR_SIZE = 1;

/** The bar time nearest to `time` in an ascending list, by binary search. */
function nearestTime(times, time) {
    let lo = 0;
    let hi = times.length - 1;
    while (lo < hi) {
        const mid = (lo + hi) >> 1;
        if (times[mid] < time) lo = mid + 1;
        else hi = mid;
    }
    if (lo > 0 && Math.abs(times[lo - 1] - time) <= Math.abs(times[lo] - time)) return times[lo - 1];
    return times[lo];
}

/** The label a marker prints: a headline's badge, or a routine story's letter, with a count of the stories behind it. */
function markerText(lead, stories) {
    const label = lead.major ? sentenceCase(lead.badge || 'Event') : (CARD_LETTERS[lead.card] || (lead.badge || '?').charAt(0).toUpperCase());
    return stories.length > 1 ? `${label} +${stories.length - 1}` : label;
}

/**
 * Pins stories to the drawn price `series` of `chart`; `follow()` moves them when the chart switches between its line
 * and candle renderings. The hover card is drawn inside `host`, a positioned element around the chart.
 */
export function attachChartEvents(chart, host, series) {
    let priceSeries = series;
    let plugin = LightweightCharts.createSeriesMarkers(priceSeries, []);
    /** @type {Map<string, {time: number, side: string, stories: Array<object>}>} marker groups by marker id */
    let groups = new Map();
    let markers = [];
    /** Counts loads, so a slow answer for a range the reader has already left is dropped. */
    let loadCount = 0;
    /** The marker the cursor is on, so the crosshair is only restyled when that changes. */
    let hovered = null;
    const plainTimeLine = { ...chart.options().crosshair.vertLine };

    const tip = document.createElement('div');
    tip.className = 'hidden absolute z-40 max-w-72 pointer-events-none rounded-md border border-outline-variant/25 bg-surface-container-highest p-3 space-y-2 shadow-sm';
    tip.setAttribute('role', 'tooltip');
    host.appendChild(tip);

    function render(list) {
        markers = list;
        plugin.setMarkers(list);
    }

    function showTip(stories, point) {
        tip.replaceChildren(...stories.map(story => {
            const row = document.createElement('div');
            row.className = 'space-y-1';
            const header = document.createElement('div');
            header.className = 'flex items-center gap-2';
            const badge = document.createElement('span');
            badge.className = `badge badge-${story.tone || 'neutral'}`;
            badge.textContent = sentenceCase(story.badge || 'Event');
            const date = document.createElement('span');
            date.className = 'text-xs text-on-surface-variant';
            date.textContent = story.dateline || '';
            header.append(badge, date);
            const text = document.createElement('p');
            text.className = 'text-xs text-on-surface leading-snug';
            text.textContent = story.headline || '';
            row.append(header, text);
            return row;
        }));
        tip.classList.remove('hidden');
        const width = tip.offsetWidth;
        // The card turns left before it would cover the price scale.
        const right = Math.min(host.clientWidth, chart.paneSize().width);
        const left = point.x + TIP_OFFSET_PX + width > right ? point.x - TIP_OFFSET_PX - width : point.x + TIP_OFFSET_PX;
        tip.style.left = `${Math.max(0, left)}px`;
        tip.style.top = `${Math.max(0, Math.min(point.y, host.clientHeight - tip.offsetHeight))}px`;
    }

    /** Tints the crosshair's time line to the hovered marker's colour, or restores it. */
    function highlight(marker) {
        if (marker === hovered) return;
        hovered = marker;
        chart.applyOptions({
            crosshair: {
                vertLine: marker === null
                    ? plainTimeLine
                    : { ...plainTimeLine, color: marker.color, style: 0, labelBackgroundColor: marker.color },
            },
        });
    }

    /**
     * The marker under the cursor: the one the chart reports hovering, else one on the nearest bar within HOVER_PX
     * when the cursor is close to the line, on the side of the line it is on.
     */
    function markerAt(param) {
        if (typeof param.hoveredObjectId === 'string' && groups.has(param.hoveredObjectId)) {
            return markers.find(marker => marker.id === param.hoveredObjectId) || null;
        }
        const bar = param.seriesData ? param.seriesData.get(priceSeries) : null;
        if (!bar) return null;
        let best = null;
        let bestDistance = HOVER_PX;
        for (const marker of markers) {
            const x = chart.timeScale().timeToCoordinate(marker.time);
            if (x === null) continue;
            const distance = Math.abs(x - param.point.x);
            if (distance <= bestDistance) {
                best = marker;
                bestDistance = distance;
            }
        }
        if (best === null) return null;
        const lineY = priceSeries.priceToCoordinate(bar.value ?? bar.close);
        if (lineY === null || Math.abs(param.point.y - lineY) > NEAR_LINE_PX) return null;
        const side = param.point.y < lineY ? 'above' : 'below';
        return markers.find(marker => marker.time === best.time && marker.id.endsWith(side))
            || markers.find(marker => marker.time === best.time)
            || null;
    }

    function onCrosshairMove(param) {
        const marker = param.point && markers.length > 0 ? markerAt(param) : null;
        highlight(marker);
        if (marker === null) {
            tip.classList.add('hidden');
            return;
        }
        showTip(groups.get(marker.id)?.stories || [], param.point);
    }
    chart.subscribeCrosshairMove(onCrosshairMove);

    return {
        /**
         * Loads the stories for `range` and pins each to the bar nearest its age; `anchorTime` is the axis second the
         * newest history row is dated at, `barTimes` the bars' times ascending. Stories older than the first bar are
         * dropped; one newer than the last, landed while the live bar is open, goes on that bar. A `refresh` keeps the
         * markers drawn until the new set arrives, where a new range clears them at once.
         */
        async load(ticker, range, anchorTime, barTimes, secondsPerYear, refresh = false) {
            const load = ++loadCount;
            if (!refresh) {
                groups = new Map();
                render([]);
            }
            if (barTimes.length === 0) return;
            let stories = [];
            try {
                const res = await fetch(`/api/chart-events?ticker=${encodeURIComponent(ticker)}&range=${encodeURIComponent(range)}`);
                stories = res.ok ? await res.json() : [];
            } catch (err) {
                return;
            }
            if (load !== loadCount || !Array.isArray(stories)) return;

            const first = barTimes[0];
            groups = new Map();
            stories.forEach(story => {
                const time = anchorTime - Math.round((Number(story.age) || 0) * secondsPerYear);
                if (time < first) return;
                const bar = nearestTime(barTimes, time);
                const side = BELOW_TONES.has(story.tone) ? 'below' : 'above';
                const id = `${bar}:${side}`;
                if (!groups.has(id)) groups.set(id, { time: bar, side, stories: [] });
                groups.get(id).stories.push(story);
            });

            render([...groups.entries()].sort((a, b) => a[1].time - b[1].time).map(([id, group]) => {
                // A headline leads the marker and names it; among equals the graver tone does.
                const lead = group.stories.reduce((a, b) => {
                    if (a.major !== b.major) return b.major ? b : a;
                    return (TONE_RANK[b.tone] ?? 0) > (TONE_RANK[a.tone] ?? 0) ? b : a;
                });
                return {
                    id,
                    time: group.time,
                    position: group.side === 'below' ? 'belowBar' : 'aboveBar',
                    shape: TONE_SHAPES[lead.tone] || 'circle',
                    color: TONE_COLORS[lead.tone] || TONE_COLORS.neutral,
                    size: lead.major ? MAJOR_SIZE : MINOR_SIZE,
                    text: markerText(lead, group.stories),
                };
            }));
        },

        /** Moves the markers onto `series`, the rendering of the price now drawn. */
        follow(series) {
            if (series === priceSeries) return;
            plugin.detach();
            priceSeries = series;
            plugin = LightweightCharts.createSeriesMarkers(priceSeries, markers);
        },

        /** Drops the markers older than `cutoff` after the live tail has trimmed its series to it. */
        trim(cutoff) {
            if (markers.length === 0 || markers[0].time >= cutoff) return;
            [...groups.entries()].forEach(([id, group]) => { if (group.time < cutoff) groups.delete(id); });
            render(markers.filter(marker => marker.time >= cutoff));
        },

        destroy() {
            chart.unsubscribeCrosshairMove(onCrosshairMove);
            plugin.detach();
            tip.remove();
        },
    };
}
