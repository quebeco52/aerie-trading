import { THEME_COLORS } from '../utils/colors.js';
import { destroyChartInstance } from '../utils/chart-config.js';

let macroEconomyChartInstance = null;
let macroRatesChartInstance = null;
let macroMortgageChartInstance = null;
let macroRiskChartInstance = null;
let macroLaborChartInstance = null;
let macroLaborCreditChartInstance = null;
let macroCommoditiesChartInstance = null;
let macroPropertyChartInstance = null;
let macroTradeLogisticsChartInstance = null;
let macroSentimentChartInstance = null;
let macroGovtSpendingChartInstance = null;
let macroInterbankLiquidityChartInstance = null;
let macroTermPremiumChartInstance = null;
let macroGdpGrowthChartInstance = null;
let macroBalanceSheetChartInstance = null;
let macroFciChartInstance = null;
let macroCostPushChartInstance = null;

export function updateMacroCharts(reports) {
    if (!reports || reports.length === 0 || typeof Chart === 'undefined') return;

    let labels = [];
    let inflationData = [], outputGapData = [], capitalOverhangData = [], corpBorrowingData = [];
    let tipsBreakevenData = [];
    let policyRateData = [], targetRateData = [], yield2yData = [], yield5yData = [], yield10yData = [], yield30yData = [];
    let spread2s10sData = [], spread30yData = [];
    let erpData = [], volData = [], taxData = [];
    let unemploymentData = [], energyPriceData = [];
    let nairuData = [];
    let sentimentData = [];
    let fxEmaData = [], metalsEmaData = [], govtSpendingEmaData = [], creEmaData = [];
    let sovereignDebtData = [];
    let retailDefaultData = [], agriEmaData = [], freightEmaData = [], residentialEmaData = [];
    let interbankSpreadBpsData = [], creditSpreadBpsData = [];
    let jobVacanciesData = [], laborTightnessData = [], wageGrowthData = [];
    let naturalRateData = [], termPremiumData = [], riskNeutralData = [], balanceSheetData = [];
    let balanceSheetAssetsData = [];
    let nominalGdpGrowthData = [], realGdpGrowthData = [], potentialGdpGrowthData = [], tfpGrowthData = [];
    let fciData = [], fciEmaData = [];
    let agriLagData = [], energySupplyDragData = [], freightSupplyDragData = [];

    const slicedReports = reports.slice(-100);
    let qCount = slicedReports.length;

    slicedReports.forEach((report, index) => {
        let labelQ = qCount - index - 1;
        labels.push(labelQ === 0 ? 'Now' : `-${labelQ}Q`);

        inflationData.push(parseFloat(report.inflation_ema) * 100);
        outputGapData.push(parseFloat(report.output_gap_ema) * 100);

        let rawTips = report.tips_breakeven_ema ?? report.tips_breakeven ?? report.tipsBreakevenEma ?? report.tipsBreakeven ?? report.inflation_ema ?? 0.02;
        tipsBreakevenData.push(parseFloat(rawTips) * 100);

        let rawCap = report.capital_stock_overhang_ema ?? report.capital_stock_overhang ?? report.capitalStockOverhangEma ?? report.capitalStockOverhang ?? 0.0;
        capitalOverhangData.push(parseFloat(rawCap) * 100);

        let pr = parseFloat(report.policy_rate_ema) * 100;
        let y10 = parseFloat(report.yield10y_ema) * 100;

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
        let rawTarget = report.target_rate ?? report.targetRate ?? report.target_rate_ema ?? report.targetRateEma;
        let tr = (rawTarget !== undefined && rawTarget !== null && rawTarget !== '') ? parseFloat(rawTarget) * 100 : null;
        targetRateData.push(tr);

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
        let rawNairu = report.nairu_ema ?? report.nairu ?? report.nairuEma ?? report.nairu ?? 0.04;
        nairuData.push(parseFloat(rawNairu) * 100);

        energyPriceData.push(parseFloat(report.energy_price_index_ema || report.energy_price_index || 100.0));
        sentimentData.push(parseFloat(report.consumer_sentiment_index_ema || report.consumer_sentiment_index || 100.0));

        fxEmaData.push(parseFloat(report.exchange_rate_index_ema || report.exchange_rate_index || 100.0));
        metalsEmaData.push(parseFloat(report.industrial_metals_index_ema || report.industrial_metals_index || 100.0));
        govtSpendingEmaData.push(parseFloat(report.government_spending_index_ema || report.government_spending_index || 100.0));
        let rawDebt = report.sovereign_debt_to_gdp_ema ?? report.sovereign_debt_to_gdp ?? report.sovereignDebtToGdpEma ?? report.sovereignDebtToGdp ?? 1.00;
        sovereignDebtData.push(parseFloat(rawDebt) * 100);

        creEmaData.push(parseFloat(report.commercial_property_index_ema || report.commercial_property_index || 100.0));
        retailDefaultData.push(parseFloat(report.retail_default_rate_ema || report.retail_default_rate || 0.025) * 100);
        agriEmaData.push(parseFloat(report.agricultural_commodity_index_ema || report.agricultural_commodity_index || 100.0));
        freightEmaData.push(parseFloat(report.freight_rate_index_ema || report.freight_rate_index || 100.0));
        residentialEmaData.push(parseFloat(report.residential_property_index_ema || report.residential_property_index || 100.0));

        let rawInterbank = report.interbank_liquidity_spread_ema ?? report.interbank_liquidity_spread ?? report.interbankLiquiditySpreadEma ?? report.interbankLiquiditySpread ?? 0.0015;
        interbankSpreadBpsData.push(parseFloat(rawInterbank) * 10000);

        let rawCreditSpread = report.macro_credit_spread_ema ?? report.macro_credit_spread ?? report.macroCreditSpreadEma ?? report.macroCreditSpread ?? 0.020;
        creditSpreadBpsData.push(parseFloat(rawCreditSpread) * 10000);

        let rawVacancies = report.job_vacancies_rate_ema ?? report.job_vacancies_rate ?? report.jobVacanciesRateEma ?? report.jobVacanciesRate ?? 0.045;
        jobVacanciesData.push(parseFloat(rawVacancies) * 100);

        let rawTightness = report.labor_tightness_ema ?? report.labor_tightness ?? report.laborTightnessEma ?? report.laborTightness ?? 1.125;
        laborTightnessData.push(parseFloat(rawTightness));

        let rawWage = report.wage_growth_ema ?? report.wage_growth ?? report.wageGrowthEma ?? report.wageGrowth ?? 0.035;
        wageGrowthData.push(parseFloat(rawWage) * 100);

        let rawNaturalRate = report.natural_rate_ema ?? report.natural_rate ?? report.naturalRateEma ?? report.naturalRate ?? 0.015;
        naturalRateData.push(parseFloat(rawNaturalRate) * 100);

        let rawTermPremium = report.term_premium10y_ema ?? report.term_premium10y ?? report.term_premium_10y_ema ?? report.term_premium_10y ?? report.termPremium10yEma ?? report.termPremium10y ?? 0.010;
        termPremiumData.push(parseFloat(rawTermPremium) * 100);

        let rawRiskNeutral = report.risk_neutral10y_ema ?? report.risk_neutral10y ?? report.risk_neutral_10y_ema ?? report.risk_neutral_10y ?? report.riskNeutral10yEma ?? report.riskNeutral10y ?? (parseFloat(report.yield10y_ema || 0.035) - parseFloat(rawTermPremium));
        riskNeutralData.push(parseFloat(rawRiskNeutral) * 100);

        let rawBalanceSheet = report.balance_sheet_intensity ?? report.balanceSheetIntensity ?? (report.qe_intensity ? parseFloat(report.qe_intensity) : 0.0);
        let bsBps = parseFloat(rawBalanceSheet) * 10000;
        balanceSheetData.push(bsBps);
        balanceSheetAssetsData.push(100.0 + (bsBps / 10.0));

        // Financial Conditions Index (FCI)
        let rawFci = report.financial_conditions_index ?? report.financialConditionsIndex ?? 0.0;
        let rawFciEma = report.financial_conditions_index_ema ?? report.financialConditionsIndexEma ?? rawFci;
        fciData.push(parseFloat(rawFci));
        fciEmaData.push(parseFloat(rawFciEma));

        // Supply-Side Cost-Push Shocks & Lags (in basis points)
        let rawAgriLag = report.agri_cost_push_lag ?? report.agriCostPushLag ?? 0.0;
        agriLagData.push(parseFloat(rawAgriLag) * 10000);

        let rawEnergy = parseFloat(report.energy_price_index_ema || report.energy_price_index || 100.0);
        let energyDragBps = Math.max(0, (rawEnergy - 100.0) / 100.0) * 0.03 * 10000;
        energySupplyDragData.push(energyDragBps);

        let rawFreight = parseFloat(report.freight_rate_index_ema || report.freight_rate_index || 100.0);
        let freightDragBps = Math.max(0, (rawFreight - 100.0) / 100.0) * 0.01 * 10000;
        freightSupplyDragData.push(freightDragBps);

        // Economic Growth Momentum & Solow-Swan Productivity Decomposition
        let inf = parseFloat(report.inflation_ema ?? report.inflation ?? 0.02) * 100;
        let currentTfp = parseFloat(report.total_factor_productivity_index_ema ?? report.total_factor_productivity_index ?? report.totalFactorProductivityIndexEma ?? report.totalFactorProductivityIndex ?? 100.0);
        let currentGap = parseFloat(report.output_gap_ema ?? report.output_gap ?? 0.0) * 100;

        let prevTfp = index > 0
            ? parseFloat(slicedReports[index - 1].total_factor_productivity_index_ema ?? slicedReports[index - 1].total_factor_productivity_index ?? slicedReports[index - 1].totalFactorProductivityIndexEma ?? slicedReports[index - 1].totalFactorProductivityIndex ?? currentTfp)
            : currentTfp / Math.exp(0.015 * 0.25);
        let prevGap = index > 0
            ? parseFloat(slicedReports[index - 1].output_gap_ema ?? slicedReports[index - 1].output_gap ?? currentGap) * 100
            : currentGap;

        // Annualized TFP productivity growth rate (%) - allowing negative values during recessions
        let tfpGrowth = Math.max(-6.0, Math.min(8.0, (Math.log(Math.max(1.0, currentTfp) / Math.max(1.0, prevTfp)) / 0.25) * 100));
        
        // Solow-Swan Real Potential GDP Growth (%): Structural Labor (0.5%) + TFP Growth
        let potentialGrowth = 0.50 + tfpGrowth;

        // Realized Cyclical Output Gap Shift Annualized (%): dGap / dt
        let cyclicalGapShift = (currentGap - prevGap) / 0.25;

        // Real GDP Annualized Growth Rate (%): Potential Growth + Cyclical Gap Momentum
        let realGrowth = Math.max(-12.0, Math.min(15.0, potentialGrowth + cyclicalGapShift));

        // Nominal GDP Annualized Growth Rate (%): Real GDP Growth + Inflation
        let nominalGrowth = realGrowth + inf;

        tfpGrowthData.push(tfpGrowth);
        potentialGdpGrowthData.push(potentialGrowth);
        realGdpGrowthData.push(realGrowth);
        nominalGdpGrowthData.push(nominalGrowth);
    });

    renderMacroEconomyChart(labels, inflationData, outputGapData, capitalOverhangData, tipsBreakevenData);
    renderMacroRatesChart(labels, policyRateData, yield2yData, yield5yData, yield10yData, spread2s10sData, targetRateData);
    renderMacroMortgageChart(labels, policyRateData, yield30yData, spread30yData);
    renderMacroRiskChart(labels, erpData, volData, taxData, corpBorrowingData);
    renderMacroLaborCreditChart(labels, unemploymentData, jobVacanciesData, wageGrowthData, nairuData);
    renderMacroCommoditiesChart(labels, energyPriceData, metalsEmaData, agriEmaData);
    renderMacroPropertyChart(labels, creEmaData, residentialEmaData);
    renderMacroTradeLogisticsChart(labels, fxEmaData, freightEmaData);
    renderMacroSentimentChart(labels, sentimentData, retailDefaultData);
    renderMacroGovtSpendingChart(labels, govtSpendingEmaData, sovereignDebtData);
    renderMacroInterbankLiquidityChart(labels, interbankSpreadBpsData, creditSpreadBpsData);
    renderMacroTermPremiumChart(labels, yield10yData, riskNeutralData, termPremiumData, naturalRateData);
    renderMacroGdpGrowthChart(labels, nominalGdpGrowthData, realGdpGrowthData, potentialGdpGrowthData, tfpGrowthData);
    renderMacroBalanceSheetChart(labels, balanceSheetAssetsData, balanceSheetData);
    renderMacroFciChart(labels, fciData, fciEmaData);
    renderMacroCostPushChart(labels, agriLagData, energySupplyDragData, freightSupplyDragData);
}

