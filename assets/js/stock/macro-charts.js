import { THEME_COLORS } from '../utils/colors.js';
import { destroyChartInstance } from '../utils/chart-config.js';
import { renderWhenVisible, resetLazyCharts } from '../utils/lazy-chart.js';

let macroEconomyChartInstance = null;
let macroRatesChartInstance = null;
let macroMortgageChartInstance = null;
let macroRiskChartInstance = null;
let macroLaborChartInstance = null;
let macroLaborCreditChartInstance = null;
let macroWealthEffectChartInstance = null;
let macroCommoditiesChartInstance = null;
let macroEnergySpreadsChartInstance = null;
let macroPropertyChartInstance = null;
let macroTradeLogisticsChartInstance = null;
let macroSentimentChartInstance = null;
let macroGovtSpendingChartInstance = null;
let macroSovereignFundChartInstance = null;
let macroInterbankLiquidityChartInstance = null;
let macroTermPremiumChartInstance = null;
let macroGdpGrowthChartInstance = null;
let macroBalanceSheetChartInstance = null;
let macroFciChartInstance = null;
let macroCostPushChartInstance = null;
let macroSectoralInflationChartInstance = null;
let macroCreditCliffChartInstance = null;
let macroInventoryCycleChartInstance = null;
let macroPolicyRuleChartInstance = null;
let macroLeadingIndicatorsChartInstance = null;
let macroHouseholdCreditChartInstance = null;
let macroGlobalCycleChartInstance = null;
let macroBankingLiquidityChartInstance = null;

let currentMacroReports = [];
let currentMacroTimeframe = '10Y';

export function setMacroTimeframe(timeframe) {
    currentMacroTimeframe = timeframe;
    if (currentMacroReports && currentMacroReports.length > 0) {
        updateMacroCharts(currentMacroReports, timeframe);
    }
}

function setHud(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
}

function updateMacroHud(d) {
    const last = (arr, def = 0) => (arr && arr.length > 0 && arr[arr.length - 1] !== null && !isNaN(arr[arr.length - 1])) ? arr[arr.length - 1] : def;

    setHud('hud-macroEconomyChart', `CPI: ${last(d.inflationData).toFixed(1)}% | Gap: ${last(d.outputGapData) >= 0 ? '+' : ''}${last(d.outputGapData).toFixed(1)}%`);
    setHud('hud-macroRatesChart', `PR: ${last(d.policyRateData).toFixed(2)}% | 10Y: ${last(d.yield10yData).toFixed(2)}%`);
    setHud('hud-macroMortgageChart', `30Y: ${last(d.mortgageYieldData).toFixed(2)}% | vs PR: ${last(d.spread30yData).toFixed(2)}%`);
    setHud('hud-macroRiskChart', `VIX: ${last(d.volData).toFixed(1)}% | ERP: ${last(d.erpData).toFixed(1)}%`);
    const lastRealWageGap = [...(d.realWageGapData ?? [])].reverse().find(v => v !== null && !isNaN(v));
    setHud('hud-macroLaborCreditChart', `Unemp: ${last(d.unemploymentData).toFixed(1)}% | Wage: ${last(d.wageGrowthData).toFixed(1)}% | Real: ${(last(d.wageGrowthData) - last(d.tipsBreakevenData)).toFixed(1)}%`
        + (lastRealWageGap === undefined ? '' : ` | vs Path: ${lastRealWageGap >= 0 ? '+' : ''}${lastRealWageGap.toFixed(1)}%`));
    const lastEquityGap = [...d.equityWealthGapData].reverse().find(v => v !== null && !isNaN(v));
    setHud('hud-macroWealthEffectChart', lastEquityGap === undefined
        ? `Equity: - | Housing: ${last(d.housingWealthGapData) >= 0 ? '+' : ''}${last(d.housingWealthGapData).toFixed(1)}%`
        : `Equity: ${lastEquityGap >= 0 ? '+' : ''}${lastEquityGap.toFixed(1)}% | Housing: ${last(d.housingWealthGapData) >= 0 ? '+' : ''}${last(d.housingWealthGapData).toFixed(1)}%`);
    const lastTed = [...d.interbankSpreadBpsData].reverse().find(v => v !== null);
    setHud('hud-macroInterbankLiquidityChart', lastTed !== undefined ? `TED: ${lastTed.toFixed(0)} bps` : 'TED: -');
    setHud('hud-macroPropertyChart', `CRE: ${last(d.creEmaData).toFixed(1)} | Resi: ${last(d.residentialEmaData).toFixed(1)} | Starts: ${last(d.housingStartsData).toFixed(1)}`);
    setHud('hud-macroSentimentChart', `Sent: ${last(d.sentimentData).toFixed(0)} | M&A: ${last(d.dealActivityData).toFixed(0)}`);
    const lastGold = [...d.goldPriceData].reverse().find(v => v !== null && !isNaN(v));
    setHud('hud-macroCommoditiesChart', `Energy: ${last(d.energyPriceData).toFixed(1)} | Metals: ${last(d.metalsEmaData).toFixed(1)} | Gold: ${lastGold === undefined ? '-' : lastGold.toFixed(1)} | Gas: ${last(d.naturalGasPriceData).toFixed(1)}`);
    const spreads = energySpreadDollars(d.powerPriceIndexData, d.naturalGasPriceData);
    const lastPower = [...spreads.power].reverse().find(v => v !== null && !isNaN(v));
    const lastSpark = [...spreads.spark].reverse().find(v => v !== null && !isNaN(v));
    setHud('hud-macroEnergySpreadsChart', `Crack: $${last(d.crackSpreadData).toFixed(2)}/bbl | Power: ${lastPower === undefined ? '-' : '$' + lastPower.toFixed(1)} | Spark: ${lastSpark === undefined ? '-' : '$' + lastSpark.toFixed(1)}/MWh`);
    setHud('hud-macroTradeLogisticsChart', `FX: ${last(d.fxEmaData).toFixed(1)} | Freight: ${last(d.freightEmaData).toFixed(1)} | GSCPI: ${last(d.gscpiData) >= 0 ? '+' : ''}${last(d.gscpiData).toFixed(2)}σ`);
    const lastFundSize = [...d.sovereignFundSizeData].reverse().find(v => v !== null && !isNaN(v));
    const lastFundWeight = [...d.sovereignFundWeightData].reverse().find(v => v !== null && !isNaN(v));
    const lastFundTarget = [...d.sovereignFundTargetData].reverse().find(v => v !== null && !isNaN(v));
    const lastFundDraw = [...d.sovereignFundDrawData].reverse().find(v => v !== null && !isNaN(v));
    const lastFundEquity = [...d.sovereignFundEquityData].reverse().find(v => v !== null && !isNaN(v));
    const lastFundDuty = [...d.sovereignFundDutyData].reverse().find(v => v !== null && !isNaN(v) && v > 0);
    const fundPct = (v) => v === undefined ? '-' : `${v.toFixed(2)}%`;
    // The header slot carries what the chart plots, as short as every other card's; the rest reads under the chart.
    setHud('hud-macroSovereignFundChart', lastFundSize === undefined
        ? 'No fund yet'
        : `Board: ${fundPct(lastFundWeight)} / ${fundPct(lastFundTarget)}`);
    setHud('stats-macroSovereignFundChart', lastFundSize === undefined
        ? ''
        : `Fund ${lastFundSize.toFixed(0)}% of GDP · Draw ${fundPct(lastFundDraw)} of GDP · Equities ${lastFundEquity === undefined ? '-' : lastFundEquity.toFixed(1) + '%'} · Stamp duty ${lastFundDuty === undefined ? '-' : lastFundDuty.toFixed(2) + '% of GDP'}`);
    setHud('hud-macroGovtSpendingChart', `Tax: ${last(d.taxData).toFixed(1)}% | Debt: ${last(d.sovereignDebtData).toFixed(1)}% | Spread: ${last(d.sovereignRiskSpreadData).toFixed(0)} bps | Deficit: ${last(d.primaryDeficitData) >= 0 ? '+' : ''}${last(d.primaryDeficitData).toFixed(1)}%`);
    setHud('hud-macroTermPremiumChart', `10Y: ${last(d.yield10yData).toFixed(2)}% | Term: ${last(d.termPremiumData) >= 0 ? '+' : ''}${last(d.termPremiumData).toFixed(2)}%`);
    setHud('hud-macroGdpGrowthChart', `Real: ${last(d.realGdpGrowthData) >= 0 ? '+' : ''}${last(d.realGdpGrowthData).toFixed(1)}% | Rec: ${last(d.recessionProbData).toFixed(0)}%`);
    setHud('hud-macroBalanceSheetChart', `Stock: ${last(d.slicedAssetStock).toFixed(1)} | QE/QT: ${last(d.balanceSheetData) >= 0 ? '+' : ''}${last(d.balanceSheetData).toFixed(0)} bps`);
    setHud('hud-macroFciChart', `Z: ${last(d.fciData) >= 0 ? '+' : ''}${last(d.fciData).toFixed(2)}σ | SLOOS: ${last(d.sloosData) >= 0 ? '+' : ''}${last(d.sloosData).toFixed(0)}%`);
    setHud('hud-macroCostPushChart', `Agri Drag: ${last(d.agriLagData) >= 0 ? '+' : ''}${last(d.agriLagData).toFixed(0)} bps`);
    setHud('hud-macroSectoralInflationChart', `CPI: ${last(d.inflationData).toFixed(1)}% | PPI: ${last(d.ppiData) >= 0 ? '+' : ''}${last(d.ppiData).toFixed(1)}% | Supercore: ${last(d.supercoreInflationData).toFixed(1)}%`);
    setHud('hud-macroCreditCliffChart', `HY: ${last(d.highYieldSpreadBpsData).toFixed(0)} bps | Cliff: ${last(d.creditCliffRatioData).toFixed(2)}x`);
    setHud('hud-macroInventoryCycleChart', `Overhang: ${last(d.inventoryStockGapData) >= 0 ? '+' : ''}${last(d.inventoryStockGapData).toFixed(1)}% | CU: ${last(d.capacityUtilizationData).toFixed(1)}%`);
    setHud('hud-macroPolicyRuleChart', `Rate vs rule: ${last(d.policyRuleGapBpsData) >= 0 ? '+' : ''}${last(d.policyRuleGapBpsData).toFixed(0)} bps`);
    setHud('hud-macroLeadingIndicatorsChart', `PMI: ${last(d.pmiData).toFixed(1)} | Starts: ${last(d.housingStartsData).toFixed(0)} | M2: ${last(d.moneySupplyGrowthData) >= 0 ? '+' : ''}${last(d.moneySupplyGrowthData).toFixed(1)}% | Trade: ${last(d.tradeBalanceData) >= 0 ? '+' : ''}${last(d.tradeBalanceData).toFixed(1)}%`);
    setHud('hud-macroHouseholdCreditChart', `DSR: ${last(d.householdDsrData).toFixed(1)}% | DTI: ${last(d.householdDtiData).toFixed(1)}% | CCyB: ${last(d.ccybRateData).toFixed(2)}% | Gap: ${last(d.creditToGdpGapData) >= 0 ? '+' : ''}${last(d.creditToGdpGapData).toFixed(1)}%`);
    setHud('hud-macroGlobalCycleChart', `Dom: ${last(d.outputGapData) >= 0 ? '+' : ''}${last(d.outputGapData).toFixed(1)}% | For: ${last(d.foreignOutputGapData) >= 0 ? '+' : ''}${last(d.foreignOutputGapData).toFixed(1)}% | Global: ${last(d.globalDemandGapData) >= 0 ? '+' : ''}${last(d.globalDemandGapData).toFixed(1)}% | For Rate: ${last(d.foreignPolicyRateData).toFixed(2)}%`);
    setHud('hud-macroBankingLiquidityChart', `Beta: ${last(d.depositBetaData).toFixed(1)}% | MMF: ${last(d.mmfShareData).toFixed(1)}% | Spread: ${last(d.interbankSpreadBpsData) !== null && !isNaN(last(d.interbankSpreadBpsData)) ? last(d.interbankSpreadBpsData).toFixed(0) + ' bps' : '-'}`);
}

