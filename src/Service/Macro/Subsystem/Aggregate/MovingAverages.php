<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem\Aggregate;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\TimeSeries;

/**
 * The macro state's smoothed readings: each indicator's quarter-horizon EMA, the slower measured trends its deviations
 * are read against, and the output gap at the lags business models read it at.
 */
final class MovingAverages
{
    // --- Continuous EMA Indicator Smoothing Horizons ---
    /** Standard quarterly macro indicator EMA smoothing horizon. */
    public const STANDARD_EMA_HORIZON_YEARS = 0.25;

    // --- Measured Trends ---
    /**
     * Years over which a valuation level stops being news and becomes the household's normal (Carroll et al. slow
     * adjustment).
     */
    public const EQUITY_WEALTH_TREND_HORIZON_YEARS = 3.0;
    /**
     * The same horizon for houses, longer because housing wealth is revalued by sales that are years apart (Carroll,
     * Otsuka & Slacalek 2011); a house price cycle runs about twice this, so the cycle still reads as deviation.
     */
    public const RESIDENTIAL_WEALTH_TREND_HORIZON_YEARS = 5.0;
    /**
     * Office and retail values over the same horizon: their cycle runs about a decade too, so it still reads as
     * deviation.
     */
    public const COMMERCIAL_PROPERTY_TREND_YEARS = self::RESIDENTIAL_WEALTH_TREND_HORIZON_YEARS;
    /**
     * Time constant (years) of the default rates' measured trends: a decade, past the credit cycle their readers
     * answer, as the credit spread's.
     */
    public const DEFAULT_RATE_TREND_YEARS = CreditFiscalSubsystem::CREDIT_SPREAD_TREND_YEARS;
    /** Time constant (years) of the HY and interbank spreads' measured trends: the IG spread's decade. */
    public const SPREAD_TREND_YEARS = CreditFiscalSubsystem::CREDIT_SPREAD_TREND_YEARS;
    /**
     * The same decade for the recession probability's trend, the average a reserving committee reads the outlook
     * against.
     */
    public const RECESSION_PROBABILITY_TREND_YEARS = CreditFiscalSubsystem::CREDIT_SPREAD_TREND_YEARS;
    /**
     * Horizon of the real exchange rate's own normal, well beyond the ~12y swing the UIP differential and the sovereign
     * risk discount drive: a first-order lag this long follows under a tenth of that swing, so PPP deviations (Rogoff
     * 1996: 3-5y half-life) survive intact while a standing level does not.
     */
    public const EXCHANGE_RATE_TREND_HORIZON_YEARS = 20.0;
    /** Horizon (years) of the excess bond premium's trend the demand drag reads: a decade, past a credit cycle. */
    public const CREDIT_PREMIUM_TREND_YEARS = 10.0;
    /**
     * Horizon (years) of the energy and freight trends the supply drags read: a decade, past the few-year swings that
     * are shocks.
     */
    public const SUPPLY_COST_TREND_YEARS = 10.0;

    // --- Demand Transmission Lags ---
    /**
     * The output gap as it reaches order books, one first-order lag of output_gap_ema per delay a business model can
     * declare (DEMAND_LAG_YEARS): field => mean lag in years.
     */
    public const DEMAND_TRANSMISSION_LAGS = [
        'outputGapLag3m' => 0.25,
        'outputGapLag6m' => 0.50,
        'outputGapLag9m' => 0.75,
        'outputGapLag12m' => 1.00,
        'outputGapLag15m' => 1.25,
        'outputGapLag18m' => 1.50,
    ];

