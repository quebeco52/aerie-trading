import { formatLarge, formatPercent, formatShares } from '../utils/formatters.js';
import { THEME_COLORS } from '../utils/colors.js';

let previousPrice = null;

export function resetPriceHistoryState() {
    previousPrice = null;
}

export function updatePriceUI(newPrice, stockUpdate, config = {}) {
    const el = document.getElementById('big-price');
    if (!el) return;

    const oldPrice = previousPrice || newPrice;
    el.innerText = '$' + newPrice.toFixed(2);
    el.style.color = newPrice > oldPrice ? THEME_COLORS.positive : (newPrice < oldPrice ? THEME_COLORS.negative : '#dae2fd');
    previousPrice = newPrice;
    setTimeout(() => {
        if (el) el.style.color = '#dae2fd';
    }, 500);

    const userQuantity = config.userQuantity || 0;
    if (userQuantity > 0) {
        const holdingEl = document.getElementById('user-holding-value');
        if (holdingEl) {
            holdingEl.innerText = '$' + (newPrice * userQuantity).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
    }

    if (!config.isEtf) {
        const currentEps = stockUpdate.eps !== undefined ? stockUpdate.eps : config.eps;

        const mktCapEl = document.getElementById('stat-mkt-cap');
        if (mktCapEl && stockUpdate.market_cap !== undefined) {
            mktCapEl.innerText = '$' + formatLarge(stockUpdate.market_cap);
        }

        const treasuryEl = document.getElementById('stat-treasury');
        if (treasuryEl && stockUpdate.treasury !== undefined) {
            treasuryEl.innerText = '$' + formatLarge(stockUpdate.treasury);
        }

        const equityEl = document.getElementById('stat-equity');
        if (equityEl && stockUpdate.equity !== undefined) {
            equityEl.innerText = '$' + formatLarge(stockUpdate.equity);
        }

        const peEl = document.getElementById('stat-pe');
        if (peEl) {
            peEl.innerText = currentEps > 0 ? (newPrice / currentEps).toFixed(2) + 'x' : '-';
        }

        const debtRatioEl = document.getElementById('stat-debt-ratio');
        if (debtRatioEl && stockUpdate.debt_ratio !== undefined) {
            debtRatioEl.innerText = stockUpdate.debt_ratio.toFixed(2) + 'x';
        }

        const creditRatingEl = document.getElementById('stat-credit-rating');
        if (creditRatingEl && stockUpdate.credit_rating !== undefined) {
            creditRatingEl.innerText = stockUpdate.credit_rating;
        }

        const mktShareEl = document.getElementById('stat-market-share');
        if (mktShareEl && stockUpdate.market_share !== undefined) {
            mktShareEl.innerText = stockUpdate.market_share.toFixed(2) + '%';
        }

        if (config.isFinancial && stockUpdate.invested_capital !== undefined && stockUpdate.treasury !== undefined) {
            updateFinancialModelBars(stockUpdate, config.businessModel);
        }

        const volEl = document.getElementById('stat-volatility');
        if (volEl && stockUpdate.current_volatility !== undefined) {
            volEl.innerText = stockUpdate.current_volatility.toFixed(2) + '%';
        }

        const sharesEl = document.getElementById('stat-shares');
        if (sharesEl && stockUpdate.shares !== undefined) {
            sharesEl.innerText = stockUpdate.shares.toLocaleString('en-US');
        }

        const roicEl = document.getElementById('stat-roic');
        if (roicEl && stockUpdate.current_roic !== undefined) {
            roicEl.innerText = (stockUpdate.current_roic * 100).toFixed(2) + '%';
        }

        if (stockUpdate.analyst_targets) {
            updateAnalystTargets(stockUpdate, newPrice);
        }
    }
}

function updateFinancialModelBars(stockUpdate, businessModel) {
    const invCap = parseFloat(stockUpdate.invested_capital) || 0;
    const treasury = parseFloat(stockUpdate.treasury) || 0;
    const totalAssets = invCap + treasury;
    const loanPct = totalAssets > 0 ? (invCap / totalAssets) * 100 : 0;
    const cashPct = totalAssets > 0 ? (treasury / totalAssets) * 100 : 0;

    const totAssetsEl = document.getElementById('stat-total-assets');
    if (totAssetsEl) totAssetsEl.innerText = formatLarge(totalAssets);

    const loanBookEl = document.getElementById('stat-loan-book');
    if (loanBookEl) loanBookEl.innerText = formatLarge(invCap);

    const vaultCashEl = document.getElementById('stat-vault-cash');
    if (vaultCashEl) vaultCashEl.innerText = formatLarge(treasury);

    const levMultEl = document.getElementById('stat-leverage-mult');
    if (levMultEl) {
        const eqVal = parseFloat(stockUpdate.equity) || 1.0;
        const lev = eqVal > 0 ? (totalAssets / eqVal) : 1.0;
        levMultEl.innerText = lev.toFixed(1) + 'x';
    }

    let assetTypeLabel = 'Invested Capital';
    let cashLabel = 'Treasury Reserves';

    if (businessModel === 'commercial_bank') {
        assetTypeLabel = 'Loan Book';
        cashLabel = 'Vault Cash';
    } else if (businessModel === 'insurance') {
        assetTypeLabel = 'Investment Portfolio';
        cashLabel = 'Claims Reserves';
    } else if (businessModel === 'credit_services') {
        assetTypeLabel = 'Credit Receivables';
    } else if (businessModel === 'shadow_bank') {
        assetTypeLabel = 'Wholesale & Mortgage Loans';
    } else if (businessModel === 'clearing_house') {
        assetTypeLabel = 'Margin & Custody Assets';
        cashLabel = 'Guaranty Fund Cash';
    } else if (businessModel === 'brokerage' || businessModel === 'investment_bank') {
        assetTypeLabel = 'Trading & Capital Markets Assets';
    } else if (businessModel === 'asset_manager' || businessModel === 'private_equity' || businessModel === 'distressed_debt') {
        assetTypeLabel = 'Deployed Capital';
    }

    const barLoan = document.getElementById('bar-loan-book');
    if (barLoan) {
        barLoan.style.width = loanPct + '%';
        barLoan.title = `${assetTypeLabel}: ${loanPct.toFixed(1)}%`;
    }
    const barCash = document.getElementById('bar-cash');
    if (barCash) {
        barCash.style.width = cashPct + '%';
        barCash.title = `${cashLabel}: ${cashPct.toFixed(1)}%`;
    }

    const labelLoan = document.getElementById('label-loan-book');
    if (labelLoan) labelLoan.innerText = `${assetTypeLabel} (${loanPct.toFixed(1)}%)`;

    const labelCash = document.getElementById('label-vault-cash');
    if (labelCash) labelCash.innerText = `${cashLabel} (${cashPct.toFixed(1)}%)`;
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

    const getTargetColor = (target) => {
        if (target > (newPrice * 1.05)) return THEME_COLORS.positive;
        if (target < (newPrice * 0.95)) return THEME_COLORS.negative;
        return '';
    };

    if (consensusEl) {
        consensusEl.innerText = '$' + compositeTarget.toFixed(2);
        consensusEl.style.color = getTargetColor(compositeTarget);
    }

    const upsideEl = document.getElementById('target-upside');
    if (upsideEl && newPrice > 0) {
        const upside = ((compositeTarget - newPrice) / newPrice) * 100;
        upsideEl.innerText = (upside >= 0 ? '+' : '') + upside.toFixed(1) + '% Implied';
        upsideEl.style.color = getTargetColor(compositeTarget);
    }

    if (growthEl) growthEl.innerText = '$' + growthTarget.toFixed(2);
    if (incomeEl) incomeEl.innerText = '$' + incomeTarget.toFixed(2);
    if (valueEl) valueEl.innerText = '$' + valueTarget.toFixed(2);

    if (badgeEl) {
        const isOutperform = compositeTarget > (newPrice * 1.05);
        const isUnderperform = compositeTarget < (newPrice * 0.95);
        const consensusColor = isOutperform ? THEME_COLORS.positive : (isUnderperform ? THEME_COLORS.negative : '');
        const consensusText = isOutperform ? 'Outperform' : (isUnderperform ? 'Underperform' : 'Neutral');
        const badgeBg = isOutperform ? 'rgba(78, 222, 163, 0.1)' : (isUnderperform ? 'rgba(255, 179, 173, 0.1)' : 'rgba(194, 198, 214, 0.1)');

        badgeEl.innerText = consensusText;
        badgeEl.style.color = consensusColor;
        badgeEl.style.backgroundColor = badgeBg;
        badgeEl.classList.remove('hidden');
    }
}

export function updateMacroIndicators(payload) {
    if (payload.market_vol) {
        const vixEl = document.getElementById('district-vix');
        if (vixEl) {
            vixEl.innerText = (payload.market_vol * 100).toFixed(2) + '%';
            vixEl.style.color = payload.market_vol > 0.30 ? THEME_COLORS.negative : '#dae2fd';
        }
    }
    if (payload.market_heat !== undefined) {
        const heatEl = document.getElementById('market-heat-value');
        if (heatEl) {
            const heat = parseFloat(payload.market_heat);
            heatEl.innerText = heat.toFixed(2);
            heatEl.style.color = heat > 85.0 ? THEME_COLORS.negative : (heat < 30.0 ? '#7dd3fc' : (heat > 65.0 ? '#fde047' : THEME_COLORS.positive));
        }
    }
    if (payload.economic_cycle) {
        const el = document.getElementById('market-economic-cycle');
        if (el) el.innerText = payload.economic_cycle;
    }
    if (payload.council_rate !== undefined) {
        const el = document.getElementById('council-rate-value');
        if (el) el.innerText = (payload.council_rate * 100).toFixed(2) + '%';
    }

    if (payload.macro) {
        const infEl = document.getElementById('macro-inflation');
        const gapEl = document.getElementById('macro-output-gap');
        const rateEl = document.getElementById('macro-policy-rate');
        const yieldEl = document.getElementById('macro-yield');
        const gdpEl = document.getElementById('macro-gdp');

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
        const qeIntensityEl = document.getElementById('macro-qe-intensity');
        if (qeStatusEl) {
            if (payload.macro.qe_active && payload.macro.qe_intensity > 0.0005) {
                const suppBps = (payload.macro.qe_intensity * 10000).toFixed(0);
                qeStatusEl.innerHTML = `<span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/25 shadow-sm"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Active (-${suppBps} bps)</span>`;
            } else {
                qeStatusEl.innerHTML = `<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-medium bg-surface-container-highest/60 text-on-surface-variant border border-outline-variant/10">Inactive</span>`;
            }
        }
        if (qeContainer && qeIntensityEl) {
            if (payload.macro.qe_active && payload.macro.qe_intensity > 0.0005) {
                qeContainer.classList.remove('hidden');
                qeIntensityEl.textContent = '-' + (payload.macro.qe_intensity * 100).toFixed(2) + '% Yield Suppression';
            } else {
                qeContainer.classList.add('hidden');
            }
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

        if (gdpEl && payload.macro.nominal_gdp_index !== undefined) {
            const gdpValue = 50.00 * payload.macro.nominal_gdp_index;
            gdpEl.textContent = '$' + gdpValue.toFixed(2) + 'T';
        }

        const tedEl = document.getElementById('macro-interbank-spread');
        if (tedEl && payload.macro.interbank_liquidity_spread !== undefined) {
            const tedBps = payload.macro.interbank_liquidity_spread * 10000;
            tedEl.textContent = tedBps.toFixed(0) + ' bps';
            if (tedBps > 100) {
                tedEl.className = 'text-lg font-bold text-tertiary animate-pulse';
            } else if (tedBps > 40) {
                tedEl.className = 'text-lg font-bold text-yellow-400';
            } else {
                tedEl.className = 'text-lg font-bold text-on-surface';
            }
        }

        const creditSpreadEl = document.getElementById('macro-credit-spread');
        if (creditSpreadEl && payload.macro.macro_credit_spread !== undefined) {
            const csBps = payload.macro.macro_credit_spread * 10000;
            creditSpreadEl.textContent = csBps.toFixed(0) + ' bps';
        }

        const tfpEl = document.getElementById('macro-tfp');
        if (tfpEl && payload.macro.total_factor_productivity_index !== undefined) {
            tfpEl.textContent = parseFloat(payload.macro.total_factor_productivity_index).toFixed(1);
        }
    }
}