function renderMacroEconomyChart(labels, inflationData, outputGapData, capitalOverhangData, tipsBreakevenData) {
    const canvas = document.getElementById('macroEconomyChart');
    if (!canvas) return;
    macroEconomyChartInstance = destroyChartInstance(macroEconomyChartInstance);
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
                    label: '10Y TIPS Breakeven (Exp.)',
                    data: tipsBreakevenData,
                    borderColor: '#38bdf8',
                    backgroundColor: '#38bdf8',
                    borderWidth: 2,
                    borderDash: [5, 4],
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

function renderMacroRatesChart(labels, policyRateData, yield2yData, yield5yData, yield10yData, spread2s10sData, targetRateData = []) {
    const canvas = document.getElementById('macroRatesChart');
    if (!canvas) return;
    macroRatesChartInstance = destroyChartInstance(macroRatesChartInstance);
    const ctx = canvas.getContext('2d');

    const datasets = [
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
    ];

    const hasTargetRate = targetRateData && targetRateData.some(v => v !== null && !isNaN(v));
    if (hasTargetRate) {
        datasets.unshift({
            type: 'line',
            label: 'Taylor Target (Shadow)',
            data: targetRateData,
            borderColor: '#fb923c',
            backgroundColor: '#fb923c',
            borderWidth: 1.8,
            borderDash: [5, 4],
            tension: 0.2,
            spanGaps: true,
            pointRadius: labels.length > 50 ? 0 : 1
        });
    }

    macroRatesChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw !== null ? ctx.raw.toFixed(2) + '%' : 'N/A'}` } }
            },
            scales: { y: { ticks: { callback: (val) => val + '%' } } }
        }
    });
}

function renderMacroMortgageChart(labels, policyRateData, yield30yData, spread30yData) {
    const canvas = document.getElementById('macroMortgageChart');
    if (!canvas) return;
    macroMortgageChartInstance = destroyChartInstance(macroMortgageChartInstance);
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
    macroRiskChartInstance = destroyChartInstance(macroRiskChartInstance);
    const ctx = canvas.getContext('2d');
    macroRiskChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Corp Borrowing Rate',
                    data: corpBorrowingData,
                    borderColor: '#f43f5e',
                    backgroundColor: '#f43f5e',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    label: 'Market Volatility (VIX)',
                    data: volData,
                    borderColor: THEME_COLORS.negative,
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
                    borderColor: THEME_COLORS.primary,
                    backgroundColor: THEME_COLORS.primary,
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

function renderMacroLaborCreditChart(labels, unemploymentData, jobVacanciesData, wageGrowthData, nairuData) {
    const canvas = document.getElementById('macroLaborCreditChart');
    if (!canvas) return;
    macroLaborCreditChartInstance = destroyChartInstance(macroLaborCreditChartInstance);
    const ctx = canvas.getContext('2d');

    macroLaborCreditChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Unemployment Rate',
                    data: unemploymentData,
                    borderColor: '#f43f5e',
                    backgroundColor: 'rgba(244, 63, 94, 0.12)',
                    borderWidth: 2.2,
                    tension: 0.3,
                    fill: true,
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    label: 'NAIRU (Structural)',
                    data: nairuData,
                    borderColor: '#fbbf24',
                    backgroundColor: '#fbbf24',
                    borderWidth: 2,
                    borderDash: [4, 4],
                    tension: 0.3,
                    fill: false,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    label: 'Job Vacancies Rate',
                    data: jobVacanciesData,
                    borderColor: '#38bdf8',
                    backgroundColor: 'rgba(56, 189, 248, 0.08)',
                    borderWidth: 2.2,
                    borderDash: [5, 4],
                    tension: 0.3,
                    fill: true,
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    label: 'Wage Growth Rate',
                    data: wageGrowthData,
                    borderColor: '#a855f7',
                    borderWidth: 2.2,
                    tension: 0.3,
                    fill: false,
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
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'Percentage Rate (%)' }
                },
                x: { ticks: { maxTicksLimit: 10 } }
            }
        }
    });
}

function renderMacroTermPremiumChart(labels, yield10yData, riskNeutralData, termPremiumData, naturalRateData) {
    const canvas = document.getElementById('macroTermPremiumChart');
    if (!canvas) return;
    macroTermPremiumChartInstance = destroyChartInstance(macroTermPremiumChartInstance);
    const ctx = canvas.getContext('2d');

    macroTermPremiumChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '10Y Sovereign Yield',
                    data: yield10yData,
                    borderColor: '#c084fc',
                    backgroundColor: '#c084fc',
                    borderWidth: 2.5,
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    label: 'Expected Policy Rate Path',
                    data: riskNeutralData,
                    borderColor: '#38bdf8',
                    backgroundColor: '#38bdf8',
                    borderWidth: 2,
                    borderDash: [5, 4],
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    label: 'Duration Term Premium',
                    data: termPremiumData,
                    borderColor: '#fb7185',
                    backgroundColor: 'rgba(251, 113, 133, 0.12)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    label: 'Natural Real Rate',
                    data: naturalRateData,
                    borderColor: '#34d399',
                    borderWidth: 1.5,
                    tension: 0.1,
                    pointRadius: labels.length > 50 ? 0 : 1
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
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'Yield (%)' }
                },
                x: { ticks: { maxTicksLimit: 10 } }
            }
        }
    });
}

function renderMacroGdpGrowthChart(labels, nominalGdpGrowthData, realGdpGrowthData, potentialGdpGrowthData, tfpGrowthData) {
    const canvas = document.getElementById('macroGdpGrowthChart');
    if (!canvas) return;
    macroGdpGrowthChartInstance = destroyChartInstance(macroGdpGrowthChartInstance);
    const ctx = canvas.getContext('2d');

    macroGdpGrowthChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Nominal GDP Growth (Ann.)',
                    data: nominalGdpGrowthData,
                    borderColor: '#38bdf8',
                    backgroundColor: '#38bdf8',
                    borderWidth: 2.2,
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    label: 'Real GDP Growth (Momentum)',
                    data: realGdpGrowthData,
                    borderColor: '#4ade80',
                    backgroundColor: 'rgba(74, 222, 128, 0.12)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    label: 'Potential Capacity Trend (Y*)',
                    data: potentialGdpGrowthData,
                    borderColor: '#fbbf24',
                    backgroundColor: '#fbbf24',
                    borderWidth: 1.8,
                    borderDash: [5, 4],
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    label: 'TFP Productivity Growth',
                    data: tfpGrowthData,
                    borderColor: '#c084fc',
                    backgroundColor: '#c084fc',
                    borderWidth: 1.5,
                    tension: 0.2,
                    pointRadius: labels.length > 50 ? 0 : 1
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
                            const val = ctx.raw;
                            return `${ctx.dataset.label}: ${val >= 0 ? '+' : ''}${val.toFixed(2)}%`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    ticks: { callback: (val) => (val >= 0 ? '+' : '') + val.toFixed(1) + '%' },
                    title: { display: true, text: 'Annualized Rate (%)' }
                },
                x: { ticks: { maxTicksLimit: 10 } }
            }
        }
    });
}

function renderMacroBalanceSheetChart(labels, balanceSheetAssetsData, balanceSheetIntensityData) {
    const canvas = document.getElementById('macroBalanceSheetChart');
    if (!canvas) return;
    macroBalanceSheetChartInstance = destroyChartInstance(macroBalanceSheetChartInstance);
    const ctx = canvas.getContext('2d');

    macroBalanceSheetChartInstance = new Chart(ctx, {
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'Balance Sheet Index',
                    data: balanceSheetAssetsData,
                    borderColor: '#38bdf8',
                    backgroundColor: 'rgba(56, 189, 248, 0.12)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.3,
                    yAxisID: 'y',
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    type: 'bar',
                    label: 'Operations Intensity (QE / QT)',
                    data: balanceSheetIntensityData,
                    backgroundColor: balanceSheetIntensityData.map((val, idx) => {
                        const prevVal = idx > 0 ? balanceSheetIntensityData[idx - 1] : val;
                        const delta = val - prevVal;
                        if (val > 5) {
                            if (delta > 2) return 'rgba(74, 222, 128, 0.50)'; // QE Expansion (Green)
                            if (delta < -2) return 'rgba(244, 63, 94, 0.50)'; // QT Runoff (Rose)
                            return 'rgba(251, 191, 36, 0.55)'; // Reinvestment Hold (Amber)
                        }
                        if (val < -5) return 'rgba(244, 63, 94, 0.50)'; // QT Runoff (Rose)
                        return 'rgba(255, 255, 255, 0.10)'; // Neutral
                    }),
                    borderColor: balanceSheetIntensityData.map((val, idx) => {
                        const prevVal = idx > 0 ? balanceSheetIntensityData[idx - 1] : val;
                        const delta = val - prevVal;
                        if (val > 5) {
                            if (delta > 2) return '#4ade80';
                            if (delta < -2) return '#f43f5e';
                            return '#fbbf24';
                        }
                        if (val < -5) return '#f43f5e';
                        return 'transparent';
                    }),
                    borderWidth: 1,
                    borderRadius: 3,
                    yAxisID: 'y1'
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
                        label: (ctx) => {
                            if (ctx.dataset.yAxisID === 'y1') {
                                const val = ctx.raw;
                                const idx = ctx.dataIndex;
                                const prevVal = idx > 0 ? balanceSheetIntensityData[idx - 1] : val;
                                const delta = val - prevVal;

                                let action = 'Neutral';
                                if (val > 5) {
                                    if (delta > 2) {
                                        action = 'QE Expansion';
                                    } else if (delta < -2) {
                                        action = 'QT Runoff';
                                    } else {
                                        action = 'Reinvestment Hold';
                                    }
                                } else if (val < -5) {
                                    action = 'QT Runoff';
                                }
                                return `${ctx.dataset.label}: ${val > 0 ? '+' : ''}${val.toFixed(0)} bps (${action})`;
                            }
                            return `${ctx.dataset.label}: ${ctx.raw.toFixed(1)}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { color: 'rgba(255, 255, 255, 0.7)', callback: (val) => Math.round(val) },
                    title: { display: true, text: 'Balance Sheet Index' }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: {
                        maxTicksLimit: 6,
                        callback: (val) => (val > 0 ? '+' : '') + Math.round(val) + ' bps'
                    },
                    title: { display: true, text: 'Operations Intensity (bps)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 10, color: 'rgba(255, 255, 255, 0.5)' }
                }
            }
        }
    });
}