    /**
     * Continuous Exponential Moving Average (EMA) Filter for Macroeconomic State Variables.
     *
     * Updates exponential distributed lags representing institutional memory and smoothed
     * trend expectations across rates, yields, spreads, indices, and real activity.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public static function updateExponentialMovingAverages(MacroState $state, float $dt): void
    {
        $emaWeight = TimeSeries::smoothingWeight($dt, self::STANDARD_EMA_HORIZON_YEARS);
        $commodityTrendWeight = TimeSeries::smoothingWeight($dt, MacroConstants::COMMODITY_TREND_HORIZON_YEARS);

        $state->outputGapEma += $emaWeight * ($state->outputGap - $state->outputGapEma);
        foreach (self::DEMAND_TRANSMISSION_LAGS as $field => $lagYears) {
            $state->$field = TimeSeries::calculateDistributedLag($state->$field, $state->outputGapEma, $dt, $lagYears);
        }
        $expectedSales = $state->domesticDemandGapEma;
        $state->domesticDemandGapEma += $emaWeight * (OutputGap::domesticDemandGap($state) - $state->domesticDemandGapEma);
        // Lovell (1961): the stock firms want follows expected sales. The stock held does not move with it, so the
        // overhang takes the shift and is worked off as investment
        // (InventoryAndTiming::stepInventoryInvestment()).
        $state->inventoryStockGap -= $state->domesticDemandGapEma - $expectedSales;
        $state->policyRateEma += $emaWeight * ($state->policyRate - $state->policyRateEma);
        $state->inflationEma += $emaWeight * ($state->inflation - $state->inflationEma);
        $state->tipsBreakevenEma += $emaWeight * ($state->tipsBreakeven - $state->tipsBreakevenEma);
        $state->tipsBreakevenQuotedEma += $emaWeight * ($state->tipsBreakevenQuoted - $state->tipsBreakevenQuotedEma);
        $state->nsSlopeEma += $emaWeight * ($state->nsSlope - $state->nsSlopeEma);
        // Lucas (1978) asset pricing: initialises equity market cap EMA on first reported observation.
        if ($state->equityMarketCap > 0.0) {
            $state->equityMarketCapEma = $state->equityMarketCapEma > 0.0
                ? $state->equityMarketCapEma + ($emaWeight * ($state->equityMarketCap - $state->equityMarketCapEma))
                : $state->equityMarketCap;
        }

        // Lettau & Ludvigson (2001) cay model: multi-year trend EMA for equity wealth effect.
        $state->equityWealthRatio = DomesticDemand::equityWealthRatio($state);
        if ($state->equityWealthRatio > 0.0) {
            $state->equityWealthTrend = $state->equityWealthTrend > 0.0
                ? TimeSeries::calculateDistributedLag($state->equityWealthTrend, $state->equityWealthRatio, $dt, self::EQUITY_WEALTH_TREND_HORIZON_YEARS)
                : $state->equityWealthRatio;
        }

        $state->naturalRateEma += $emaWeight * ($state->naturalRate - $state->naturalRateEma);
        $state->jobVacanciesRateEma += $emaWeight * ($state->jobVacanciesRate - $state->jobVacanciesRateEma);
        $state->laborTightnessEma += $emaWeight * ($state->laborTightness - $state->laborTightnessEma);
        $state->wageGrowthEma += $emaWeight * ($state->wageGrowth - $state->wageGrowthEma);

        $state->yield2yEma += $emaWeight * ($state->yield2y - $state->yield2yEma);
        $state->yield5yEma += $emaWeight * ($state->yield5y - $state->yield5yEma);
        $state->yield10yEma += $emaWeight * ($state->yield10y - $state->yield10yEma);
        $state->yield30yEma += $emaWeight * ($state->yield30y - $state->yield30yEma);

        $state->termPremium10yEma += $emaWeight * ($state->termPremium10y - $state->termPremium10yEma);
        $state->riskNeutral10yEma += $emaWeight * ($state->riskNeutral10y - $state->riskNeutral10yEma);

        $state->marketVolatilityEma += $emaWeight * ($state->marketVolatility - $state->marketVolatilityEma);
        $state->macroCreditSpreadEma += $emaWeight * ($state->macroCreditSpread - $state->macroCreditSpreadEma);
        $state->unemploymentRateEma += $emaWeight * ($state->unemploymentRate - $state->unemploymentRateEma);
        $state->energyPriceIndexEma += $emaWeight * ($state->energyPriceIndex - $state->energyPriceIndexEma);
        $state->energyPriceIndexTrend = $state->energyPriceIndexTrend > 0.0
            ? $state->energyPriceIndexTrend + ($commodityTrendWeight * ($state->energyPriceIndex - $state->energyPriceIndexTrend))
            : $state->energyPriceIndex;
        $supplyCostTrendWeight = TimeSeries::smoothingWeight($dt, self::SUPPLY_COST_TREND_YEARS);
        $state->energyPriceIndexDecadeTrend += $supplyCostTrendWeight * ($state->energyPriceIndexEma - $state->energyPriceIndexDecadeTrend);
        $state->consumerSentimentIndexEma += $emaWeight * ($state->consumerSentimentIndex - $state->consumerSentimentIndexEma);
        $state->exchangeRateIndexEma += $emaWeight * ($state->exchangeRateIndex - $state->exchangeRateIndexEma);
        $state->exchangeRateTrend = TimeSeries::calculateDistributedLag($state->exchangeRateTrend, $state->exchangeRateIndexEma, $dt, self::EXCHANGE_RATE_TREND_HORIZON_YEARS);
        $state->industrialMetalsIndexEma += $emaWeight * ($state->industrialMetalsIndex - $state->industrialMetalsIndexEma);
        $state->industrialMetalsIndexTrend = $state->industrialMetalsIndexTrend > 0.0
            ? $state->industrialMetalsIndexTrend + ($commodityTrendWeight * ($state->industrialMetalsIndex - $state->industrialMetalsIndexTrend))
            : $state->industrialMetalsIndex;
        $state->governmentSpendingIndexEma += $emaWeight * ($state->governmentSpendingIndex - $state->governmentSpendingIndexEma);
        $state->governmentSpendingIndexTrend = TimeSeries::calculateDistributedLag($state->governmentSpendingIndexTrend, $state->governmentSpendingIndexEma, $dt, CreditFiscalSubsystem::GOVT_SPENDING_TREND_YEARS);
        $state->alliedDefenseSpendingIndexEma += $emaWeight * ($state->alliedDefenseSpendingIndex - $state->alliedDefenseSpendingIndexEma);
        $state->retailDefaultRateEma += $emaWeight * ($state->retailDefaultRate - $state->retailDefaultRateEma);
        $defaultTrendWeight = TimeSeries::smoothingWeight($dt, self::DEFAULT_RATE_TREND_YEARS);
        $state->retailDefaultRateTrend += $defaultTrendWeight * ($state->retailDefaultRateEma - $state->retailDefaultRateTrend);
        $state->agriculturalCommodityIndexEma += $emaWeight * ($state->agriculturalCommodityIndex - $state->agriculturalCommodityIndexEma);
        $state->agriculturalCommodityIndexTrend = $state->agriculturalCommodityIndexTrend > 0.0
            ? $state->agriculturalCommodityIndexTrend + ($commodityTrendWeight * ($state->agriculturalCommodityIndex - $state->agriculturalCommodityIndexTrend))
            : $state->agriculturalCommodityIndex;
        $state->freightRateIndexEma += $emaWeight * ($state->freightRateIndex - $state->freightRateIndexEma);
        $state->freightRateIndexDecadeTrend += $supplyCostTrendWeight * ($state->freightRateIndexEma - $state->freightRateIndexDecadeTrend);
        $state->excessBondPremiumTrend = TimeSeries::calculateDistributedLag($state->excessBondPremiumTrend, $state->excessBondPremium, $dt, self::CREDIT_PREMIUM_TREND_YEARS);
        $state->interbankLiquiditySpreadEma += $emaWeight * ($state->interbankLiquiditySpread - $state->interbankLiquiditySpreadEma);
        $spreadTrendWeight = TimeSeries::smoothingWeight($dt, self::SPREAD_TREND_YEARS);
        $state->interbankLiquiditySpreadTrend += $spreadTrendWeight * ($state->interbankLiquiditySpreadEma - $state->interbankLiquiditySpreadTrend);
        $state->totalFactorProductivityIndexEma += $emaWeight * ($state->totalFactorProductivityIndex - $state->totalFactorProductivityIndexEma);
        $state->capitalStockOverhangEma += $emaWeight * ($state->capitalStockOverhang - $state->capitalStockOverhangEma);
        $state->residentialPropertyIndexEma += $emaWeight * ($state->residentialPropertyIndex - $state->residentialPropertyIndexEma);
        $state->residentialWealthTrend = TimeSeries::calculateDistributedLag($state->residentialWealthTrend, $state->residentialPropertyIndexEma, $dt, self::RESIDENTIAL_WEALTH_TREND_HORIZON_YEARS);
        $state->commercialPropertyIndexEma += $emaWeight * ($state->commercialPropertyIndex - $state->commercialPropertyIndexEma);
        $state->commercialPropertyIndexTrend = TimeSeries::calculateDistributedLag($state->commercialPropertyIndexTrend, $state->commercialPropertyIndexEma, $dt, self::COMMERCIAL_PROPERTY_TREND_YEARS);
        $state->nairuEma += $emaWeight * ($state->nairu - $state->nairuEma);
        $state->sovereignDebtToGdpEma += $emaWeight * ($state->sovereignDebtToGdp - $state->sovereignDebtToGdpEma);
        $state->sovereignNetDebtToGdpEma += $emaWeight * ($state->sovereignNetDebtToGdp - $state->sovereignNetDebtToGdpEma);
        $state->sovereignNetFinancialLiabilitiesToGdpEma += $emaWeight * ($state->sovereignNetFinancialLiabilitiesToGdp - $state->sovereignNetFinancialLiabilitiesToGdpEma);
        $state->financialConditionsIndexEma += $emaWeight * ($state->financialConditionsIndex - $state->financialConditionsIndexEma);

        $state->supercoreInflationEma += $emaWeight * ($state->supercoreInflation - $state->supercoreInflationEma);
        $state->coreGoodsInflationEma += $emaWeight * ($state->coreGoodsInflation - $state->coreGoodsInflationEma);
        $state->highYieldCreditSpreadEma += $emaWeight * ($state->highYieldCreditSpread - $state->highYieldCreditSpreadEma);
        $state->highYieldCreditSpreadTrend += $spreadTrendWeight * ($state->highYieldCreditSpreadEma - $state->highYieldCreditSpreadTrend);
        $state->inventoryStockGapEma += $emaWeight * ($state->inventoryStockGap - $state->inventoryStockGapEma);
        $state->energyInventoryIndexEma += $emaWeight * ($state->energyInventoryIndex - $state->energyInventoryIndexEma);
        $state->capacityUtilizationRateEma += $emaWeight * ($state->capacityUtilizationRate - $state->capacityUtilizationRateEma);
        $state->recessionProbabilityEma += $emaWeight * ($state->recessionProbability - $state->recessionProbabilityEma);
        $state->recessionProbabilityTrend = TimeSeries::calculateDistributedLag($state->recessionProbabilityTrend, $state->recessionProbabilityEma, $dt, self::RECESSION_PROBABILITY_TREND_YEARS);
        $state->corporateDefaultRateEma += $emaWeight * ($state->corporateDefaultRate - $state->corporateDefaultRateEma);
        $state->corporateDefaultRateTrend += $defaultTrendWeight * ($state->corporateDefaultRateEma - $state->corporateDefaultRateTrend);
        $state->sloosTighteningIndexEma += $emaWeight * ($state->sloosTighteningIndex - $state->sloosTighteningIndexEma);
        $state->supplyChainPressureIndexEma += $emaWeight * ($state->supplyChainPressureIndex - $state->supplyChainPressureIndexEma);
        $state->supplierDeliveryTimesEma += $emaWeight * ($state->supplierDeliveryTimes - $state->supplierDeliveryTimesEma);
        $state->refiningCrackSpreadEma += $emaWeight * ($state->refiningCrackSpread - $state->refiningCrackSpreadEma);
        $state->dealActivityIndexEma += $emaWeight * ($state->dealActivityIndex - $state->dealActivityIndexEma);

        $state->manufacturingPmiEma += $emaWeight * ($state->manufacturingPmi - $state->manufacturingPmiEma);
        $state->producerPriceInflationEma += $emaWeight * ($state->producerPriceInflation - $state->producerPriceInflationEma);
        $state->tradeBalanceToGdpEma += $emaWeight * ($state->tradeBalanceToGdp - $state->tradeBalanceToGdpEma);
        $state->housingStartsIndexEma += $emaWeight * ($state->housingStartsIndex - $state->housingStartsIndexEma);
        $state->moneySupplyGrowthEma += $emaWeight * ($state->moneySupplyGrowth - $state->moneySupplyGrowthEma);
        $state->reimbursementRateIndexEma += $emaWeight * ($state->reimbursementRateIndex - $state->reimbursementRateIndexEma);
        $state->policyUncertaintyIndexEma += $emaWeight * ($state->policyUncertaintyIndex - $state->policyUncertaintyIndexEma);
        $state->sovereignRiskSpreadEma += $emaWeight * ($state->sovereignRiskSpread - $state->sovereignRiskSpreadEma);
        $state->naturalGasPriceIndexEma += $emaWeight * ($state->naturalGasPriceIndex - $state->naturalGasPriceIndexEma);
        $state->goldPriceIndexEma += $emaWeight * ($state->goldPriceIndex - $state->goldPriceIndexEma);
        $state->wholesalePowerPriceIndexEma += $emaWeight * ($state->wholesalePowerPriceIndex - $state->wholesalePowerPriceIndexEma);
        $state->catastropheLossIndexEma += $emaWeight * ($state->catastropheLossIndex - $state->catastropheLossIndexEma);
        $state->foreignOutputGapEma += $emaWeight * ($state->foreignOutputGap - $state->foreignOutputGapEma);
        $state->householdDebtToIncomeEma += $emaWeight * ($state->householdDebtToIncome - $state->householdDebtToIncomeEma);
        $state->householdDebtServiceRatioEma += $emaWeight * ($state->householdDebtServiceRatio - $state->householdDebtServiceRatioEma);
        $state->creditToGdpGapEma += $emaWeight * ($state->creditToGdpGap - $state->creditToGdpGapEma);
        $state->foreignPolicyRateEma += $emaWeight * ($state->foreignPolicyRate - $state->foreignPolicyRateEma);
        $state->globalDemandGapEma += $emaWeight * ($state->globalDemandGap - $state->globalDemandGapEma);
        $state->moneyMarketFundShareEma += $emaWeight * ($state->moneyMarketFundShare - $state->moneyMarketFundShareEma);
    }

    /**
     * The field that carries the output gap at a given demand lag. The macro publishes the gap at a fixed set of lags
     * rather than at whatever each business model asks for, so a model declaring any other lag is a configuration
     * error.
     *
     * @param float $lagYears Mean demand lag in years, as a business model declares it.
     * @return string MacroState property name.
     * @throws \InvalidArgumentException When the lag is not one DEMAND_TRANSMISSION_LAGS publishes.
     */
    public static function demandTransmissionLagField(float $lagYears): string
    {
        $field = array_search($lagYears, self::DEMAND_TRANSMISSION_LAGS, true);
        if (!is_string($field)) {
            throw new \InvalidArgumentException(sprintf('The macro publishes no output gap lag of %s years; add one to DEMAND_TRANSMISSION_LAGS.', $lagYears));
        }

        return $field;
    }
}
