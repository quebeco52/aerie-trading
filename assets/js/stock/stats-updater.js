import { formatBps, formatCurrency, formatLarge, formatMultiple, formatPercent, formatShares, signedClass } from '../utils/formatters.js';
import { flashTick } from '../utils/tick-flash.js';

let previousPrice = null;

export function resetPriceHistoryState() {
    previousPrice = null;
}

export function updatePriceUI(newPrice, stockUpdate, config = {}) {
    const el = document.getElementById('big-price');
    if (!el) return;

    const oldPrice = previousPrice || newPrice;
    el.textContent = formatCurrency(newPrice);
    flashTick(el, newPrice - oldPrice);
    previousPrice = newPrice;

    // A short shows what covering would cost, so the panel reads the absolute size either way.
    const userQuantity = config.userQuantity || 0;
    if (userQuantity !== 0) {
        const holdingEl = document.getElementById('user-holding-value');
        if (holdingEl) {
            holdingEl.textContent = formatCurrency(newPrice * Math.abs(userQuantity));
        }
    }

    // The past-month change rides each frame (ChartBufferWriter); null is "no history yet", printed as a dash.
    const changeEl = document.getElementById('big-change');
    if (changeEl && 'change_percent' in stockUpdate) {
        changeEl.textContent = formatPercent(stockUpdate.change_percent, 2, false, true);
        changeEl.className = `font-semibold ${signedClass(stockUpdate.change_percent)}`;
    }

    if (!config.isEtf) {
        const currentEps = stockUpdate.eps !== undefined ? stockUpdate.eps : config.eps;

        const advEl = document.getElementById('stat-adv');
        if (advEl && stockUpdate.adv_shares !== undefined) {
            advEl.textContent = formatLarge(stockUpdate.adv_shares);
        }

        // The spread moves with volatility, so it has to be repriced with everything else. Left static it
        // would keep quoting a calm-market cost through a crash.
        const spreadEl = document.getElementById('stat-spread');
        if (spreadEl && stockUpdate.spread_bps !== undefined) {
            spreadEl.textContent = `${Number(stockUpdate.spread_bps).toFixed(1)} bps`;
        }

        const mktCapEl = document.getElementById('stat-mkt-cap');
        if (mktCapEl && stockUpdate.market_cap !== undefined) {
            mktCapEl.textContent = formatLarge(stockUpdate.market_cap, '$');
        }

        const equityEl = document.getElementById('stat-equity');
        if (equityEl && stockUpdate.equity !== undefined) {
            equityEl.textContent = formatLarge(stockUpdate.equity, '$');
        }

        const peEl = document.getElementById('stat-pe');
        if (peEl) {
            peEl.textContent = formatMultiple(currentEps > 0 ? newPrice / currentEps : null);
        }

        // Price over tangible book moves with the price; the tangible book itself only moves on a report.
        const ptbvEl = document.getElementById('stat-ptbv');
        if (ptbvEl) {
            const tbvps = parseFloat(ptbvEl.dataset.tbvps);
            ptbvEl.textContent = formatMultiple(tbvps > 0 ? newPrice / tbvps : null);
        }

        const debtRatioEl = document.getElementById('stat-debt-ratio');
        if (debtRatioEl && stockUpdate.debt_ratio !== undefined) {
            // Null on negative equity, which prints the dash on both sides.
            debtRatioEl.textContent = formatMultiple(stockUpdate.debt_ratio);
        }

        // The rating and the spread its credit trades at are separate nodes, so repainting one leaves the other.
        const creditRatingEl = document.getElementById('stat-credit-rating');
        if (creditRatingEl && stockUpdate.credit_rating !== undefined) {
            creditRatingEl.textContent = stockUpdate.credit_rating;
        }

        const creditSpreadEl = document.getElementById('stat-credit-spread');
        if (creditSpreadEl && Number.isFinite(stockUpdate.credit_spread)) {
            creditSpreadEl.textContent = formatBps(stockUpdate.credit_spread, 0, true);
        }

        const mktShareEl = document.getElementById('stat-market-share');
        if (mktShareEl && stockUpdate.market_share !== undefined) {
            mktShareEl.textContent = formatPercent(stockUpdate.market_share, 2, true);
        }

        const volEl = document.getElementById('stat-volatility');
        if (volEl && stockUpdate.current_volatility !== undefined) {
            volEl.textContent = formatPercent(stockUpdate.current_volatility, 2, true);
        }

        const sharesEl = document.getElementById('stat-shares');
        if (sharesEl && stockUpdate.shares !== undefined) {
            sharesEl.textContent = stockUpdate.shares.toLocaleString('en-US');
        }

        const roicEl = document.getElementById('stat-roic');
        if (roicEl && stockUpdate.current_roic !== undefined) {
            roicEl.textContent = formatPercent(stockUpdate.current_roic, 2);
        }

        if (stockUpdate.analyst_targets) {
            updateAnalystTargets(stockUpdate, newPrice);
        }
    }
}

