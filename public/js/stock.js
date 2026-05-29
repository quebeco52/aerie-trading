const CURRENT_TICKER = window.AERIE_DATA.ticker;
const IS_ETF = window.AERIE_DATA.isEtf;
const LEVERAGE_TYPE = window.AERIE_DATA.leverageType || 'none';
const IS_FINANCIAL = LEVERAGE_TYPE !== 'none';
const SHARES_OUTSTANDING = window.AERIE_DATA.sharesOutstanding;
const USER_QUANTITY = window.AERIE_DATA.userQuantity;
const EPS = window.AERIE_DATA.eps;
const TICKS_PER_YEAR = window.AERIE_DATA.ticksPerYear || 54000;
const SECONDS_PER_TICK = Math.round(31536000 / TICKS_PER_YEAR);

let rawReports = [];
let profitEngineChartInstance = null;
let debtEquityChartInstance = null;
let creditHealthChartInstance = null;
let capitalEfficiencyChartInstance = null;
let capitalReturnChartInstance = null;
let regulatoryRatiosChartInstance = null;

Chart.defaults.color = '#c2c6d6';
Chart.defaults.scale.grid.color = 'rgba(45, 52, 73, 0.4)';
Chart.defaults.font.family = '"Courier Prime", monospace';

const COLORS = {
    primary: '#adc6ff',
    positive: '#4edea3',
    negative: '#ffb3ad',
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

document.addEventListener('DOMContentLoaded', () => {
    // variables
    let previousPrice = null;
    let currentRange = '1y';
    let currentSimTime = 0;
    let lastChartPointTime = 0;
    let currentStepSize = 1;
    let lwChart = null;
    let areaSeries = null;
    let etfPieChart = null;
    let etfComponents = prepareEtfData();

    // Initial ui
    initMainChart();
    if (IS_ETF) initEtfChart();
    loadHistory('1y');
    setupEventListeners();

    // Fetch Fundamental Data Skip if ETF
    if (!IS_ETF) {
        fetch(`/api/fundamentals?ticker=${CURRENT_TICKER}`)
            .then(res => res.json())
            .then(data => {
                rawReports = data;
                updateCharts('5Y');
            })
            .catch(err => console.error("Failed to load fundamentals:", err));
    }

    if (!window.WS_TICKET || window.WS_TICKET === "") {
        console.log("Guest mode: Live WebSocket updates disabled.");
        return; // Safe to exit here so we don't try to connect to the socket
    }

    // Websocket connection
    const protocol = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
    const marketSocket = new WebSocket(`${protocol}${window.location.host}/ws/?ticket=${window.WS_TICKET}`);

    // The Master Router
    marketSocket.onmessage = function (event) {
        const payload = JSON.parse(event.data);

        if (IS_ETF) {
            updateMacroIndicators(payload);
            updateEtfPie(payload);
        }

        const stockUpdate = payload.stocks.find(s => s.ticker === CURRENT_TICKER);
        if (stockUpdate) {
            const newPrice = parseFloat(stockUpdate.price);
            updatePriceUI(newPrice, stockUpdate);
            updateLiveChart(newPrice);
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
            if (yieldEl) yieldEl.textContent = (payload.macro.yield_10y * 100).toFixed(2) + '%';

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
                const gdpValue = 20.00 * payload.macro.nominal_gdp_index;
                gdpEl.textContent = '$' + gdpValue.toFixed(2) + 'T';
            }

        }

        // Update the Current Cycle Badge
        if (payload.economic_cycle) {
            const cycleEl = document.getElementById('market-economic-cycle');
            if (cycleEl) cycleEl.textContent = payload.economic_cycle;
        }
    };




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

        new ResizeObserver(entries => {
            if (entries.length > 0 && entries[0].target === container) {
                lwChart.applyOptions({ height: entries[0].contentRect.height, width: entries[0].contentRect.width });
            }
        }).observe(container);
    }

    function initEtfChart() {
        const ctx = document.getElementById('etfPieChart').getContext('2d');
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
        const oldPrice = previousPrice || newPrice;

        el.innerText = '$' + newPrice.toFixed(2);
        el.style.color = newPrice > oldPrice ? COLORS.positive : (newPrice < oldPrice ? COLORS.negative : '#dae2fd');
        previousPrice = newPrice;
        setTimeout(() => el.style.color = '#dae2fd', 500);

        if (USER_QUANTITY > 0) {
            document.getElementById('user-holding-value').innerText = '$' + (newPrice * USER_QUANTITY).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        if (!IS_ETF) {
            // Use the live EPS from the WebSocket if available, otherwise fallback to the page load EPS
            const currentEps = stockUpdate.eps !== undefined ? stockUpdate.eps : EPS;

            // Format the large numbers dynamically!
            if (document.getElementById('stat-mkt-cap') && stockUpdate.market_cap !== undefined) {
                document.getElementById('stat-mkt-cap').innerText = '$' + formatLarge(stockUpdate.market_cap);
            }
            if (document.getElementById('stat-treasury') && stockUpdate.treasury !== undefined) {
                document.getElementById('stat-treasury').innerText = '$' + formatLarge(stockUpdate.treasury);
            }
            if (document.getElementById('stat-equity') && stockUpdate.equity !== undefined) {
                document.getElementById('stat-equity').innerText = '$' + formatLarge(stockUpdate.equity);
            }
            if (document.getElementById('stat-pe')) {
                document.getElementById('stat-pe').innerText = currentEps > 0 ? (newPrice / currentEps).toFixed(2) : '0.00';
            }
            if (document.getElementById('stat-debt-ratio')) {
                document.getElementById('stat-debt-ratio').innerText = stockUpdate.debt_ratio.toFixed(2) + 'x';
            }
            if (stockUpdate.market_share !== undefined && document.getElementById('stat-market-share')) {
                document.getElementById('stat-market-share').innerText = stockUpdate.market_share.toFixed(2) + '%';
            }

            // Other live stats
            if (stockUpdate.current_volatility !== undefined) {
                document.getElementById('stat-volatility').innerText = stockUpdate.current_volatility.toFixed(2) + '%';
            }
            if (stockUpdate.shares !== undefined) {
                document.getElementById('stat-shares').innerText = stockUpdate.shares.toLocaleString('en-US');
            }
            if (stockUpdate.current_roic !== undefined && document.getElementById('stat-roic')) {
                document.getElementById('stat-roic').innerText = (stockUpdate.current_roic * 100).toFixed(2) + '%';
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
        if (!etfPieChart) return;
        let updated = false;

        payload.stocks.forEach(stock => {
            let comp = etfComponents.find(c => c.ticker === stock.ticker);
            if (comp) {
                comp.value = parseFloat(stock.price) * window.AERIE_DATA.sharesMap[stock.ticker];
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
        if (!IS_ETF) return [];
        let components = [];
        let fIndex = 0;
        window.AERIE_DATA.pieLabels.forEach((ticker, i) => {
            let color = BRAND_COLORS[ticker] || FALLBACK_PALETTE[fIndex++ % FALLBACK_PALETTE.length];
            components.push({ ticker, value: window.AERIE_DATA.pieData[i], color });
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
    }
});

// FUNDAMENTAL CHARTING LOGIC

function updateCharts(timeframe) {
    if (!rawReports || rawReports.length === 0) return;

    // Update button styles to match your UI
    const btn12Q = document.getElementById('btn-12Q');
    const btn5Y = document.getElementById('btn-5Y');

    if (timeframe === '12Q') {
        btn12Q.className = 'px-3 py-1 text-[10px] font-bold rounded-md bg-primary text-[#001a42] shadow-lg shadow-primary/20 transition-colors uppercase tracking-widest';
        btn5Y.className = 'px-3 py-1 text-[10px] font-bold rounded-md bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-colors uppercase tracking-widest';
    } else {
        btn5Y.className = 'px-3 py-1 text-[10px] font-bold rounded-md bg-primary text-[#001a42] shadow-lg shadow-primary/20 transition-colors uppercase tracking-widest';
        btn12Q.className = 'px-3 py-1 text-[10px] font-bold rounded-md bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-colors uppercase tracking-widest';
    }

    let labels = [];

    // Profit Engine
    let revenueData = [];
    let netIncomeData = [];
    let capexData = [];
    let operatingMarginData = [];

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
    
    // Leveraged Metrics (Banking)
    let roeData = [];
    let coeData = [];
    let capitalRatioData = [];
    let customerDepositRatioData = [];

    if (timeframe === '12Q') {
        const sliced = rawReports.slice(-12);
        sliced.forEach((report, index) => {
            labels.push(`Q${(index % 4) + 1}`);

            let rev = parseFloat(report.revenue || 0) / 4;
            let inc = parseFloat(report.net_income || 0) / 4;
            let intExp = parseFloat(report.interest_expense || 0) / 4;

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

            dividendData.push(parseFloat(report.dividend_paid || 0));
            buybackData.push(parseFloat(report.stock_buybacks || 0));

            roeData.push(parseFloat(report.return_on_equity || 0) * 100);
            coeData.push(parseFloat(report.cost_of_equity || 0) * 100);
            capitalRatioData.push(parseFloat(report.capital_ratio || 0) * 100);
            customerDepositRatioData.push(parseFloat(report.customer_deposit_ratio || 0) * 100);
        });
    }
    else if (timeframe === '5Y') {
        const yearsToFetch = 12;
        let yearCount = 1;

        for (let i = rawReports.length - 1; i >= 0 && yearCount <= yearsToFetch; i -= 4) {
            let report = rawReports[i];

            if (yearCount === 1) labels.unshift("Now");
            else if (yearCount === 2) labels.unshift("-1 Yr");
            else labels.unshift(`-${yearCount - 1} Yrs`);

            let rev = parseFloat(report.revenue || 0);
            let inc = parseFloat(report.net_income || 0);
            let intExp = parseFloat(report.interest_expense || 0);

            revenueData.unshift(rev);
            netIncomeData.unshift(inc);
            capexData.unshift(-(parseFloat(report.capital_expenditures || 0) * 4));
            operatingMarginData.unshift(parseFloat(report.operating_margin || 0) * 100);

            debtData.unshift(parseFloat(report.total_debt || 0));
            equityData.unshift(parseFloat(report.equity || 0));
            treasuryData.unshift(parseFloat(report.treasury || 0));

            spreadData.unshift(parseFloat(report.dynamic_spread || 0) * 100);
            blendedRateData.unshift(parseFloat(report.blended_rate || 0) * 100);
            expenseRatioData.unshift(rev > 0 ? (intExp / rev) * 100 : 0.0);
            cashYieldData.unshift(parseFloat(report.cash_yield || report.cashYield || 0) * 100);
            depositApyData.unshift(parseFloat(report.deposit_apy || report.depositApy || 0) * 100);

            roicData.unshift(parseFloat(report.roic || 0) * 100);
            waccData.unshift(parseFloat(report.wacc || 0) * 100);
            evaData.unshift(parseFloat(report.eva || 0));

            // Sum the last 4 quarters for a accurate annualized figure
            let sumDiv = 0;
            let sumBuy = 0;
            for (let j = 0; j < 4; j++) {
                if (i - j >= 0) {
                    sumDiv += parseFloat(rawReports[i - j].dividend_paid || 0);
                    sumBuy += parseFloat(rawReports[i - j].stock_buybacks || 0);
                }
            }
            dividendData.unshift(sumDiv);
            buybackData.unshift(sumBuy);
            
            roeData.unshift(parseFloat(report.return_on_equity || 0) * 100);
            coeData.unshift(parseFloat(report.cost_of_equity || 0) * 100);
            capitalRatioData.unshift(parseFloat(report.capital_ratio || 0) * 100);
            customerDepositRatioData.unshift(parseFloat(report.customer_deposit_ratio || 0) * 100);

            yearCount++;
        }
    }

    renderProfitEngineChart(labels, revenueData, netIncomeData, capexData, operatingMarginData);
    renderDebtEquityChart(labels, debtData, equityData, treasuryData);
    renderCreditHealthChart(labels, spreadData, blendedRateData, expenseRatioData, cashYieldData, depositApyData);
    renderCapitalReturnChart(labels, dividendData, buybackData);
    
    // THE NEW FINANCIAL SPLIT LOGIC
    if (IS_FINANCIAL) {
        // ALL financial companies are evaluated on Return on Equity (ROE)
        renderCapitalEfficiencyChart(labels, roeData, coeData, evaData, 'ROE', 'Cost of Equity');
        
        if (LEVERAGE_TYPE === 'commercial_bank') {
            renderRegulatoryRatiosChart(labels, capitalRatioData, customerDepositRatioData, 'Customer Deposit Ratio');
        } else if (LEVERAGE_TYPE === 'insurance') {
            renderRegulatoryRatiosChart(labels, capitalRatioData, customerDepositRatioData, 'Float Ratio (0% Interest)');
        } else if (LEVERAGE_TYPE === 'brokerage') {
            // Brokerages don't use deposits/float, so we only pass the Capital Ratio
            renderRegulatoryRatiosChart(labels, capitalRatioData, null, null);
        }
    } else {
        // Normal companies use ROIC
        renderCapitalEfficiencyChart(labels, roicData, waccData, evaData, 'ROIC', 'WACC');
    }
}

function renderProfitEngineChart(labels, revenueData, netIncomeData, capexData, operatingMarginData) {
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
                    label: 'Operating Margin',
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
                            if (ctx.dataset.label === 'Operating Margin') {
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
    if (LEVERAGE_TYPE === 'commercial_bank') {
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

function renderCapitalReturnChart(labels, dividendData, buybackData) {
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
                },
                {
                    type: 'bar',
                    label: 'Stock Buybacks',
                    data: buybackData,
                    backgroundColor: COLORS.positive,
                    borderRadius: 4,
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
                        label: (ctx) => `${ctx.dataset.label}: $${formatLarge(ctx.raw)}`
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
                }
            }
        }
    });
}