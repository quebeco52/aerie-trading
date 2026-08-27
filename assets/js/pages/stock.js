import { BRAND_COLORS, FALLBACK_PALETTE } from '../utils/colors.js';

let CURRENT_TICKER = window.AERIE_DATA?.ticker;
let IS_ETF = window.AERIE_DATA?.isEtf;
let BUSINESS_MODEL = window.AERIE_DATA?.businessModel || 'none';
let IS_FINANCIAL = window.AERIE_DATA?.isFinancial || false;
let SHARES_OUTSTANDING = window.AERIE_DATA?.sharesOutstanding;
let CURRENT_PRICE = window.AERIE_DATA?.currentPrice || 0;
let USER_QUANTITY = window.AERIE_DATA?.userQuantity;
let EPS = window.AERIE_DATA?.eps;
let TICKS_PER_YEAR = window.AERIE_DATA?.ticksPerYear || 54000;
let SECONDS_PER_TICK = Math.round(31536000 / TICKS_PER_YEAR);

function updateAerieData() {
    if (window.AERIE_DATA) {
        CURRENT_TICKER = window.AERIE_DATA.ticker;
        IS_ETF = window.AERIE_DATA.isEtf;
        BUSINESS_MODEL = window.AERIE_DATA.businessModel || 'none';
        IS_FINANCIAL = window.AERIE_DATA.isFinancial || false;
        SHARES_OUTSTANDING = window.AERIE_DATA.sharesOutstanding;
        CURRENT_PRICE = window.AERIE_DATA.currentPrice || 0;
        USER_QUANTITY = window.AERIE_DATA.userQuantity;
        EPS = window.AERIE_DATA.eps;
        TICKS_PER_YEAR = window.AERIE_DATA.ticksPerYear || 54000;
        SECONDS_PER_TICK = Math.round(31536000 / TICKS_PER_YEAR);
    } else {
        CURRENT_TICKER = null;
        IS_ETF = false;
        BUSINESS_MODEL = 'none';
        IS_FINANCIAL = false;
        SHARES_OUTSTANDING = null;
        CURRENT_PRICE = 0;
        USER_QUANTITY = 0;
        EPS = null;
    }
}

document.addEventListener('turbo:load', updateAerieData);

let rawReports = [];
let profitEngineChartInstance = null;
let revenueStreamsChartInstance = null;
let debtEquityChartInstance = null;
let creditHealthChartInstance = null;
let capitalEfficiencyChartInstance = null;
let capitalReturnChartInstance = null;
let payoutRatioChartInstance = null;
let regulatoryRatiosChartInstance = null;
let valuationMultiplesChartInstance = null;
let shareholderValueChartInstance = null;
let cashFlowSummaryChartInstance = null;
let netInterestEngineChartInstance = null;
let insuranceDualEngineChartInstance = null;
let reitCoverageChartInstance = null;
let reinvestmentIntensityChartInstance = null;
let cyclicalDynamicsChartInstance = null;
let lwChart = null;
let areaSeries = null;
let etfPieChart = null;
let chartResizeObserver = null;

Chart.defaults.color = '#c2c6d6';
Chart.defaults.scale.grid.color = 'rgba(45, 52, 73, 0.4)';
Chart.defaults.font.family = '"Courier Prime", monospace';

const centerTextPlugin = {
    id: 'centerText',
    beforeDraw(chart) {
        if (chart.config.type !== 'doughnut') return;
        const text = chart.config.options?.plugins?.centerText?.text;
        if (!text) return;
        const { ctx, chartArea } = chart;
        if (!chartArea) return;
        ctx.save();
        ctx.font = 'bold 20px "Courier Prime", monospace';
        ctx.fillStyle = '#e2e8f0';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        const centerX = (chartArea.left + chartArea.right) / 2;
        const centerY = (chartArea.top + chartArea.bottom) / 2;
        ctx.fillText(text, centerX, centerY);
        ctx.restore();
    }
};
Chart.register(centerTextPlugin);

const COLORS = {
    primary: '#adc6ff',
    positive: '#4edea3',
    negative: '#ffb3ad',
    warning: '#ff9800',
    grid: '#2d3449'
};

function formatLarge(num) {
    if (num === null || num === undefined) return '0.00';

    const isNegative = num < 0;
    const absNum = Math.abs(num);

    let formatted;
    if (absNum >= 1000000000000) formatted = (absNum / 1000000000000).toFixed(2) + 'T';
    else if (absNum >= 1000000000) formatted = (absNum / 1000000000).toFixed(2) + 'B';
    else if (absNum >= 1000000) formatted = (absNum / 1000000).toFixed(2) + 'M';
    else formatted = absNum.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return isNegative ? '-' + formatted : formatted;
}

