import { formatCurrency, formatLarge, formatPercent, formatShares } from '../utils/formatters.js';
import { flashTick } from '../utils/tick-flash.js';

let previousPrice = null;

export function resetPriceHistoryState() {
    previousPrice = null;
}

export function updatePriceUI(newPrice, stockUpdate, config = {}) {
    const el = document.getElementById('big-price');
    if (!el) return;

    const oldPrice = previousPrice || newPrice;
    el.textContent = '$' + newPrice.toFixed(2);
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
            peEl.textContent = currentEps > 0 ? (newPrice / currentEps).toFixed(2) + 'x' : '-';
        }

        // Price over tangible book moves with the price; the tangible book itself only moves on a report.
        const ptbvEl = document.getElementById('stat-ptbv');
        if (ptbvEl) {
            const tbvps = parseFloat(ptbvEl.dataset.tbvps);
            ptbvEl.textContent = tbvps > 0 ? (newPrice / tbvps).toFixed(2) + 'x' : '-';
        }

        const debtRatioEl = document.getElementById('stat-debt-ratio');
        if (debtRatioEl && stockUpdate.debt_ratio !== undefined) {
            debtRatioEl.textContent = stockUpdate.debt_ratio.toFixed(2) + 'x';
        }

        const creditRatingEl = document.getElementById('stat-credit-rating');
        if (creditRatingEl && stockUpdate.credit_rating !== undefined) {
            creditRatingEl.textContent = stockUpdate.credit_rating;
        }

        const mktShareEl = document.getElementById('stat-market-share');
        if (mktShareEl && stockUpdate.market_share !== undefined) {
            mktShareEl.textContent = stockUpdate.market_share.toFixed(2) + '%';
        }

        const volEl = document.getElementById('stat-volatility');
        if (volEl && stockUpdate.current_volatility !== undefined) {
            volEl.textContent = stockUpdate.current_volatility.toFixed(2) + '%';
        }

        const sharesEl = document.getElementById('stat-shares');
        if (sharesEl && stockUpdate.shares !== undefined) {
            sharesEl.textContent = stockUpdate.shares.toLocaleString('en-US');
        }

        const roicEl = document.getElementById('stat-roic');
        if (roicEl && stockUpdate.current_roic !== undefined) {
            roicEl.textContent = (stockUpdate.current_roic * 100).toFixed(2) + '%';
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
        consensusEl.textContent = '$' + compositeTarget.toFixed(2);
        applyTargetTone(consensusEl, compositeTarget);
    }

    const upsideEl = document.getElementById('target-upside');
    if (upsideEl && newPrice > 0) {
        const upside = ((compositeTarget - newPrice) / newPrice) * 100;
        upsideEl.textContent = (upside > 0 ? '+' : '') + upside.toFixed(1) + '% to target';
        applyTargetTone(upsideEl, compositeTarget);
    }

    if (growthEl) growthEl.textContent = '$' + growthTarget.toFixed(2);
    if (incomeEl) incomeEl.textContent = '$' + incomeTarget.toFixed(2);
    if (valueEl) valueEl.textContent = '$' + valueTarget.toFixed(2);

    if (badgeEl) {
        // The rating the server published, struck on the standing target against the live price with the
        // sell side's asymmetric bands. Re-deriving it here on a symmetric 5% band had the badge disagree
        // with the number printed directly beneath it.
        const rating = stockUpdate.analyst_rating
            || (compositeTarget > (newPrice * 1.05)
                ? 'Outperform'
                : (compositeTarget < (newPrice * 0.95) ? 'Underperform' : 'Neutral'));

        badgeEl.textContent = rating;
        badgeEl.classList.remove(
            'text-secondary', 'bg-secondary/10',
            'text-tertiary', 'bg-tertiary/10',
            'text-on-surface-variant', 'bg-on-surface-variant/10'
        );
        if (rating === 'Outperform') {
            badgeEl.classList.add('text-secondary', 'bg-secondary/10');
        } else if (rating === 'Underperform') {
            badgeEl.classList.add('text-tertiary', 'bg-tertiary/10');
        } else {
            badgeEl.classList.add('text-on-surface-variant', 'bg-on-surface-variant/10');
        }
        badgeEl.classList.remove('hidden');
    }
}

export function updateMacroIndicators(payload) {
    if (payload.economic_cycle) {
        const el = document.getElementById('market-economic-cycle');
        if (el) el.textContent = payload.economic_cycle;
    }
    if (payload.macro) {
        const infEl = document.getElementById('macro-inflation');
        const gapEl = document.getElementById('macro-output-gap');
        const rateEl = document.getElementById('macro-policy-rate');
        const yieldEl = document.getElementById('macro-yield');

        if (infEl) infEl.textContent = (payload.macro.inflation * 100).toFixed(2) + '%';
        if (rateEl) rateEl.textContent = (payload.macro.policy_rate * 100).toFixed(2) + '%';
        if (yieldEl) yieldEl.textContent = (payload.macro.yield_10y * 100).toFixed(2) + '%';

        // Only the text and the tone change here; the template owns every other class.
        const qeOn = payload.macro.qe_active && payload.macro.qe_intensity > 0.0005;
        const qeStatusEl = document.getElementById('macro-qe-status');
        if (qeStatusEl) qeStatusEl.textContent = qeOn ? `\u2212${(payload.macro.qe_intensity * 10000).toFixed(0)} bps` : '';
        document.getElementById('qe-status-container')?.classList.toggle('hidden', !qeOn);

        if (gapEl) {
            const gapVal = payload.macro.output_gap * 100;
            gapEl.textContent = gapVal.toFixed(2) + '%';
            gapEl.classList.toggle('text-tertiary', gapVal < -1.0);
            gapEl.classList.toggle('text-secondary', gapVal > 1.0);
            gapEl.classList.toggle('text-on-surface', gapVal >= -1.0 && gapVal <= 1.0);
        }

    }
}
