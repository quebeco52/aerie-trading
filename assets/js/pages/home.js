import { formatLarge } from '../utils/formatters.js';
import { THEME_COLORS } from '../utils/colors.js';

const previousPrices = {};

function initHome() {
    const etfEl = document.getElementById('etf-price');
    if (!etfEl) return;
    if (etfEl.dataset.initialized) return;
    etfEl.dataset.initialized = 'true';

    function onMarketUpdate(event) {
        const payload = event.detail;
        if (!payload) return;

        // Update Macro UI
        if (payload.macro) {
            const macroCycleEl = document.getElementById('macro-cycle');
            if (macroCycleEl) {
                const cycleText = payload.macro.output_gap > 0.01 ? 'Boom' : (payload.macro.output_gap < -0.01 ? 'Bust' : 'Neutral');
                if (macroCycleEl.innerText !== cycleText) {
                    macroCycleEl.innerText = cycleText;
                    macroCycleEl.style.color = THEME_COLORS.secondary;
                    setTimeout(() => {
                        if (macroCycleEl) macroCycleEl.style.color = THEME_COLORS.textPrimary;
                    }, 500);
                }
            }
        }

        // Update the Market Index (ETF) Live
        const lbiStock = payload.stocks ? payload.stocks.find(s => s.ticker === 'LBI') : null;
        if (lbiStock) {
            const etfElLive = document.getElementById('etf-price');
            if (etfElLive) etfElLive.innerText = '$' + parseFloat(lbiStock.price).toFixed(2);
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
                        priceEl.innerText = '$0.00';
                        priceEl.classList.add('text-tertiary', 'line-through');
                        mcapEl.innerText = '$0.00';
                        if (chgEl) {
                            chgEl.innerText = '\u2014';
                            chgEl.style.color = THEME_COLORS.textMuted;
                        }
                        rowEl.classList.add('opacity-50', 'bg-tertiary/10');
                        return;
                    }

                    const newPrice = parseFloat(stock.price);
                    const oldPrice = previousPrices[stock.ticker] || newPrice;

                    priceEl.innerText = '$' + newPrice.toFixed(2);

                    const sharesCount = (window.MARKET_SHARES && window.MARKET_SHARES[stock.ticker]) ? window.MARKET_SHARES[stock.ticker] : 0;
                    const newMcap = stock.market_cap !== undefined ? stock.market_cap : (newPrice * sharesCount);

                    mcapEl.innerText = '$' + formatLarge(newMcap);
                    rowEl.setAttribute('data-mcap', newMcap);

                    // A ticker with nothing buffered yet reports no change at all, which is a
                    // different fact from "flat" and has to keep printing as an em dash.
                    if (chgEl) {
                        const chg = stock.changePercent;
                        if (chg === null || chg === undefined) {
                            chgEl.innerText = '\u2014';
                            chgEl.style.color = THEME_COLORS.textMuted;
                        } else {
                            chgEl.innerText = (chg >= 0 ? '+' : '') + (chg * 100).toFixed(2) + '%';
                            chgEl.style.color = chg >= 0 ? THEME_COLORS.positive : THEME_COLORS.negative;
                        }
                    }

                    if (newPrice > oldPrice) {
                        priceEl.style.color = THEME_COLORS.secondary;
                        mcapEl.style.color = THEME_COLORS.secondary;
                    } else if (newPrice < oldPrice) {
                        priceEl.style.color = THEME_COLORS.tertiary;
                        mcapEl.style.color = THEME_COLORS.tertiary;
                    }

                    previousPrices[stock.ticker] = newPrice;

                    setTimeout(() => {
                        if (priceEl) priceEl.style.color = THEME_COLORS.textPrimary;
                        if (mcapEl) mcapEl.style.color = THEME_COLORS.textMuted;
                    }, 500);
                }
            });
        }
    }

    document.addEventListener('market:update', onMarketUpdate);

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
        document.removeEventListener('market:update', onMarketUpdate);
        clearInterval(sortInterval);
    }, { once: true });
}

document.addEventListener('turbo:load', initHome);
initHome();