function initStockPage() {
    updateAerieData();
    const container = document.getElementById('mainChartContainer');
    if (!container || !window.AERIE_DATA || !CURRENT_TICKER) return;
    if (container.dataset.initialized) return;
    container.dataset.initialized = 'true';

    let isAborted = false;

    if (lwChart) {
        try { lwChart.remove(); } catch (e) { }
        lwChart = null;
    }
    if (chartResizeObserver) {
        try { chartResizeObserver.disconnect(); } catch (e) { }
        chartResizeObserver = null;
    }
    const destroyChart = (inst) => { if (inst) { try { inst.destroy(); } catch (e) { } } return null; };
    etfPieChart = destroyChart(etfPieChart);
    macroEconomyChartInstance = destroyChart(macroEconomyChartInstance);
    macroRatesChartInstance = destroyChart(macroRatesChartInstance);
    macroMortgageChartInstance = destroyChart(macroMortgageChartInstance);
    macroRiskChartInstance = destroyChart(macroRiskChartInstance);
    macroLaborCreditChartInstance = destroyChart(macroLaborCreditChartInstance);
    macroCommoditiesChartInstance = destroyChart(macroCommoditiesChartInstance);
    macroPropertyChartInstance = destroyChart(macroPropertyChartInstance);
    macroTradeLogisticsChartInstance = destroyChart(macroTradeLogisticsChartInstance);
    macroSentimentChartInstance = destroyChart(macroSentimentChartInstance);
    macroGovtSpendingChartInstance = destroyChart(macroGovtSpendingChartInstance);
    macroInterbankLiquidityChartInstance = destroyChart(macroInterbankLiquidityChartInstance);
    profitEngineChartInstance = destroyChart(profitEngineChartInstance);
    debtEquityChartInstance = destroyChart(debtEquityChartInstance);
    creditHealthChartInstance = destroyChart(creditHealthChartInstance);
    capitalEfficiencyChartInstance = destroyChart(capitalEfficiencyChartInstance);
    capitalReturnChartInstance = destroyChart(capitalReturnChartInstance);
    payoutRatioChartInstance = destroyChart(payoutRatioChartInstance);
    regulatoryRatiosChartInstance = destroyChart(regulatoryRatiosChartInstance);
    valuationMultiplesChartInstance = destroyChart(valuationMultiplesChartInstance);
    shareholderValueChartInstance = destroyChart(shareholderValueChartInstance);
    cashFlowSummaryChartInstance = destroyChart(cashFlowSummaryChartInstance);
    netInterestEngineChartInstance = destroyChart(netInterestEngineChartInstance);
    insuranceDualEngineChartInstance = destroyChart(insuranceDualEngineChartInstance);
    reitCoverageChartInstance = destroyChart(reitCoverageChartInstance);
    reinvestmentIntensityChartInstance = destroyChart(reinvestmentIntensityChartInstance);
    cyclicalDynamicsChartInstance = destroyChart(cyclicalDynamicsChartInstance);

    container.innerHTML = '';

    // variables
    let previousPrice = null;
    let currentRange = '1y';
    let currentSimTime = 0;
    let lastChartPointTime = 0;
    let currentStepSize = 1;
    let etfComponents = prepareEtfData();

    // Initial ui
    initMainChart();
    if (IS_ETF) initEtfChart();
    loadHistory('1y');
    setupEventListeners();
    setupStockTabs();

    function setupStockTabs() {
        const tabButtons = document.querySelectorAll('.stock-tab-btn');
        const tabPanels = document.querySelectorAll('.stock-tab-panel');

        function activateTab(tabId) {
            tabButtons.forEach(btn => {
                const isActive = btn.dataset.tab === tabId;
                if (isActive) {
                    btn.classList.remove('text-on-surface-variant', 'border-transparent');
                    btn.classList.add('text-primary', 'border-primary');
                } else {
                    btn.classList.remove('text-primary', 'border-primary');
                    btn.classList.add('text-on-surface-variant', 'border-transparent');
                }
            });

            tabPanels.forEach(panel => {
                if (panel.id === `stock-tab-content-${tabId}`) {
                    panel.classList.remove('hidden');
                } else {
                    panel.classList.add('hidden');
                }
            });

            // Trigger chart resize when switching tabs so hidden canvases paint properly
            setTimeout(() => {
                window.dispatchEvent(new Event('resize'));
                const chartInstances = [
                    profitEngineChartInstance, debtEquityChartInstance, creditHealthChartInstance,
                    capitalEfficiencyChartInstance, payoutRatioChartInstance, valuationMultiplesChartInstance,
                    shareholderValueChartInstance, cashFlowSummaryChartInstance, netInterestEngineChartInstance,
                    insuranceDualEngineChartInstance, reitCoverageChartInstance, reinvestmentIntensityChartInstance,
                    cyclicalDynamicsChartInstance, etfPieChart,
                    macroEconomyChartInstance, macroRatesChartInstance, macroMortgageChartInstance,
                    macroRiskChartInstance, macroLaborCreditChartInstance, macroCommoditiesChartInstance,
                    macroPropertyChartInstance, macroTradeLogisticsChartInstance, macroSentimentChartInstance,
                    macroGovtSpendingChartInstance, macroInterbankLiquidityChartInstance
                ];
                chartInstances.forEach(c => {
                    if (c) {
                        try { c.resize(); } catch(e) {}
                    }
                });
                if (lwChart) {
                    const cEl = document.getElementById('mainChartContainer');
                    if (cEl && cEl.clientWidth > 0) {
                        lwChart.applyOptions({ width: cEl.clientWidth });
                    }
                }
            }, 50);
        }

        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const tabId = btn.dataset.tab;
                activateTab(tabId);
                if (history.replaceState) {
                    history.replaceState(null, null, `#${tabId}`);
                }
            });
        });

        const hash = window.location.hash.replace('#', '');
        if (hash && document.getElementById(`stock-tab-content-${hash}`)) {
            activateTab(hash);
        }
    }

    if (!IS_ETF) {
        // Fetch Stock Fundamental Data
        fetch(`/api/fundamentals?ticker=${CURRENT_TICKER}`)
            .then(res => res.json())
            .then(data => {
                if (isAborted) return;
                rawReports = data;
                updateCharts('12Y');
            })
            .catch(err => console.error("Failed to load fundamentals:", err));
    } else {
        // Fetch Macroeconomic Reports
        fetch(`/api/macro-reports`)
            .then(res => res.json())
            .then(data => {
                if (isAborted) return;
                rawReports = data;
                updateMacroCharts();
            })
            .catch(err => console.error("Failed to load macro reports:", err));
    }

    function onMarketUpdate(event) {
        const payload = event.detail;

        if (IS_ETF) {
            updateMacroIndicators(payload);
            updateEtfPie(payload);
        }

        const stockUpdate = payload.stocks ? payload.stocks.find(s => s.ticker === CURRENT_TICKER) : null;
        if (stockUpdate) {
            const newPrice = parseFloat(stockUpdate.price);
            try {
                updatePriceUI(newPrice, stockUpdate);
            } catch (err) {
                console.error("Error updating price UI:", err);
            }
            try {
                updateLiveChart(newPrice);
            } catch (err) {
                console.error("Error updating live chart:", err);
            }
        }

        if (payload.events && payload.events.length > 0) {
            renderEvents(payload.events);
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
                yieldEl.className = payload.macro.qe_active ? 'text-lg font-bold text-secondary' : 'text-lg font-bold text-on-surface';
            }

            const qeContainer = document.getElementById('qe-status-container');
            const qeIntensityEl = document.getElementById('macro-qe-intensity');
            if (qeContainer && qeIntensityEl) {
                if (payload.macro.qe_active && payload.macro.qe_intensity > 0.001) {
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

        // Update the Current Cycle Badge
        if (payload.economic_cycle) {
            const cycleEl = document.getElementById('market-economic-cycle');
            if (cycleEl) cycleEl.textContent = payload.economic_cycle;
        }
    }

    document.addEventListener('market:update', onMarketUpdate);

    // Clean up when leaving the page to prevent ghost DOM errors
    document.addEventListener('turbo:before-render', () => {
        isAborted = true;
        document.removeEventListener('market:update', onMarketUpdate);
    }, { once: true });

    function initMainChart() {
        const container = document.getElementById('mainChartContainer');

        lwChart = LightweightCharts.createChart(container, {
            layout: { background: { type: 'solid', color: 'transparent' }, textColor: '#c2c6d6', fontFamily: '"Courier Prime", monospace' },
            grid: { vertLines: { visible: false }, horzLines: { color: COLORS.grid, style: 3 } },
            rightPriceScale: { borderVisible: false, autoScale: true, scaleMargins: { top: 0.1, bottom: 0.1 } },
            timeScale: { borderVisible: false, timeVisible: true, secondsVisible: true, fixLeftEdge: true, fixRightEdge: true },
            crosshair: { mode: 0 }
        });

        areaSeries = lwChart.addSeries(LightweightCharts.AreaSeries, {
            lineColor: COLORS.positive, topColor: 'rgba(78, 222, 163, 0.4)', bottomColor: 'rgba(78, 222, 163, 0.0)',
            lineWidth: 2, priceFormat: { type: 'price', precision: 2, minMove: 0.01 }
        });

        chartResizeObserver = new ResizeObserver(entries => {
            if (entries.length > 0 && entries[0].target === container) {
                if (lwChart) {
                    lwChart.applyOptions({ height: entries[0].contentRect.height, width: entries[0].contentRect.width });
                }
            }
        });
        chartResizeObserver.observe(container);
    }

    function initEtfChart() {
        const canvas = document.getElementById('etfPieChart');
        if (!canvas) return;
        if (etfPieChart) {
            try { etfPieChart.destroy(); } catch (e) { }
            etfPieChart = null;
        }
        const ctx = canvas.getContext('2d');
        etfPieChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: etfComponents.map(c => c.ticker),
                datasets: [{ data: etfComponents.map(c => c.value), backgroundColor: etfComponents.map(c => c.color), borderWidth: 0, hoverOffset: 4 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '75%',
                onHover: (e, el) => e.native.target.style.cursor = el.length ? 'pointer' : 'default',
                onClick: (e, el) => { if (el.length > 0) window.location.href = '/stock/' + etfPieChart.data.labels[el[0].index]; },
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 8, usePointStyle: true, color: '#c2c6d6', font: { family: '"Courier Prime", monospace', size: 10 } } },
                    tooltip: {
                        backgroundColor: 'rgba(19, 27, 46, 0.9)',
                        titleColor: '#dae2fd',
                        bodyColor: '#c2c6d6',
                        borderColor: '#424754',
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: function (context) {
                                const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
                                const value = context.raw;
                                const percentage = ((value / total) * 100).toFixed(2);

                                return ` ${context.label}: ${percentage}%`;
                            }
                        }
                    }
                }
            }
        });
    }




    function loadHistory(range) {
        currentRange = range;
        const spinner = document.getElementById('chart-spinner');
        if (spinner) spinner.classList.remove('hidden');

        document.querySelectorAll('.range-btn').forEach(btn => {
            btn.className = btn.dataset.range === range
                ? 'range-btn px-4 py-1.5 text-xs font-bold rounded-md bg-primary text-[#001a42] shadow-lg shadow-primary/20 transition-colors'
                : 'range-btn px-4 py-1.5 text-xs font-bold rounded-md bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-colors';
        });

        fetch(`/api/history?ticker=${CURRENT_TICKER}&range=${range}`)
            .then(res => res.json())
            .then(data => {
                if (!data || data.length === 0) return;

                const rangeSpans = { '1w': 604800, '1m': 2592000, '3m': 7776000, '6m': 15552000, '1y': 31536000, '3y': 94608000, '5y': 157680000, '10y': 315360000, 'max': 630720000 };
                const anchorTime = Math.floor(Date.now() / 1000);

                currentStepSize = Math.max(1, Math.floor((rangeSpans[range] || 31536000) / data.length));

                const chartData = data.map((d, i) => {
                    const pointsFromEnd = (data.length - 1) - i;
                    return { time: anchorTime - (pointsFromEnd * currentStepSize), value: parseFloat(d.price) };
                }).filter(d => !isNaN(d.value)).sort((a, b) => a.time - b.time);

                areaSeries.setData(chartData);
                setTimeout(() => lwChart.timeScale().fitContent(), 50);

                currentSimTime = chartData[chartData.length - 1].time;
                lastChartPointTime = currentSimTime;
            })
            .catch(console.error)
            .finally(() => { if (spinner) spinner.classList.add('hidden'); });
    }

    function updateLiveChart(newPrice) {
        if (document.visibilityState !== 'visible' || isNaN(newPrice) || currentSimTime <= 0) return;

        currentSimTime += SECONDS_PER_TICK;
        if (currentSimTime >= lastChartPointTime + currentStepSize) {
            lastChartPointTime += currentStepSize;
        }

        areaSeries.update({ time: lastChartPointTime, value: newPrice });
    }

    function updatePriceUI(newPrice, stockUpdate) {
        const el = document.getElementById('big-price');
        if (!el) return;

        const oldPrice = previousPrice || newPrice;

        el.innerText = '$' + newPrice.toFixed(2);
        el.style.color = newPrice > oldPrice ? COLORS.positive : (newPrice < oldPrice ? COLORS.negative : '#dae2fd');
        previousPrice = newPrice;
        setTimeout(() => el.style.color = '#dae2fd', 500);

        if (USER_QUANTITY > 0) {
            const holdingEl = document.getElementById('user-holding-value');
            if (holdingEl) {
                holdingEl.innerText = '$' + (newPrice * USER_QUANTITY).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
        }

        if (!IS_ETF) {
            // Use the live EPS from the WebSocket if available, otherwise fallback to the page load EPS
            const currentEps = stockUpdate.eps !== undefined ? stockUpdate.eps : EPS;

            // Format the large numbers dynamically!
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
            const mktShareEl = document.getElementById('stat-market-share');
            if (mktShareEl && stockUpdate.market_share !== undefined) {
                mktShareEl.innerText = stockUpdate.market_share.toFixed(2) + '%';
            }

            if (IS_FINANCIAL && stockUpdate.invested_capital !== undefined && stockUpdate.treasury !== undefined) {
                const invCap = parseFloat(stockUpdate.invested_capital);
                const treasury = parseFloat(stockUpdate.treasury);
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

                if (BUSINESS_MODEL === 'commercial_bank') {
                    assetTypeLabel = 'Loan Book';
                    cashLabel = 'Vault Cash';
                } else if (BUSINESS_MODEL === 'insurance') {
                    assetTypeLabel = 'Investment Portfolio';
                    cashLabel = 'Claims Reserves';
                } else if (BUSINESS_MODEL === 'credit_services') {
                    assetTypeLabel = 'Credit Receivables';
                } else if (BUSINESS_MODEL === 'shadow_bank') {
                    assetTypeLabel = 'Wholesale & Mortgage Loans';
                } else if (BUSINESS_MODEL === 'clearing_house') {
                    assetTypeLabel = 'Margin & Custody Assets';
                    cashLabel = 'Guaranty Fund Cash';
                } else if (BUSINESS_MODEL === 'brokerage' || BUSINESS_MODEL === 'investment_bank') {
                    assetTypeLabel = 'Trading & Capital Markets Assets';
                } else if (BUSINESS_MODEL === 'asset_manager' || BUSINESS_MODEL === 'private_equity' || BUSINESS_MODEL === 'distressed_debt') {
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

            // Other live stats
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


            // Update Analyst Consensus Targets
            if (stockUpdate.analyst_targets) {
                const growthEl = document.getElementById('target-growth');
                const incomeEl = document.getElementById('target-income');
                const valueEl = document.getElementById('target-value');
                const consensusEl = document.getElementById('target-consensus');
                const badgeEl = document.getElementById('analyst-consensus-badge');

                const growthTarget = stockUpdate.analyst_targets.growth_analyst;
                const incomeTarget = stockUpdate.analyst_targets.income_analyst;
                const valueTarget = stockUpdate.analyst_targets.value_analyst;

                // Use perceived fair value (the blended consensus) as the main target
                const compositeTarget = stockUpdate.perceived_fair_value !== undefined
                    ? parseFloat(stockUpdate.perceived_fair_value)
                    : Math.max(growthTarget, incomeTarget, valueTarget);

                // Helper to determine color based on 5% neutral margin
                const getTargetColor = (target) => {
                    if (target > (newPrice * 1.05)) return COLORS.positive;
                    if (target < (newPrice * 0.95)) return COLORS.negative;
                    return ''; // Neutral
                };

                // Apply to Consensus Blended Target
                if (consensusEl) {
                    consensusEl.innerText = '$' + compositeTarget.toFixed(2);
                    consensusEl.style.color = getTargetColor(compositeTarget);
                }

                if (growthEl) {
                    growthEl.innerText = '$' + growthTarget.toFixed(2);
                }
                if (incomeEl) {
                    incomeEl.innerText = '$' + incomeTarget.toFixed(2);
                }
                if (valueEl) {
                    valueEl.innerText = '$' + valueTarget.toFixed(2);
                }

                if (badgeEl) {
                    const isOutperform = compositeTarget > (newPrice * 1.05);
                    const isUnderperform = compositeTarget < (newPrice * 0.95);
                    const consensusColor = isOutperform ? COLORS.positive : (isUnderperform ? COLORS.negative : '');
                    const consensusText = isOutperform ? 'Outperform' : (isUnderperform ? 'Underperform' : 'Neutral');
                    const badgeBg = isOutperform ? 'rgba(78, 222, 163, 0.1)' : (isUnderperform ? 'rgba(255, 179, 173, 0.1)' : 'rgba(194, 198, 214, 0.1)');

                    badgeEl.innerText = consensusText;
                    badgeEl.style.color = consensusColor;
                    badgeEl.style.backgroundColor = badgeBg;
                    badgeEl.classList.remove('hidden');
                }
            }

        }
    }

    function updateMacroIndicators(payload) {
        if (payload.market_vol) {
            const vixEl = document.getElementById('district-vix');
            if (vixEl) {
                vixEl.innerText = (payload.market_vol * 100).toFixed(2) + '%';
                vixEl.style.color = payload.market_vol > 0.30 ? COLORS.negative : '#dae2fd';
            }
        }
        if (payload.market_heat !== undefined) {
            const heatEl = document.getElementById('market-heat-value');
            if (heatEl) {
                const heat = parseFloat(payload.market_heat);
                heatEl.innerText = heat.toFixed(2);
                heatEl.style.color = heat > 85.0 ? COLORS.negative : (heat < 30.0 ? '#7dd3fc' : (heat > 65.0 ? '#fde047' : COLORS.positive));
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
    }

    function updateEtfPie(payload) {
        if (!etfPieChart || !Array.isArray(payload?.stocks)) return;
        let updated = false;

        payload.stocks.forEach(stock => {
            let comp = etfComponents.find(c => c.ticker === stock.ticker);
            if (comp && window.AERIE_DATA?.sharesMap) {
                const shares = window.AERIE_DATA.sharesMap[stock.ticker] || 0;
                comp.value = parseFloat(stock.price) * shares;
                updated = true;
            }
        });

        if (updated) {
            etfComponents.sort((a, b) => b.value - a.value);
            etfPieChart.data.labels = etfComponents.map(c => c.ticker);
            etfPieChart.data.datasets[0].data = etfComponents.map(c => c.value);
            etfPieChart.data.datasets[0].backgroundColor = etfComponents.map(c => c.color);
            etfPieChart.update('none');
        }
    }

    function renderEvents(events) {
        events.forEach(evt => {
            if (evt.ticker !== CURRENT_TICKER) return;

            const noMsg = document.getElementById('no-events-msg');
            const list = document.getElementById('events-list');
            if (noMsg) noMsg.classList.add('hidden');

            const isPos = parseFloat(evt.change_percent) >= 0;
            let icon = evt.type === 'SHOCK' ? 'bolt' : (evt.type === 'SPLIT' || evt.type === 'REVSPLIT' ? 'content_cut' : 'campaign');
            let rawDesc = evt.description || (evt.type === 'SHOCK' ? 'Sudden market shock detected.' : 'Earnings report released.');
            let desc = String(rawDesc).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));

            const now = new Date();
            const timeStr = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');

            const li = document.createElement('li');
            li.className = 'py-3';
            li.innerHTML = `
                <div class="flex items-start justify-between">
                    <div class="flex items-start gap-3 flex-1 min-w-0">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 ${isPos ? 'bg-secondary/10 text-secondary' : 'bg-tertiary/10 text-tertiary'}">
                            <span class="material-symbols-outlined text-sm">${icon}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-bold text-on-surface">${evt.type}</p>
                            <p class="text-[11px] text-on-surface-variant mt-1 leading-relaxed whitespace-pre-line">${desc}</p>
                        </div>
                    </div>
                    <div class="text-right flex-shrink-0 ml-2"> 
                        <span class="inline-flex items-center rounded bg-transparent px-1 py-0.5 text-xs font-bold ${isPos ? 'text-secondary' : 'text-tertiary'}">
                            ${isPos ? '+' : ''}${parseFloat(evt.change_percent).toFixed(2)}%
                        </span>
                        <p class="text-[10px] text-on-surface-variant mt-1">${timeStr}</p>
                    </div>
                </div>`;

            if (list) {
                list.prepend(li);
                if (list.children.length > 15) list.removeChild(list.lastChild);
            }
        });
    }

    function prepareEtfData() {
        if (!IS_ETF || !window.AERIE_DATA || !Array.isArray(window.AERIE_DATA.pieLabels)) return [];
        let components = [];
        let fIndex = 0;
        window.AERIE_DATA.pieLabels.forEach((ticker, i) => {
            let color = BRAND_COLORS[ticker] || FALLBACK_PALETTE[fIndex++ % FALLBACK_PALETTE.length];
            let val = (window.AERIE_DATA.pieData && window.AERIE_DATA.pieData[i]) || 0;
            components.push({ ticker, value: val, color });
        });
        return components.sort((a, b) => b.value - a.value);
    }

    function setupEventListeners() {
        document.querySelectorAll('.range-btn').forEach(btn => {
            btn.addEventListener('click', (e) => loadHistory(e.target.dataset.range));
        });

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') loadHistory(currentRange);
        });

        // Setup Chart Expansion Logic
        document.querySelectorAll('.expand-chart-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const card = this.closest('.chart-card');
                const grid = card.closest('.grid');
                const icon = this.querySelector('.expand-icon');
                const canvasContainer = card.querySelector('.chart-canvas-container');
                const allCards = grid.querySelectorAll('.chart-card');

                const isExpanded = card.classList.contains('md:col-span-2');

                if (isExpanded) {
                    card.classList.remove('md:col-span-2');
                    canvasContainer.classList.remove('h-96', 'md:h-[500px]');
                    canvasContainer.classList.add('h-48');
                    icon.textContent = 'open_in_full';
                    allCards.forEach(c => { if (c !== card) c.style.display = ''; });
                } else {
                    card.classList.add('md:col-span-2');
                    canvasContainer.classList.remove('h-48');
                    canvasContainer.classList.add('h-96', 'md:h-[500px]');
                    icon.textContent = 'close_fullscreen';
                    allCards.forEach(c => { if (c !== card) c.style.display = 'none'; });
                }
            });
        });
    }
}