export function updateMacroCharts(reports, timeframe = currentMacroTimeframe) {
    if (!reports || reports.length === 0 || typeof Chart === 'undefined') return;

    currentMacroReports = reports;
    currentMacroTimeframe = timeframe;

    let limit = 40;
    if (timeframe === '5Y') limit = 20;
    else if (timeframe === '25Y' || timeframe === 'MAX') limit = 100;

    let labels = [];
    let inflationData = [], outputGapData = [], capitalOverhangData = [], corpBorrowingData = [];
    let tipsBreakevenData = [];
    let policyRateData = [], targetRateData = [], yield2yData = [], yield5yData = [], yield10yData = [], yield30yData = [];
    let mortgageYieldData = [];
    let spread2s10sData = [], spread30yData = [];
    let erpData = [], volData = [], taxData = [];
    let unemploymentData = [], energyPriceData = [];
    let nairuData = [];
    let sentimentData = [];
    let fxEmaData = [], metalsEmaData = [], govtSpendingEmaData = [], creEmaData = [];
    let sovereignDebtData = [];
    let retailDefaultData = [], agriEmaData = [], freightEmaData = [], residentialEmaData = [];
    let interbankSpreadBpsData = [], creditSpreadBpsData = [];
    let jobVacanciesData = [], laborTightnessData = [], wageGrowthData = [], realWageGapData = [];
    let equityWealthRatioData = [], equityWealthTrendData = [], equityWealthGapData = [], housingWealthGapData = [];
    let naturalRateData = [], termPremiumData = [], riskNeutralData = [], balanceSheetData = [];
    let balanceSheetAssetsData = [];
    let nominalGdpGrowthData = [], realGdpGrowthData = [], potentialGdpGrowthData = [], tfpGrowthData = [];
    // MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE, published on the canvas so the page never keeps its own copy.
    const structuralLaborGrowthPct = parseFloat(document.getElementById('macroGdpGrowthChart')?.dataset.structuralLaborGrowth ?? '0.005') * 100;
    let fciData = [], fciEmaData = [];
    let agriLagData = [], foodLagPctData = [], energySupplyDragData = [], freightSupplyDragData = [];
    let supercoreInflationData = [], coreGoodsInflationData = [];
    let highYieldSpreadBpsData = [], creditCliffRatioData = [];
    let inventoryStockGapData = [], energyBufferData = [];
    let policyRuleGapBpsData = [];
    let capacityUtilizationData = [], recessionProbData = [];
    let crackSpreadData = [], gscpiData = [];
    let corporateDefaultPctData = [], corporateDefaultBpsData = [];
    let sloosData = [], dealActivityData = [];
    let pmiData = [], ppiData = [], tradeBalanceData = [], housingStartsData = [], moneySupplyGrowthData = [];
    let naturalGasPriceData = [], goldPriceData = [], powerPriceIndexData = [], sovereignRiskSpreadData = [], primaryDeficitData = [];
    let householdDsrData = [], householdDtiData = [], creditToGdpGapData = [], ccybRateData = [];
    let foreignOutputGapData = [], foreignPolicyRateData = [], globalDemandGapData = [];
    let depositBetaData = [], mmfShareData = [];
    let sovereignFundSizeData = [], sovereignFundWeightData = [], sovereignFundTargetData = [], sovereignFundOwnershipData = [], sovereignFundDrawData = [], sovereignFundEquityData = [], sovereignFundDutyData = [];

    const slicedReports = reports.slice(-limit);
    let qCount = slicedReports.length;

    // Cumulative central bank balance sheet asset stock holdings (Base 100)
    let runningAssetIndex = 100.0;
    const assetStockHistory = [];
    const bsMeanReversionSpeed = 0.50; // Annual speed mean-reverting toward structural baseline 100
    reports.forEach((r) => {
        let rawBs = r.balance_sheet_intensity ?? r.balanceSheetIntensity ?? (r.qe_intensity ? parseFloat(r.qe_intensity) : 0.0);
        let bsBps = parseFloat(rawBs) * 10000;
        runningAssetIndex += ((bsBps / 10.0) + bsMeanReversionSpeed * (100.0 - runningAssetIndex)) * 0.25;
        runningAssetIndex = Math.max(50.0, Math.min(200.0, runningAssetIndex));
        assetStockHistory.push(runningAssetIndex);
    });
    const slicedAssetStock = assetStockHistory.slice(-slicedReports.length);

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
        // Mirrors MacroEngine::RESIDENTIAL_MORTGAGE_SPREAD: 30Y fixed mortgages price off the 10Y (prepayment duration) + 170 bps
        let mortgageRate = y10 !== null && !isNaN(y10) ? y10 + 1.70 : null;
        mortgageYieldData.push(mortgageRate);

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
        spread30yData.push((mortgageRate !== null && pr !== null && !isNaN(pr)) ? mortgageRate - pr : null);

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

        // Household wealth, as the board's CAPITALISATION over income, taken straight off the engine's own
        // ratio. Not an index level: a level is a tradable instrument's scale and is restated when that
        // instrument splits, so it re-bases on a share-count cosmetic that household wealth never had.
        //
        // The ratio is currency over an index and so has no meaningful unit of its own. That does not
        // matter: the gap is measured against the ratio's own trend, so the scale divides out, and the two
        // level lines below are rebased to the window's own opening for display.
        const rawWealthRatio = report.equity_wealth_ratio ?? report.equityWealthRatio ?? null;
        const rawWealthTrend = report.equity_wealth_trend ?? report.equityWealthTrend ?? null;
        const wealthRatio = rawWealthRatio === null ? 0 : parseFloat(rawWealthRatio);
        const wealthTrend = rawWealthTrend === null ? 0 : parseFloat(rawWealthTrend);

        // Zero is the engine's sentinel for a market it was never told about, which is not a market worth
        // nothing: plot a gap rather than a collapse that never happened.
        if (!(wealthRatio > 0) || !(wealthTrend > 0)) {
            equityWealthRatioData.push(null);
            equityWealthTrendData.push(null);
            equityWealthGapData.push(null);
        } else {
            equityWealthRatioData.push(wealthRatio);
            equityWealthTrendData.push(wealthTrend);
            equityWealthGapData.push(((wealthRatio / wealthTrend) - 1.0) * 100.0);
        }

        housingWealthGapData.push((parseFloat(report.residential_property_index_ema || report.residential_property_index || 100.0) / 100.0 - 1.0) * 100.0);

        // The interbank columns were added to macro_report in Aug 2026 as NOT NULL, so every report written
        // before that reads 0.0000. The CIR process never drops below INTERBANK_MIN_SPREAD (1 bp), so a
        // non-positive value is "not recorded", not a reading: plot a gap rather than a fake zero.
        const rawInterbank = parseFloat(report.interbank_liquidity_spread_ema ?? report.interbank_liquidity_spread ?? report.interbankLiquiditySpreadEma ?? report.interbankLiquiditySpread ?? NaN);
        interbankSpreadBpsData.push(rawInterbank > 0 ? rawInterbank * 10000 : null);

        let rawCreditSpread = report.macro_credit_spread_ema ?? report.macro_credit_spread ?? report.macroCreditSpreadEma ?? report.macroCreditSpread ?? 0.020;
        creditSpreadBpsData.push(parseFloat(rawCreditSpread) * 10000);

        let rawVacancies = report.job_vacancies_rate_ema ?? report.job_vacancies_rate ?? report.jobVacanciesRateEma ?? report.jobVacanciesRate ?? 0.045;
        jobVacanciesData.push(parseFloat(rawVacancies) * 100);

        let rawTightness = report.labor_tightness_ema ?? report.labor_tightness ?? report.laborTightnessEma ?? report.laborTightness ?? 1.125;
        laborTightnessData.push(parseFloat(rawTightness));

        let rawWage = report.wage_growth_ema ?? report.wage_growth ?? report.wageGrowthEma ?? report.wageGrowth ?? 0.035;
        wageGrowthData.push(parseFloat(rawWage) * 100);

        // Recorded only since the wage level joined the macro: older quarters stay gaps, not a flat zero.
        const rawRealWageGap = report.real_wage_gap ?? report.realWageGap ?? null;
        realWageGapData.push(rawRealWageGap === null ? null : parseFloat(rawRealWageGap) * 100);

        let rawNaturalRate = report.natural_rate_ema ?? report.natural_rate ?? report.naturalRateEma ?? report.naturalRate ?? 0.015;
        naturalRateData.push(parseFloat(rawNaturalRate) * 100);

        let rawTermPremium = report.term_premium10y_ema ?? report.term_premium10y ?? report.term_premium_10y_ema ?? report.term_premium_10y ?? report.termPremium10yEma ?? report.termPremium10y ?? 0.010;
        termPremiumData.push(parseFloat(rawTermPremium) * 100);

        let rawRiskNeutral = report.risk_neutral10y_ema ?? report.risk_neutral10y ?? report.risk_neutral_10y_ema ?? report.risk_neutral_10y ?? report.riskNeutral10yEma ?? report.riskNeutral10y ?? (parseFloat(report.yield10y_ema || 0.035) - parseFloat(rawTermPremium));
        riskNeutralData.push(parseFloat(rawRiskNeutral) * 100);

        let rawBalanceSheet = report.balance_sheet_intensity ?? report.balanceSheetIntensity ?? (report.qe_intensity ? parseFloat(report.qe_intensity) : 0.0);
        let bsBps = parseFloat(rawBalanceSheet) * 10000;
        balanceSheetData.push(bsBps);
        balanceSheetAssetsData.push(slicedAssetStock[index] ?? 100.0);

        // Financial Conditions Index (FCI)
        let rawFci = report.financial_conditions_index ?? report.financialConditionsIndex ?? 0.0;
        let rawFciEma = report.financial_conditions_index_ema ?? report.financialConditionsIndexEma ?? rawFci;
        fciData.push(parseFloat(rawFci));
        fciEmaData.push(parseFloat(rawFciEma));

        // Supply-Side Cost-Push Shocks & Lags (in basis points and percent)
        let rawAgriLag = report.agri_cost_push_lag ?? report.agriCostPushLag ?? 0.0;
        agriLagData.push(parseFloat(rawAgriLag) * 10000);
        foodLagPctData.push(parseFloat(rawAgriLag) * 100);

        let rawEnergy = parseFloat(report.energy_price_index_ema || report.energy_price_index || 100.0);
        // Mirrors MacroEngine::ENERGY_COST_PUSH_TRANSMISSION (headline inflation per unit energy shock)
        let energyDragBps = ((rawEnergy - 100.0) / 100.0) * 0.025 * 10000;
        energySupplyDragData.push(energyDragBps);

        let rawFreight = parseFloat(report.freight_rate_index_ema || report.freight_rate_index || 100.0);
        // Mirrors MacroEngine::CORE_GOODS_FREIGHT_SENSITIVITY x INFLATION_WEIGHT_GOODS (headline share of the core-goods freight push)
        let freightDragBps = ((rawFreight - 100.0) / 100.0) * 0.015 * 0.25 * 10000;
        freightSupplyDragData.push(freightDragBps);

        // Economic Growth Momentum & Solow-Swan Productivity Decomposition
        let inf = parseFloat(report.inflation_ema ?? report.inflation ?? 0.02) * 100;
        let currentGap = parseFloat(report.output_gap_ema ?? report.output_gap ?? 0.0) * 100;

        // Potential growth is read off the potential GDP the engine accumulated over a trailing year: labour force
        // plus trend TFP plus the productivity shocks potential has absorbed so far. The raw TFP index moves first
        // and potential catches up over years, so growth read off the index shows gains the economy has not yet made.
        const tfpLookback = Math.min(4, index);
        const rawPotential = report.potential_gdp_index ?? report.potentialGdpIndex ?? null;
        const rawPrevPotential = tfpLookback > 0
            ? (slicedReports[index - tfpLookback].potential_gdp_index ?? slicedReports[index - tfpLookback].potentialGdpIndex ?? null)
            : null;
        let prevGap = index > 0
            ? parseFloat(slicedReports[index - 1].output_gap_ema ?? slicedReports[index - 1].output_gap ?? currentGap) * 100
            : currentGap;

        // Real Potential GDP Growth (%) and the productivity growth inside it (potential less structural labour-force growth).
        let potentialGrowth = null;
        let tfpGrowth = null;
        if (rawPotential !== null && rawPrevPotential !== null && parseFloat(rawPotential) > 0 && parseFloat(rawPrevPotential) > 0) {
            potentialGrowth = (Math.log(parseFloat(rawPotential) / parseFloat(rawPrevPotential)) / (tfpLookback * 0.25)) * 100;
            tfpGrowth = potentialGrowth - structuralLaborGrowthPct;
        }

        // Realized Cyclical Output Gap Shift Annualized (%): dGap / dt
        let cyclicalGapShift = (currentGap - prevGap) / 0.25;

        // Real GDP Annualized Growth Rate (%): Potential Growth + Cyclical Gap Momentum
        let realGrowth = potentialGrowth === null ? null : Math.max(-12.0, Math.min(15.0, potentialGrowth + cyclicalGapShift));

        // Nominal GDP Annualized Growth Rate (%): Real GDP Growth + Inflation
        let nominalGrowth = realGrowth === null ? null : realGrowth + inf;

        tfpGrowthData.push(tfpGrowth);
        potentialGdpGrowthData.push(potentialGrowth);
        realGdpGrowthData.push(realGrowth);
        nominalGdpGrowthData.push(nominalGrowth);

        // Shapiro (2022) Sectoral Inflation Components
        let rawSupercore = report.supercore_inflation_ema ?? report.supercoreInflationEma ?? report.inflation_ema;
        supercoreInflationData.push(parseFloat(rawSupercore) * 100);

        let rawCoreGoods = report.core_goods_inflation_ema ?? report.coreGoodsInflationEma ?? report.inflation_ema;
        coreGoodsInflationData.push(parseFloat(rawCoreGoods) * 100);

        // Jarrow-Lando-Turnbull (1997) Dual-Tranche Corporate Credit Spreads
        let rawHySpread = report.high_yield_credit_spread_ema ?? report.highYieldCreditSpreadEma ?? (parseFloat(rawCreditSpread) * 2.5);
        let hyBps = parseFloat(rawHySpread) * 10000;
        let igBps = parseFloat(rawCreditSpread) * 10000;
        highYieldSpreadBpsData.push(hyBps);
        creditCliffRatioData.push(igBps > 0 ? (hyBps / igBps) : 2.5);

        // Metzler (1941) & Working (1949) Inventory & Storage
        let rawInvGap = report.inventory_stock_gap_ema ?? report.inventoryStockGapEma ?? 0.0;
        inventoryStockGapData.push(parseFloat(rawInvGap) * 100);

        let rawEnergyBuf = report.energy_inventory_index_ema ?? report.energyInventoryIndexEma ?? 100.0;
        energyBufferData.push(parseFloat(rawEnergyBuf));

        // Clarida-Gali-Gertler (2000) partial adjustment: how far the policy rate trails its rule's target.
        let lastPolicy = policyRateData[policyRateData.length - 1];
        let lastTarget = targetRateData[targetRateData.length - 1];
        policyRuleGapBpsData.push(lastPolicy !== null && lastTarget !== null ? (lastPolicy - lastTarget) * 100 : null);

        // 7 New Macro & Financial Indicators
        let rawCu = report.capacity_utilization_rate_ema ?? report.capacity_utilization_rate ?? report.capacityUtilizationRateEma ?? report.capacityUtilizationRate ?? 0.785;
        capacityUtilizationData.push(parseFloat(rawCu) * 100);

        let rawRec = report.recession_probability_ema ?? report.recession_probability ?? report.recessionProbabilityEma ?? report.recessionProbability ?? 0.05;
        recessionProbData.push(parseFloat(rawRec) * 100);

        let rawCrack = report.refining_crack_spread_ema ?? report.refining_crack_spread ?? report.refiningCrackSpreadEma ?? report.refiningCrackSpread ?? 22.0;
        crackSpreadData.push(parseFloat(rawCrack));

        let rawGscpi = report.supply_chain_pressure_index_ema ?? report.supply_chain_pressure_index ?? report.supplyChainPressureIndexEma ?? report.supplyChainPressureIndex ?? 0.0;
        gscpiData.push(parseFloat(rawGscpi));

        let rawCdr = report.corporate_default_rate_ema ?? report.corporate_default_rate ?? report.corporateDefaultRateEma ?? report.corporateDefaultRate ?? 0.018;
        corporateDefaultPctData.push(parseFloat(rawCdr) * 100);
        corporateDefaultBpsData.push(parseFloat(rawCdr) * 10000);

        let rawSloos = report.sloos_tightening_index_ema ?? report.sloos_tightening_index ?? report.sloosTighteningIndexEma ?? report.sloosTighteningIndex ?? 0.0;
        sloosData.push(parseFloat(rawSloos) * 100);

        let rawDeal = report.deal_activity_index_ema ?? report.deal_activity_index ?? report.dealActivityIndexEma ?? report.dealActivityIndex ?? 100.0;
        dealActivityData.push(parseFloat(rawDeal));

        // Trading Economics Leading Indicators
        let rawPmi = report.manufacturing_pmi_ema ?? report.manufacturing_pmi ?? report.manufacturingPmiEma ?? report.manufacturingPmi ?? 50.0;
        pmiData.push(parseFloat(rawPmi));

        let rawPpi = report.producer_price_inflation_ema ?? report.producer_price_inflation ?? report.producerPriceInflationEma ?? report.producerPriceInflation ?? 0.02;
        ppiData.push(parseFloat(rawPpi) * 100);

        let rawTradeBalance = report.trade_balance_to_gdp_ema ?? report.trade_balance_to_gdp ?? report.tradeBalanceToGdpEma ?? report.tradeBalanceToGdp ?? -0.025;
        tradeBalanceData.push(parseFloat(rawTradeBalance) * 100);

        let rawHousingStarts = report.housing_starts_index_ema ?? report.housing_starts_index ?? report.housingStartsIndexEma ?? report.housingStartsIndex ?? 100.0;
        housingStartsData.push(parseFloat(rawHousingStarts));

        let rawM2 = report.money_supply_growth_ema ?? report.money_supply_growth ?? report.moneySupplyGrowthEma ?? report.moneySupplyGrowth ?? 0.045;
        moneySupplyGrowthData.push(parseFloat(rawM2) * 100);

        let rawNg = report.natural_gas_price_index_ema ?? report.natural_gas_price_index ?? report.naturalGasPriceIndexEma ?? report.naturalGasPriceIndex ?? 100.0;
        naturalGasPriceData.push(parseFloat(rawNg));

        // Recorded only since the gold index joined the macro: older quarters stay gaps, not a flat 100.
        const rawGold = report.gold_price_index_ema ?? report.gold_price_index ?? report.goldPriceIndexEma ?? report.goldPriceIndex ?? null;
        goldPriceData.push(rawGold === null ? null : parseFloat(rawGold));

        // Recorded only since the power index joined the macro: older quarters stay gaps.
        const rawPower = report.wholesale_power_price_index_ema ?? report.wholesale_power_price_index ?? report.wholesalePowerPriceIndexEma ?? report.wholesalePowerPriceIndex ?? null;
        powerPriceIndexData.push(rawPower === null ? null : parseFloat(rawPower));

        let rawSovSpread = report.sovereign_risk_spread_ema ?? report.sovereign_risk_spread ?? report.sovereignRiskSpreadEma ?? report.sovereignRiskSpread ?? 0.0;
        sovereignRiskSpreadData.push(parseFloat(rawSovSpread) * 10000);

        let rawPrimDef = report.primary_deficit_to_gdp ?? report.primaryDeficitToGdp ?? 0.0;
        primaryDeficitData.push(parseFloat(rawPrimDef) * 100);

        // Recorded only once the sovereign fund exists: quarters before it (or before the columns) stay gaps, not zeros.
        const rawFundSize = parseFloat(report.sovereign_fund_to_gdp ?? report.sovereignFundToGdp ?? 0.0);
        const fundExists = rawFundSize > 0;
        const fundReading = (value) => fundExists && value !== null && value !== undefined ? parseFloat(value) * 100 : null;
        sovereignFundSizeData.push(fundExists ? rawFundSize * 100 : null);
        sovereignFundWeightData.push(fundReading(report.sovereign_fund_domestic_weight ?? report.sovereignFundDomesticWeight));
        sovereignFundTargetData.push(fundReading(report.sovereign_fund_target_weight ?? report.sovereignFundTargetWeight));
        sovereignFundOwnershipData.push(fundReading(report.sovereign_fund_ownership_share ?? report.sovereignFundOwnershipShare));
        sovereignFundDrawData.push(fundReading(report.sovereign_fund_draw_to_gdp ?? report.sovereignFundDrawToGdp));
        sovereignFundEquityData.push(fundReading(report.sovereign_fund_equity_share ?? report.sovereignFundEquityShare));
        sovereignFundDutyData.push(fundReading(report.sovereign_fund_stamp_duty_to_gdp ?? report.sovereignFundStampDutyToGdp));

        let rawDsr = report.household_debt_service_ratio_ema ?? report.household_debt_service_ratio ?? report.householdDebtServiceRatioEma ?? report.householdDebtServiceRatio ?? 0.106;
        householdDsrData.push(parseFloat(rawDsr) * 100);

        let rawDti = report.household_debt_to_income_ema ?? report.household_debt_to_income ?? report.householdDebtToIncomeEma ?? report.householdDebtToIncome ?? 1.0;
        householdDtiData.push(parseFloat(rawDti) * 100);

        let rawCreditGap = report.credit_to_gdp_gap_ema ?? report.credit_to_gdp_gap ?? report.creditToGdpGapEma ?? report.creditToGdpGap ?? 0.0;
        creditToGdpGapData.push(parseFloat(rawCreditGap) * 100);

        let rawCcyb = report.countercyclical_buffer_rate_ema ?? report.countercyclical_buffer_rate ?? report.countercyclicalBufferRateEma ?? report.countercyclicalBufferRate ?? 0.0;
        ccybRateData.push(parseFloat(rawCcyb) * 100);

        let rawForGap = report.foreign_output_gap_ema ?? report.foreign_output_gap ?? report.foreignOutputGapEma ?? report.foreignOutputGap ?? 0.0;
        foreignOutputGapData.push(parseFloat(rawForGap) * 100);

        let rawForRate = report.foreign_policy_rate_ema ?? report.foreign_policy_rate ?? report.foreignPolicyRateEma ?? report.foreignPolicyRate ?? 0.025;
        foreignPolicyRateData.push(parseFloat(rawForRate) * 100);

        let rawGlobGap = report.global_demand_gap_ema ?? report.global_demand_gap ?? report.globalDemandGapEma ?? report.globalDemandGap ?? 0.0;
        globalDemandGapData.push(parseFloat(rawGlobGap) * 100);

        let rawBeta = report.system_deposit_beta_ema ?? report.system_deposit_beta ?? report.systemDepositBetaEma ?? report.systemDepositBeta ?? 0.20;
        depositBetaData.push(parseFloat(rawBeta) * 100);

        let rawMmf = report.money_market_fund_share_ema ?? report.money_market_fund_share ?? report.moneyMarketFundShareEma ?? report.moneyMarketFundShare ?? 0.15;
        mmfShareData.push(parseFloat(rawMmf) * 100);
    });

    updateMacroHud({
        inflationData, outputGapData, policyRateData, yield10yData,
        mortgageYieldData, spread30yData, volData, erpData, taxData,
        unemploymentData, wageGrowthData, tipsBreakevenData, realWageGapData, interbankSpreadBpsData,
        equityWealthGapData, housingWealthGapData,
        creEmaData, residentialEmaData, sentimentData, dealActivityData,
        energyPriceData, crackSpreadData, gscpiData, sovereignDebtData,
        termPremiumData, realGdpGrowthData, tfpGrowthData, recessionProbData,
        slicedAssetStock, balanceSheetData, fciData, sloosData, agriLagData,
        supercoreInflationData, coreGoodsInflationData,
        highYieldSpreadBpsData, creditCliffRatioData,
        inventoryStockGapData, policyRuleGapBpsData,
        capacityUtilizationData, fxEmaData, freightEmaData,
        pmiData, ppiData, tradeBalanceData, housingStartsData, moneySupplyGrowthData,
        naturalGasPriceData, goldPriceData, powerPriceIndexData, metalsEmaData, sovereignRiskSpreadData, primaryDeficitData,
        householdDsrData, householdDtiData, creditToGdpGapData, ccybRateData,
        foreignOutputGapData, foreignPolicyRateData, globalDemandGapData,
        depositBetaData, mmfShareData,
        sovereignFundSizeData, sovereignFundWeightData, sovereignFundTargetData, sovereignFundDrawData, sovereignFundEquityData, sovereignFundDutyData
    });

    ['5Y', '10Y', '25Y'].forEach(tf => {
        const btn = document.getElementById(`btn-macro-${tf}`);
        if (btn) {
            if (tf === currentMacroTimeframe) {
                btn.className = 'macro-range-btn px-3 py-1 text-xs font-bold rounded-lg bg-primary text-on-primary shadow-md shadow-primary/20 transition-all cursor-pointer';
            } else {
                btn.className = 'macro-range-btn px-3 py-1 text-xs font-bold rounded-lg bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-all cursor-pointer';
            }
        }
    });

    renderWhenVisible('macroEconomyChart', () => renderMacroEconomyChart(labels, inflationData, outputGapData, capitalOverhangData, tipsBreakevenData));
    renderWhenVisible('macroRatesChart', () => renderMacroRatesChart(labels, policyRateData, yield2yData, yield5yData, yield10yData, spread2s10sData, targetRateData));
    renderWhenVisible('macroMortgageChart', () => renderMacroMortgageChart(labels, policyRateData, mortgageYieldData, spread30yData));
    renderWhenVisible('macroRiskChart', () => renderMacroRiskChart(labels, erpData, volData, creditSpreadBpsData, corpBorrowingData));
    renderWhenVisible('macroLaborCreditChart', () => renderMacroLaborCreditChart(labels, unemploymentData, jobVacanciesData, wageGrowthData, nairuData, tipsBreakevenData, realWageGapData));
    // The wealth ratio has no unit, so the level pair is shown relative to where the window opens.
    const firstWealthRatio = equityWealthRatioData.find(v => v !== null && v > 0);
    const rebase = (arr) => firstWealthRatio ? arr.map(v => v === null ? null : (v / firstWealthRatio) * 100.0) : arr;
    renderWhenVisible('macroWealthEffectChart', () => renderMacroWealthEffectChart(labels, rebase(equityWealthRatioData), rebase(equityWealthTrendData), equityWealthGapData, housingWealthGapData, outputGapData));
    renderWhenVisible('macroCommoditiesChart', () => renderMacroCommoditiesChart(labels, energyPriceData, metalsEmaData, agriEmaData, naturalGasPriceData, goldPriceData));
    renderWhenVisible('macroEnergySpreadsChart', () => renderMacroEnergySpreadsChart(labels, crackSpreadData, powerPriceIndexData, naturalGasPriceData));
    renderWhenVisible('macroPropertyChart', () => renderMacroPropertyChart(labels, creEmaData, residentialEmaData, housingStartsData));
    renderWhenVisible('macroTradeLogisticsChart', () => renderMacroTradeLogisticsChart(labels, fxEmaData, freightEmaData, gscpiData));
    renderWhenVisible('macroSentimentChart', () => renderMacroSentimentChart(labels, sentimentData, retailDefaultData, dealActivityData, corporateDefaultPctData));
    renderWhenVisible('macroGovtSpendingChart', () => renderMacroGovtSpendingChart(labels, govtSpendingEmaData, sovereignDebtData, sovereignRiskSpreadData, primaryDeficitData, taxData));
    renderWhenVisible('macroSovereignFundChart', () => renderMacroSovereignFundChart(labels, sovereignFundWeightData, sovereignFundTargetData, sovereignFundOwnershipData));
    renderWhenVisible('macroInterbankLiquidityChart', () => renderMacroInterbankLiquidityChart(labels, interbankSpreadBpsData, creditSpreadBpsData));
    renderWhenVisible('macroTermPremiumChart', () => renderMacroTermPremiumChart(labels, yield10yData, riskNeutralData, termPremiumData, naturalRateData));
    renderWhenVisible('macroGdpGrowthChart', () => renderMacroGdpGrowthChart(labels, nominalGdpGrowthData, realGdpGrowthData, potentialGdpGrowthData, tfpGrowthData, recessionProbData));
    renderWhenVisible('macroBalanceSheetChart', () => renderMacroBalanceSheetChart(labels, balanceSheetAssetsData, balanceSheetData));
    renderWhenVisible('macroFciChart', () => renderMacroFciChart(labels, fciData, fciEmaData, sloosData));
    renderWhenVisible('macroCostPushChart', () => renderMacroCostPushChart(labels, agriLagData, energySupplyDragData, freightSupplyDragData));
    renderWhenVisible('macroSectoralInflationChart', () => renderMacroSectoralInflationChart(labels, inflationData, supercoreInflationData, coreGoodsInflationData, foodLagPctData, ppiData));
    renderWhenVisible('macroCreditCliffChart', () => renderMacroCreditCliffChart(labels, creditSpreadBpsData, highYieldSpreadBpsData, creditCliffRatioData, corporateDefaultBpsData));
    renderWhenVisible('macroInventoryCycleChart', () => renderMacroInventoryCycleChart(labels, inventoryStockGapData, outputGapData, energyBufferData, capacityUtilizationData));
    renderWhenVisible('macroPolicyRuleChart', () => renderMacroPolicyRuleChart(labels, policyRuleGapBpsData, policyRateData, targetRateData));
    renderWhenVisible('macroLeadingIndicatorsChart', () => renderMacroLeadingIndicatorsChart(labels, pmiData, housingStartsData, moneySupplyGrowthData, tradeBalanceData, ppiData));
    renderWhenVisible('macroHouseholdCreditChart', () => renderMacroHouseholdCreditChart(labels, householdDsrData, householdDtiData, creditToGdpGapData, ccybRateData));
    renderWhenVisible('macroGlobalCycleChart', () => renderMacroGlobalCycleChart(labels, outputGapData, foreignOutputGapData, globalDemandGapData, foreignPolicyRateData));
    renderWhenVisible('macroBankingLiquidityChart', () => renderMacroBankingLiquidityChart(labels, depositBetaData, mmfShareData, interbankSpreadBpsData));
}

