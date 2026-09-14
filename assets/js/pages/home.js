import { formatLarge } from '../utils/formatters.js';
import { readPageData } from '../utils/page-data.js';
import { flashTick } from '../utils/tick-flash.js';
import { setText } from '../utils/set-text.js';

const previousPrices = {};

/** Shares outstanding per ticker, for recomputing market caps as prices tick. */
let sharesByTicker = {};

function initHome() {
    const etfEl = document.getElementById('etf-price');
    if (!etfEl) return;
    if (etfEl.dataset.initialized) return;
    etfEl.dataset.initialized = 'true';

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

        // Update the Market Index (ETF) Live
        const lbiStock = payload.stocks ? payload.stocks.find(s => s.ticker === 'LBI') : null;
        if (lbiStock) {
            const etfElLive = document.getElementById('etf-price');
            setText(etfElLive, '$' + parseFloat(lbiStock.price).toFixed(2));
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
                    const oldPrice = previousPrices[stock.ticker] || newPrice;

                    setText(priceEl, '$' + newPrice.toFixed(2));

                    const sharesCount = sharesByTicker[stock.ticker] || 0;
                    const newMcap = stock.market_cap !== undefined ? stock.market_cap : (newPrice * sharesCount);

                    setText(mcapEl, formatLarge(newMcap, '$'));
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

                    flashTick(priceEl, newPrice - oldPrice);
                    flashTick(mcapEl, newPrice - oldPrice);

                    previousPrices[stock.ticker] = newPrice;
                }
            });
        }
    }

    document.addEventListener('market:frame', onMarketFrame);

    // Throttled Table Sorter
    const sortInterval = setInterval(() => {
        const tbody = document.getElementById('market-table-body');
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort((a, b) => {
            const aBankrupt = a.getAttribute('data-bankrupt') === 'true';
            const bBankrupt = b.getAttribute('data-bankrupt') === 'true';
            if (aBankrupt !== bBankrupt) {
                return aBankrupt ? 1 : -1;
            }
            const mcapA = parseFloat(a.getAttribute('data-mcap')) || 0;
            const mcapB = parseFloat(b.getAttribute('data-mcap')) || 0;
            return mcapB - mcapA;
        });

        rows.forEach(row => tbody.appendChild(row));
    }, 5000);

    // Clean up when leaving the page
    document.addEventListener('turbo:before-render', () => {
        document.removeEventListener('market:frame', onMarketFrame);
        clearInterval(sortInterval);
    }, { once: true });
}


/* Bound to `turbo:load` only. It fires on first load as well as on every Turbo navigation,
   and it is the load-bearing path: on a repeat visit this module is already in the module
   registry and its top level never runs again, so a direct call here would fire only on
   the very first evaluation and be pure duplication on that one. */
document.addEventListener('turbo:load', initHome);
