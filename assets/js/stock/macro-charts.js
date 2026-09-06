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
let macroSectoralInflationChartInstance = null;
let macroCreditCliffChartInstance = null;
let macroInventoryCycleChartInstance = null;
let macroFaitChartInstance = null;
let macroLeadingIndicatorsChartInstance = null;

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
    setHud('hud-macroMortgageChart', `30Y: ${last(d.mortgageYieldData).toFixed(2)}% | Spr: ${last(d.spread30yData).toFixed(2)}%`);
    setHud('hud-macroRiskChart', `VIX: ${last(d.volData).toFixed(1)}% | ERP: ${last(d.erpData).toFixed(1)}%`);
    setHud('hud-macroLaborCreditChart', `Unemp: ${last(d.unemploymentData).toFixed(1)}% | Wage: ${last(d.wageGrowthData).toFixed(1)}%`);
    setHud('hud-macroInterbankLiquidityChart', `TED: ${last(d.interbankSpreadBpsData).toFixed(0)} bps`);
    setHud('hud-macroPropertyChart', `CRE: ${last(d.creEmaData).toFixed(1)} | Resi: ${last(d.residentialEmaData).toFixed(1)} | Starts: ${last(d.housingStartsData).toFixed(1)}`);
    setHud('hud-macroSentimentChart', `Sent: ${last(d.sentimentData).toFixed(0)} | M&A: ${last(d.dealActivityData).toFixed(0)}`);
    setHud('hud-macroCommoditiesChart', `Energy: ${last(d.energyPriceData).toFixed(1)} | Crack: $${last(d.crackSpreadData).toFixed(1)}`);
    setHud('hud-macroTradeLogisticsChart', `FX: ${last(d.fxEmaData).toFixed(1)} | Freight: ${last(d.freightEmaData).toFixed(1)} | GSCPI: ${last(d.gscpiData) >= 0 ? '+' : ''}${last(d.gscpiData).toFixed(2)}σ`);
    setHud('hud-macroGovtSpendingChart', `Debt/GDP: ${last(d.sovereignDebtData).toFixed(1)}%`);
    setHud('hud-macroTermPremiumChart', `10Y: ${last(d.yield10yData).toFixed(2)}% | Term: ${last(d.termPremiumData) >= 0 ? '+' : ''}${last(d.termPremiumData).toFixed(2)}%`);
    setHud('hud-macroGdpGrowthChart', `Real: ${last(d.realGdpGrowthData) >= 0 ? '+' : ''}${last(d.realGdpGrowthData).toFixed(1)}% | Rec: ${last(d.recessionProbData).toFixed(0)}%`);
    setHud('hud-macroBalanceSheetChart', `Stock: ${last(d.slicedAssetStock).toFixed(1)} | QE/QT: ${last(d.balanceSheetData) >= 0 ? '+' : ''}${last(d.balanceSheetData).toFixed(0)} bps`);
    setHud('hud-macroFciChart', `Z: ${last(d.fciData) >= 0 ? '+' : ''}${last(d.fciData).toFixed(2)}σ | SLOOS: ${last(d.sloosData) >= 0 ? '+' : ''}${last(d.sloosData).toFixed(0)}%`);
    setHud('hud-macroCostPushChart', `Agri Drag: ${last(d.agriLagData) >= 0 ? '+' : ''}${last(d.agriLagData).toFixed(0)} bps`);
    setHud('hud-macroSectoralInflationChart', `CPI: ${last(d.inflationData).toFixed(1)}% | PPI: ${last(d.ppiData) >= 0 ? '+' : ''}${last(d.ppiData).toFixed(1)}% | Supercore: ${last(d.supercoreInflationData).toFixed(1)}%`);
    setHud('hud-macroCreditCliffChart', `HY: ${last(d.highYieldSpreadBpsData).toFixed(0)} bps | Cliff: ${last(d.creditCliffRatioData).toFixed(2)}x`);
    setHud('hud-macroInventoryCycleChart', `Overhang: ${last(d.inventoryStockGapData) >= 0 ? '+' : ''}${last(d.inventoryStockGapData).toFixed(1)}% | CU: ${last(d.capacityUtilizationData).toFixed(1)}%`);
    setHud('hud-macroFaitChart', `Cum Gap: ${last(d.faitCumulativeGapData) >= 0 ? '+' : ''}${last(d.faitCumulativeGapData).toFixed(0)} bps`);
    setHud('hud-macroLeadingIndicatorsChart', `PMI: ${last(d.pmiData).toFixed(1)} | Starts: ${last(d.housingStartsData).toFixed(0)} | M2: ${last(d.moneySupplyGrowthData) >= 0 ? '+' : ''}${last(d.moneySupplyGrowthData).toFixed(1)}% | Trade: ${last(d.tradeBalanceData) >= 0 ? '+' : ''}${last(d.tradeBalanceData).toFixed(1)}%`);
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
    let jobVacanciesData = [], laborTightnessData = [], wageGrowthData = [];
    let naturalRateData = [], termPremiumData = [], riskNeutralData = [], balanceSheetData = [];
    let balanceSheetAssetsData = [];
    let nominalGdpGrowthData = [], realGdpGrowthData = [], potentialGdpGrowthData = [], tfpGrowthData = [];
    let fciData = [], fciEmaData = [];
    let agriLagData = [], foodLagPctData = [], energySupplyDragData = [], freightSupplyDragData = [];
    let supercoreInflationData = [], coreGoodsInflationData = [];
    let highYieldSpreadBpsData = [], creditCliffRatioData = [];
    let inventoryStockGapData = [], energyBufferData = [];
    let faitCumulativeGapData = [], faitOffsetBpsData = [];
    let capacityUtilizationData = [], recessionProbData = [];
    let crackSpreadData = [], gscpiData = [];
    let corporateDefaultPctData = [], corporateDefaultBpsData = [];
    let sloosData = [], dealActivityData = [];
    let pmiData = [], ppiData = [], tradeBalanceData = [], housingStartsData = [], moneySupplyGrowthData = [];

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
        let mortgageRate = y30 !== null ? y30 + 1.80 : null; // 180 bps prime residential lending spread
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
        spread30yData.push((mortgageRate !== null && pr !== null) ? mortgageRate - pr : null);

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
        let energyDragBps = ((rawEnergy - 100.0) / 100.0) * 0.03 * 10000;
        energySupplyDragData.push(energyDragBps);

        let rawFreight = parseFloat(report.freight_rate_index_ema || report.freight_rate_index || 100.0);
        let freightDragBps = ((rawFreight - 100.0) / 100.0) * 0.01 * 10000;
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

        // Powell (2020) FAIT Memory: Asymmetric make-up buffer for cumulative inflation shortfall (clamped <= 0)
        let rawFaitGap = report.cumulative_inflation_gap_ema ?? report.cumulativeInflationGapEma ?? 0.0;
        let faitGapPct = parseFloat(rawFaitGap) * 100;
        faitCumulativeGapData.push(faitGapPct);
        faitOffsetBpsData.push(Math.min(0.0, faitGapPct * 25.0)); // Asymmetric make-up buffer: tolerates overshoots without hiking extra

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
    });

    updateMacroHud({
        inflationData, outputGapData, policyRateData, yield10yData,
        mortgageYieldData, spread30yData, volData, erpData,
        unemploymentData, wageGrowthData, interbankSpreadBpsData,
        creEmaData, residentialEmaData, sentimentData, dealActivityData,
        energyPriceData, crackSpreadData, gscpiData, sovereignDebtData,
        termPremiumData, realGdpGrowthData, tfpGrowthData, recessionProbData,
        slicedAssetStock, balanceSheetData, fciData, sloosData, agriLagData,
        supercoreInflationData, coreGoodsInflationData,
        highYieldSpreadBpsData, creditCliffRatioData,
        inventoryStockGapData, faitCumulativeGapData,
        capacityUtilizationData, fxEmaData, freightEmaData,
        pmiData, ppiData, tradeBalanceData, housingStartsData, moneySupplyGrowthData
    });

    ['5Y', '10Y', '25Y'].forEach(tf => {
        const btn = document.getElementById(`btn-macro-${tf}`);
        if (btn) {
            if (tf === currentMacroTimeframe) {
                btn.className = 'macro-range-btn px-3 py-1 text-xs font-bold rounded-lg bg-primary text-[#001a42] shadow-md shadow-primary/20 transition-all cursor-pointer';
            } else {
                btn.className = 'macro-range-btn px-3 py-1 text-xs font-bold rounded-lg bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-all cursor-pointer';
            }
        }
    });

    renderMacroEconomyChart(labels, inflationData, outputGapData, capitalOverhangData, tipsBreakevenData);
    renderMacroRatesChart(labels, policyRateData, yield2yData, yield5yData, yield10yData, spread2s10sData, targetRateData);
    renderMacroMortgageChart(labels, policyRateData, mortgageYieldData, spread30yData);
    renderMacroRiskChart(labels, erpData, volData, creditSpreadBpsData, corpBorrowingData);
    renderMacroLaborCreditChart(labels, unemploymentData, jobVacanciesData, wageGrowthData, nairuData);
    renderMacroCommoditiesChart(labels, energyPriceData, metalsEmaData, agriEmaData, crackSpreadData);
    renderMacroPropertyChart(labels, creEmaData, residentialEmaData, housingStartsData);
    renderMacroTradeLogisticsChart(labels, fxEmaData, freightEmaData, gscpiData);
    renderMacroSentimentChart(labels, sentimentData, retailDefaultData, dealActivityData, corporateDefaultPctData);
    renderMacroGovtSpendingChart(labels, govtSpendingEmaData, sovereignDebtData);
    renderMacroInterbankLiquidityChart(labels, interbankSpreadBpsData, creditSpreadBpsData);
    renderMacroTermPremiumChart(labels, yield10yData, riskNeutralData, termPremiumData, naturalRateData);
    renderMacroGdpGrowthChart(labels, nominalGdpGrowthData, realGdpGrowthData, potentialGdpGrowthData, tfpGrowthData, recessionProbData);
    renderMacroBalanceSheetChart(labels, balanceSheetAssetsData, balanceSheetData);
    renderMacroFciChart(labels, fciData, fciEmaData, sloosData);
    renderMacroCostPushChart(labels, agriLagData, energySupplyDragData, freightSupplyDragData);
    renderMacroSectoralInflationChart(labels, inflationData, supercoreInflationData, coreGoodsInflationData, foodLagPctData, ppiData);
    renderMacroCreditCliffChart(labels, creditSpreadBpsData, highYieldSpreadBpsData, creditCliffRatioData, corporateDefaultBpsData);
    renderMacroInventoryCycleChart(labels, inventoryStockGapData, outputGapData, energyBufferData, capacityUtilizationData);
    renderMacroFaitChart(labels, faitCumulativeGapData, faitOffsetBpsData, policyRateData, targetRateData);
    renderMacroLeadingIndicatorsChart(labels, pmiData, housingStartsData, moneySupplyGrowthData, tradeBalanceData, ppiData);
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
                    label: 'Mortgage Spread (30Y-PR)',
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
                    title: { display: true, text: 'Percentage Rate (%)' }
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
            label: 'TFP Productivity Growth',
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

