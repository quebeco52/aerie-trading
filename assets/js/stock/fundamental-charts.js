import { THEME_COLORS } from '../utils/colors.js';
import { formatLarge } from '../utils/formatters.js';
import { destroyChartInstance } from '../utils/chart-config.js';
import { renderWhenVisible, resetLazyCharts } from '../utils/lazy-chart.js';

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

export function updateFundamentalCharts(timeframe, rawReports, context = {}) {
    if (!rawReports || rawReports.length === 0 || typeof Chart === 'undefined') return;

    const {
        businessModel = 'none',
        isFinancial = false,
        sharesOutstanding = 1000000000,
        currentPrice = 0
    } = context;

    // Toolbar button styles.
    const btnGlobal12Q = document.getElementById('btn-global-12Q');
    const btnGlobal12Y = document.getElementById('btn-global-12Y');

    const activeClass = 'px-4 py-1.5 text-xs font-bold rounded-lg bg-primary text-on-primary shadow-lg shadow-primary/20 transition-all uppercase tracking-widest';
    const inactiveClass = 'px-4 py-1.5 text-xs font-bold rounded-lg bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-all uppercase tracking-widest';

    if (timeframe === '12Q') {
        if (btnGlobal12Q) btnGlobal12Q.className = activeClass;
        if (btnGlobal12Y) btnGlobal12Y.className = inactiveClass;
    } else {
        if (btnGlobal12Y) btnGlobal12Y.className = activeClass;
        if (btnGlobal12Q) btnGlobal12Q.className = inactiveClass;
    }

    const latest = rawReports[rawReports.length - 1];
    renderFinancialStatements(latest);

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

    // Capital Return
    let dividendData = [];
    let buybackData = [];
    let dividendYieldData = [];

    // Leveraged Metrics
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
    // The three statement sections, now real lines rather than reconstructions.
    let operatingCashFlowData = [];
    let investingCashFlowData = [];
    let financingCashFlowData = [];

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

            let shs = parseFloat(report.shares || sharesOutstanding || 1000000000);
            let pr = parseFloat(report.historical_price || report.current_price || currentPrice || 0);
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
            operatingCashFlowData.push(parseFloat(report.operating_cash_flow ?? 0));
            investingCashFlowData.push(parseFloat(report.investing_cash_flow ?? 0));
            financingCashFlowData.push(parseFloat(report.financing_cash_flow ?? 0));

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
    } else {
        const yearsToFetch = 12;
        let yearCount = 1;

        for (let i = rawReports.length - 1; i >= 0 && yearCount <= yearsToFetch; i -= 4) {
            let report = rawReports[i];

            if (yearCount === 1) labels.unshift("Now");
            else if (yearCount === 2) labels.unshift("-1 Yr");
            else labels.unshift(`-${yearCount - 1} Yrs`);

            let sumRev = 0;
            let sumInc = 0;
            let sumIntExp = 0;
            let sumCapEx = 0;
            let sumDiv = 0;
            let sumBuy = 0;
            let sumFcf = 0;
            let sumOcf = 0, sumIcf = 0, sumFinCf = 0;
            for (let j = 0; j < 4; j++) {
                if (i - j >= 0) {
                    let rep = rawReports[i - j];
                    sumOcf += parseFloat(rep.operating_cash_flow ?? 0);
                    sumIcf += parseFloat(rep.investing_cash_flow ?? 0);
                    sumFinCf += parseFloat(rep.financing_cash_flow ?? 0);
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

            let shs = parseFloat(report.shares || sharesOutstanding || 1000000000);
            let pr = parseFloat(report.historical_price || report.current_price || currentPrice || 0);
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
            operatingCashFlowData.unshift(sumOcf);
            investingCashFlowData.unshift(sumIcf);
            financingCashFlowData.unshift(sumFinCf);

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

    let marginLabel = businessModel === 'insurance' ? 'Combined Ratio' : 'Operating Margin';
    let displayMarginData = businessModel === 'insurance'
        ? operatingMarginData.map(m => 100 - m)
        : operatingMarginData;

    let ltmDiv = 0;
    let ltmInc = 0;
    const recentReports = rawReports.slice(-4);
    recentReports.forEach(r => {
        ltmDiv += parseFloat(r.dividend_paid || 0);
        ltmInc += parseFloat(r.net_income || 0);
    });

    renderWhenVisible('netIncomeChart', () => renderProfitEngineChart(labels, revenueData, netIncomeData, capexData, displayMarginData, marginLabel));
    renderWhenVisible('revenueStreamsChart', () => renderRevenueStreamsChart(labels, revenueStreamsKeys, revenueStreamsDataRaw, streamDetailsDataRaw));
    renderWhenVisible('debtEquityChart', () => renderDebtEquityChart(labels, debtData, equityData, treasuryData));
    renderWhenVisible('creditHealthChart', () => renderCreditHealthChart(labels, spreadData, blendedRateData, expenseRatioData, cashYieldData, depositApyData, businessModel));
    renderWhenVisible('capitalReturnChart', () => renderCapitalReturnChart(labels, dividendData, buybackData, dividendYieldData));
    renderWhenVisible('payoutRatioChart', () => renderPayoutRatioChart(ltmDiv, ltmInc));

    if (isFinancial) {
        renderWhenVisible('capitalEfficiencyChart', () => renderCapitalEfficiencyChart(labels, roeData, coeData, evaData, 'ROE', 'Cost of Equity'));

        if (businessModel === 'commercial_bank' || businessModel === 'credit_services') {
            renderWhenVisible('regulatoryRatiosChart', () => renderRegulatoryRatiosChart(labels, capitalRatioData, customerDepositRatioData, 'Customer Deposit Ratio'));
        } else if (businessModel === 'insurance') {
            renderWhenVisible('regulatoryRatiosChart', () => renderRegulatoryRatiosChart(labels, capitalRatioData, customerDepositRatioData, 'Float Ratio (0% Interest)'));
        } else if (businessModel === 'shadow_bank') {
            renderWhenVisible('regulatoryRatiosChart', () => renderRegulatoryRatiosChart(labels, capitalRatioData, customerDepositRatioData, 'Wholesale Funding / Deposit Ratio'));
        } else if (businessModel === 'clearing_house') {
            renderWhenVisible('regulatoryRatiosChart', () => renderRegulatoryRatiosChart(labels, capitalRatioData, customerDepositRatioData, 'Member Initial Margin Ratio'));
        } else {
            const hasDeposits = customerDepositRatioData && customerDepositRatioData.some(val => val !== 0 && val !== null && !isNaN(val));
            renderWhenVisible('regulatoryRatiosChart', () => renderRegulatoryRatiosChart(labels, capitalRatioData, hasDeposits ? customerDepositRatioData : null, hasDeposits ? 'Client Float / Funding Ratio' : null));
        }
    } else if (businessModel === 'reit') {
        renderWhenVisible('capitalEfficiencyChart', () => renderCapitalEfficiencyChart(labels, roicData, waccData, evaData, 'Cap Rate', 'WACC'));
    } else {
        renderWhenVisible('capitalEfficiencyChart', () => renderCapitalEfficiencyChart(labels, roicData, waccData, evaData, 'ROIC', 'WACC'));
    }

    renderWhenVisible('valuationMultiplesChart', () => renderValuationMultiplesChart(labels, peData, pbData, psData));
    renderWhenVisible('shareholderValueChart', () => renderShareholderValueChart(labels, epsData, bvpsData, sharesData));
    renderWhenVisible('cashFlowSummaryChart', () => renderCashFlowSummaryChart(labels, fcfData, fcfConversionData, retainedCashData, operatingCashFlowData, investingCashFlowData, financingCashFlowData));

    if (['commercial_bank', 'credit_services', 'shadow_bank'].includes(businessModel)) {
        renderWhenVisible('netInterestEngineChart', () => renderNetInterestEngineChart(labels, interestIncomeData, interestExpenseData, netInterestSpreadData));
    } else if (businessModel === 'insurance') {
        renderWhenVisible('insuranceDualEngineChart', () => renderInsuranceDualEngineChart(labels, underwritingProfitData, interestIncomeData, displayMarginData));
    } else if (businessModel === 'reit') {
        renderWhenVisible('reitCoverageChart', () => renderReitCoverageChart(labels, reitPayoutRatioData, reitLtvData, reitSpreadData));
    } else if (['tech', 'semiconductor', 'biotech', 'defense_contractor'].includes(businessModel)) {
        renderWhenVisible('reinvestmentIntensityChart', () => renderReinvestmentIntensityChart(labels, capexRevenueRatioData, operatingMarginData, roicData));
    } else if (['commodity', 'shipping'].includes(businessModel)) {
        renderWhenVisible('cyclicalDynamicsChart', () => renderCyclicalDynamicsChart(labels, operatingMarginData, debtData, treasuryData));
    }

    let payoutRatio = 0;
    if (ltmInc > 0 && ltmDiv > 0) {
        payoutRatio = Math.min(100, (ltmDiv / ltmInc) * 100);
    } else if (ltmDiv > 0 && ltmInc <= 0) {
        payoutRatio = 100;
    }
    let retainedRatio = Math.max(0, 100 - payoutRatio);

    updateFinancialHud({
        revenueData, netIncomeData, operatingMarginData: displayMarginData, marginLabel,
        revenueStreamsKeys, rawStreamsData: revenueStreamsDataRaw,
        debtData, equityData,
        spreadData, blendedRateData,
        returnLabel: isFinancial ? 'ROE' : (businessModel === 'reit' ? 'Cap Rate' : 'ROIC'),
        hurdleLabel: isFinancial ? 'Cost of Equity' : 'WACC',
        returnData: isFinancial ? roeData : roicData,
        hurdleData: isFinancial ? coeData : waccData,
        evaData,
        dividendData, buybackData, dividendYieldData,
        payoutRatio, retainedRatio,
        peData, pbData,
        epsData, bvpsData,
        fcfData, fcfConversionData,
        interestIncomeData, interestExpenseData, netInterestSpreadData,
        capitalRatioData, customerDepositRatioData,
        underwritingProfitData, displayMarginData,
        reitPayoutRatioData, reitLtvData,
        capexRevenueRatioData, roicData,
        treasuryData, businessModel, isFinancial
    });
}

function setHud(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
}

function updateFinancialHud(m) {
    const last = (arr, def = 0) => (arr && arr.length > 0 && arr[arr.length - 1] !== null && !isNaN(arr[arr.length - 1])) ? arr[arr.length - 1] : def;

    const lastRev = last(m.revenueData);
    const lastNet = last(m.netIncomeData);
    const lastMargin = last(m.operatingMarginData);
    setHud('hud-netIncomeChart', `Rev: ${formatLarge(lastRev, '$')} | Net: ${formatLarge(lastNet, '$')} (${lastMargin.toFixed(1)}%)`);
    setHud('hud-profitEngineChart', `Rev: ${formatLarge(lastRev, '$')} | Net: ${formatLarge(lastNet, '$')} (${lastMargin.toFixed(1)}%)`);

    const streamsCount = m.revenueStreamsKeys ? m.revenueStreamsKeys.size : 0;
    setHud('hud-revenueStreamsChart', `Streams: ${streamsCount} | Rev: ${formatLarge(lastRev, '$')}`);

    const lastDebt = last(m.debtData);
    const lastEq = last(m.equityData);
    const deRatio = lastEq > 0 ? (lastDebt / lastEq) : 0;
    setHud('hud-debtEquityChart', `Debt: ${formatLarge(lastDebt, '$')} | Eq: ${formatLarge(lastEq, '$')} (D/E: ${deRatio.toFixed(2)}x)`);

    const lastRate = last(m.blendedRateData);
    const lastSpread = last(m.spreadData);
    setHud('hud-creditHealthChart', `Rate: ${lastRate.toFixed(2)}% | Spr: ${lastSpread.toFixed(0)} bps`);

    const lastRet = last(m.returnData);
    const lastHurd = last(m.hurdleData);
    const lastEva = last(m.evaData);
    setHud('hud-capitalEfficiencyChart', `${m.returnLabel || 'ROIC'}: ${lastRet.toFixed(1)}% | ${m.hurdleLabel || 'WACC'}: ${lastHurd.toFixed(1)}% | EVA: ${formatLarge(lastEva, '$')}`);

    const lastDiv = last(m.dividendData);
    const lastBuyback = last(m.buybackData);
    const lastYield = last(m.dividendYieldData);
    setHud('hud-capitalReturnChart', `Div: ${formatLarge(lastDiv, '$')} | Buyback: ${formatLarge(lastBuyback, '$')} | Yield: ${lastYield.toFixed(2)}%`);

    setHud('hud-payoutRatioChart', `Payout: ${m.payoutRatio.toFixed(1)}% | Retained: ${m.retainedRatio.toFixed(1)}%`);

    const lastPe = last(m.peData);
    const lastPb = last(m.pbData);
    setHud('hud-valuationMultiplesChart', `P/E: ${lastPe > 0 ? lastPe.toFixed(1) + 'x' : '-'} | P/B: ${lastPb > 0 ? lastPb.toFixed(2) + 'x' : '-'}`);

    const lastEps = last(m.epsData);
    const lastBvps = last(m.bvpsData);
    setHud('hud-shareholderValueChart', `EPS: $${lastEps.toFixed(2)} | BVPS: $${lastBvps.toFixed(2)}`);

    const lastFcf = last(m.fcfData);
    const lastConv = last(m.fcfConversionData);
    setHud('hud-cashFlowSummaryChart', `FCF: ${formatLarge(lastFcf, '$')} | FCF/NI: ${lastConv.toFixed(0)}%`);

    if (['commercial_bank', 'credit_services', 'shadow_bank'].includes(m.businessModel)) {
        const lastNii = last(m.interestIncomeData) - last(m.interestExpenseData);
        const lastNim = last(m.netInterestSpreadData);
        setHud('hud-netInterestEngineChart', `NII: ${formatLarge(lastNii, '$')} | NIM: ${lastNim.toFixed(2)}%`);
    }
    if (m.isFinancial) {
        const lastCap = last(m.capitalRatioData);
        const lastDep = last(m.customerDepositRatioData);
        setHud('hud-regulatoryRatiosChart', `Capital: ${lastCap.toFixed(1)}% | Reserves: ${lastDep ? lastDep.toFixed(1) + '%' : '-'}`);
    }
    if (m.businessModel === 'insurance') {
        const lastUw = last(m.underwritingProfitData);
        const lastFloat = last(m.interestIncomeData);
        const lastComb = last(m.displayMarginData);
        setHud('hud-insuranceDualEngineChart', `UW: ${formatLarge(lastUw, '$')} | Float: ${formatLarge(lastFloat, '$')} | CR: ${lastComb.toFixed(1)}%`);
    }
    if (m.businessModel === 'reit') {
        const lastCov = last(m.reitPayoutRatioData);
        const lastLtv = last(m.reitLtvData);
        setHud('hud-reitCoverageChart', `AFFO Cov: ${lastCov.toFixed(0)}% | LTV: ${lastLtv.toFixed(1)}%`);
    }
    if (['tech', 'semiconductor', 'biotech', 'defense_contractor'].includes(m.businessModel)) {
        const lastCapEx = last(m.capexRevenueRatioData);
        const lastRoic = last(m.roicData);
        setHud('hud-reinvestmentIntensityChart', `CapEx/Rev: ${lastCapEx.toFixed(1)}% | ROIC: ${lastRoic.toFixed(1)}%`);
    }
    if (['commodity', 'shipping'].includes(m.businessModel)) {
        const lastMargin = last(m.operatingMarginData);
        const lastCash = last(m.treasuryData);
        setHud('hud-cyclicalDynamicsChart', `Margin: ${lastMargin.toFixed(1)}% | Cash: ${formatLarge(lastCash, '$')}`);
    }
}

function renderProfitEngineChart(labels, revenueData, netIncomeData, capexData, operatingMarginData, marginLabel = 'Operating Margin') {
    const el = document.getElementById('netIncomeChart');
    if (!el) return;
    profitEngineChartInstance = destroyChartInstance(profitEngineChartInstance);

    const ctx = el.getContext('2d');
    profitEngineChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Revenue',
                    data: revenueData,
                    backgroundColor: THEME_COLORS.primary,
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'bar',
                    label: 'Net Income',
                    data: netIncomeData,
                    backgroundColor: netIncomeData.map(val => val < 0 ? THEME_COLORS.negative : THEME_COLORS.positive),
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
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataset.label === marginLabel) {
                                return `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%`;
                            }
                            return `${ctx.dataset.label}: ${formatLarge(ctx.raw, '$')}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => formatLarge(val, '$') }
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
    const ctx = document.getElementById('revenueStreamsChart');
    if (!ctx) return;
    revenueStreamsChartInstance = destroyChartInstance(revenueStreamsChartInstance);

    const streamsKeys = Array.from(streamsKeysSet);

    if (streamsKeys.length === 0) {
        revenueStreamsChartInstance = new Chart(ctx.getContext('2d'), { type: 'bar', data: { labels: labels, datasets: [] } });
        return;
    }

    const palette = [
        '#adc6ff', '#4edea3', '#d8b4fe', '#facc15', '#67e8f9',
        '#ffb3ad', '#fdba74', '#a7f3d0', '#fbcfe8', '#e2e8f0'
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
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: {
                    stacked: true,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    stacked: true,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => formatLarge(val, '$') }
                }
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${formatLarge(ctx.raw, '$')}`,
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

function renderRegulatoryRatiosChart(labels, capitalRatioData, secondaryData, secondaryLabel) {
    const canvas = document.getElementById('regulatoryRatiosChart');
    if (!canvas) return;
    regulatoryRatiosChartInstance = destroyChartInstance(regulatoryRatiosChartInstance);

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
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val + '%' },
                    beginAtZero: true
                }
            }
        }
    });
}

