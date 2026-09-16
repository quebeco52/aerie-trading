import { formatLarge } from '../utils/formatters.js';
import { readPageData } from '../utils/page-data.js';
import { flashTick } from '../utils/tick-flash.js';
import { setText } from '../utils/set-text.js';
import { onPageLoad } from '../utils/page-init.js';

/**
 * The last price each ticker was *shown at*, not the last price received. A move too small to
 * survive rounding to the cent leaves the reader looking at the same digits, so it must not
 * arm a flash and must not become the baseline the next flash is measured against.
 */
const renderedPrices = {};

/** Shares outstanding per ticker, for recomputing market caps as prices tick. */
let sharesByTicker = {};

// --- Table re-ranking ---
/** How often the table is re-ranked by market cap. */
const SORT_INTERVAL_MS = 5000;
/** How long a row takes to slide from its old rank to its new one after a re-rank. */
const ROW_SLIDE_MS = 320;

function initHome() {
    const tableEl = document.getElementById('market-table-body');
    if (!tableEl) return;
    if (tableEl.dataset.initialized) return;
    tableEl.dataset.initialized = 'true';

    sharesByTicker = readPageData('market-data').shares || {};

    /**
     * One coalesced frame from market-stream.js (`market:frame`): the latest entry per ticker,
     * at most every FRAME_INTERVAL_MS. Every figure on the table is written here, in place and
     * only when its text changed, so the page's cost is set by that cadence, not the tick rate.
     */
    function onMarketFrame(event) {
        const payload = event.detail;
        if (!payload) return;

        // Update Macro UI
        if (payload.macro) {
            const macroCycleEl = document.getElementById('macro-cycle');
            if (macroCycleEl) {
                const cycleText = payload.macro.output_gap > 0.01 ? 'Boom' : (payload.macro.output_gap < -0.01 ? 'Bust' : 'Neutral');
                if (macroCycleEl.textContent !== cycleText) {
                    setText(macroCycleEl, cycleText);
                    flashTick(macroCycleEl, 1);
                }
            }
        }

        // The index tiles: every fund on the frame that has a tile is repainted, whatever it is called.
        if (Array.isArray(payload.stocks)) {
            payload.stocks.forEach(update => {
                const tileEl = document.getElementById(`index-price-${update.ticker}`);
                if (tileEl) setText(tileEl, '$' + parseFloat(update.price).toFixed(2));
            });
        }

        // Loop through live prices and update DOM
        if (Array.isArray(payload.stocks)) {
            payload.stocks.forEach(stock => {
                const priceEl = document.getElementById(`price-${stock.ticker}`);
                const mcapEl = document.getElementById(`mcap-${stock.ticker}`);
                const chgEl = document.getElementById(`chg-${stock.ticker}`);
                const rowEl = document.getElementById(`row-${stock.ticker}`);

                if (priceEl && mcapEl && rowEl) {
                    if (stock.is_bankrupt) {
                        rowEl.setAttribute('data-bankrupt', 'true');
                        rowEl.setAttribute('data-mcap', '0');
                        setText(priceEl, '$0.00');
                        priceEl.classList.add('text-tertiary', 'line-through');
                        setText(mcapEl, '$0.00');
                        if (chgEl) {
                            setText(chgEl, '\u2014');
                            chgEl.classList.remove('text-secondary', 'text-tertiary');
                            chgEl.classList.add('text-on-surface-variant');
                        }
                        rowEl.classList.add('opacity-50', 'bg-tertiary/10');
                        return;
                    }

                    const newPrice = parseFloat(stock.price);
                    const priceMoved = setText(priceEl, '$' + newPrice.toFixed(2));

                    const sharesCount = sharesByTicker[stock.ticker] || 0;
                    const newMcap = stock.market_cap !== undefined ? stock.market_cap : (newPrice * sharesCount);

                    const mcapMoved = setText(mcapEl, formatLarge(newMcap, '$'));
                    rowEl.setAttribute('data-mcap', newMcap);

                    // A ticker with nothing buffered yet reports no change at all, which is a
                    // different fact from "flat" and has to keep printing as an em dash.
                    if (chgEl) {
                        const chg = stock.changePercent;
                        // Steady state, not a flash: the day's direction, held until it changes.
                        chgEl.classList.remove('text-secondary', 'text-tertiary', 'text-on-surface-variant');
                        if (chg === null || chg === undefined) {
                            setText(chgEl, '\u2014');
                            chgEl.classList.add('text-on-surface-variant');
                        } else {
                            setText(chgEl, (chg >= 0 ? '+' : '') + (chg * 100).toFixed(2) + '%');
                            chgEl.classList.add(chg >= 0 ? 'text-secondary' : 'text-tertiary');
                        }
                    }

                    // Only figures whose digits changed flash, and they flash against the last
                    // price the reader was shown. A ticker with no baseline yet — the first frame
                    // after the server-rendered table — reports no direction, so arriving on the
                    // page does not light the whole board up.
                    const shownAt = renderedPrices[stock.ticker];
                    const direction = shownAt === undefined ? 0 : newPrice - shownAt;

                    if (priceMoved) flashTick(priceEl, direction);
                    if (mcapMoved) flashTick(mcapEl, direction);
                    if (priceMoved) renderedPrices[stock.ticker] = newPrice;
                }
            });
        }
    }

    document.addEventListener('market:frame', onMarketFrame);

    /** Largest market cap first, with the bankrupt shells held at the bottom. */
    function byMarketCap(a, b) {
        const aBankrupt = a.getAttribute('data-bankrupt') === 'true';
        const bBankrupt = b.getAttribute('data-bankrupt') === 'true';
        if (aBankrupt !== bBankrupt) {
            return aBankrupt ? 1 : -1;
        }
        const mcapA = parseFloat(a.getAttribute('data-mcap')) || 0;
        const mcapB = parseFloat(b.getAttribute('data-mcap')) || 0;
        return mcapB - mcapA;
    }

    /**
     * Re-ranks the table. Two neighbours trading places used to teleport, which on a table that
     * re-ranks every few seconds is the most visible jump on the page, so the rows that move
     * slide from where they stood to where they stand now (FLIP: measure, reorder, animate the
     * difference away). Reordering is skipped outright when the ranking has not changed, which
     * is the common case and spares both the layout and the animation.
     */
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const sortInterval = setInterval(() => {
        const tbody = document.getElementById('market-table-body');
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll('tr'));
        const ranked = rows.slice().sort(byMarketCap);
        if (ranked.every((row, i) => row === rows[i])) return;

        const before = reduceMotion ? null : new Map(ranked.map(row => [row, row.getBoundingClientRect().top]));

        ranked.forEach(row => tbody.appendChild(row));
        if (!before) return;

        const after = new Map(ranked.map(row => [row, row.getBoundingClientRect().top]));
        ranked.forEach(row => {
            const dy = before.get(row) - after.get(row);
            if (!dy) return;
            row.animate(
                [{ transform: `translateY(${dy}px)` }, { transform: 'none' }],
                { duration: ROW_SLIDE_MS, easing: 'ease-out' }
            );
        });
    }, SORT_INTERVAL_MS);

    // Clean up when leaving the page
    document.addEventListener('turbo:before-render', () => {
        document.removeEventListener('market:frame', onMarketFrame);
        clearInterval(sortInterval);
    }, { once: true });
}


/* `onPageLoad`, not a bare `turbo:load` listener: on a Turbo navigation this module is
   fetched asynchronously and can evaluate after that page's `turbo:load` has already
   fired. See utils/page-init.js. */
onPageLoad(initHome);