// FUNDAMENTAL CHARTING LOGIC
window.updateCharts = updateCharts;

function updateCharts(timeframe) {
    if (!rawReports || rawReports.length === 0) return;

    // Update button styles to match your UI across both local and global toolbars
    const btn12Q = document.getElementById('btn-12Q');
    const btn12Y = document.getElementById('btn-12Y') || document.getElementById('btn-5Y');
    const btnGlobal12Q = document.getElementById('btn-global-12Q');
    const btnGlobal12Y = document.getElementById('btn-global-12Y');

    const activeClass = 'px-4 py-1.5 text-xs font-bold rounded-lg bg-primary text-[#001a42] shadow-lg shadow-primary/20 transition-all uppercase tracking-widest';
    const inactiveClass = 'px-4 py-1.5 text-xs font-bold rounded-lg bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-all uppercase tracking-widest';

    if (timeframe === '12Q') {
        if (btn12Q) btn12Q.className = activeClass;
        if (btn12Y) btn12Y.className = inactiveClass;
        if (btnGlobal12Q) btnGlobal12Q.className = activeClass;
        if (btnGlobal12Y) btnGlobal12Y.className = inactiveClass;
    } else {
        if (btn12Y) btn12Y.className = activeClass;
        if (btn12Q) btn12Q.className = inactiveClass;
        if (btnGlobal12Y) btnGlobal12Y.className = activeClass;
        if (btnGlobal12Q) btnGlobal12Q.className = inactiveClass;
    }

    if (rawReports && rawReports.length > 0) {
        const latest = rawReports[rawReports.length - 1];
        const spreadEl = document.getElementById('stat-bank-spread');
        if (spreadEl && latest) {
            const bRate = parseFloat(latest.blended_rate || 0) * 100;
            const depOrCash = parseFloat(latest.deposit_apy || latest.cash_yield || 0) * 100;
            const spreadVal = bRate - depOrCash;
            spreadEl.innerText = spreadVal.toFixed(2) + '%';
            spreadEl.className = 'font-mono ' + (spreadVal >= 0 ? 'text-positive' : 'text-negative');
        }
    }

    let labels = [];

    // Profit Engine
    let revenueData = [];
    let netIncomeData = [];
    let capexData = [];
    let operatingMarginData = [];

    // Revenue Streams
    let revenueStreamsKeys = new Set();
    let revenueStreamsDataRaw = [];
    let streamDetailsDataRaw = [];


    // Balance Sheet
    let debtData = [];
    let equityData = [];
    let treasuryData = [];

    // Credit Health
    let spreadData = [];
    let blendedRateData = [];
    let expenseRatioData = [];
    let cashYieldData = [];
    let depositApyData = [];

    // Capital Efficiency
    let roicData = [];
    let waccData = [];
    let evaData = [];

    // Capital Return (Shareholder Yield)
    let dividendData = [];
    let buybackData = [];
    let dividendYieldData = [];

    // Leveraged Metrics (Banking)
    let roeData = [];
    let coeData = [];
    let capitalRatioData = [];
    let customerDepositRatioData = [];

    // Universal Arrays
    let peData = [];
    let pbData = [];
    let psData = [];
    let epsData = [];
    let bvpsData = [];
    let sharesData = [];
    let fcfData = [];
    let fcfConversionData = [];
    let retainedCashData = [];

    // Sector Arrays
    let interestIncomeData = [];
    let interestExpenseData = [];
    let netInterestSpreadData = [];
    let underwritingProfitData = [];
    let reitPayoutRatioData = [];
    let reitLtvData = [];
    let reitSpreadData = [];
    let capexRevenueRatioData = [];

    if (timeframe === '12Q') {
        const sliced = rawReports.slice(-12);
        sliced.forEach((report, index) => {
            labels.push(`Q${(index % 4) + 1}`);

            let rev = parseFloat(report.revenue || 0);
            let inc = parseFloat(report.net_income || 0);
            let intExp = parseFloat(report.interest_expense || 0);

            let streams = {};
            try {
                streams = typeof report.revenue_streams === 'string' ? JSON.parse(report.revenue_streams) : (report.revenue_streams || {});
            } catch (e) { }
            Object.keys(streams).forEach(k => revenueStreamsKeys.add(k));
            revenueStreamsDataRaw.push(streams);

            let details = {};
            try {
                details = typeof report.stream_details === 'string' ? JSON.parse(report.stream_details) : (report.stream_details || {});
            } catch (e) { }
            streamDetailsDataRaw.push(details);

            revenueData.push(rev);
            netIncomeData.push(inc);
            capexData.push(-parseFloat(report.capital_expenditures || 0));
            operatingMarginData.push(parseFloat(report.operating_margin || 0) * 100);

            debtData.push(parseFloat(report.total_debt || 0));
            equityData.push(parseFloat(report.equity || 0));
            treasuryData.push(parseFloat(report.treasury || 0));

            spreadData.push(parseFloat(report.dynamic_spread || 0) * 100);
            blendedRateData.push(parseFloat(report.blended_rate || 0) * 100);
            expenseRatioData.push(rev > 0 ? (intExp / rev) * 100 : 0.0);
            cashYieldData.push(parseFloat(report.cash_yield || report.cashYield || 0) * 100);
            depositApyData.push(parseFloat(report.deposit_apy || report.depositApy || 0) * 100);

            roicData.push(parseFloat(report.roic || 0) * 100);
            waccData.push(parseFloat(report.wacc || 0) * 100);
            evaData.push(parseFloat(report.eva || 0));

            let divPaid = parseFloat(report.dividend_paid || 0);
            dividendData.push(divPaid);
            buybackData.push(parseFloat(report.stock_buybacks || 0));

            let shs = parseFloat(report.shares || SHARES_OUTSTANDING || 1000000000);
            let pr = parseFloat(report.historical_price || report.current_price || CURRENT_PRICE || 0);
            let mktCap = pr * shs;
            let divYield = mktCap > 0 ? ((divPaid * 4) / mktCap) * 100 : 0.0;
            dividendYieldData.push(divYield);

            roeData.push(parseFloat(report.return_on_equity || 0) * 100);
            coeData.push(parseFloat(report.cost_of_equity || 0) * 100);
            capitalRatioData.push(parseFloat(report.capital_ratio || 0) * 100);
            customerDepositRatioData.push(parseFloat(report.customer_deposit_ratio || 0) * 100);

            let intInc = parseFloat(report.interest_income || 0);
            let capExVal = parseFloat(report.capital_expenditures || 0);
            let eqVal = parseFloat(report.equity || 0);
            let totDebtVal = parseFloat(report.total_debt || 0);
            let opMarginVal = parseFloat(report.operating_margin || 0);
            let annRev = rev * 4.0;
            let annInc = inc * 4.0;

            peData.push(annInc > 0 ? (mktCap / annInc) : null);
            pbData.push(eqVal > 0 ? (mktCap / eqVal) : null);
            psData.push(annRev > 0 ? (mktCap / annRev) : null);

            epsData.push(shs > 0 ? (inc / shs) : 0);
            bvpsData.push(shs > 0 ? (eqVal / shs) : 0);
            sharesData.push(shs);

            let fcf = report.free_cash_flow !== undefined && report.free_cash_flow !== null
                ? parseFloat(report.free_cash_flow)
                : (inc - capExVal);
            fcfData.push(fcf);
            fcfConversionData.push(inc > 0 ? (fcf / inc) * 100 : (inc < 0 && fcf < 0 ? -100 : 0));
            retainedCashData.push(fcf - divPaid - buybackData[buybackData.length - 1]);

            interestIncomeData.push(intInc);
            interestExpenseData.push(intExp);
            let bRateVal = parseFloat(report.blended_rate || 0) * 100;
            let depOrCashVal = parseFloat(report.deposit_apy || report.depositApy || report.cash_yield || report.cashYield || 0) * 100;
            netInterestSpreadData.push(bRateVal - depOrCashVal);

            let combRatioVal = (1.0 - opMarginVal);
            underwritingProfitData.push(rev * combRatioVal);

            reitPayoutRatioData.push(inc > 0 ? (divPaid / inc) * 100 : (divPaid > 0 ? 100 : 0));
            let totAssetsVal = totDebtVal + eqVal;
            reitLtvData.push(totAssetsVal > 0 ? (totDebtVal / totAssetsVal) * 100 : 0);
            let rRoicVal = parseFloat(report.roic || 0) * 100;
            let rWaccVal = parseFloat(report.wacc || 0) * 100;
            reitSpreadData.push(rRoicVal - rWaccVal);

            capexRevenueRatioData.push(rev > 0 ? (capExVal / rev) * 100 : 0);
        });
    }
    else if (timeframe === '12Y' || timeframe === '5Y') {
        const yearsToFetch = 12;
        let yearCount = 1;

        for (let i = rawReports.length - 1; i >= 0 && yearCount <= yearsToFetch; i -= 4) {
            let report = rawReports[i];

            if (yearCount === 1) labels.unshift("Now");
            else if (yearCount === 2) labels.unshift("-1 Yr");
            else labels.unshift(`-${yearCount - 1} Yrs`);

            // Sum the last 4 quarters for a accurate annualized figure
            let sumRev = 0;
            let sumInc = 0;
            let sumIntExp = 0;
            let sumCapEx = 0;
            let sumDiv = 0;
            let sumBuy = 0;
            let sumFcf = 0;
            for (let j = 0; j < 4; j++) {
                if (i - j >= 0) {
                    let rep = rawReports[i - j];
                    sumRev += parseFloat(rep.revenue || 0);
                    sumInc += parseFloat(rep.net_income || 0);
                    sumIntExp += parseFloat(rep.interest_expense || 0);
                    sumCapEx += parseFloat(rep.capital_expenditures || 0);
                    sumDiv += parseFloat(rep.dividend_paid || 0);
                    sumBuy += parseFloat(rep.stock_buybacks || 0);
                    let repFcf = rep.free_cash_flow !== undefined && rep.free_cash_flow !== null
                        ? parseFloat(rep.free_cash_flow)
                        : (parseFloat(rep.net_income || 0) - parseFloat(rep.capital_expenditures || 0));
                    sumFcf += repFcf;
                }
            }

            let sumStreams = {};
            for (let j = 0; j < 4; j++) {
                if (i - j >= 0) {
                    let rep = rawReports[i - j];
                    let s = {};
                    try {
                        s = typeof rep.revenue_streams === 'string' ? JSON.parse(rep.revenue_streams) : (rep.revenue_streams || {});
                    } catch (e) { }
                    for (const [k, v] of Object.entries(s)) {
                        sumStreams[k] = (sumStreams[k] || 0) + parseFloat(v || 0);
                        revenueStreamsKeys.add(k);
                    }
                }
            }
            revenueStreamsDataRaw.unshift(sumStreams);

            let latestYearDetails = {};
            try {
                latestYearDetails = typeof report.stream_details === 'string' ? JSON.parse(report.stream_details) : (report.stream_details || {});
            } catch (e) { }
            streamDetailsDataRaw.unshift(latestYearDetails);

            revenueData.unshift(sumRev);
            netIncomeData.unshift(sumInc);
            capexData.unshift(-sumCapEx);
            operatingMarginData.unshift(parseFloat(report.operating_margin || 0) * 100);

            debtData.unshift(parseFloat(report.total_debt || 0));
            equityData.unshift(parseFloat(report.equity || 0));
            treasuryData.unshift(parseFloat(report.treasury || 0));

            spreadData.unshift(parseFloat(report.dynamic_spread || 0) * 100);
            blendedRateData.unshift(parseFloat(report.blended_rate || 0) * 100);
            expenseRatioData.unshift(sumRev > 0 ? (sumIntExp / sumRev) * 100 : 0.0);
            cashYieldData.unshift(parseFloat(report.cash_yield || report.cashYield || 0) * 100);
            depositApyData.unshift(parseFloat(report.deposit_apy || report.depositApy || 0) * 100);

            roicData.unshift(parseFloat(report.roic || 0) * 100);
            waccData.unshift(parseFloat(report.wacc || 0) * 100);
            evaData.unshift(parseFloat(report.eva || 0));

            dividendData.unshift(sumDiv);
            buybackData.unshift(sumBuy);

            let shs = parseFloat(report.shares || SHARES_OUTSTANDING || 1000000000);
            let pr = parseFloat(report.historical_price || report.current_price || CURRENT_PRICE || 0);
            let mktCap = pr * shs;
            let divYield = mktCap > 0 ? (sumDiv / mktCap) * 100 : 0.0;
            dividendYieldData.unshift(divYield);

            roeData.unshift(parseFloat(report.return_on_equity || 0) * 100);
            coeData.unshift(parseFloat(report.cost_of_equity || 0) * 100);
            capitalRatioData.unshift(parseFloat(report.capital_ratio || 0) * 100);
            customerDepositRatioData.unshift(parseFloat(report.customer_deposit_ratio || 0) * 100);

            let eqVal = parseFloat(report.equity || 0);
            let totDebtVal = parseFloat(report.total_debt || 0);
            let opMarginVal = parseFloat(report.operating_margin || 0);

            peData.unshift(sumInc > 0 ? (mktCap / sumInc) : null);
            pbData.unshift(eqVal > 0 ? (mktCap / eqVal) : null);
            psData.unshift(sumRev > 0 ? (mktCap / sumRev) : null);

            epsData.unshift(shs > 0 ? (sumInc / shs) : 0);
            bvpsData.unshift(shs > 0 ? (eqVal / shs) : 0);
            sharesData.unshift(shs);

            let fcf = sumFcf;
            fcfData.unshift(fcf);
            fcfConversionData.unshift(sumInc > 0 ? (fcf / sumInc) * 100 : (sumInc < 0 && fcf < 0 ? -100 : 0));
            retainedCashData.unshift(fcf - sumDiv - sumBuy);

            let sumIntInc = 0;
            for (let j = 0; j < 4; j++) {
                if (i - j >= 0) sumIntInc += parseFloat(rawReports[i - j].interest_income || 0);
            }
            interestIncomeData.unshift(sumIntInc);
            interestExpenseData.unshift(sumIntExp);
            let bRateVal = parseFloat(report.blended_rate || 0) * 100;
            let depOrCashVal = parseFloat(report.deposit_apy || report.depositApy || report.cash_yield || report.cashYield || 0) * 100;
            netInterestSpreadData.unshift(bRateVal - depOrCashVal);

            let combRatioVal = (1.0 - opMarginVal);
            underwritingProfitData.unshift(sumRev * combRatioVal);

            reitPayoutRatioData.unshift(sumInc > 0 ? (sumDiv / sumInc) * 100 : (sumDiv > 0 ? 100 : 0));
            let totAssetsVal = totDebtVal + eqVal;
            reitLtvData.unshift(totAssetsVal > 0 ? (totDebtVal / totAssetsVal) * 100 : 0);
            let rRoicVal = parseFloat(report.roic || 0) * 100;
            let rWaccVal = parseFloat(report.wacc || 0) * 100;
            reitSpreadData.unshift(rRoicVal - rWaccVal);

            capexRevenueRatioData.unshift(sumRev > 0 ? (sumCapEx / sumRev) * 100 : 0);

            yearCount++;
        }
    }

    // Convert Operating Margin to Combined Ratio (100 - Margin) specifically for Insurance companies
    let marginLabel = BUSINESS_MODEL === 'insurance' ? 'Combined Ratio' : 'Operating Margin';
    let displayMarginData = BUSINESS_MODEL === 'insurance'
        ? operatingMarginData.map(m => 100 - m)
        : operatingMarginData;

    let ltmDiv = 0;
    let ltmInc = 0;
    const recentReports = rawReports.slice(-4);
    recentReports.forEach(r => {
        ltmDiv += parseFloat(r.dividend_paid || 0);
        ltmInc += parseFloat(r.net_income || 0);
    });

    renderProfitEngineChart(labels, revenueData, netIncomeData, capexData, displayMarginData, marginLabel);
    renderRevenueStreamsChart(labels, revenueStreamsKeys, revenueStreamsDataRaw, streamDetailsDataRaw);
    renderDebtEquityChart(labels, debtData, equityData, treasuryData);
    renderCreditHealthChart(labels, spreadData, blendedRateData, expenseRatioData, cashYieldData, depositApyData);
    renderCapitalReturnChart(labels, dividendData, buybackData, dividendYieldData);
    renderPayoutRatioChart(ltmDiv, ltmInc);

    // THE NEW FINANCIAL SPLIT LOGIC
    if (IS_FINANCIAL) {
        // ALL financial companies are evaluated on Return on Equity (ROE)
        renderCapitalEfficiencyChart(labels, roeData, coeData, evaData, 'ROE', 'Cost of Equity');

        if (BUSINESS_MODEL === 'commercial_bank' || BUSINESS_MODEL === 'credit_services') {
            renderRegulatoryRatiosChart(labels, capitalRatioData, customerDepositRatioData, 'Customer Deposit Ratio');
        } else if (BUSINESS_MODEL === 'insurance') {
            renderRegulatoryRatiosChart(labels, capitalRatioData, customerDepositRatioData, 'Float Ratio (0% Interest)');
        } else if (BUSINESS_MODEL === 'shadow_bank') {
            renderRegulatoryRatiosChart(labels, capitalRatioData, customerDepositRatioData, 'Wholesale Funding / Deposit Ratio');
        } else if (BUSINESS_MODEL === 'clearing_house') {
            renderRegulatoryRatiosChart(labels, capitalRatioData, customerDepositRatioData, 'Member Initial Margin Ratio');
        } else {
            // Brokerage, Asset Management, Private Equity, Investment Bank, Distressed Debt, etc.
            const hasDeposits = customerDepositRatioData && customerDepositRatioData.some(val => val !== 0 && val !== null && !isNaN(val));
            renderRegulatoryRatiosChart(labels, capitalRatioData, hasDeposits ? customerDepositRatioData : null, hasDeposits ? 'Client Float / Funding Ratio' : null);
        }
    } else if (BUSINESS_MODEL === 'reit') {
        // REITs use FFO-adjusted ROIC, which is essentially the portfolio's Cap Rate
        renderCapitalEfficiencyChart(labels, roicData, waccData, evaData, 'Cap Rate', 'WACC');
    } else {
        // Normal companies use ROIC
        renderCapitalEfficiencyChart(labels, roicData, waccData, evaData, 'ROIC', 'WACC');
    }

    // Render new universal charts
    renderValuationMultiplesChart(labels, peData, pbData, psData);
    renderShareholderValueChart(labels, epsData, bvpsData, sharesData);
    renderCashFlowSummaryChart(labels, fcfData, fcfConversionData, retainedCashData);

    // Render conditional sector & business-model charts
    if (['commercial_bank', 'credit_services', 'shadow_bank'].includes(BUSINESS_MODEL)) {
        renderNetInterestEngineChart(labels, interestIncomeData, interestExpenseData, netInterestSpreadData);
    } else if (BUSINESS_MODEL === 'insurance') {
        renderInsuranceDualEngineChart(labels, underwritingProfitData, interestIncomeData, displayMarginData);
    } else if (BUSINESS_MODEL === 'reit') {
        renderReitCoverageChart(labels, reitPayoutRatioData, reitLtvData, reitSpreadData);
    } else if (['tech', 'semiconductor', 'biotech', 'defense_contractor'].includes(BUSINESS_MODEL)) {
        renderReinvestmentIntensityChart(labels, capexRevenueRatioData, operatingMarginData, roicData);
    } else if (['commodity', 'shipping'].includes(BUSINESS_MODEL)) {
        renderCyclicalDynamicsChart(labels, operatingMarginData, debtData, treasuryData);
    }
}

