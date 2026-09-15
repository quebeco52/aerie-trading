import { setText } from '../utils/set-text.js';

/**
 * Keeps the index fund's constituent table live.
 *
 * Prices come straight off the frame. Weights are recomputed from FLOAT-adjusted shares (the page hands
 * over `sharesMap` on that basis), so the table stays on the same footing the server struck it on rather
 * than drifting back to whole-company capitalisation as prices tick. Everything is written in place and
 * only when it changed; the cost of this is set by the frame cadence, not the tick rate.
 */
export function updateConstituentRows(payload, sharesMap = {}) {
    if (!Array.isArray(payload?.stocks)) return;

    const caps = {};
    let touched = false;

    payload.stocks.forEach(stock => {
        const shares = sharesMap[stock.ticker];
        if (shares === undefined) return;

        const price = parseFloat(stock.price);
        if (!Number.isFinite(price)) return;

        setText(document.getElementById(`idx-price-${stock.ticker}`), '$' + price.toFixed(2));
        caps[stock.ticker] = stock.is_bankrupt ? 0 : price * shares;
        touched = true;
    });

    if (!touched) return;

    // Only names the frame did not carry keep their server-rendered cap, read back off the row.
    let total = 0;
    Object.keys(sharesMap).forEach(ticker => {
        if (caps[ticker] === undefined) {
            const row = document.getElementById(`idx-row-${ticker}`);
            caps[ticker] = row ? parseFloat(row.dataset.floatCap) || 0 : 0;
        }
        total += caps[ticker];
    });
    if (total <= 0) return;

    Object.keys(caps).forEach(ticker => {
        const weight = (caps[ticker] / total) * 100;
        setText(document.getElementById(`idx-weight-${ticker}`), weight.toFixed(2) + '%');

        const bar = document.getElementById(`idx-bar-${ticker}`);
        if (bar) {
            const width = Math.max(0, Math.min(100, weight)).toFixed(2) + '%';
            if (bar.style.width !== width) bar.style.width = width;
        }
    });
}