function renderDebtEquityChart(labels, debtData, equityData, treasuryData) {
    const el = document.getElementById('debtEquityChart');
    if (!el) return;
    debtEquityChartInstance = destroyChartInstance(debtEquityChartInstance);

    const ctx = el.getContext('2d');
    debtEquityChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Total Debt',
                    data: debtData,
                    backgroundColor: THEME_COLORS.negative,
                    borderRadius: 4,
                },
                {
                    label: 'Book Value',
                    data: equityData,
                    backgroundColor: THEME_COLORS.primary,
                    borderRadius: 4,
                },
                {
                    label: 'Total Cash',
                    data: treasuryData,
                    backgroundColor: THEME_COLORS.positive,
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
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${formatLarge(ctx.raw, '$')}` } }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => formatLarge(val, '$') }
                }
            }
        }
    });
}

function renderCreditHealthChart(labels, spreadData, blendedRateData, expenseRatioData, cashYieldData, depositApyData, businessModel) {
    const canvas = document.getElementById('creditHealthChart');
    if (!canvas) return;
    creditHealthChartInstance = destroyChartInstance(creditHealthChartInstance);

    const ctx = canvas.getContext('2d');
    const config = {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Dynamic Spread (Risk Premium)',
                    data: spreadData,
                    borderColor: THEME_COLORS.negative,
                    backgroundColor: THEME_COLORS.negative,
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
                    borderColor: THEME_COLORS.positive,
                    backgroundColor: THEME_COLORS.positive,
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
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val + '%' },
                    beginAtZero: true
                }
            }
        }
    };

    if (businessModel === 'commercial_bank' || businessModel === 'credit_services') {
        config.data.datasets.push({
            label: 'Deposit APY',
            data: depositApyData,
            borderColor: '#c084fc',
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
    capitalEfficiencyChartInstance = destroyChartInstance(capitalEfficiencyChartInstance);

    const ctx = canvas.getContext('2d');
    capitalEfficiencyChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: returnLabel,
                    data: returnData,
                    borderColor: THEME_COLORS.positive,
                    backgroundColor: THEME_COLORS.positive,
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    yAxisID: 'y'
                },
                {
                    label: hurdleLabel,
                    data: hurdleData,
                    borderColor: THEME_COLORS.negative,
                    backgroundColor: THEME_COLORS.negative,
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
                    backgroundColor: evaData.map(val => val < 0 ? 'rgba(255, 179, 173, 0.3)' : 'rgba(78, 222, 163, 0.3)'),
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
                        label: (ctx) => {
                            if (ctx.dataset.label === 'EVA ($)') {
                                return `EVA: ${formatLarge(ctx.raw, '$')}`;
                            }
                            return `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val + '%' },
                    title: { display: true, text: 'Percentage' }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { callback: (val) => formatLarge(val, '$') },
                }
            }
        }
    });
}