function renderProfitEngineChart(labels, revenueData, netIncomeData, capexData, operatingMarginData, marginLabel = 'Operating Margin') {
    if (profitEngineChartInstance) profitEngineChartInstance.destroy();

    const ctx = document.getElementById('netIncomeChart').getContext('2d');
    profitEngineChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Revenue',
                    data: revenueData,
                    backgroundColor: COLORS.primary,
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'bar',
                    label: 'Net Income',
                    data: netIncomeData,
                    backgroundColor: netIncomeData.map(val => val < 0 ? COLORS.negative : COLORS.positive),
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'bar',
                    label: 'CapEx',
                    data: capexData,
                    backgroundColor: '#fde047',
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'line',
                    label: marginLabel,
                    data: operatingMarginData,
                    borderColor: '#facc15',
                    backgroundColor: '#facc15',
                    borderWidth: 2,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#131b2e',
                    pointBorderColor: '#facc15',
                    pointBorderWidth: 2,
                    pointHoverRadius: 6,
                    yAxisID: 'y1',
                    order: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataset.label === marginLabel) {
                                return `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%`;
                            }
                            return `${ctx.dataset.label}: $${formatLarge(ctx.raw)}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    ticks: { callback: (val) => formatLarge(val) }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(0) + '%' }
                }
            }
        }
    });
}

function renderRevenueStreamsChart(labels, streamsKeysSet, rawStreamsData, streamDetailsData = []) {
    if (revenueStreamsChartInstance) revenueStreamsChartInstance.destroy();

    const ctx = document.getElementById('revenueStreamsChart');
    if (!ctx) return;

    const streamsKeys = Array.from(streamsKeysSet);

    // Fallback if there are no streams
    if (streamsKeys.length === 0) {
        revenueStreamsChartInstance = new Chart(ctx.getContext('2d'), { type: 'bar', data: { labels: labels, datasets: [] } });
        return;
    }

    const palette = [
        '#adc6ff', // primary blue
        '#4edea3', // positive green
        '#d8b4fe', // pastel purple
        '#facc15', // yellow
        '#67e8f9', // cyan
        '#ffb3ad', // pastel red
        '#fdba74', // pastel orange
        '#a7f3d0', // mint
        '#fbcfe8', // pink
        '#e2e8f0'  // slate
    ];

    const datasets = streamsKeys.map((key, index) => {
        const color = palette[index % palette.length];

        return {
            type: 'bar',
            label: key.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' '),
            streamKey: key,
            data: rawStreamsData.map(d => parseFloat(d[key] || 0)),
            backgroundColor: color,
            borderRadius: 2,
            stacked: true
        };
    });

    revenueStreamsChartInstance = new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true },
                y: {
                    stacked: true,
                    ticks: { callback: (val) => formatLarge(val) }
                }
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: $${formatLarge(ctx.raw)}`,
                        afterLabel: (ctx) => {
                            const idx = ctx.dataIndex;
                            const streamKey = ctx.dataset.streamKey;
                            const details = streamDetailsData[idx]?.[streamKey];
                            if (!details) return [];

                            const lines = [];
                            if (details.share !== undefined && details.share > 0) {
                                lines.push(`  Mix: ${(details.share * 100).toFixed(1)}% of total`);
                            }
                            if (details.event) {
                                lines.push(`  ⚡ Shock: ${details.event}`);
                            }
                            return lines;
                        }
                    }
                }
            }
        }
    });
}