function updateAnalystTargets(stockUpdate, newPrice) {
    const growthEl = document.getElementById('target-growth');
    const incomeEl = document.getElementById('target-income');
    const valueEl = document.getElementById('target-value');
    const consensusEl = document.getElementById('target-consensus');
    const badgeEl = document.getElementById('analyst-consensus-badge');

    const growthTarget = stockUpdate.analyst_targets.growth_analyst;
    const incomeTarget = stockUpdate.analyst_targets.income_analyst;
    const valueTarget = stockUpdate.analyst_targets.value_analyst;

    // A bankrupt shell publishes an empty target set, which is truthy and has none of these keys.
    if (![growthTarget, incomeTarget, valueTarget].every(Number.isFinite)) return;

    /**
     * The PUBLISHED target: a standing figure revised in steps, and the same number the page was rendered
     * with. Recomputing it here from fair value made it tick continuously and drop by the optimism premium
     * on the first frame after load, which is the live quote wearing an analyst's name that a sticky target
     * exists to avoid. The three desk targets below stay live, because each of those is one analyst's own
     * arithmetic rather than anybody's published call.
     */
    const published = stockUpdate.analyst_price_target;
    const compositeTarget = Number.isFinite(published)
        ? published
        : (stockUpdate.perceived_fair_value !== undefined
            ? parseFloat(stockUpdate.perceived_fair_value)
            : Math.max(growthTarget, incomeTarget, valueTarget));

    /**
     * A target within 5% of the last price is not a call in either direction; outside that band
     * it is. Applied as theme classes rather than inline hex so these figures follow the palette
     * the rest of the page is painted from.
     */
    const applyTargetTone = (el, target) => {
        if (!el) return;
        el.classList.remove('text-secondary', 'text-tertiary', 'text-on-surface-faint');
        if (target > (newPrice * 1.05)) {
            el.classList.add('text-secondary');
        } else if (target < (newPrice * 0.95)) {
            el.classList.add('text-tertiary');
        }
    };

    if (consensusEl) {
        consensusEl.textContent = formatCurrency(compositeTarget);
        applyTargetTone(consensusEl, compositeTarget);
    }

    const upsideEl = document.getElementById('target-upside');
    if (upsideEl && newPrice > 0) {
        upsideEl.textContent = `${formatPercent((compositeTarget - newPrice) / newPrice, 1, false, true)} to target`;
        applyTargetTone(upsideEl, compositeTarget);
    }

    if (growthEl) growthEl.textContent = formatCurrency(growthTarget);
    if (incomeEl) incomeEl.textContent = formatCurrency(incomeTarget);
    if (valueEl) valueEl.textContent = formatCurrency(valueTarget);

    if (badgeEl) {
        // The rating the server published, struck on the standing target against the live price with the
        // sell side's asymmetric bands. Re-deriving it here on a symmetric 5% band had the badge disagree
        // with the number printed directly beneath it.
        const rating = stockUpdate.analyst_rating
            || (compositeTarget > (newPrice * 1.05)
                ? 'Outperform'
                : (compositeTarget < (newPrice * 0.95) ? 'Underperform' : 'Neutral'));

        // The badge tones CompanySnapshotBuilder::RATING_TONES gives the same ratings.
        badgeEl.textContent = rating;
        badgeEl.classList.remove('badge-up', 'badge-down', 'badge-neutral');
        badgeEl.classList.add(rating === 'Outperform' ? 'badge-up' : (rating === 'Underperform' ? 'badge-down' : 'badge-neutral'));
        badgeEl.classList.remove('hidden');
    }
}