function renderCapitalReturnChart(labels, dividendData, buybackData, dividendYieldData) {
    const canvas = document.getElementById('capitalReturnChart');
    if (!canvas) return;
    capitalReturnChartInstance = destroyChartInstance(capitalReturnChartInstance);

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
                    backgroundColor: THEME_COLORS.primary,
                    borderRadius: 4,
                    yAxisID: 'y'
                },
                {
                    type: 'bar',
                    label: 'Stock Buybacks',
                    data: buybackData,
                    backgroundColor: THEME_COLORS.positive,
                    borderRadius: 4,
                    yAxisID: 'y'
                },
                {
                    type: 'line',
                    label: 'Dividend Yield',
                    data: dividendYieldData || [],
                    borderColor: THEME_COLORS.warning,
                    backgroundColor: 'rgba(255, 152, 0, 0.15)',
                    borderWidth: 2.5,
                    pointBackgroundColor: THEME_COLORS.warning,
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
                            return `${ctx.dataset.label}: ${formatLarge(ctx.raw, '$')}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => formatLarge(val, '$') },
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

    payoutRatioChartInstance = destroyChartInstance(payoutRatioChartInstance);

    const ctx = canvas.getContext('2d');
    payoutRatioChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Payout Ratio', 'Retained Earnings'],
            datasets: [{
                data: [payoutRatio, retainedRatio],
                backgroundColor: [THEME_COLORS.positive, '#2d3449'],
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

function renderValuationMultiplesChart(labels, peData, pbData, psData) {
    const canvas = document.getElementById('valuationMultiplesChart');
    if (!canvas) return;
    valuationMultiplesChartInstance = destroyChartInstance(valuationMultiplesChartInstance);

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
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(1) + 'x' }
                }
            }
        }
    });
}

function renderShareholderValueChart(labels, epsData, bvpsData, sharesData) {
    const canvas = document.getElementById('shareholderValueChart');
    if (!canvas) return;
    shareholderValueChartInstance = destroyChartInstance(shareholderValueChartInstance);

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
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
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

/** The three statement cash-flow lines are parameters: this scope cannot see the caller's locals. */
function renderCashFlowSummaryChart(labels, fcfData, fcfConversionData, retainedCashData, operatingCashFlowData, investingCashFlowData, financingCashFlowData) {
    const canvas = document.getElementById('cashFlowSummaryChart');
    if (!canvas) return;
    cashFlowSummaryChartInstance = destroyChartInstance(cashFlowSummaryChartInstance);

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
                    backgroundColor: fcfData.map(val => val < 0 ? THEME_COLORS.negative : THEME_COLORS.positive),
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'bar',
                    label: 'Operating CF',
                    data: operatingCashFlowData,
                    backgroundColor: 'rgba(56, 189, 248, 0.65)',
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'bar',
                    label: 'Investing CF',
                    data: investingCashFlowData,
                    backgroundColor: 'rgba(234, 179, 8, 0.65)',
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'bar',
                    label: 'Financing CF',
                    data: financingCashFlowData,
                    backgroundColor: 'rgba(217, 70, 239, 0.65)',
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
                            return `${ctx.dataset.label}: ${formatLarge(ctx.raw, '$')}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => formatLarge(val, '$') }
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
    netInterestEngineChartInstance = destroyChartInstance(netInterestEngineChartInstance);

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
                    backgroundColor: THEME_COLORS.positive,
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'bar',
                    label: 'Interest Expense',
                    data: interestExpenseData,
                    backgroundColor: THEME_COLORS.negative,
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
                            return `${ctx.dataset.label}: ${formatLarge(ctx.raw, '$')}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => formatLarge(val, '$') }
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

function renderInsuranceDualEngineChart(labels, underwritingProfitData, interestIncomeData, combinedRatioData) {
    const canvas = document.getElementById('insuranceDualEngineChart');
    if (!canvas) return;
    insuranceDualEngineChartInstance = destroyChartInstance(insuranceDualEngineChartInstance);

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
                    backgroundColor: underwritingProfitData.map(val => val < 0 ? THEME_COLORS.negative : THEME_COLORS.positive),
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
                            return `${ctx.dataset.label}: ${formatLarge(ctx.raw, '$')}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => formatLarge(val, '$') }
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
    reitCoverageChartInstance = destroyChartInstance(reitCoverageChartInstance);

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
                    borderColor: THEME_COLORS.negative,
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
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
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
    reinvestmentIntensityChartInstance = destroyChartInstance(reinvestmentIntensityChartInstance);

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
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toFixed(2)}%` } }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => val.toFixed(0) + '%' }
                }
            }
        }
    });
}

function renderCyclicalDynamicsChart(labels, operatingMarginData, debtData, treasuryData) {
    const canvas = document.getElementById('cyclicalDynamicsChart');
    if (!canvas) return;
    cyclicalDynamicsChartInstance = destroyChartInstance(cyclicalDynamicsChartInstance);

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
                    backgroundColor: THEME_COLORS.negative,
                    borderRadius: 4,
                    yAxisID: 'y',
                    order: 1
                },
                {
                    type: 'bar',
                    label: 'Treasury Reserves',
                    data: treasuryData,
                    backgroundColor: THEME_COLORS.positive,
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
                            return `${ctx.dataset.label}: ${formatLarge(ctx.raw, '$')}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { maxTicksLimit: 8 }
                },
                y: {
                    type: 'linear',
                    position: 'left',
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { callback: (val) => formatLarge(val, '$') }
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

export function resizeFundamentalCharts() {
    const instances = [
        profitEngineChartInstance, revenueStreamsChartInstance, debtEquityChartInstance,
        creditHealthChartInstance, capitalEfficiencyChartInstance, capitalReturnChartInstance,
        payoutRatioChartInstance, regulatoryRatiosChartInstance, valuationMultiplesChartInstance,
        shareholderValueChartInstance, cashFlowSummaryChartInstance, netInterestEngineChartInstance,
        insuranceDualEngineChartInstance, reitCoverageChartInstance, reinvestmentIntensityChartInstance,
        cyclicalDynamicsChartInstance
    ];
    instances.forEach(c => {
        if (c) {
            try { c.resize(); } catch (e) {}
        }
    });
}

/**
 * Formats a signed dollar amount compactly, keeping the sign visible: on a cash flow statement the sign
 * is the whole point, since it is what separates a firm investing from one liquidating.
 */
function formatStatementAmount(value) {
    const n = parseFloat(value || 0);
    if (!isFinite(n)) return '-';

    const abs = Math.abs(n);
    const sign = n < 0 ? '-' : '';
    if (abs >= 1e12) return `${sign}$${(abs / 1e12).toFixed(2)}T`;
    if (abs >= 1e9) return `${sign}$${(abs / 1e9).toFixed(2)}B`;
    if (abs >= 1e6) return `${sign}$${(abs / 1e6).toFixed(2)}M`;
    if (abs >= 1e3) return `${sign}$${(abs / 1e3).toFixed(2)}K`;
    return `${sign}$${abs.toFixed(0)}`;
}

function renderStatementRows(containerId, rows) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = rows.map(row => {
        if (row.divider) {
            return '<div class="border-t border-outline-variant/20 my-1"></div>';
        }

        const emphasis = row.total
            ? 'font-bold text-on-surface'
            : (row.indent ? 'text-on-surface-variant pl-3' : 'text-on-surface-variant');
        const valueColor = row.signed && parseFloat(row.value || 0) < 0 ? 'text-negative' : 'text-on-surface';

        return `<div class="flex justify-between items-baseline py-0.5 text-xs">
            <span class="${emphasis}">${row.label}</span>
            <span class="font-mono tabular-nums ${row.total ? 'font-bold' : ''} ${valueColor}">${formatStatementAmount(row.value)}</span>
        </div>`;
    }).join('');
}