// =========================================================================
// MACROECONOMIC CHARTING LOGIC
// =========================================================================

let macroEconomyChartInstance = null;
let macroRatesChartInstance = null;
let macroMortgageChartInstance = null;
let macroRiskChartInstance = null;
let macroLaborCreditChartInstance = null;
let macroCommoditiesChartInstance = null;
let macroPropertyChartInstance = null;
let macroTradeLogisticsChartInstance = null;
let macroSentimentChartInstance = null;
let macroGovtSpendingChartInstance = null;
let macroInterbankLiquidityChartInstance = null;

function updateMacroCharts() {
    if (!rawReports || rawReports.length === 0) return;

    let labels = [];
    let inflationData = [], outputGapData = [], capitalOverhangData = [], corpBorrowingData = [];
    let policyRateData = [], yield2yData = [], yield5yData = [], yield10yData = [], yield30yData = [];
    let spread2s10sData = [], spread30yData = [];
    let erpData = [], volData = [], taxData = [];
    let unemploymentData = [], energyPriceData = [];
    let sentimentData = [];
    let fxEmaData = [], metalsEmaData = [], govtSpendingEmaData = [], creEmaData = [];
    let retailDefaultData = [], agriEmaData = [], freightEmaData = [], residentialEmaData = [];
    let interbankSpreadBpsData = [], creditSpreadBpsData = [];

    // Expand and cap the macro charts to show exactly the last 100 quarters (25 years)
    const slicedReports = rawReports.slice(-100);
    let qCount = slicedReports.length;

    slicedReports.forEach((report, index) => {
        let labelQ = qCount - index - 1;
        labels.push(labelQ === 0 ? 'Now' : `-${labelQ}Q`);

        inflationData.push(parseFloat(report.inflation_ema) * 100);
        outputGapData.push(parseFloat(report.output_gap_ema) * 100);

        let rawCap = report.capital_stock_overhang_ema ?? report.capital_stock_overhang ?? report.capitalStockOverhangEma ?? report.capitalStockOverhang ?? 0.0;
        capitalOverhangData.push(parseFloat(rawCap) * 100);

        let pr = parseFloat(report.policy_rate_ema) * 100;
        let y10 = parseFloat(report.yield10y_ema) * 100;

        // Support both snake_case and camelCase serialization, fallback to null for historical records
        let rawY2 = report.yield2y_ema || report.yield2yEma;
        let y2 = rawY2 ? parseFloat(rawY2) * 100 : null;

        let rawY5 = report.yield5y_ema || report.yield5yEma;
        let y5 = rawY5 ? parseFloat(rawY5) * 100 : null;

        let rawY30 = report.yield30y_ema || report.yield30yEma;
        let y30 = rawY30 ? parseFloat(rawY30) * 100 : null;

        let creditSpread = report.macro_credit_spread_ema || report.macroCreditSpreadEma;
        let corpRate = (y5 !== null && creditSpread !== undefined) ? y5 + (parseFloat(creditSpread) * 100) : null;
        corpBorrowingData.push(corpRate);

        policyRateData.push(pr);
        yield2yData.push(y2);
        yield5yData.push(y5);
        yield10yData.push(y10);
        yield30yData.push(y30);

        spread2s10sData.push((y10 !== null && y2 !== null) ? y10 - y2 : null);
        spread30yData.push((y30 !== null && pr !== null) ? y30 - pr : null);

        erpData.push(parseFloat(report.equity_risk_premium) * 100);
        volData.push(parseFloat(report.market_volatility) * 100);
        taxData.push(parseFloat(report.corporate_tax_rate) * 100);

        unemploymentData.push(parseFloat(report.unemployment_rate) * 100);
        energyPriceData.push(parseFloat(report.energy_price_index_ema || report.energy_price_index || 100.0));
        sentimentData.push(parseFloat(report.consumer_sentiment_index_ema || report.consumer_sentiment_index || 100.0));

        fxEmaData.push(parseFloat(report.exchange_rate_index_ema || report.exchange_rate_index || 100.0));
        metalsEmaData.push(parseFloat(report.industrial_metals_index_ema || report.industrial_metals_index || 100.0));
        govtSpendingEmaData.push(parseFloat(report.government_spending_index_ema || report.government_spending_index || 100.0));
        creEmaData.push(parseFloat(report.commercial_property_index_ema || report.commercial_property_index || 100.0));
        retailDefaultData.push(parseFloat(report.retail_default_rate_ema || report.retail_default_rate || 0.025) * 100);
        agriEmaData.push(parseFloat(report.agricultural_commodity_index_ema || report.agricultural_commodity_index || 100.0));
        freightEmaData.push(parseFloat(report.freight_rate_index_ema || report.freight_rate_index || 100.0));
        residentialEmaData.push(parseFloat(report.residential_property_index_ema || report.residential_property_index || 100.0));

        let rawInterbank = report.interbank_liquidity_spread_ema ?? report.interbank_liquidity_spread ?? report.interbankLiquiditySpreadEma ?? report.interbankLiquiditySpread ?? 0.0015;
        interbankSpreadBpsData.push(parseFloat(rawInterbank) * 10000);

        let rawCreditSpread = report.macro_credit_spread_ema ?? report.macro_credit_spread ?? report.macroCreditSpreadEma ?? report.macroCreditSpread ?? 0.020;
        creditSpreadBpsData.push(parseFloat(rawCreditSpread) * 10000);
    });

    renderMacroEconomyChart(labels, inflationData, outputGapData, capitalOverhangData);
    renderMacroRatesChart(labels, policyRateData, yield2yData, yield5yData, yield10yData, spread2s10sData);
    renderMacroMortgageChart(labels, policyRateData, yield30yData, spread30yData);
    renderMacroRiskChart(labels, erpData, volData, taxData, corpBorrowingData);
    renderMacroLaborCreditChart(labels, unemploymentData, retailDefaultData);
    renderMacroCommoditiesChart(labels, energyPriceData, metalsEmaData, agriEmaData);
    renderMacroPropertyChart(labels, creEmaData, residentialEmaData);
    renderMacroTradeLogisticsChart(labels, fxEmaData, freightEmaData);
    renderMacroSentimentChart(labels, sentimentData);
    renderMacroGovtSpendingChart(labels, govtSpendingEmaData);
    renderMacroInterbankLiquidityChart(labels, interbankSpreadBpsData, creditSpreadBpsData);
}