function renderMacroCommoditiesChart(labels, energyPriceData, metalsEmaData, agriEmaData) {
    const canvas = document.getElementById('macroCommoditiesChart');
    if (!canvas) return;
    macroCommoditiesChartInstance = destroyChartInstance(macroCommoditiesChartInstance);
    const ctx = canvas.getContext('2d');

    macroCommoditiesChartInstance = new Chart(ctx, {
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
    const canvas = document.getElementById('macroPropertyChart');
    if (!canvas) return;
    macroPropertyChartInstance = destroyChartInstance(macroPropertyChartInstance);
    const ctx = canvas.getContext('2d');

    macroPropertyChartInstance = new Chart(ctx, {
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
    const canvas = document.getElementById('macroTradeLogisticsChart');
    if (!canvas) return;
    macroTradeLogisticsChartInstance = destroyChartInstance(macroTradeLogisticsChartInstance);
    const ctx = canvas.getContext('2d');

    macroTradeLogisticsChartInstance = new Chart(ctx, {
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

function renderMacroSentimentChart(labels, sentimentData, retailDefaultData) {
    const canvas = document.getElementById('macroSentimentChart');
    if (!canvas) return;
    macroSentimentChartInstance = destroyChartInstance(macroSentimentChartInstance);
    const ctx = canvas.getContext('2d');

    macroSentimentChartInstance = new Chart(ctx, {
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'Consumer Sentiment Index',
                    data: sentimentData,
                    borderColor: '#a855f7',
                    backgroundColor: 'rgba(168, 85, 247, 0.15)',
                    borderWidth: 2.2,
                    tension: 0.25,
                    fill: true,
                    yAxisID: 'y',
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    type: 'line',
                    label: 'Household Default Rate',
                    data: retailDefaultData || [],
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.08)',
                    borderWidth: 2,
                    borderDash: [4, 4],
                    tension: 0.25,
                    fill: false,
                    yAxisID: 'y1',
                    pointRadius: labels.length > 50 ? 0 : 1
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
                        label: (ctx) => ctx.dataset.yAxisID === 'y1'
                            ? `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%`
                            : `${ctx.dataset.label}: ${ctx.raw.toFixed(1)} pts`
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    min: 40,
                    max: 130,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { color: 'rgba(255, 255, 255, 0.7)', callback: (val) => val },
                    title: { display: true, text: 'Sentiment Index' }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'Default Rate (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 10, color: 'rgba(255, 255, 255, 0.5)' }
                }
            }
        }
    });
}

function renderMacroGovtSpendingChart(labels, govtSpendingEmaData, sovereignDebtData) {
    const canvas = document.getElementById('macroGovtSpendingChart');
    if (!canvas) return;
    macroGovtSpendingChartInstance = destroyChartInstance(macroGovtSpendingChartInstance);
    const ctx = canvas.getContext('2d');

    macroGovtSpendingChartInstance = new Chart(ctx, {
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'Fiscal Spending Index',
                    data: govtSpendingEmaData,
                    borderColor: '#34d399',
                    backgroundColor: 'rgba(52, 211, 153, 0.15)',
                    borderWidth: 2,
                    tension: 0.2,
                    fill: true,
                    yAxisID: 'y',
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    type: 'line',
                    label: 'Sovereign Debt-to-GDP',
                    data: sovereignDebtData,
                    borderColor: '#f43f5e',
                    backgroundColor: 'rgba(244, 63, 94, 0.08)',
                    borderWidth: 2.2,
                    borderDash: [5, 4],
                    tension: 0.2,
                    fill: false,
                    yAxisID: 'y1',
                    pointRadius: labels.length > 50 ? 0 : 2
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
                        label: (ctx) => ctx.dataset.yAxisID === 'y1'
                            ? `${ctx.dataset.label}: ${ctx.raw.toFixed(1)}%`
                            : `${ctx.dataset.label}: ${ctx.raw.toFixed(1)} pts`
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { color: 'rgba(255, 255, 255, 0.7)', callback: (val) => val },
                    title: { display: true, text: 'Spending Index' }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(0) + '%' },
                    title: { display: true, text: 'Debt / GDP (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 10, color: 'rgba(255, 255, 255, 0.5)' }
                }
            }
        }
    });
}

