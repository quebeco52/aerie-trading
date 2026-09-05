<?php

namespace App\Service\Macro\Recorder;

use App\DTO\MacroStateDTO;
use Doctrine\DBAL\Connection;

/**
 * Persists macroeconomic state vector snapshots into the historical macro_report database table.
 */
class MacroSnapshotRecorder
{
    /**
     * Persists an immutable historical econometric snapshot to the database.
     *
     * @param MacroStateDTO $macroState State snapshot to record.
     * @param Connection    $conn       Database connection.
     */
    public function recordSnapshot(MacroStateDTO $macroState, Connection $conn): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn->executeStatement(
            "INSERT INTO macro_report (recorded_at, inflation, inflation_ema, output_gap, output_gap_ema, policy_rate, policy_rate_ema, target_rate, yield2y, yield2y_ema, yield5y, yield5y_ema, yield10y, yield10y_ema, yield30y, yield30y_ema, corporate_tax_rate, equity_risk_premium, nominal_gdp_index, market_volatility, macro_credit_spread, macro_credit_spread_ema, unemployment_rate, unemployment_rate_ema, energy_price_index, energy_price_index_ema, consumer_sentiment_index, consumer_sentiment_index_ema, exchange_rate_index, exchange_rate_index_ema, industrial_metals_index, industrial_metals_index_ema, government_spending_index, government_spending_index_ema, commercial_property_index, commercial_property_index_ema, residential_property_index, residential_property_index_ema, retail_default_rate, retail_default_rate_ema, agricultural_commodity_index, agricultural_commodity_index_ema, freight_rate_index, freight_rate_index_ema, capital_stock_overhang, capital_stock_overhang_ema, interbank_liquidity_spread, interbank_liquidity_spread_ema, total_factor_productivity_index, total_factor_productivity_index_ema, job_vacancies_rate, job_vacancies_rate_ema, labor_tightness, labor_tightness_ema, wage_growth, wage_growth_ema, natural_rate, natural_rate_ema, term_premium10y, term_premium10y_ema, risk_neutral10y, risk_neutral10y_ema, balance_sheet_intensity, tips_breakeven, tips_breakeven_ema, ns_curvature2, nairu, nairu_ema, sovereign_debt_to_gdp, sovereign_debt_to_gdp_ema, financial_conditions_index, financial_conditions_index_ema, agri_cost_push_lag, supercore_inflation_ema, core_goods_inflation_ema, cumulative_inflation_gap_ema, high_yield_credit_spread_ema, inventory_stock_gap_ema, energy_inventory_index_ema) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $now,
                $macroState->inflation,
                $macroState->inflationEma,
                $macroState->outputGap,
                $macroState->outputGapEma,
                $macroState->policyRate,
                $macroState->policyRateEma,
                $macroState->targetRate,
                $macroState->yield2y,
                $macroState->yield2yEma,
                $macroState->yield5y,
                $macroState->yield5yEma,
                $macroState->yield10y,
                $macroState->yield10yEma,
                $macroState->yield30y,
                $macroState->yield30yEma,
                $macroState->corporateTaxRate,
                $macroState->equityRiskPremium,
                $macroState->nominalGdpIndex,
                $macroState->marketVolatility,
                $macroState->macroCreditSpread,
                $macroState->macroCreditSpreadEma,
                $macroState->unemploymentRate,
                $macroState->unemploymentRateEma,
                $macroState->energyPriceIndex,
                $macroState->energyPriceIndexEma,
                $macroState->consumerSentimentIndex,
                $macroState->consumerSentimentIndexEma,
                $macroState->exchangeRateIndex,
                $macroState->exchangeRateIndexEma,
                $macroState->industrialMetalsIndex,
                $macroState->industrialMetalsIndexEma,
                $macroState->governmentSpendingIndex,
                $macroState->governmentSpendingIndexEma,
                $macroState->commercialPropertyIndex,
                $macroState->commercialPropertyIndexEma,
                $macroState->residentialPropertyIndex,
                $macroState->residentialPropertyIndexEma,
                $macroState->retailDefaultRate,
                $macroState->retailDefaultRateEma,
                $macroState->agriculturalCommodityIndex,
                $macroState->agriculturalCommodityIndexEma,
                $macroState->freightRateIndex,
                $macroState->freightRateIndexEma,
                $macroState->capitalStockOverhang,
                $macroState->capitalStockOverhangEma,
                $macroState->interbankLiquiditySpread,
                $macroState->interbankLiquiditySpreadEma,
                $macroState->totalFactorProductivityIndex,
                $macroState->totalFactorProductivityIndexEma,
                $macroState->jobVacanciesRate,
                $macroState->jobVacanciesRateEma,
                $macroState->laborTightness,
                $macroState->laborTightnessEma,
                $macroState->wageGrowth,
                $macroState->wageGrowthEma,
                $macroState->naturalRate,
                $macroState->naturalRateEma,
                $macroState->termPremium10y,
                $macroState->termPremium10yEma,
                $macroState->riskNeutral10y,
                $macroState->riskNeutral10yEma,
                $macroState->balanceSheetIntensity,
                $macroState->tipsBreakeven,
                $macroState->tipsBreakevenEma,
                $macroState->nsCurvature2,
                $macroState->nairu,
                $macroState->nairuEma,
                $macroState->sovereignDebtToGdp,
                $macroState->sovereignDebtToGdpEma,
                $macroState->financialConditionsIndex,
                $macroState->financialConditionsIndexEma,
                $macroState->agriCostPushLag,
                $macroState->supercoreInflationEma,
                $macroState->coreGoodsInflationEma,
                $macroState->cumulativeInflationGapEma,
                $macroState->highYieldCreditSpreadEma,
                $macroState->inventoryStockGapEma,
                $macroState->energyInventoryIndexEma,
            ]
        );
    }
}