function renderMacroEconomyChart(labels, inflationData, outputGapData, capitalOverhangData) {
    const canvas = document.getElementById('macroEconomyChart');
    if (!canvas) return;
    if (macroEconomyChartInstance) macroEconomyChartInstance.destroy();
    const ctx = canvas.getContext('2d');
    macroEconomyChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'Inflation (EMA)',
                    data: inflationData,
                    borderColor: '#facc15',
                    backgroundColor: '#facc15',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 1,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Capital Overhang (EMA)',
                    data: capitalOverhangData,
                    borderColor: '#c084fc',
                    backgroundColor: '#c084fc',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 1,
                    yAxisID: 'y'
                },
                {
                    type: 'bar',
                    label: 'Output Gap (EMA)',
                    data: outputGapData,
                    backgroundColor: outputGapData.map(val => val < 0 ? 'rgba(255, 179, 173, 0.4)' : 'rgba(78, 222, 163, 0.4)'),
                    borderRadius: 4,
                    yAxisID: 'y'
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } }, tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } } },
            scales: { y: { ticks: { callback: (val) => val + '%' }, title: { display: true, text: 'Percentage' } } }
        }
    });
}

function renderMacroRatesChart(labels, policyRateData, yield2yData, yield5yData, yield10yData, spread2s10sData) {
    const canvas = document.getElementById('macroRatesChart');
    if (!canvas) return;
    if (macroRatesChartInstance) macroRatesChartInstance.destroy();
    const ctx = canvas.getContext('2d');
    macroRatesChartInstance = new Chart(ctx, {
        type: 'bar', // Set base type to bar so we can render the background slope
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'Policy Rate',
                    data: policyRateData,
                    borderColor: '#7dd3fc',
                    backgroundColor: '#7dd3fc',
                    borderWidth: 2,
                    tension: 0.1,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    type: 'line',
                    label: '2Y Yield',
                    data: yield2yData,
                    borderColor: '#4ade80',
                    backgroundColor: '#4ade80',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    type: 'line',
                    label: '5Y Yield',
                    data: yield5yData,
                    borderColor: '#facc15',
                    backgroundColor: '#facc15',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    type: 'line',
                    label: '10Y Yield',
                    data: yield10yData,
                    borderColor: '#c084fc',
                    backgroundColor: '#c084fc',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    type: 'bar',
                    label: '2s10s Spread (10Y-2Y)',
                    data: spread2s10sData,
                    backgroundColor: spread2s10sData.map(val => val !== null && val < 0 ? 'rgba(255, 179, 173, 0.4)' : 'rgba(192, 132, 252, 0.4)'),
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } }, tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } } },
            scales: { y: { ticks: { callback: (val) => val + '%' } } }
        }
    });
}

function renderMacroMortgageChart(labels, policyRateData, yield30yData, spread30yData) {
    const canvas = document.getElementById('macroMortgageChart');
    if (!canvas) return; // Fail gracefully if the HTML template hasn't been updated yet

    if (macroMortgageChartInstance) macroMortgageChartInstance.destroy();
    const ctx = canvas.getContext('2d');

    macroMortgageChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'Policy Rate',
                    data: policyRateData,
                    borderColor: '#7dd3fc',
                    backgroundColor: '#7dd3fc',
                    borderWidth: 2,
                    tension: 0.1,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    type: 'line',
                    label: '30Y Mortgage Yield',
                    data: yield30yData,
                    borderColor: '#fb7185',
                    backgroundColor: '#fb7185',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    type: 'bar',
                    label: 'Mortgage Spread (30Y-PR)',
                    data: spread30yData,
                    backgroundColor: spread30yData.map(val => val !== null && val < 0 ? 'rgba(255, 179, 173, 0.4)' : 'rgba(251, 113, 133, 0.4)'),
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } }, tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } } },
            scales: { y: { ticks: { callback: (val) => val + '%' } } }
        }
    });
}

function renderMacroRiskChart(labels, erpData, volData, taxData, corpBorrowingData) {
    const canvas = document.getElementById('macroRiskChart');
    if (!canvas) return;
    if (macroRiskChartInstance) macroRiskChartInstance.destroy();
    const ctx = canvas.getContext('2d');
    macroRiskChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Corp Borrowing Rate',
                    data: corpBorrowingData,
                    borderColor: '#f43f5e', // Rose color for debt
                    backgroundColor: '#f43f5e',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    label: 'Market Volatility (VIX)',
                    data: volData,
                    borderColor: COLORS.negative,
                    backgroundColor: 'rgba(255, 179, 173, 0.15)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointRadius: 0
                },
                {
                    label: 'Equity Risk Premium',
                    data: erpData,
                    borderColor: '#fde047',
                    backgroundColor: '#fde047',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    label: 'Corporate Tax Rate',
                    data: taxData,
                    borderColor: COLORS.primary,
                    backgroundColor: COLORS.primary,
                    borderWidth: 2,
                    borderDash: [5, 5],
                    tension: 0.1,
                    pointRadius: 0
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } }, tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } } },
            scales: { y: { ticks: { callback: (val) => val + '%' } } }
        }
    });
}

function renderMacroLaborChart(labels, unemploymentData) {
    const canvas = document.getElementById('macroLaborChart');
    if (!canvas) return;
    if (macroLaborChartInstance) macroLaborChartInstance.destroy();
    const ctx = canvas.getContext('2d');
    macroLaborChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Unemployment Rate',
                    data: unemploymentData,
                    borderColor: '#f43f5e',
                    backgroundColor: 'rgba(244, 63, 94, 0.2)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointRadius: labels.length > 50 ? 0 : 2
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } }, tooltip: { callbacks: { label: (ctx) => `${ctx.raw.toFixed(2)}%` } } },
            scales: { y: { ticks: { callback: (val) => val + '%' } } }
        }
    });
}


function renderMacroLaborCreditChart(labels, unemploymentData, retailDefaultData) {
    if (macroLaborCreditChartInstance) macroLaborCreditChartInstance.destroy();
    const ctx = document.getElementById('macroLaborCreditChart');
    if (!ctx) return;

    macroLaborCreditChartInstance = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Unemployment Rate',
                    data: unemploymentData,
                    borderColor: '#f43f5e',
                    backgroundColor: 'rgba(244, 63, 94, 0.15)',
                    borderWidth: 2,
                    tension: 0.2,
                    fill: true,
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    label: 'Consumer Default Rate',
                    data: retailDefaultData,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.15)',
                    borderWidth: 2,
                    tension: 0.2,
                    pointRadius: labels.length > 50 ? 0 : 2
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } }
            },
            scales: { y: { ticks: { callback: (val) => val.toFixed(1) + '%' }, title: { display: true, text: 'Percentage' } } }
        }
    });
}

function renderMacroCommoditiesChart(labels, energyPriceData, metalsEmaData, agriEmaData) {
    if (macroCommoditiesChartInstance) macroCommoditiesChartInstance.destroy();
    const ctx = document.getElementById('macroCommoditiesChart');
    if (!ctx) return;

    macroCommoditiesChartInstance = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Energy Price Index',
                    data: energyPriceData,
                    borderColor: '#eab308',
                    backgroundColor: 'rgba(234, 179, 8, 0.15)',
                    borderWidth: 2,
                    tension: 0.2,
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    label: 'Industrial Metals Index',
                    data: metalsEmaData,
                    borderColor: '#fb923c',
                    backgroundColor: 'rgba(251, 146, 60, 0.15)',
                    borderWidth: 2,
                    tension: 0.2,
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    label: 'Agricultural Commodities',
                    data: agriEmaData,
                    borderColor: '#a3e635',
                    backgroundColor: 'rgba(163, 230, 53, 0.15)',
                    borderWidth: 2,
                    tension: 0.2,
                    pointRadius: labels.length > 50 ? 0 : 2
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}` } }
            },
            scales: { y: { ticks: { callback: (val) => val } } }
        }
    });
}

function renderMacroPropertyChart(labels, creEmaData, residentialEmaData) {
    if (macroPropertyChartInstance) macroPropertyChartInstance.destroy();
    const ctx = document.getElementById('macroPropertyChart');
    if (!ctx) return;

    macroPropertyChartInstance = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Commercial Property Index (CRE)',
                    data: creEmaData,
                    borderColor: '#f472b6',
                    backgroundColor: 'rgba(244, 114, 182, 0.15)',
                    borderWidth: 2,
                    tension: 0.2,
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    label: 'Residential Property Index',
                    data: residentialEmaData,
                    borderColor: '#c084fc',
                    backgroundColor: 'rgba(192, 132, 252, 0.15)',
                    borderWidth: 2,
                    tension: 0.2,
                    pointRadius: labels.length > 50 ? 0 : 2
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}` } }
            },
            scales: { y: { ticks: { callback: (val) => val } } }
        }
    });
}