/**
 * Renders the balance sheet and cash flow statement from the most recent quarterly report.
 *
 * These are the two statements the page never had: everything on screen was an income statement line or a
 * ratio derived from one. With the asset, working capital and deferred tax ledgers now real, the filing
 * can be shown as a filing.
 */
function renderFinancialStatements(latest) {
    if (!latest) return;

    const num = (k) => parseFloat(latest[k] || 0);
    const present = (k) => latest[k] !== null && latest[k] !== undefined;
    const hasCashFlow = present('operating_cash_flow');
    // The asset side exists once a ledger is open: plant and a trade cycle for an operating company, loans
    // and securities for a balance-sheet business. A report from before the first ledger has no total.
    const hasAssetSide = present('total_assets') && num('total_assets') > 0;
    const totalAssets = num('total_assets');
    // A lender's sheet is drawn in its own shape: the book, the losses expected on it, and the deposits
    // that fund it, rather than plant and inventory it does not have.
    const isLender = present('earning_assets') && num('earning_assets') > 0;

    // A report written before these columns existed has nothing to show; leave the panel hidden.
    const panel = document.getElementById('financial-statements-panel');
    if (panel) panel.classList.toggle('hidden', !hasCashFlow);
    if (!hasCashFlow) return;

    const sheetColumn = document.getElementById('balance-sheet-column');
    if (sheetColumn) sheetColumn.classList.toggle('hidden', !hasAssetSide);
    const sheetNote = document.getElementById('balance-sheet-unavailable');
    if (sheetNote) sheetNote.classList.toggle('hidden', hasAssetSide);

    if (hasAssetSide && isLender) renderStatementRows('balance-sheet-rows', [
        { label: 'Cash & reserves', value: num('treasury'), indent: true },
        { label: 'Loans & securities', value: num('earning_assets'), indent: true },
        { label: 'Allowance for credit losses', value: -num('credit_loss_allowance'), signed: true, indent: true },
        { label: 'Goodwill', value: num('goodwill'), indent: true },
        { label: 'Right-of-use asset', value: num('lease_liability'), indent: true },
        { divider: true },
        { label: 'Total assets', value: totalAssets, total: true },
        { divider: true },
        { label: 'Customer deposits', value: num('customer_deposits'), indent: true },
        { label: 'Wholesale debt', value: num('total_debt') - num('customer_deposits'), indent: true },
        { label: 'Lease liability', value: num('lease_liability'), indent: true },
        { divider: true },
        { label: 'Total liabilities', value: num('total_liabilities'), total: true },
        { label: 'Shareholders equity', value: num('equity'), total: true },
    ]);
    else if (hasAssetSide) renderStatementRows('balance-sheet-rows', [
        { label: 'Cash & equivalents', value: num('treasury'), indent: true },
        { label: 'Receivables, net', value: num('receivables'), indent: true },
        { label: 'Inventory', value: num('inventory'), indent: true },
        { label: 'Net PP&E', value: num('net_ppe'), indent: true },
        { label: 'Construction in progress', value: num('cip'), indent: true },
        { label: 'Goodwill', value: num('goodwill'), indent: true },
        { label: 'Right-of-use asset', value: num('lease_liability'), indent: true },
        { divider: true },
        { label: 'Total assets', value: totalAssets, total: true },
        { divider: true },
        { label: 'Debt & deposits', value: num('total_debt'), indent: true },
        { label: 'Payables', value: num('payables'), indent: true },
        { label: 'Deferred tax liability', value: num('deferred_tax_liability'), indent: true },
        { label: 'Lease liability', value: num('lease_liability'), indent: true },
        { divider: true },
        { label: 'Total liabilities', value: num('total_liabilities'), total: true },
        { label: 'Shareholders equity', value: num('equity'), total: true },
    ]);

    const ageEl = document.getElementById('asset-age-badge');
    if (ageEl) {
        const age = latest.asset_age !== null && latest.asset_age !== undefined ? parseFloat(latest.asset_age) : NaN;
        ageEl.innerText = isFinite(age) ? `${(age * 100).toFixed(0)}% depreciated` : '-';
    }
    const ageLabelEl = document.getElementById('asset-age-label');
    if (ageLabelEl) ageLabelEl.innerText = isLender ? 'Reserve ratio' : 'Plant age';
    if (ageEl && isLender) {
        const reserveRatio = num('earning_assets') > 0 ? num('credit_loss_allowance') / num('earning_assets') : NaN;
        ageEl.innerText = isFinite(reserveRatio) ? `${(reserveRatio * 100).toFixed(2)}% of book` : '-';
    }

    const operatingRows = isLender ? [
        { label: 'Net income', value: num('net_income'), signed: true, indent: true },
        { label: 'Depreciation', value: num('depreciation'), indent: true },
        { label: 'Equity compensation', value: num('stock_compensation'), indent: true },
        { label: 'Provision for credit losses', value: num('credit_loss_provision'), signed: true, indent: true },
        { label: 'Goodwill impairment', value: num('goodwill_impairment'), indent: true },
    ] : [
        { label: 'Net income', value: num('net_income'), signed: true, indent: true },
        { label: 'Depreciation', value: num('depreciation'), indent: true },
        { label: 'Equity compensation', value: num('stock_compensation'), indent: true },
        { label: 'Deferred tax', value: num('deferred_tax_expense'), signed: true, indent: true },
        { label: 'Inventory writedown', value: num('inventory_write_down'), indent: true },
        { label: 'Credit loss provision', value: num('receivables_provision'), signed: true, indent: true },
        { label: 'Goodwill impairment', value: num('goodwill_impairment'), indent: true },
    ];
    const investingRows = isLender ? [
        { label: 'Net loans originated', value: -num('net_loan_originations'), signed: true, indent: true },
    ] : [];

    renderStatementRows('cash-flow-rows', [
        ...operatingRows,
        { divider: true },
        ...investingRows,
        { label: 'Operating cash flow', value: num('operating_cash_flow'), signed: true, total: true },
        { label: 'Investing cash flow', value: num('investing_cash_flow'), signed: true, total: true },
        { label: 'Financing cash flow', value: num('financing_cash_flow'), signed: true, total: true },
        { divider: true },
        { label: 'Free cash flow', value: num('free_cash_flow'), signed: true, total: true },
        { label: 'Cash taxes paid', value: num('cash_tax_paid'), indent: true },
    ]);

    const stageEl = document.getElementById('lifecycle-stage-badge');
    if (stageEl) {
        const stage = latest.lifecycle_stage;
        stageEl.innerText = stage ? stage.replace(/_/g, ' ') : '-';
    }
}