function renderMacroEconomyChart(labels, inflationData, outputGapData, capitalOverhangData, tipsBreakevenData) {
    const canvas = document.getElementById('macroEconomyChart');
    if (!canvas) return;
    macroEconomyChartInstance = destroyChartInstance(macroEconomyChartInstance);
    const ctx = canvas.getContext('2d');

    const datasets = [
        {
            type: 'line',
            label: 'Inflation (EMA)',
            data: inflationData,
            borderColor: '#facc15',
            backgroundColor: '#facc15',
            borderWidth: 2.2,
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
            borderWidth: 1.8,
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
            borderWidth: 1.5,
            borderDash: [4, 4],
            tension: 0.3,
            pointRadius: labels.length > 50 ? 0 : 1,
            yAxisID: 'y'
        },
        {
            type: 'bar',
            label: 'Output Gap (EMA)',
            data: outputGapData,
            backgroundColor: outputGapData.map(val => val < 0 ? 'rgba(255, 179, 173, 0.45)' : 'rgba(78, 222, 163, 0.45)'),
            borderRadius: 2,
            barPercentage: 0.65,
            categoryPercentage: 0.85,
            yAxisID: 'y'
        }
    ];

    macroEconomyChartInstance = new Chart(ctx, {
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
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.raw !== null && ctx.raw !== undefined ? ctx.raw.toFixed(2) + '%' : 'N/A'}`
                    }
                }
            },
            scales: {
                y: {
                    suggestedMin: -2.5,
                    suggestedMax: 4.0,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val + '%' },
                    title: { display: true, text: 'Gaps & Inflation (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
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
            borderColor: '#38bdf8',
            backgroundColor: '#38bdf8',
            borderWidth: 2.5,
            tension: 0.1,
            pointRadius: labels.length > 50 ? 0 : 1,
            yAxisID: 'y'
        },
        {
            type: 'line',
            label: '10Y Yield',
            data: yield10yData,
            borderColor: '#c084fc',
            backgroundColor: '#c084fc',
            borderWidth: 2.5,
            tension: 0.25,
            pointRadius: labels.length > 50 ? 0 : 1,
            yAxisID: 'y'
        },
        {
            type: 'line',
            label: '2Y Yield',
            data: yield2yData,
            borderColor: '#4ade80',
            backgroundColor: '#4ade80',
            borderWidth: 1.8,
            tension: 0.25,
            pointRadius: labels.length > 50 ? 0 : 1,
            yAxisID: 'y'
        },
        {
            type: 'line',
            label: '5Y Yield',
            data: yield5yData,
            borderColor: '#facc15',
            backgroundColor: '#facc15',
            borderWidth: 1.8,
            tension: 0.25,
            pointRadius: labels.length > 50 ? 0 : 1,
            yAxisID: 'y'
        },
        {
            type: 'line',
            label: '2s10s Spread (10Y-2Y)',
            data: spread2s10sData,
            borderColor: '#a78bfa',
            backgroundColor: '#a78bfa',
            borderWidth: 1.5,
            borderDash: [3, 2],
            tension: 0.25,
            pointRadius: labels.length > 50 ? 0 : 1,
            yAxisID: 'y'
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
            borderDash: [4, 4],
            tension: 0.2,
            spanGaps: true,
            pointRadius: labels.length > 50 ? 0 : 1,
            yAxisID: 'y'
        });
    }

    macroRatesChartInstance = new Chart(ctx, {
        type: 'line',
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
            scales: {
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val + '%' },
                    title: { display: true, text: 'Rates & Spreads (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
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
                    borderColor: '#38bdf8',
                    backgroundColor: '#38bdf8',
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
                    borderWidth: 2.2,
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    type: 'bar',
                    label: 'Mortgage Spread (30Y Mtg - PR)',
                    data: spread30yData,
                    backgroundColor: spread30yData.map(val => val !== null && val < 0 ? 'rgba(255, 179, 173, 0.4)' : 'rgba(251, 113, 133, 0.4)'),
                    borderRadius: 3,
                    barPercentage: 0.6,
                    categoryPercentage: 0.85
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } }, tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } } },
            scales: {
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val + '%' },
                    title: { display: true, text: 'Rate & Spread (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroRiskChart(labels, erpData, volData, creditSpreadBpsData, corpBorrowingData) {
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
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Equity Risk Premium',
                    data: erpData,
                    borderColor: '#fde047',
                    backgroundColor: '#fde047',
                    borderWidth: 2.2,
                    tension: 0.3,
                    pointRadius: labels.length > 50 ? 0 : 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Corp Credit Spread',
                    data: creditSpreadBpsData.map(val => val !== null ? val / 100 : null),
                    borderColor: THEME_COLORS.primary,
                    backgroundColor: THEME_COLORS.primary,
                    borderWidth: 1.8,
                    borderDash: [4, 3],
                    tension: 0.2,
                    pointRadius: 0,
                    yAxisID: 'y'
                },
                {
                    label: 'Market Volatility (VIX)',
                    data: volData,
                    borderColor: '#fb923c',
                    backgroundColor: 'rgba(251, 146, 60, 0.12)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointRadius: 0,
                    yAxisID: 'y1'
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
                    position: 'left',
                    min: 0,
                    suggestedMax: 12,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'Rates & Premia (%)' }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    min: 0,
                    max: 60,
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(0) + '%' },
                    title: { display: true, text: 'VIX Volatility (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroLaborCreditChart(labels, unemploymentData, jobVacanciesData, wageGrowthData, nairuData, tipsBreakevenData, realWageGapData = []) {
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
                    backgroundColor: 'rgba(244, 63, 94, 0.10)',
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
                    borderWidth: 1.8,
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
                    borderWidth: 2,
                    tension: 0.3,
                    fill: false,
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
                },
                {
                    // Wage settlements are indexed to expected inflation one-for-one, so this is the line the
                    // purple one is bargained against: the distance between them is productivity plus whatever
                    // the labour market is worth, and a gap that opens is the spiral turning.
                    label: 'Expected Inflation (Wage Anchor)',
                    data: tipsBreakevenData,
                    borderColor: '#c4b5fd',
                    backgroundColor: '#c4b5fd',
                    borderWidth: 1.8,
                    borderDash: [5, 4],
                    tension: 0.3,
                    fill: false,
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    // A LEVEL, not a rate: the real wage against prices times trend productivity. It is what payroll
                    // costs firms per unit of output, so above zero every company's margin is carrying it.
                    label: 'Real Wage vs Productivity Path',
                    data: realWageGapData,
                    yAxisID: 'yRealWage',
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.10)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: 'origin',
                    spanGaps: false,
                    pointRadius: labels.length > 50 ? 0 : 1
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: { callbacks: { label: (ctx) => ctx.raw === null ? `${ctx.dataset.label}: -` : `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } }
            },
            scales: {
                // Rates on top, the wage LEVEL in its own panel below: left axes in one stack go top-down by descending weight.
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left', stack: 'labor', stackWeight: 2, weight: 1,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'Percentage Rate (%)' }
                },
                yRealWage: {
                    type: 'linear',
                    position: 'left', stack: 'labor', stackWeight: 1, weight: 0, offset: true,
                    // Zero is the productivity path itself, so it always stays on the axis.
                    suggestedMin: 0, suggestedMax: 0,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(1) + '%', maxTicksLimit: 4 },
                    title: { display: true, text: 'vs Path (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
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
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'Yield (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroGdpGrowthChart(labels, nominalGdpGrowthData, realGdpGrowthData, potentialGdpGrowthData, tfpGrowthData, recessionProbData = []) {
    const canvas = document.getElementById('macroGdpGrowthChart');
    if (!canvas) return;
    macroGdpGrowthChartInstance = destroyChartInstance(macroGdpGrowthChartInstance);
    const ctx = canvas.getContext('2d');

    const datasets = [
        {
            label: 'Nominal GDP Growth (Ann.)',
            data: nominalGdpGrowthData,
            borderColor: '#38bdf8',
            backgroundColor: '#38bdf8',
            borderWidth: 2.2,
            tension: 0.25,
            yAxisID: 'y',
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
            yAxisID: 'y',
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
            yAxisID: 'y',
            pointRadius: labels.length > 50 ? 0 : 1
        },
        {
            label: 'Potential Productivity Growth',
            data: tfpGrowthData,
            borderColor: '#c084fc',
            backgroundColor: '#c084fc',
            borderWidth: 1.5,
            tension: 0.2,
            yAxisID: 'y',
            pointRadius: labels.length > 50 ? 0 : 1
        }
    ];

    if (recessionProbData && recessionProbData.length > 0) {
        datasets.push({
            type: 'line',
            label: '12M Recession Prob (Probit)',
            data: recessionProbData,
            borderColor: '#f87171',
            backgroundColor: 'rgba(248, 113, 113, 0.10)',
            borderWidth: 2,
            borderDash: [4, 3],
            fill: true,
            tension: 0.25,
            pointRadius: labels.length > 50 ? 0 : 1,
            yAxisID: 'y1'
        });
    }

    macroGdpGrowthChartInstance = new Chart(ctx, {
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
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const val = ctx.raw;
                            if (val === null || val === undefined || isNaN(val)) return `${ctx.dataset.label}: N/A`;
                            if (ctx.dataset.yAxisID === 'y1') {
                                return `${ctx.dataset.label}: ${val.toFixed(1)}%`;
                            }
                            return `${ctx.dataset.label}: ${val >= 0 ? '+' : ''}${val.toFixed(2)}%`;
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
                    ticks: { callback: (val) => (val >= 0 ? '+' : '') + val.toFixed(1) + '%' },
                    title: { display: true, text: 'Growth Rate (%)' }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    min: 0,
                    max: 100,
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val + '%' },
                    title: { display: true, text: 'Recession Prob (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
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
                    ticks: { callback: (val) => Math.round(val) },
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
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

// Commodity indices share one base-100 axis. Colours are the reference categorical palette's first five
// dark-mode slots in its fixed order (validated adjacent-pair on this surface): gold takes the yellow slot.
const COMMODITY_SERIES_COLORS = {
    energy: '#3987e5',
    metals: '#d95926',
    agri: '#199e70',
    gold: '#c98500',
    gas: '#d55181',
};

function commodityLine(label, data, color, pointsHidden) {
    return {
        label,
        data,
        borderColor: color,
        backgroundColor: color,
        borderWidth: 2,
        tension: 0.2,
        pointRadius: pointsHidden ? 0 : 2,
        spanGaps: false,
    };
}

function renderMacroCommoditiesChart(labels, energyPriceData, metalsEmaData, agriEmaData, naturalGasPriceData = [], goldPriceData = []) {
    const canvas = document.getElementById('macroCommoditiesChart');
    if (!canvas) return;
    macroCommoditiesChartInstance = destroyChartInstance(macroCommoditiesChartInstance);
    const ctx = canvas.getContext('2d');
    const dense = labels.length > 50;

    const datasets = [
        commodityLine('Energy Price Index', energyPriceData, COMMODITY_SERIES_COLORS.energy, dense),
        commodityLine('Industrial Metals Index', metalsEmaData, COMMODITY_SERIES_COLORS.metals, dense),
        commodityLine('Agricultural Commodities', agriEmaData, COMMODITY_SERIES_COLORS.agri, dense),
    ];
    // Gold is recorded only since it joined the macro; an older window has no gold line rather than a flat 100.
    if (goldPriceData && goldPriceData.some(v => v !== null)) {
        datasets.push(commodityLine('Gold Index', goldPriceData, COMMODITY_SERIES_COLORS.gold, dense));
    }
    if (naturalGasPriceData && naturalGasPriceData.length > 0) {
        datasets.push(commodityLine('Natural Gas Index', naturalGasPriceData, COMMODITY_SERIES_COLORS.gas, dense));
    }

    macroCommoditiesChartInstance = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw === null ? '-' : ctx.raw.toFixed(1)}` } }
            },
            scales: {
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    title: { display: true, text: 'Index (Base 100)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

/** The 3:2:1 refining crack spread in dollars a barrel, on its own axis rather than a second scale beside the indices. */
// The crack is $/bbl and power $/MWh, so each gets its own panel on a shared time axis (small multiples, one axis per unit).
const ENERGY_SPREAD_SERIES_COLORS = {
    crack: '#3987e5',
    power: '#d95926',
    spark: '#199e70',
};

// Power and the spark spread in dollars. The references are the PHP constants, rendered onto the canvas as data attributes.
function energySpreadDollars(powerIndexData, gasIndexData) {
    const canvas = document.getElementById('macroEnergySpreadsChart');
    const read = (key) => {
        const value = parseFloat(canvas?.dataset?.[key]);
        return Number.isFinite(value) ? value : null;
    };
    const powerReference = read('powerReference');
    const gasReference = read('gasReference');
    const heatRate = read('sparkHeatRate');
    if (powerReference === null || gasReference === null || heatRate === null) {
        return { power: powerIndexData.map(() => null), spark: powerIndexData.map(() => null) };
    }

    const power = powerIndexData.map((index) => (index === null || isNaN(index)) ? null : index / 100 * powerReference);
    const spark = power.map((dollars, i) => {
        const gas = gasIndexData[i];
        return (dollars === null || gas === null || gas === undefined || isNaN(gas)) ? null : dollars - (heatRate * gas / 100 * gasReference);
    });

    return { power, spark };
}

function renderMacroEnergySpreadsChart(labels, crackSpreadData, powerPriceIndexData = [], naturalGasPriceData = []) {
    const canvas = document.getElementById('macroEnergySpreadsChart');
    if (!canvas) return;
    macroEnergySpreadsChartInstance = destroyChartInstance(macroEnergySpreadsChartInstance);
    const ctx = canvas.getContext('2d');
    const dense = labels.length > 50;
    const spreads = energySpreadDollars(powerPriceIndexData, naturalGasPriceData);
    const grid = { color: 'rgba(255, 255, 255, 0.05)' };

    macroEnergySpreadsChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { ...commodityLine('3:2:1 Crack Spread', crackSpreadData, ENERGY_SPREAD_SERIES_COLORS.crack, dense), yAxisID: 'yCrack' },
                { ...commodityLine('Wholesale Power', spreads.power, ENERGY_SPREAD_SERIES_COLORS.power, dense), yAxisID: 'yPower' },
                { ...commodityLine('Spark Spread (7,000 Btu/kWh)', spreads.spark, ENERGY_SPREAD_SERIES_COLORS.spark, dense), yAxisID: 'yPower' },
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: true, position: 'top', labels: { boxWidth: 10, boxHeight: 2 } },
                tooltip: {
                    filter: (item) => item.raw !== null && !isNaN(item.raw),
                    callbacks: {
                        label: (item) => `${item.dataset.label}: $${item.raw.toFixed(2)}${item.dataset.yAxisID === 'yCrack' ? '/bbl' : '/MWh'}`
                    }
                }
            },
            scales: {
                // Left axes sharing a stack are placed top-down by descending weight: the crack panel sits above power.
                yCrack: {
                    type: 'linear', position: 'left', stack: 'energySpreads', stackWeight: 1, weight: 1,
                    grid, ticks: { callback: (val) => '$' + val },
                    title: { display: true, text: '$/bbl' }
                },
                yPower: {
                    type: 'linear', position: 'left', stack: 'energySpreads', stackWeight: 1, weight: 0, offset: true,
                    grid, ticks: { callback: (val) => '$' + val },
                    title: { display: true, text: '$/MWh' }
                },
                x: {
                    grid,
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroPropertyChart(labels, creEmaData, residentialEmaData, housingStartsData = []) {
    const canvas = document.getElementById('macroPropertyChart');
    if (!canvas) return;
    macroPropertyChartInstance = destroyChartInstance(macroPropertyChartInstance);
    const ctx = canvas.getContext('2d');

    const datasets = [
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
    ];

    if (housingStartsData && housingStartsData.length > 0) {
        datasets.push({
            label: 'Housing Starts Index (TE)',
            data: housingStartsData,
            borderColor: '#34d399',
            backgroundColor: 'rgba(52, 211, 153, 0.15)',
            borderWidth: 2,
            borderDash: [3, 2],
            tension: 0.2,
            pointRadius: labels.length > 50 ? 0 : 2
        });
    }

    macroPropertyChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}` } }
            },
            scales: {
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val },
                    title: { display: true, text: 'Property Index' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroTradeLogisticsChart(labels, fxEmaData, freightEmaData, gscpiData = []) {
    const canvas = document.getElementById('macroTradeLogisticsChart');
    if (!canvas) return;
    macroTradeLogisticsChartInstance = destroyChartInstance(macroTradeLogisticsChartInstance);
    const ctx = canvas.getContext('2d');

    const datasets = [
        {
            label: 'Exchange Rate Index (FX)',
            data: fxEmaData,
            borderColor: '#38bdf8',
            backgroundColor: 'rgba(56, 189, 248, 0.15)',
            borderWidth: 2,
            tension: 0.2,
            pointRadius: labels.length > 50 ? 0 : 2,
            yAxisID: 'y'
        },
        {
            label: 'Freight Rate Index',
            data: freightEmaData,
            borderColor: '#f97316',
            backgroundColor: 'rgba(249, 115, 22, 0.15)',
            borderWidth: 2,
            tension: 0.2,
            pointRadius: labels.length > 50 ? 0 : 2,
            yAxisID: 'y'
        }
    ];

    if (gscpiData && gscpiData.length > 0) {
        datasets.push({
            label: 'Supply Chain Pressure (GSCPI σ)',
            data: gscpiData,
            borderColor: '#e879f9',
            backgroundColor: 'rgba(232, 121, 249, 0.15)',
            borderWidth: 2,
            borderDash: [3, 3],
            tension: 0.2,
            pointRadius: labels.length > 50 ? 0 : 2,
            yAxisID: 'y1'
        });
    }

    macroTradeLogisticsChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.dataset.yAxisID === 'y1' ? (ctx.raw >= 0 ? '+' : '') + ctx.raw.toFixed(2) + ' σ' : ctx.raw.toFixed(2)}`
                    }
                }
            },
            scales: {
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val },
                    title: { display: true, text: 'Trade Index (Base 100)' }
                },
                y1: {
                    position: 'right',
                    suggestedMin: -1.5,
                    suggestedMax: 3.0,
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => (val >= 0 ? '+' : '') + val.toFixed(1) + 'σ' },
                    title: { display: true, text: 'GSCPI (Std Dev σ)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroSentimentChart(labels, sentimentData, retailDefaultData, dealActivityData = [], corporateDefaultPctData = []) {
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
                    label: 'Capital Markets Deal Flow',
                    data: dealActivityData || [],
                    borderColor: '#06b6d4',
                    backgroundColor: 'rgba(6, 182, 212, 0.15)',
                    borderWidth: 2,
                    tension: 0.25,
                    fill: false,
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
                },
                {
                    type: 'line',
                    label: 'Corporate Default Rate (ASRF)',
                    data: corporateDefaultPctData || [],
                    borderColor: '#f43f5e',
                    backgroundColor: 'rgba(244, 63, 94, 0.08)',
                    borderWidth: 2,
                    borderDash: [5, 3],
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
                    min: 0,
                    suggestedMax: 220,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val },
                    title: { display: true, text: 'Confidence & Deal Activity (Base 100)' }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    min: 0,
                    suggestedMax: 20,
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'Default Rate (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

/**
 * The sovereign reserve fund's position on the board: its domestic weight against the policy weight it rebalances
 * to, and the share of the float it owns. All three are percentages of the same order, so they share one axis; the
 * fund's size and its budget draw, which are not, are read off the card's latest line instead.
 */
function renderMacroSovereignFundChart(labels, weightData, targetData, ownershipData) {
    const canvas = document.getElementById('macroSovereignFundChart');
    if (!canvas) return;
    macroSovereignFundChartInstance = destroyChartInstance(macroSovereignFundChartInstance);
    const ctx = canvas.getContext('2d');
    const pointRadius = labels.length > 50 ? 0 : 2;

    macroSovereignFundChartInstance = new Chart(ctx, {
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'Domestic Weight (% of fund)',
                    data: weightData,
                    borderColor: '#0284c7',
                    backgroundColor: 'rgba(2, 132, 199, 0.12)',
                    borderWidth: 2,
                    tension: 0.2,
                    fill: false,
                    spanGaps: false,
                    pointRadius
                },
                {
                    type: 'line',
                    label: 'Policy Weight (% of fund)',
                    data: targetData,
                    borderColor: '#d97706',
                    borderWidth: 2,
                    borderDash: [5, 4],
                    tension: 0,
                    fill: false,
                    spanGaps: false,
                    pointRadius: 0
                },
                {
                    type: 'line',
                    label: 'Ownership (% of float)',
                    data: ownershipData,
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5, 150, 105, 0.12)',
                    borderWidth: 2,
                    tension: 0.2,
                    fill: false,
                    spanGaps: false,
                    pointRadius
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
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.raw !== null && ctx.raw !== undefined ? ctx.raw.toFixed(2) + '%' : 'N/A'}`
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    suggestedMin: 0,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'Percent' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroGovtSpendingChart(labels, govtSpendingEmaData, sovereignDebtData, sovereignRiskSpreadData = [], primaryDeficitData = [], taxData = []) {
    const canvas = document.getElementById('macroGovtSpendingChart');
    if (!canvas) return;
    macroGovtSpendingChartInstance = destroyChartInstance(macroGovtSpendingChartInstance);
    const ctx = canvas.getContext('2d');

    const datasets = [
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
            yAxisID: 'y',
            pointRadius: labels.length > 50 ? 0 : 2
        }
    ];

    if (taxData && taxData.length > 0) {
        datasets.push({
            type: 'line',
            label: 'Corporate Tax Rate (%)',
            data: taxData,
            borderColor: '#38bdf8',
            backgroundColor: 'rgba(56, 189, 248, 0.12)',
            borderWidth: 2,
            tension: 0.2,
            fill: false,
            yAxisID: 'yTax',
            pointRadius: labels.length > 50 ? 0 : 1.5
        });
    }

    if (sovereignRiskSpreadData && sovereignRiskSpreadData.length > 0) {
        datasets.push({
            type: 'line',
            label: 'Sovereign Risk Spread (bps)',
            data: sovereignRiskSpreadData,
            borderColor: '#a855f7',
            backgroundColor: 'rgba(168, 85, 247, 0.15)',
            borderWidth: 2,
            tension: 0.2,
            fill: false,
            yAxisID: 'y1',
            pointRadius: labels.length > 50 ? 0 : 1.5
        });
    }

    if (primaryDeficitData && primaryDeficitData.length > 0) {
        datasets.push({
            type: 'line',
            label: 'Primary Deficit (% GDP)',
            data: primaryDeficitData,
            borderColor: '#f59e0b',
            backgroundColor: 'rgba(245, 158, 11, 0.15)',
            borderWidth: 1.8,
            borderDash: [3, 3],
            tension: 0.2,
            yAxisID: 'yDeficit',
            pointRadius: labels.length > 50 ? 0 : 1.5
        });
    }

    macroGovtSpendingChartInstance = new Chart(ctx, {
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
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataset.label.includes('Spread')) {
                                return `${ctx.dataset.label}: ${ctx.raw !== null ? ctx.raw.toFixed(0) + ' bps' : 'N/A'}`;
                            }
                            if (ctx.dataset.label.includes('Deficit') || ctx.dataset.label.includes('Tax')) {
                                return `${ctx.dataset.label}: ${ctx.raw !== null ? (ctx.dataset.label.includes('Deficit') && ctx.raw >= 0 ? '+' : '') + ctx.raw.toFixed(1) + '%' : 'N/A'}`;
                            }
                            if (ctx.dataset.label.includes('Debt')) {
                                return `${ctx.dataset.label}: ${ctx.raw !== null ? ctx.raw.toFixed(1) + '%' : 'N/A'}`;
                            }
                            return `${ctx.dataset.label}: ${ctx.raw !== null ? ctx.raw.toFixed(1) + ' pts' : 'N/A'}`;
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
                    ticks: { callback: (val) => val.toFixed(0) },
                    title: { display: true, text: 'Spending Index & Debt (% / pts)' }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    suggestedMin: 0,
                    ticks: { callback: (val) => val.toFixed(0) + ' bps' },
                    title: { display: true, text: 'Sovereign Spread (bps)' }
                },
                yTax: {
                    type: 'linear',
                    display: false,
                    position: 'right',
                    suggestedMin: 10,
                    suggestedMax: 32,
                    grid: { drawOnChartArea: false }
                },
                yDeficit: {
                    type: 'linear',
                    display: false,
                    position: 'right',
                    grid: { drawOnChartArea: false }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
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
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val + ' bps' },
                    title: { display: true, text: 'Basis Points (bps)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroFciChart(labels, fciData, fciEmaData, sloosData = []) {
    const canvas = document.getElementById('macroFciChart');
    if (!canvas) return;
    macroFciChartInstance = destroyChartInstance(macroFciChartInstance);
    const ctx = canvas.getContext('2d');

    const datasets = [
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
    ];

    if (sloosData && sloosData.length > 0) {
        datasets.push({
            type: 'line',
            label: 'SLOOS Bank Lending Standards (Net %)',
            data: sloosData,
            borderColor: '#f59e0b',
            backgroundColor: '#f59e0b',
            borderWidth: 2,
            tension: 0.25,
            pointRadius: labels.length > 50 ? 0 : 2,
            yAxisID: 'y1'
        });
    }

    macroFciChartInstance = new Chart(ctx, {
        type: 'bar',
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
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const val = ctx.raw;
                            if (ctx.dataset.yAxisID === 'y1') {
                                return `${ctx.dataset.label}: ${val >= 0 ? '+' : ''}${val.toFixed(1)}%`;
                            }
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
                    title: { display: true, text: 'FCI Z-Score (σ)' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: {
                        callback: (val) => (val > 0.001 ? '+' : '') + val.toFixed(0) + '%'
                    },
                    title: { display: true, text: 'SLOOS Net Tightening (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
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
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.raw >= 0 ? '+' : ''}${ctx.raw.toFixed(1)} bps`
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => (val >= 0 ? '+' : '') + val.toFixed(0) + ' bps' },
                    title: { display: true, text: 'Basis Points (bps)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroSectoralInflationChart(labels, headlineData, supercoreData, coreGoodsData, foodLagData, ppiData = []) {
    const canvas = document.getElementById('macroSectoralInflationChart');
    if (!canvas) return;
    macroSectoralInflationChartInstance = destroyChartInstance(macroSectoralInflationChartInstance);
    const ctx = canvas.getContext('2d');

    const datasets = [
        {
            label: 'Headline CPI Inflation',
            data: headlineData,
            borderColor: '#facc15',
            backgroundColor: 'rgba(250, 204, 21, 0.10)',
            borderWidth: 2.5,
            tension: 0.3,
            pointRadius: labels.length > 50 ? 0 : 1.5
        },
        {
            label: 'Supercore Services (Wage-Push)',
            data: supercoreData,
            borderColor: '#38bdf8',
            backgroundColor: 'rgba(56, 189, 248, 0.08)',
            borderWidth: 2,
            tension: 0.3,
            pointRadius: labels.length > 50 ? 0 : 1.5
        },
        {
            label: 'Core Goods (Supply-Chain/Friction)',
            data: coreGoodsData,
            borderColor: '#c084fc',
            backgroundColor: 'rgba(192, 132, 252, 0.08)',
            borderWidth: 2,
            tension: 0.3,
            pointRadius: labels.length > 50 ? 0 : 1.5
        },
        {
            label: 'Food & Agri Cost-Push Lag',
            data: foodLagData,
            borderColor: '#4ade80',
            borderWidth: 1.5,
            borderDash: [4, 4],
            tension: 0.3,
            pointRadius: 0
        }
    ];

    if (ppiData && ppiData.length > 0) {
        datasets.push({
            label: 'Producer Price Inflation (PPI Wholesale)',
            data: ppiData,
            borderColor: '#f97316',
            backgroundColor: 'rgba(249, 115, 22, 0.08)',
            borderWidth: 2,
            tension: 0.3,
            pointRadius: labels.length > 50 ? 0 : 1.5
        });
    }

    macroSectoralInflationChartInstance = new Chart(ctx, {
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
                    type: 'linear',
                    display: true,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'Annual Inflation Rate (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroCreditCliffChart(labels, igBpsData, hyBpsData, cliffRatioData, corporateDefaultBpsData = []) {
    const canvas = document.getElementById('macroCreditCliffChart');
    if (!canvas) return;
    macroCreditCliffChartInstance = destroyChartInstance(macroCreditCliffChartInstance);
    const ctx = canvas.getContext('2d');

    const datasets = [
        {
            label: 'High Yield (HY) Spread',
            data: hyBpsData,
            borderColor: '#f43f5e',
            backgroundColor: 'rgba(244, 63, 94, 0.12)',
            borderWidth: 2.5,
            tension: 0.25,
            fill: true,
            yAxisID: 'y',
            pointRadius: labels.length > 50 ? 0 : 1.5
        },
        {
            label: 'Investment Grade (IG) Spread',
            data: igBpsData,
            borderColor: '#38bdf8',
            backgroundColor: 'rgba(56, 189, 248, 0.15)',
            borderWidth: 2,
            tension: 0.25,
            fill: true,
            yAxisID: 'y',
            pointRadius: labels.length > 50 ? 0 : 1.5
        }
    ];

    if (corporateDefaultBpsData && corporateDefaultBpsData.length > 0) {
        datasets.push({
            label: 'Corporate Default Rate (ASRF)',
            data: corporateDefaultBpsData,
            borderColor: '#fb7185',
            backgroundColor: 'rgba(251, 113, 133, 0.1)',
            borderWidth: 2,
            borderDash: [4, 4],
            tension: 0.25,
            fill: false,
            yAxisID: 'y',
            pointRadius: labels.length > 50 ? 0 : 1.5
        });
    }

    datasets.push({
        label: 'HY/IG Cliff Multiplier',
        data: cliffRatioData,
        borderColor: '#fbbf24',
        borderWidth: 2,
        tension: 0.25,
        yAxisID: 'y1',
        pointRadius: 0
    });

    macroCreditCliffChartInstance = new Chart(ctx, {
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
                tooltip: {
                    callbacks: {
                        label: (ctx) => ctx.dataset.yAxisID === 'y1'
                            ? `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}x`
                            : `${ctx.dataset.label}: ${ctx.raw.toFixed(0)} bps`
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(0) + ' bps' },
                    title: { display: true, text: 'Credit Spread & Defaults (bps)' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    suggestedMin: 1.5,
                    suggestedMax: 4.0,
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(1) + 'x' },
                    title: { display: true, text: 'Spread Ratio' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroInventoryCycleChart(labels, invGapData, outputGapData, energyBufData, capacityUtilizationData = []) {
    const canvas = document.getElementById('macroInventoryCycleChart');
    if (!canvas) return;
    macroInventoryCycleChartInstance = destroyChartInstance(macroInventoryCycleChartInstance);
    const ctx = canvas.getContext('2d');

    const datasets = [
        {
            label: 'Metzler Inventory Overhang Gap',
            data: invGapData,
            borderColor: '#2dd4bf',
            backgroundColor: 'rgba(45, 212, 191, 0.15)',
            borderWidth: 2,
            tension: 0.3,
            fill: true,
            yAxisID: 'y',
            pointRadius: labels.length > 50 ? 0 : 1.5
        },
        {
            label: 'Cyclical Output Gap',
            data: outputGapData,
            borderColor: '#818cf8',
            borderWidth: 2,
            borderDash: [4, 4],
            tension: 0.3,
            yAxisID: 'y',
            pointRadius: labels.length > 50 ? 0 : 1
        }
    ];

    if (capacityUtilizationData && capacityUtilizationData.length > 0) {
        datasets.push({
            label: 'Capacity Utilization (G.17)',
            data: capacityUtilizationData,
            borderColor: '#4ade80',
            borderWidth: 2,
            tension: 0.25,
            fill: false,
            yAxisID: 'y1',
            pointRadius: labels.length > 50 ? 0 : 1
        });
    }

    datasets.push({
        label: 'Strategic Energy Buffer Stock',
        data: energyBufData,
        borderColor: '#f59e0b',
        borderWidth: 2,
        borderDash: [5, 3],
        tension: 0.25,
        fill: false,
        yAxisID: 'y1',
        pointRadius: 0
    });

    macroInventoryCycleChartInstance = new Chart(ctx, {
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
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataset.label.includes('Buffer')) {
                                return `${ctx.dataset.label}: ${ctx.raw !== null && ctx.raw !== undefined ? ctx.raw.toFixed(1) : 'N/A'}`;
                            }
                            return `${ctx.dataset.label}: ${ctx.raw !== null && ctx.raw !== undefined ? ctx.raw.toFixed(2) + '%' : 'N/A'}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'Cyclical Gap (%)' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    min: 50,
                    suggestedMax: 110,
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(0) },
                    title: { display: true, text: 'Capacity (%) & Buffer Index' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroPolicyRuleChart(labels, ruleGapBpsData, policyRateData, targetRateData) {
    const canvas = document.getElementById('macroPolicyRuleChart');
    if (!canvas) return;
    macroPolicyRuleChartInstance = destroyChartInstance(macroPolicyRuleChartInstance);
    const ctx = canvas.getContext('2d');

    macroPolicyRuleChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Target Rate',
                    data: targetRateData,
                    borderColor: '#38bdf8',
                    borderWidth: 2,
                    borderDash: [4, 4],
                    tension: 0.15,
                    yAxisID: 'y',
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    label: 'Policy Rate',
                    data: policyRateData,
                    borderColor: '#34d399',
                    borderWidth: 2,
                    tension: 0.15,
                    yAxisID: 'y',
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    label: 'Policy Rate - Rule Target',
                    data: ruleGapBpsData,
                    borderColor: '#fbbf24',
                    borderWidth: 2,
                    tension: 0.25,
                    yAxisID: 'y1',
                    pointRadius: 0
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
                            ? `${ctx.dataset.label}: ${ctx.raw.toFixed(1)} bps`
                            : `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%`
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'Rates (%)' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(0) + ' bps' },
                    title: { display: true, text: 'Rate vs Rule (bps)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroWealthEffectChart(labels, ratioData, trendData, equityGapData, housingGapData, outputGapData) {
    const canvas = document.getElementById('macroWealthEffectChart');
    if (!canvas) return;
    macroWealthEffectChartInstance = destroyChartInstance(macroWealthEffectChartInstance);
    const ctx = canvas.getContext('2d');

    macroWealthEffectChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    // The distance between the two right-hand lines, and the only part of household wealth
                    // that reaches aggregate demand.
                    label: 'Equity Wealth Gap',
                    data: equityGapData,
                    borderColor: '#f472b6',
                    backgroundColor: 'rgba(244, 114, 182, 0.15)',
                    borderWidth: 2.2,
                    tension: 0.25,
                    fill: true,
                    spanGaps: false,
                    yAxisID: 'y',
                    pointRadius: labels.length > 50 ? 0 : 1.5
                },
                {
                    label: 'Housing Wealth Gap',
                    data: housingGapData,
                    borderColor: '#fb923c',
                    borderWidth: 2,
                    tension: 0.25,
                    fill: false,
                    yAxisID: 'y',
                    pointRadius: labels.length > 50 ? 0 : 1.5
                },
                {
                    label: 'Output Gap',
                    data: outputGapData,
                    borderColor: '#38bdf8',
                    borderWidth: 2,
                    tension: 0.25,
                    fill: false,
                    yAxisID: 'y',
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    label: 'Equity Wealth (Cap / GDP)',
                    data: ratioData,
                    borderColor: '#34d399',
                    borderWidth: 2,
                    tension: 0.25,
                    fill: false,
                    spanGaps: false,
                    yAxisID: 'y1',
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    // What households have got used to. The effect is measured from THIS, not from 100:
                    // a valuation they have had for years has long since stopped being news.
                    label: 'Wealth Trend (3Y)',
                    data: trendData,
                    borderColor: '#94a3b8',
                    borderWidth: 1.8,
                    borderDash: [5, 4],
                    tension: 0.25,
                    fill: false,
                    spanGaps: false,
                    yAxisID: 'y1',
                    pointRadius: 0
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
                            ? `${ctx.dataset.label}: ${ctx.raw === null ? 'n/a' : ctx.raw.toFixed(1)}`
                            : `${ctx.dataset.label}: ${ctx.raw === null ? 'n/a' : ctx.raw.toFixed(2) + '%'}`
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(0) + '%' },
                    title: { display: true, text: 'Deviation from Normal (%)' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(0) },
                    title: { display: true, text: 'Wealth Index (100 = window start)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroLeadingIndicatorsChart(labels, pmiData, housingStartsData, moneySupplyGrowthData, tradeBalanceData, ppiData) {
    const canvas = document.getElementById('macroLeadingIndicatorsChart');
    if (!canvas) return;
    macroLeadingIndicatorsChartInstance = destroyChartInstance(macroLeadingIndicatorsChartInstance);
    const ctx = canvas.getContext('2d');

    macroLeadingIndicatorsChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Manufacturing PMI (Base 50)',
                    data: pmiData,
                    borderColor: '#38bdf8',
                    backgroundColor: 'rgba(56, 189, 248, 0.10)',
                    borderWidth: 2.5,
                    tension: 0.25,
                    yAxisID: 'y',
                    pointRadius: labels.length > 50 ? 0 : 1.5
                },
                {
                    label: 'Housing Starts Index (Base 100)',
                    data: housingStartsData,
                    borderColor: '#34d399',
                    backgroundColor: 'rgba(52, 211, 153, 0.08)',
                    borderWidth: 2,
                    tension: 0.25,
                    yAxisID: 'y',
                    pointRadius: labels.length > 50 ? 0 : 1.5
                },
                {
                    label: 'M2 Money Supply Growth (YoY %)',
                    data: moneySupplyGrowthData,
                    borderColor: '#c084fc',
                    backgroundColor: 'rgba(192, 132, 252, 0.08)',
                    borderWidth: 2,
                    tension: 0.3,
                    yAxisID: 'y1',
                    pointRadius: labels.length > 50 ? 0 : 1.5
                },
                {
                    label: 'Trade Balance (% of GDP)',
                    data: tradeBalanceData,
                    borderColor: '#f59e0b',
                    borderWidth: 2,
                    borderDash: [3, 2],
                    tension: 0.25,
                    yAxisID: 'y1',
                    pointRadius: labels.length > 50 ? 0 : 1
                },
                {
                    label: 'PPI Wholesale Inflation (YoY %)',
                    data: ppiData,
                    borderColor: '#f97316',
                    borderWidth: 1.5,
                    borderDash: [4, 4],
                    tension: 0.3,
                    yAxisID: 'y1',
                    pointRadius: 0
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
                            if (ctx.dataset.yAxisID === 'y1') {
                                return `${ctx.dataset.label}: ${ctx.raw >= 0 ? '+' : ''}${ctx.raw.toFixed(2)}%`;
                            }
                            if (ctx.dataset.label.includes('PMI')) {
                                return `${ctx.dataset.label}: ${ctx.raw.toFixed(1)} ${ctx.raw >= 50 ? '(Expansion)' : '(Contraction)'}`;
                            }
                            return `${ctx.dataset.label}: ${ctx.raw.toFixed(1)}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    suggestedMin: 40,
                    suggestedMax: 120,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(0) },
                    title: { display: true, text: 'Index / Diffusion Points' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => (val >= 0 ? '+' : '') + val.toFixed(1) + '%' },
                    title: { display: true, text: 'Growth & Inflation (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroHouseholdCreditChart(labels, householdDsrData, householdDtiData, creditToGdpGapData, ccybRateData) {
    const canvas = document.getElementById('macroHouseholdCreditChart');
    if (!canvas) return;
    macroHouseholdCreditChartInstance = destroyChartInstance(macroHouseholdCreditChartInstance);
    const ctx = canvas.getContext('2d');

    macroHouseholdCreditChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Credit-to-GDP Gap (%)',
                    data: creditToGdpGapData,
                    backgroundColor: creditToGdpGapData.map(v => v >= 0 ? 'rgba(245, 158, 11, 0.35)' : 'rgba(14, 165, 233, 0.35)'),
                    borderColor: creditToGdpGapData.map(v => v >= 0 ? '#f59e0b' : '#0ea5e9'),
                    borderWidth: 1,
                    borderRadius: 2,
                    barPercentage: 0.65,
                    categoryPercentage: 0.85,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Debt Service Ratio (DSR %)',
                    data: householdDsrData,
                    borderColor: '#38bdf8',
                    backgroundColor: 'rgba(56, 189, 248, 0.15)',
                    borderWidth: 2.2,
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 2,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Basel III CCyB Buffer (%)',
                    data: ccybRateData,
                    borderColor: '#c084fc',
                    backgroundColor: 'rgba(192, 132, 252, 0.15)',
                    borderWidth: 2,
                    borderDash: [4, 4],
                    stepped: 'before',
                    pointRadius: labels.length > 50 ? 0 : 1,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Household Debt-to-Income (%)',
                    data: householdDtiData,
                    borderColor: '#f43f5e',
                    backgroundColor: 'rgba(244, 63, 94, 0.08)',
                    borderWidth: 2,
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 1.5,
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
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.raw !== null ? (ctx.raw >= 0 && ctx.dataset.label.includes('Gap') ? '+' : '') + ctx.raw.toFixed(2) + '%' : 'N/A'}`
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'DSR, CCyB & Credit Gap (%)' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(0) + '%' },
                    title: { display: true, text: 'Debt-to-Income (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroGlobalCycleChart(labels, outputGapData, foreignOutputGapData, globalDemandGapData, foreignPolicyRateData) {
    const canvas = document.getElementById('macroGlobalCycleChart');
    if (!canvas) return;
    macroGlobalCycleChartInstance = destroyChartInstance(macroGlobalCycleChartInstance);
    const ctx = canvas.getContext('2d');

    macroGlobalCycleChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Global Demand Gap (%)',
                    data: globalDemandGapData,
                    backgroundColor: globalDemandGapData.map(v => v >= 0 ? 'rgba(45, 212, 191, 0.35)' : 'rgba(244, 63, 94, 0.35)'),
                    borderColor: globalDemandGapData.map(v => v >= 0 ? '#2dd4bf' : '#f43f5e'),
                    borderWidth: 1,
                    borderRadius: 2,
                    barPercentage: 0.65,
                    categoryPercentage: 0.85,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Domestic Output Gap (%)',
                    data: outputGapData,
                    borderColor: '#34d399',
                    backgroundColor: 'rgba(52, 211, 153, 0.15)',
                    borderWidth: 2.2,
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 2,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Foreign Output Gap (%)',
                    data: foreignOutputGapData,
                    borderColor: '#38bdf8',
                    backgroundColor: 'rgba(56, 189, 248, 0.15)',
                    borderWidth: 2,
                    borderDash: [4, 4],
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 1.5,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Foreign Policy Rate (%)',
                    data: foreignPolicyRateData,
                    borderColor: '#fb923c',
                    backgroundColor: 'rgba(251, 146, 60, 0.15)',
                    borderWidth: 1.8,
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 1.5,
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
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.raw !== null ? (ctx.raw >= 0 && ctx.dataset.yAxisID === 'y' ? '+' : '') + ctx.raw.toFixed(2) + '%' : 'N/A'}`
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => (val >= 0 ? '+' : '') + val.toFixed(1) + '%' },
                    title: { display: true, text: 'Cyclical Gaps (%)' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    suggestedMin: 0,
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => val.toFixed(1) + '%' },
                    title: { display: true, text: 'Foreign Rate (%)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

function renderMacroBankingLiquidityChart(labels, depositBetaData, mmfShareData, interbankSpreadBpsData) {
    const canvas = document.getElementById('macroBankingLiquidityChart');
    if (!canvas) return;
    macroBankingLiquidityChartInstance = destroyChartInstance(macroBankingLiquidityChartInstance);
    const ctx = canvas.getContext('2d');

    macroBankingLiquidityChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'System Deposit Beta (%)',
                    data: depositBetaData,
                    borderColor: '#06b6d4',
                    backgroundColor: 'rgba(6, 182, 212, 0.15)',
                    borderWidth: 2.2,
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 2,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Money Market Fund Share (% Deposits)',
                    data: mmfShareData,
                    borderColor: '#a855f7',
                    backgroundColor: 'rgba(168, 85, 247, 0.15)',
                    borderWidth: 2,
                    tension: 0.25,
                    pointRadius: labels.length > 50 ? 0 : 1.5,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Interbank Liquidity Spread (bps)',
                    data: interbankSpreadBpsData,
                    borderColor: '#f43f5e',
                    backgroundColor: 'rgba(244, 63, 94, 0.10)',
                    borderWidth: 1.8,
                    borderDash: [4, 4],
                    tension: 0.2,
                    pointRadius: labels.length > 50 ? 0 : 1,
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
                            if (ctx.dataset.yAxisID === 'y1') {
                                return `${ctx.dataset.label}: ${ctx.raw !== null ? ctx.raw.toFixed(0) + ' bps' : 'N/A'}`;
                            }
                            return `${ctx.dataset.label}: ${ctx.raw !== null ? ctx.raw.toFixed(1) + '%' : 'N/A'}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    suggestedMin: 0,
                    suggestedMax: 100,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(0) + '%' },
                    title: { display: true, text: 'Deposit Beta & MMF Share (%)' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    suggestedMin: 0,
                    ticks: { callback: (val) => val.toFixed(0) + ' bps' },
                    title: { display: true, text: 'Interbank Spread (bps)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                }
            }
        }
    });
}