function renderMacroTradeLogisticsChart(labels, fxEmaData, freightEmaData) {
    if (macroTradeLogisticsChartInstance) macroTradeLogisticsChartInstance.destroy();
    const ctx = document.getElementById('macroTradeLogisticsChart');
    if (!ctx) return;

    macroTradeLogisticsChartInstance = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Exchange Rate Index (FX)',
                    data: fxEmaData,
                    borderColor: '#38bdf8',
                    backgroundColor: 'rgba(56, 189, 248, 0.15)',
                    borderWidth: 2,
                    tension: 0.2,
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    label: 'Freight Rate Index',
                    data: freightEmaData,
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.15)',
                    borderWidth: 2,
                    tension: 0.2,
                    pointRadius: labels.length > 50 ? 0 : 2
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}` } }
            },
            scales: { y: { ticks: { callback: (val) => val } } }
        }
    });
}

function renderMacroSentimentChart(labels, sentimentData) {
    if (macroSentimentChartInstance) macroSentimentChartInstance.destroy();
    const ctx = document.getElementById('macroSentimentChart');
    if (!ctx) return;

    macroSentimentChartInstance = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Consumer Sentiment Index',
                    data: sentimentData,
                    borderColor: '#a855f7',
                    backgroundColor: 'rgba(168, 85, 247, 0.2)',
                    borderWidth: 2,
                    tension: 0.2,
                    fill: true,
                    pointRadius: labels.length > 50 ? 0 : 2
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } }, tooltip: { callbacks: { label: (ctx) => `${ctx.raw.toFixed(1)}` } } },
            scales: {
                y: {
                    min: 40,
                    max: 120,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { color: 'rgba(255, 255, 255, 0.7)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 10, color: 'rgba(255, 255, 255, 0.5)' }
                }
            }
        }
    });
}

function renderMacroGovtSpendingChart(labels, govtSpendingEmaData) {
    if (macroGovtSpendingChartInstance) macroGovtSpendingChartInstance.destroy();
    const ctx = document.getElementById('macroGovtSpendingChart');
    if (!ctx) return;

    macroGovtSpendingChartInstance = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Fiscal Spending Index',
                    data: govtSpendingEmaData,
                    borderColor: '#34d399',
                    backgroundColor: 'rgba(52, 211, 153, 0.15)',
                    borderWidth: 2,
                    tension: 0.2,
                    fill: true,
                    pointRadius: labels.length > 50 ? 0 : 2
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } }, tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}` } } },
            scales: { y: { ticks: { callback: (val) => val } } }
        }
    });
}

function renderMacroInterbankLiquidityChart(labels, interbankSpreadBpsData, creditSpreadBpsData) {
    if (macroInterbankLiquidityChartInstance) macroInterbankLiquidityChartInstance.destroy();
    const ctx = document.getElementById('macroInterbankLiquidityChart');
    if (!ctx) return;

    macroInterbankLiquidityChartInstance = new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'TED / Interbank Spread',
                    data: interbankSpreadBpsData,
                    borderColor: '#38bdf8',
                    backgroundColor: 'rgba(56, 189, 248, 0.20)',
                    borderWidth: 2,
                    tension: 0.2,
                    fill: true,
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    label: 'Corporate Credit Spread',
                    data: creditSpreadBpsData,
                    borderColor: '#f43f5e',
                    backgroundColor: 'rgba(244, 63, 94, 0.10)',
                    borderWidth: 2,
                    borderDash: [4, 4],
                    tension: 0.2,
                    pointRadius: labels.length > 50 ? 0 : 2
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(0)} bps (${(ctx.raw / 100).toFixed(2)}%)`
                    }
                }
            },
            scales: {
                y: {
                    ticks: { callback: (val) => val + ' bps' },
                    title: { display: true, text: 'Basis Points (bps)' }
                }
            }
        }
    });
}

function renderRegulatoryRatiosChart(labels, capitalRatioData, secondaryData, secondaryLabel) {
    const canvas = document.getElementById('regulatoryRatiosChart');
    if (!canvas) return;

    if (regulatoryRatiosChartInstance) regulatoryRatiosChartInstance.destroy();

    const datasets = [
        {
            label: 'Capital Ratio',
            data: capitalRatioData,
            borderColor: '#7dd3fc',
            backgroundColor: 'rgba(125, 211, 252, 0.2)',
            borderWidth: 2,
            tension: 0.3,
            pointRadius: 3,
            fill: true,
        }
    ];

    // Only add the second line if the data was passed (Banks and Insurance)
    if (secondaryData) {
        datasets.push({
            label: secondaryLabel,
            data: secondaryData,
            borderColor: '#facc15',
            backgroundColor: 'rgba(250, 204, 21, 0.2)',
            borderWidth: 2,
            tension: 0.3,
            pointRadius: 3,
            fill: true,
        });
    }

    const ctx = canvas.getContext('2d');
    regulatoryRatiosChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } }
            },
            scales: {
                y: {
                    ticks: { callback: (val) => val + '%' },
                    beginAtZero: true
                }
            }
        }
    });
}

function renderDebtEquityChart(labels, debtData, equityData, treasuryData) {
    if (debtEquityChartInstance) debtEquityChartInstance.destroy();

    const ctx = document.getElementById('debtEquityChart').getContext('2d');
    debtEquityChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Total Debt',
                    data: debtData,
                    backgroundColor: COLORS.negative,
                    borderRadius: 4,
                },
                {
                    label: 'Book Value',
                    data: equityData,
                    backgroundColor: COLORS.primary,
                    borderRadius: 4,
                },
                {
                    label: 'Total Cash',
                    data: treasuryData,
                    backgroundColor: COLORS.positive,
                    borderRadius: 4,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: $${formatLarge(ctx.raw)}` } }
            },
            scales: {
                y: { ticks: { callback: (val) => formatLarge(val) } }
            }
        }
    });
}

function renderCreditHealthChart(labels, spreadData, blendedRateData, expenseRatioData, cashYieldData, depositApyData) {
    const canvas = document.getElementById('creditHealthChart');
    if (!canvas) return;

    if (creditHealthChartInstance) creditHealthChartInstance.destroy();

    const ctx = canvas.getContext('2d');
    const config = {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Dynamic Spread (Risk Premium)',
                    data: spreadData,
                    borderColor: COLORS.negative,
                    backgroundColor: COLORS.negative,
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3
                },
                {
                    label: 'Blended Interest Rate',
                    data: blendedRateData,
                    borderColor: '#fde047',
                    backgroundColor: '#fde047',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3
                },
                {
                    label: 'Interest Expense / Revenue',
                    data: expenseRatioData,
                    borderColor: '#7dd3fc',
                    backgroundColor: '#7dd3fc',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    tension: 0.3,
                    pointRadius: 0
                },
                {
                    label: 'Cash Yield',
                    data: cashYieldData,
                    borderColor: COLORS.positive,
                    backgroundColor: COLORS.positive,
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } }
            },
            scales: {
                y: {
                    ticks: { callback: (val) => val + '%' },
                    beginAtZero: true
                }
            }
        }
    };

    // ONLY Banks pay Deposit APY. Insurance Float is 0%, Brokerages have no deposits.
    if (BUSINESS_MODEL === 'commercial_bank' || BUSINESS_MODEL === 'credit_services') {
        config.data.datasets.push({
            label: 'Deposit APY',
            data: depositApyData,
            borderColor: '#c084fc', // Distinct purple color
            backgroundColor: '#c084fc',
            borderWidth: 2,
            borderDash: [4, 4],
            tension: 0.3,
            pointRadius: 3
        });
    }

    creditHealthChartInstance = new Chart(ctx, config);
}


function renderCapitalEfficiencyChart(labels, returnData, hurdleData, evaData, returnLabel, hurdleLabel) {
    const canvas = document.getElementById('capitalEfficiencyChart');
    if (!canvas) return;

    if (capitalEfficiencyChartInstance) capitalEfficiencyChartInstance.destroy();

    const ctx = canvas.getContext('2d');
    capitalEfficiencyChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: returnLabel,
                    data: returnData,
                    borderColor: COLORS.positive,
                    backgroundColor: COLORS.positive,
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y'
                },
                {
                    label: hurdleLabel,
                    data: hurdleData,
                    borderColor: COLORS.negative,
                    backgroundColor: COLORS.negative,
                    borderWidth: 2,
                    borderDash: [5, 5],
                    tension: 0.3,
                    pointRadius: 0,
                    yAxisID: 'y'
                },
                {
                    type: 'bar',
                    label: 'EVA ($)',
                    data: evaData,
                    // Semi-transparent bars so they don't hide the lines
                    backgroundColor: evaData.map(val => val < 0 ? 'rgba(255, 179, 173, 0.3)' : 'rgba(78, 222, 163, 0.3)'),
                    borderRadius: 4,
                    yAxisID: 'y1' // Binds to the right-side dollar axis
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataset.label === 'EVA ($)') {
                                return `EVA: $${formatLarge(ctx.raw)}`;
                            }
                            return `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    ticks: { callback: (val) => val + '%' },
                    title: { display: true, text: 'Percentage' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false }, // Hides overlapping grid lines
                    ticks: { callback: (val) => formatLarge(val) },
                }
            }
        }
    });
}

function renderCapitalReturnChart(labels, dividendData, buybackData, dividendYieldData) {
    const canvas = document.getElementById('capitalReturnChart');
    if (!canvas) return;

    if (capitalReturnChartInstance) capitalReturnChartInstance.destroy();

    const ctx = canvas.getContext('2d');
    capitalReturnChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Dividends Paid',
                    data: dividendData,
                    backgroundColor: COLORS.primary,
                    borderRadius: 4,
                    yAxisID: 'y'
                },
                {
                    type: 'bar',
                    label: 'Stock Buybacks',
                    data: buybackData,
                    backgroundColor: COLORS.positive,
                    borderRadius: 4,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Dividend Yield',
                    data: dividendYieldData || [],
                    borderColor: COLORS.warning,
                    backgroundColor: 'rgba(255, 152, 0, 0.15)',
                    borderWidth: 2.5,
                    pointBackgroundColor: COLORS.warning,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 1,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    tension: 0.3,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataset.type === 'line' || ctx.dataset.label.includes('Yield')) {
                                return `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%`;
                            }
                            return `${ctx.dataset.label}: $${formatLarge(ctx.raw)}`;
                        }
                    }
                }
            },
            scales: {
                x: {},
                y: {
                    type: 'linear',
                    position: 'left',
                    ticks: { callback: (val) => formatLarge(val) },
                    beginAtZero: true,
                    suggestedMax: 100000000
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => `${val.toFixed(1)}%` },
                    beginAtZero: true
                }
            }
        }
    });
}

function renderPayoutRatioChart(latestDiv, latestInc) {
    const canvas = document.getElementById('payoutRatioChart');
    if (!canvas) return;

    let payoutRatio = 0;
    if (latestInc > 0 && latestDiv > 0) {
        payoutRatio = Math.min(100, (latestDiv / latestInc) * 100);
    } else if (latestDiv > 0 && latestInc <= 0) {
        payoutRatio = 100;
    }
    let retainedRatio = Math.max(0, 100 - payoutRatio);

    if (payoutRatioChartInstance && payoutRatioChartInstance.canvas === canvas) {
        payoutRatioChartInstance.data.datasets[0].data = [payoutRatio, retainedRatio];
        if (payoutRatioChartInstance.options.plugins && payoutRatioChartInstance.options.plugins.centerText) {
            payoutRatioChartInstance.options.plugins.centerText.text = `${payoutRatio.toFixed(1)}%`;
        }
        payoutRatioChartInstance.update('none');
        return;
    }

    if (payoutRatioChartInstance) payoutRatioChartInstance.destroy();

    const ctx = canvas.getContext('2d');
    payoutRatioChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Payout Ratio', 'Retained Earnings'],
            datasets: [{
                data: [payoutRatio, retainedRatio],
                backgroundColor: [COLORS.positive, '#2d3449'],
                borderWidth: 1,
                borderColor: 'rgba(255, 255, 255, 0.08)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '72%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 10, usePointStyle: true, padding: 15 }
                },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.label}: ${ctx.raw.toFixed(2)}%`
                    }
                },
                centerText: {
                    text: `${payoutRatio.toFixed(1)}%`
                }
            }
        }
    });
}