export function destroyFundamentalCharts() {
    // Charts that were never scrolled into view must not build themselves after teardown.
    resetLazyCharts();
    profitEngineChartInstance = destroyChartInstance(profitEngineChartInstance);
    revenueStreamsChartInstance = destroyChartInstance(revenueStreamsChartInstance);
    debtEquityChartInstance = destroyChartInstance(debtEquityChartInstance);
    creditHealthChartInstance = destroyChartInstance(creditHealthChartInstance);
    capitalEfficiencyChartInstance = destroyChartInstance(capitalEfficiencyChartInstance);
    capitalReturnChartInstance = destroyChartInstance(capitalReturnChartInstance);
    payoutRatioChartInstance = destroyChartInstance(payoutRatioChartInstance);
    regulatoryRatiosChartInstance = destroyChartInstance(regulatoryRatiosChartInstance);
    valuationMultiplesChartInstance = destroyChartInstance(valuationMultiplesChartInstance);
    shareholderValueChartInstance = destroyChartInstance(shareholderValueChartInstance);
    cashFlowSummaryChartInstance = destroyChartInstance(cashFlowSummaryChartInstance);
    netInterestEngineChartInstance = destroyChartInstance(netInterestEngineChartInstance);
    insuranceDualEngineChartInstance = destroyChartInstance(insuranceDualEngineChartInstance);
    reitCoverageChartInstance = destroyChartInstance(reitCoverageChartInstance);
    reinvestmentIntensityChartInstance = destroyChartInstance(reinvestmentIntensityChartInstance);
    cyclicalDynamicsChartInstance = destroyChartInstance(cyclicalDynamicsChartInstance);
}