export function resizeMacroCharts() {
    const instances = [
        macroEconomyChartInstance, macroRatesChartInstance, macroMortgageChartInstance,
        macroRiskChartInstance, macroLaborChartInstance, macroLaborCreditChartInstance, macroWealthEffectChartInstance,
        macroCommoditiesChartInstance, macroEnergySpreadsChartInstance, macroPropertyChartInstance, macroTradeLogisticsChartInstance,
        macroSentimentChartInstance, macroGovtSpendingChartInstance, macroSovereignFundChartInstance, macroInterbankLiquidityChartInstance,
        macroTermPremiumChartInstance, macroGdpGrowthChartInstance, macroBalanceSheetChartInstance,
        macroFciChartInstance, macroCostPushChartInstance,
        macroSectoralInflationChartInstance, macroCreditCliffChartInstance,
        macroInventoryCycleChartInstance, macroPolicyRuleChartInstance,
        macroLeadingIndicatorsChartInstance,
        macroHouseholdCreditChartInstance, macroGlobalCycleChartInstance, macroBankingLiquidityChartInstance
    ];
    instances.forEach(c => {
        if (c) {
            try { c.resize(); } catch (e) { }
        }
    });
}

export function destroyMacroCharts() {
    // Charts that were never scrolled into view must not build themselves after teardown.
    resetLazyCharts();
    macroEconomyChartInstance = destroyChartInstance(macroEconomyChartInstance);
    macroRatesChartInstance = destroyChartInstance(macroRatesChartInstance);
    macroMortgageChartInstance = destroyChartInstance(macroMortgageChartInstance);
    macroRiskChartInstance = destroyChartInstance(macroRiskChartInstance);
    macroLaborChartInstance = destroyChartInstance(macroLaborChartInstance);
    macroLaborCreditChartInstance = destroyChartInstance(macroLaborCreditChartInstance);
    macroWealthEffectChartInstance = destroyChartInstance(macroWealthEffectChartInstance);
    macroCommoditiesChartInstance = destroyChartInstance(macroCommoditiesChartInstance);
    macroEnergySpreadsChartInstance = destroyChartInstance(macroEnergySpreadsChartInstance);
    macroPropertyChartInstance = destroyChartInstance(macroPropertyChartInstance);
    macroTradeLogisticsChartInstance = destroyChartInstance(macroTradeLogisticsChartInstance);
    macroSentimentChartInstance = destroyChartInstance(macroSentimentChartInstance);
    macroGovtSpendingChartInstance = destroyChartInstance(macroGovtSpendingChartInstance);
    macroSovereignFundChartInstance = destroyChartInstance(macroSovereignFundChartInstance);
    macroInterbankLiquidityChartInstance = destroyChartInstance(macroInterbankLiquidityChartInstance);
    macroTermPremiumChartInstance = destroyChartInstance(macroTermPremiumChartInstance);
    macroGdpGrowthChartInstance = destroyChartInstance(macroGdpGrowthChartInstance);
    macroBalanceSheetChartInstance = destroyChartInstance(macroBalanceSheetChartInstance);
    macroFciChartInstance = destroyChartInstance(macroFciChartInstance);
    macroCostPushChartInstance = destroyChartInstance(macroCostPushChartInstance);
    macroSectoralInflationChartInstance = destroyChartInstance(macroSectoralInflationChartInstance);
    macroCreditCliffChartInstance = destroyChartInstance(macroCreditCliffChartInstance);
    macroInventoryCycleChartInstance = destroyChartInstance(macroInventoryCycleChartInstance);
    macroPolicyRuleChartInstance = destroyChartInstance(macroPolicyRuleChartInstance);
    macroLeadingIndicatorsChartInstance = destroyChartInstance(macroLeadingIndicatorsChartInstance);
    macroHouseholdCreditChartInstance = destroyChartInstance(macroHouseholdCreditChartInstance);
    macroGlobalCycleChartInstance = destroyChartInstance(macroGlobalCycleChartInstance);
    macroBankingLiquidityChartInstance = destroyChartInstance(macroBankingLiquidityChartInstance);
}