document.addEventListener('turbo:load', initStockPage);
initStockPage();

// =========================================================================
// NEW UNIVERSAL & SECTOR CHART RENDERERS
// =========================================================================

function renderValuationMultiplesChart(labels, peData, pbData, psData) {
    const canvas = document.getElementById('valuationMultiplesChart');
    if (!canvas) return;
    if (valuationMultiplesChartInstance) valuationMultiplesChartInstance.destroy();

    const ctx = canvas.getContext('2d');
    valuationMultiplesChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'P/E Ratio',
                    data: peData,
                    borderColor: '#7dd3fc',
                    backgroundColor: 'rgba(125, 211, 252, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3
                },
                {
                    label: 'P/B Ratio',
                    data: pbData,
                    borderColor: '#4ade80',
                    backgroundColor: 'rgba(74, 222, 128, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3
                },
                {
                    label: 'P/S Ratio',
                    data: psData,
                    borderColor: '#facc15',
                    backgroundColor: 'rgba(250, 204, 21, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.raw !== null && ctx.raw !== undefined ? ctx.raw.toFixed(1) + 'x' : 'N/A'}`
                    }
                }
            },
            scales: {
                y: {
                    ticks: { callback: (val) => val.toFixed(1) + 'x' }
                }
            }
        }
    });
}

function renderShareholderValueChart(labels, epsData, bvpsData, sharesData) {
    const canvas = document.getElementById('shareholderValueChart');
    if (!canvas) return;
    if (shareholderValueChartInstance) shareholderValueChartInstance.destroy();

    const ctx = canvas.getContext('2d');
    shareholderValueChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Shares Outstanding',
                    data: sharesData,
                    backgroundColor: 'rgba(168, 85, 247, 0.35)',
                    borderRadius: 4,
                    yAxisID: 'y1',
                    order: 1
                },
                {
                    type: 'line',
                    label: 'EPS ($)',
                    data: epsData,
                    borderColor: '#4ade80',
                    backgroundColor: '#4ade80',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y',
                    order: 0
                },
                {
                    type: 'line',
                    label: 'BVPS ($)',
                    data: bvpsData,
                    borderColor: '#7dd3fc',
                    backgroundColor: '#7dd3fc',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y',
                    order: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataset.label === 'Shares Outstanding') {
                                return `Shares: ${formatLarge(ctx.raw)}`;
                            }
                            return `${ctx.dataset.label}: $${ctx.raw !== null ? ctx.raw.toFixed(2) : '0.00'}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    ticks: { callback: (val) => '$' + val.toFixed(2) }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => formatLarge(val) }
                }
            }
        }
    });
}

function renderCashFlowSummaryChart(labels, fcfData, fcfConversionData, retainedCashData) {
    const canvas = document.getElementById('cashFlowSummaryChart');
    if (!canvas) return;
    if (cashFlowSummaryChartInstance) cashFlowSummaryChartInstance.destroy();

    const ctx = canvas.getContext('2d');
    cashFlowSummaryChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Free Cash Flow',
                    data: fcfData,
                    backgroundColor: fcfData.map(val => val < 0 ? COLORS.negative : COLORS.positive),
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'bar',
                    label: 'Net Retained Cash',
                    data: retainedCashData,
                    backgroundColor: 'rgba(56, 189, 248, 0.6)',
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'line',
                    label: 'FCF Conversion Rate',
                    data: fcfConversionData,
                    borderColor: '#facc15',
                    backgroundColor: '#facc15',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y1',
                    order: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataset.label === 'FCF Conversion Rate') {
                                return `${ctx.dataset.label}: ${ctx.raw !== null ? ctx.raw.toFixed(1) + '%' : '0%'}`;
                            }
                            return `${ctx.dataset.label}: $${formatLarge(ctx.raw)}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    ticks: { callback: (val) => '$' + formatLarge(val) }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(0) + '%' }
                }
            }
        }
    });
}

function renderNetInterestEngineChart(labels, interestIncomeData, interestExpenseData, netInterestSpreadData) {
    const canvas = document.getElementById('netInterestEngineChart');
    if (!canvas) return;
    if (netInterestEngineChartInstance) netInterestEngineChartInstance.destroy();

    const ctx = canvas.getContext('2d');
    netInterestEngineChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Interest Income',
                    data: interestIncomeData,
                    backgroundColor: COLORS.positive,
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'bar',
                    label: 'Interest Expense',
                    data: interestExpenseData,
                    backgroundColor: COLORS.negative,
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'line',
                    label: 'Net Interest Spread',
                    data: netInterestSpreadData,
                    borderColor: '#facc15',
                    backgroundColor: '#facc15',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y1',
                    order: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataset.label === 'Net Interest Spread') {
                                return `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%`;
                            }
                            return `${ctx.dataset.label}: $${formatLarge(ctx.raw)}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    ticks: { callback: (val) => '$' + formatLarge(val) }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(1) + '%' }
                }
            }
        }
    });
}

function renderInsuranceDualEngineChart(labels, underwritingProfitData, interestIncomeData, combinedRatioData) {
    const canvas = document.getElementById('insuranceDualEngineChart');
    if (!canvas) return;
    if (insuranceDualEngineChartInstance) insuranceDualEngineChartInstance.destroy();

    const ctx = canvas.getContext('2d');
    insuranceDualEngineChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Underwriting Profit',
                    data: underwritingProfitData,
                    backgroundColor: underwritingProfitData.map(val => val < 0 ? COLORS.negative : COLORS.positive),
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'bar',
                    label: 'Investment Float Income',
                    data: interestIncomeData,
                    backgroundColor: '#38bdf8',
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'line',
                    label: 'Combined Ratio',
                    data: combinedRatioData,
                    borderColor: '#facc15',
                    backgroundColor: '#facc15',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y1',
                    order: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataset.label === 'Combined Ratio') {
                                return `${ctx.dataset.label}: ${ctx.raw.toFixed(1)}%`;
                            }
                            return `${ctx.dataset.label}: $${formatLarge(ctx.raw)}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    ticks: { callback: (val) => '$' + formatLarge(val) }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(0) + '%' }
                }
            }
        }
    });
}

function renderReitCoverageChart(labels, payoutRatioData, ltvData, capRateSpreadData) {
    const canvas = document.getElementById('reitCoverageChart');
    if (!canvas) return;
    if (reitCoverageChartInstance) reitCoverageChartInstance.destroy();

    const ctx = canvas.getContext('2d');
    reitCoverageChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'Dividend Payout Ratio',
                    data: payoutRatioData,
                    borderColor: '#facc15',
                    backgroundColor: 'rgba(250, 204, 21, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Leverage Ratio (LTV)',
                    data: ltvData,
                    borderColor: COLORS.negative,
                    backgroundColor: 'rgba(248, 113, 113, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y'
                },
                {
                    type: 'bar',
                    label: 'Cap Rate vs WACC Spread',
                    data: capRateSpreadData,
                    backgroundColor: capRateSpreadData.map(val => val < 0 ? 'rgba(248, 113, 113, 0.4)' : 'rgba(74, 222, 128, 0.4)'),
                    borderRadius: 4,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%`
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    ticks: { callback: (val) => val.toFixed(0) + '%' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(1) + '%' }
                }
            }
        }
    });
}

function renderReinvestmentIntensityChart(labels, capexRevenueRatioData, operatingMarginData, roicData) {
    const canvas = document.getElementById('reinvestmentIntensityChart');
    if (!canvas) return;
    if (reinvestmentIntensityChartInstance) reinvestmentIntensityChartInstance.destroy();

    const ctx = canvas.getContext('2d');
    reinvestmentIntensityChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'CapEx / Revenue Intensity',
                    data: capexRevenueRatioData,
                    backgroundColor: 'rgba(56, 189, 248, 0.45)',
                    borderRadius: 4,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Operating Margin',
                    data: operatingMarginData,
                    borderColor: '#facc15',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'ROIC',
                    data: roicData,
                    borderColor: '#4ade80',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%`
                    }
                }
            },
            scales: {
                y: {
                    ticks: { callback: (val) => val.toFixed(0) + '%' }
                }
            }
        }
    });
}

function renderCyclicalDynamicsChart(labels, operatingMarginData, debtData, treasuryData) {
    const canvas = document.getElementById('cyclicalDynamicsChart');
    if (!canvas) return;
    if (cyclicalDynamicsChartInstance) cyclicalDynamicsChartInstance.destroy();

    const ctx = canvas.getContext('2d');
    cyclicalDynamicsChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Total Debt',
                    data: debtData,
                    backgroundColor: COLORS.negative,
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'bar',
                    label: 'Treasury Reserves',
                    data: treasuryData,
                    backgroundColor: COLORS.positive,
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'line',
                    label: 'Operating Margin (%)',
                    data: operatingMarginData,
                    borderColor: '#facc15',
                    backgroundColor: '#facc15',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y1',
                    order: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataset.label === 'Operating Margin (%)') {
                                return `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%`;
                            }
                            return `${ctx.dataset.label}: $${formatLarge(ctx.raw)}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    ticks: { callback: (val) => '$' + formatLarge(val) }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(0) + '%' }
                }
            }
        }
    });
}