function renderMacroCommoditiesChart(labels, energyPriceData, metalsEmaData, agriEmaData, crackSpreadData = []) {
    const canvas = document.getElementById('macroCommoditiesChart');
    if (!canvas) return;
    macroCommoditiesChartInstance = destroyChartInstance(macroCommoditiesChartInstance);
    const ctx = canvas.getContext('2d');

    const datasets = [
        {
            label: 'Energy Price Index',
            data: energyPriceData,
            borderColor: '#eab308',
            backgroundColor: 'rgba(234, 179, 8, 0.15)',
            borderWidth: 2,
            tension: 0.2,
            pointRadius: labels.length > 50 ? 0 : 2,
            yAxisID: 'y'
        },
        {
            label: 'Industrial Metals Index',
            data: metalsEmaData,
            borderColor: '#fb923c',
            backgroundColor: 'rgba(251, 146, 60, 0.15)',
            borderWidth: 2,
            tension: 0.2,
            pointRadius: labels.length > 50 ? 0 : 2,
            yAxisID: 'y'
        },
        {
            label: 'Agricultural Commodities',
            data: agriEmaData,
            borderColor: '#a3e635',
            backgroundColor: 'rgba(163, 230, 53, 0.15)',
            borderWidth: 2,
            tension: 0.2,
            pointRadius: labels.length > 50 ? 0 : 2,
            yAxisID: 'y'
        }
    ];

    if (crackSpreadData && crackSpreadData.length > 0) {
        datasets.push({
            label: '3:2:1 Crack Spread ($/bbl)',
            data: crackSpreadData,
            borderColor: '#38bdf8',
            backgroundColor: 'rgba(56, 189, 248, 0.15)',
            borderWidth: 2,
            borderDash: [4, 4],
            tension: 0.2,
            pointRadius: labels.length > 50 ? 0 : 2,
            yAxisID: 'y1'
        });
    }

    macroCommoditiesChartInstance = new Chart(ctx, {
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
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.dataset.yAxisID === 'y1' ? '$' + ctx.raw.toFixed(2) : ctx.raw.toFixed(2)}`
                    }
                }
            },
            scales: {
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val },
                    title: { display: true, text: 'Commodity Index (Base 100)' }
                },
                y1: {
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => '$' + val },
                    title: { display: true, text: 'Crack Spread ($/bbl)' }
                },
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
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
                    ticks: { callback: (val) => val },
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

function renderMacroFaitChart(labels, faitGapData, faitOffsetBpsData, policyRateData, targetRateData) {
    const canvas = document.getElementById('macroFaitChart');
    if (!canvas) return;
    macroFaitChartInstance = destroyChartInstance(macroFaitChartInstance);
    const ctx = canvas.getContext('2d');

    macroFaitChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Cumulative Inflation Gap (FAIT)',
                    data: faitGapData,
                    borderColor: '#c084fc',
                    backgroundColor: 'rgba(192, 132, 252, 0.15)',
                    borderWidth: 2,
                    tension: 0.25,
                    fill: true,
                    yAxisID: 'y',
                    pointRadius: labels.length > 50 ? 0 : 1.5
                },
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
                    label: 'FAIT Make-Up Offset',
                    data: faitOffsetBpsData,
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
                    title: { display: true, text: 'Rates & Cumulative Gap (%)' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    min: -50,
                    max: 10,
                    grid: { drawOnChartArea: false },
                    ticks: {
                        stepSize: 10,
                        callback: (val) => val.toFixed(0) + ' bps'
                    },
                    title: { display: true, text: 'Policy Offset (bps)' }
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

export function resizeMacroCharts() {
    const instances = [
        macroEconomyChartInstance, macroRatesChartInstance, macroMortgageChartInstance,
        macroRiskChartInstance, macroLaborChartInstance, macroLaborCreditChartInstance,
        macroCommoditiesChartInstance, macroPropertyChartInstance, macroTradeLogisticsChartInstance,
        macroSentimentChartInstance, macroGovtSpendingChartInstance, macroInterbankLiquidityChartInstance,
        macroTermPremiumChartInstance, macroGdpGrowthChartInstance, macroBalanceSheetChartInstance,
        macroFciChartInstance, macroCostPushChartInstance,
        macroSectoralInflationChartInstance, macroCreditCliffChartInstance,
        macroInventoryCycleChartInstance, macroFaitChartInstance,
        macroLeadingIndicatorsChartInstance
    ];
    instances.forEach(c => {
        if (c) {
            try { c.resize(); } catch (e) { }
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
    macroSectoralInflationChartInstance = destroyChartInstance(macroSectoralInflationChartInstance);
    macroCreditCliffChartInstance = destroyChartInstance(macroCreditCliffChartInstance);
    macroInventoryCycleChartInstance = destroyChartInstance(macroInventoryCycleChartInstance);
    macroFaitChartInstance = destroyChartInstance(macroFaitChartInstance);
    macroLeadingIndicatorsChartInstance = destroyChartInstance(macroLeadingIndicatorsChartInstance);
}