function renderMacroInterbankLiquidityChart(labels, interbankSpreadBpsData, creditSpreadBpsData) {
    const canvas = document.getElementById('macroInterbankLiquidityChart');
    if (!canvas) return;
    macroInterbankLiquidityChartInstance = destroyChartInstance(macroInterbankLiquidityChartInstance);
    const ctx = canvas.getContext('2d');

    macroInterbankLiquidityChartInstance = new Chart(ctx, {
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

function renderMacroFciChart(labels, fciData, fciEmaData) {
    const canvas = document.getElementById('macroFciChart');
    if (!canvas) return;
    macroFciChartInstance = destroyChartInstance(macroFciChartInstance);
    const ctx = canvas.getContext('2d');

    macroFciChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'FCI Trend (EMA)',
                    data: fciEmaData,
                    borderColor: '#c084fc',
                    backgroundColor: '#c084fc',
                    borderWidth: 2,
                    borderDash: [4, 4],
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 2,
                    yAxisID: 'y'
                },
                {
                    type: 'bar',
                    label: 'Financial Conditions (Z-Score)',
                    data: fciData,
                    backgroundColor: fciData.map(val => val > 0 ? 'rgba(244, 63, 94, 0.45)' : 'rgba(74, 222, 128, 0.45)'),
                    borderColor: fciData.map(val => val > 0 ? '#f43f5e' : '#4ade80'),
                    borderWidth: 1,
                    borderRadius: 3,
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
                        label: (ctx) => {
                            const val = ctx.raw;
                            if (ctx.dataset.type === 'bar') {
                                const stance = val > 0 ? 'Restrictive (Tight)' : (val < 0 ? 'Accommodative (Loose)' : 'Neutral');
                                return `${ctx.dataset.label}: ${val >= 0 ? '+' : ''}${val.toFixed(2)} σ (${stance})`;
                            }
                            return `${ctx.dataset.label}: ${val >= 0 ? '+' : ''}${val.toFixed(2)} σ`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: {
                        callback: (val) => (val > 0.001 ? '+' : '') + val.toFixed(2) + ' σ'
                    },
                    title: { display: true, text: 'Z-Score (σ)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 10, color: 'rgba(255, 255, 255, 0.5)' }
                }
            }
        }
    });
}

