import { formatLarge, formatPercent, formatShares } from '../utils/formatters.js';
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

    const userQuantity = config.userQuantity || 0;
    if (userQuantity > 0) {
        const holdingEl = document.getElementById('user-holding-value');
        if (holdingEl) {
            holdingEl.textContent = '$' + (newPrice * userQuantity).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
    }

    if (!config.isEtf) {
        const currentEps = stockUpdate.eps !== undefined ? stockUpdate.eps : config.eps;

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

    const compositeTarget = stockUpdate.perceived_fair_value !== undefined
        ? parseFloat(stockUpdate.perceived_fair_value)
        : Math.max(growthTarget, incomeTarget, valueTarget);

    /**
     * A target within 5% of the last price is not a call in either direction; outside that band
     * it is. Applied as theme classes rather than inline hex so these figures follow the palette
     * the rest of the page is painted from.
     */
    const applyTargetTone = (el, target) => {
        if (!el) return;
        el.classList.remove('text-secondary', 'text-tertiary');
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
        upsideEl.textContent = (upside >= 0 ? '+' : '') + upside.toFixed(1) + '% Implied';
        applyTargetTone(upsideEl, compositeTarget);
    }

    if (growthEl) growthEl.textContent = '$' + growthTarget.toFixed(2);
    if (incomeEl) incomeEl.textContent = '$' + incomeTarget.toFixed(2);
    if (valueEl) valueEl.textContent = '$' + valueTarget.toFixed(2);

    if (badgeEl) {
        const isOutperform = compositeTarget > (newPrice * 1.05);
        const isUnderperform = compositeTarget < (newPrice * 0.95);

        badgeEl.textContent = isOutperform ? 'Outperform' : (isUnderperform ? 'Underperform' : 'Neutral');
        badgeEl.classList.remove(
            'text-secondary', 'bg-secondary/10',
            'text-tertiary', 'bg-tertiary/10',
            'text-on-surface-variant', 'bg-on-surface-variant/10'
        );
        if (isOutperform) {
            badgeEl.classList.add('text-secondary', 'bg-secondary/10');
        } else if (isUnderperform) {
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
        if (yieldEl) {
            yieldEl.textContent = (payload.macro.yield_10y * 100).toFixed(2) + '%';
            yieldEl.className = payload.macro.qe_active
                ? 'font-bold text-emerald-400 font-mono'
                : 'font-bold text-on-surface font-mono';
        }

        const qeStatusEl = document.getElementById('macro-qe-status');
        const qeContainer = document.getElementById('qe-status-container');
        if (qeStatusEl) {
            if (payload.macro.qe_active && payload.macro.qe_intensity > 0.0005) {
                const suppBps = (payload.macro.qe_intensity * 10000).toFixed(0);
                qeStatusEl.innerHTML = `<span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-3xs font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/25 shadow-sm"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Active (-${suppBps} bps)</span>`;
            } else {
                qeStatusEl.innerHTML = `<span class="inline-flex items-center px-2 py-0.5 rounded-md text-3xs font-medium bg-surface-container-highest/60 text-on-surface-variant border border-outline-variant/10">Inactive</span>`;
            }
        }
        if (qeContainer) {
            qeContainer.classList.toggle('hidden', !(payload.macro.qe_active && payload.macro.qe_intensity > 0.0005));
        }

        if (gapEl) {
            const gapVal = payload.macro.output_gap * 100;
            gapEl.textContent = gapVal.toFixed(2) + '%';
            if (gapVal < -1.0) {
                gapEl.className = 'text-lg font-bold text-tertiary';
            } else if (gapVal > 1.0) {
                gapEl.className = 'text-lg font-bold text-secondary';
            } else {
                gapEl.className = 'text-lg font-bold text-on-surface';
            }
        }

    }
}