function renderMacroCostPushChart(labels, agriLagData, energySupplyDragData, freightSupplyDragData) {
    const canvas = document.getElementById('macroCostPushChart');
    if (!canvas) return;
    macroCostPushChartInstance = destroyChartInstance(macroCostPushChartInstance);
    const ctx = canvas.getContext('2d');

    macroCostPushChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Agricultural Food CPI Lag',
                    data: agriLagData,
                    borderColor: '#a3e635',
                    backgroundColor: 'rgba(163, 230, 53, 0.15)',
                    borderWidth: 2,
                    tension: 0.25,
                    fill: true,
                    pointRadius: labels.length > 50 ? 0 : 2
                },
                {
                    label: 'Energy Supply Drag',
                    data: energySupplyDragData,
                    borderColor: '#eab308',
                    backgroundColor: 'rgba(234, 179, 8, 0.10)',
                    borderWidth: 2,
                    borderDash: [5, 4],
                    tension: 0.25,
                    fill: false,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    label: 'Freight Logistics Drag',
                    data: freightSupplyDragData,
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.10)',
                    borderWidth: 2,
                    borderDash: [3, 3],
                    tension: 0.25,
                    fill: false,
                    pointRadius: labels.length > 50 ? 0 : 1
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
                        label: (ctx) => `${ctx.dataset.label}: +${ctx.raw.toFixed(1)} bps`
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => '+' + val.toFixed(0) + ' bps' },
                    title: { display: true, text: 'Basis Points (bps)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 10, color: 'rgba(255, 255, 255, 0.5)' }
                }
            }
        }
    });
}

export function resizeMacroCharts() {
    const instances = [
        macroEconomyChartInstance, macroRatesChartInstance, macroMortgageChartInstance,
        macroRiskChartInstance, macroLaborChartInstance, macroLaborCreditChartInstance,
        macroCommoditiesChartInstance, macroPropertyChartInstance, macroTradeLogisticsChartInstance,
        macroSentimentChartInstance, macroGovtSpendingChartInstance, macroInterbankLiquidityChartInstance,
        macroTermPremiumChartInstance, macroGdpGrowthChartInstance, macroBalanceSheetChartInstance,
        macroFciChartInstance, macroCostPushChartInstance
    ];
    instances.forEach(c => {
        if (c) {
            try { c.resize(); } catch (e) {}
        }
    });
}

export function destroyMacroCharts() {
    macroEconomyChartInstance = destroyChartInstance(macroEconomyChartInstance);
    macroRatesChartInstance = destroyChartInstance(macroRatesChartInstance);
    macroMortgageChartInstance = destroyChartInstance(macroMortgageChartInstance);
    macroRiskChartInstance = destroyChartInstance(macroRiskChartInstance);
    macroLaborChartInstance = destroyChartInstance(macroLaborChartInstance);
    macroLaborCreditChartInstance = destroyChartInstance(macroLaborCreditChartInstance);
    macroCommoditiesChartInstance = destroyChartInstance(macroCommoditiesChartInstance);
    macroPropertyChartInstance = destroyChartInstance(macroPropertyChartInstance);
    macroTradeLogisticsChartInstance = destroyChartInstance(macroTradeLogisticsChartInstance);
    macroSentimentChartInstance = destroyChartInstance(macroSentimentChartInstance);
    macroGovtSpendingChartInstance = destroyChartInstance(macroGovtSpendingChartInstance);
    macroInterbankLiquidityChartInstance = destroyChartInstance(macroInterbankLiquidityChartInstance);
    macroTermPremiumChartInstance = destroyChartInstance(macroTermPremiumChartInstance);
    macroGdpGrowthChartInstance = destroyChartInstance(macroGdpGrowthChartInstance);
    macroBalanceSheetChartInstance = destroyChartInstance(macroBalanceSheetChartInstance);
    macroFciChartInstance = destroyChartInstance(macroFciChartInstance);
    macroCostPushChartInstance = destroyChartInstance(macroCostPushChartInstance);
}
