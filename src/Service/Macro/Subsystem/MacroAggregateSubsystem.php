<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;

/**
 * Models the core macroeconomic aggregate feedback loop:
 * Total Factor Productivity (TFP), Solow-Swan potential and nominal GDP,
 * Laubach-Williams dynamic natural rate (r*), Kaldor non-linear output gap cycle,
 * Hybrid New Keynesian Phillips Curve (NKPC) inflation, TIPS breakeven expectations,
 * and continuous exponential moving average (EMA) smoothing filters.
 */
class MacroAggregateSubsystem
{
    public function __construct(
        private readonly MathUtility $mathUtility
    ) {}

    /**
     * Solow-Swan (1956) & Romer (1990) Endogenous Growth with Merton (1976) Breakthrough Jumps.
     *
     * Simulates technological progress via secular drift, endogenous R&D capital deepening,
     * Brownian diffusion, and Schumpeterian general-purpose breakthrough jumps.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     * @return float Realized annual trend TFP growth rate.
     */
    public function calculateTotalFactorProductivity(MacroState $state, float $dt): float
    {
        $currentTfp = $state->totalFactorProductivityIndex ?? MacroEngine::TFP_BASELINE;

        $endogenousGrowth = $state->outputGapEma * MacroEngine::TFP_OUTPUT_GAP_SENSITIVITY;
        $trendGrowthRate = MacroEngine::TFP_DRIFT + $endogenousGrowth;
        $clampedTrendGrowthRate = max(MacroEngine::MIN_TFP_GROWTH_RATE, min(MacroEngine::MAX_TFP_GROWTH_RATE, $trendGrowthRate));

        $dW = $this->mathUtility->generateStandardNormal();
        $innovationDiffusion = MacroEngine::TFP_VOLATILITY * sqrt($dt) * $dW;

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: MacroEngine::TFP_JUMP_PROBABILITY,
            jumpMean: MacroEngine::TFP_JUMP_MEAN,
            jumpVol: MacroEngine::TFP_JUMP_VOL,
            dt: $dt
        );

        $jumpExponent = (float) ($jumpData['exponent'] ?? 0.0);
        $logIncrement = ($clampedTrendGrowthRate * $dt) + $innovationDiffusion + $jumpExponent;
        $clampedLogIncrement = max(MacroEngine::MIN_TFP_GROWTH_RATE * $dt, min(MacroEngine::MAX_TFP_GROWTH_RATE * $dt, $logIncrement));

        $state->totalFactorProductivityIndex = max(1.0, $currentTfp * exp($clampedLogIncrement));

        return $clampedTrendGrowthRate;
    }

    /**
     * Laubach & Williams (2003) Dynamic Natural Rate of Interest (r*).
     *
     * Drifts equilibrium real rate r* tracking secular Total Factor Productivity (TFP)
     * growth deviations from long-term trend, via continuous Ornstein-Uhlenbeck adjustment.
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $tfpGrowthRate Realized annual trend TFP growth rate.
     * @param float      $dt            Time increment in years.
     */
    public function calculateNaturalRate(MacroState $state, float $tfpGrowthRate, float $dt): void
    {
        // Holston-Laubach-Williams (2017): Natural rate r* tracks secular TFP trend drift and cyclical investment demand
        $tfpEffect = MacroEngine::NATURAL_RATE_TFP_SENSITIVITY * ($tfpGrowthRate - MacroEngine::TFP_DRIFT);
        $demandEffect = MacroEngine::NATURAL_RATE_OUTPUT_GAP_SENSITIVITY * $state->outputGapEma;
        $targetNaturalRate = MacroEngine::BASE_NATURAL_RATE + $tfpEffect + $demandEffect;
        $targetNaturalRate = max(MacroEngine::MIN_NATURAL_RATE, min(MacroEngine::MAX_NATURAL_RATE, $targetNaturalRate));

        $state->naturalRate += MacroEngine::NATURAL_RATE_ADJUSTMENT_SPEED * ($targetNaturalRate - $state->naturalRate) * $dt;
    }

    /**
     * Kaldor (1940) Non-Linear Business Cycle with Modigliani Wealth Effect & Marshall-Lerner FX Drag.
     *
     * Solves continuous macroeconomic aggregate demand dynamics:
     *   dy = [Momentum - CubicCapacity - RealRateDrag + FiscalStimulus - CapitalOverhang + WealthEffect - FxDrag] * dt + sigma * dW
     *
     * @param MacroState $state            Current macroeconomic state.
     * @param float      $yield5y          5-Year Treasury yield benchmark for business borrowing.
     * @param float      $naturalRate      Dynamic natural real rate of interest (r*).
     * @param float      $dt               Time increment in years.
     * @param float      $stressMultiplier Non-linear crisis volatility multiplier.
     * @return float Updated cyclical output gap bounded between -12% and +10%.
     */
    public function calculateOutputGap(MacroState $state, float $yield5y, float $naturalRate, float $dt, float $stressMultiplier): float
    {
        $y = $state->outputGap;
        $outZ = $this->mathUtility->generateStandardNormal();

        $borrowingPolicy = (MacroEngine::BORROWING_POLICY_WEIGHT * $state->policyRate)
            + (MacroEngine::BORROWING_YIELD5Y_WEIGHT * $yield5y);
        $realRate = $borrowingPolicy - $state->inflation;

        $neutral5yDurationScale = MathUtility::calculateTermPremiumDurationScale(5.0, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        $neutral5yYield = $naturalRate + MacroEngine::TARGET_INFLATION + (MacroEngine::NS_BASE_TERM_PREMIUM * $neutral5yDurationScale);

        $neutralBorrowingPolicy = (MacroEngine::BORROWING_POLICY_WEIGHT * ($naturalRate + MacroEngine::TARGET_INFLATION))
            + (MacroEngine::BORROWING_YIELD5Y_WEIGHT * $neutral5yYield);
        $neutralRealRate = $neutralBorrowingPolicy - MacroEngine::TARGET_INFLATION;

        // Pure risk-free real monetary policy transmission stance (Curdia & Woodford 2010 Eq. 14)
        $monetaryDrag = MacroEngine::KALDOR_MONETARY_DRAG * ($realRate - $neutralRealRate);

        // Bernanke, Gertler, & Gilchrist (1999) Financial Accelerator & Wholesale Credit Friction
        $excessCreditSpread = max(-MacroEngine::BASE_CREDIT_SPREAD * 0.5, $state->macroCreditSpreadEma - MacroEngine::BASE_CREDIT_SPREAD);
        $excessInterbankSpread = max(-MacroEngine::INTERBANK_BASELINE_SPREAD * 0.5, $state->interbankLiquiditySpreadEma - MacroEngine::INTERBANK_BASELINE_SPREAD);
        $creditFrictionDrag = MacroEngine::KALDOR_CREDIT_FRICTION_DRAG * ($excessCreditSpread + $excessInterbankSpread);

        $momentum = MacroEngine::KALDOR_MOMENTUM * $y;
        $cubicConstraint = MacroEngine::KALDOR_CAPACITY * pow($y, 3);
        $fiscalStimulus = MacroEngine::KALDOR_FISCAL_MULTIPLIER * (MacroEngine::TARGET_CORPORATE_TAX_RATE - $state->corporateTaxRate);
        $capitalDrag = MacroEngine::KALDOR_CAPITAL_DRAG * $state->capitalStockOverhang;

        $housingWealthEffect = (($state->residentialPropertyIndexEma / MacroEngine::RESIDENTIAL_BASELINE) - 1.0) * MacroEngine::KALDOR_WEALTH_EFFECT_ELASTICITY;
        $fxShift = ($state->exchangeRateIndexEma / MacroEngine::EXCHANGE_RATE_BASELINE) - 1.0;
        $netExportDrag = MacroEngine::KALDOR_FX_ELASTICITY * $fxShift;

        // Symmetric supply shocks (Bruno-Sachs 1985 & Blanchard-Gali 2007): below baseline is cost dividend
        $energyShock = $state->energyPriceShock != 0.0
            ? $state->energyPriceShock
            : (($state->energyPriceIndexEma > 0.0 ? $state->energyPriceIndexEma : $state->energyPriceIndex) - MacroEngine::ENERGY_BASELINE);
        $energySupplyShift = $energyShock / MacroEngine::ENERGY_BASELINE;
        $energySupplyDrag = $energySupplyShift * MacroEngine::KALDOR_ENERGY_SUPPLY_DRAG;

        $freightRate = $state->freightRateIndexEma > 0.0 ? $state->freightRateIndexEma : $state->freightRateIndex;
        $freightSupplyShift = ($freightRate - MacroEngine::FREIGHT_BASELINE) / MacroEngine::FREIGHT_BASELINE;
        $freightSupplyDrag = $freightSupplyShift * MacroEngine::KALDOR_FREIGHT_SUPPLY_DRAG;

        // Metzler (1941) & Blinder (1982) Inventory Investment Cycle Step
        $state->inventoryStockGap = $this->mathUtility->calculateInventoryCycleStep(
            currentInventoryGap: $state->inventoryStockGap,
            outputGap: $y,
            outputGapEma: $state->outputGapEma,
            speed: MacroEngine::INVENTORY_ADJUSTMENT_SPEED,
            surpriseSens: MacroEngine::INVENTORY_SURPRISE_SENSITIVITY,
            dt: $dt,
            cyclicalSens: MacroEngine::INVENTORY_CYCLICAL_DEMAND_SENSITIVITY
        );
        $inventoryDrag = MacroEngine::METZLER_INVENTORY_DRAG * $state->inventoryStockGap;

        $drift = ($momentum
            - $cubicConstraint
            - $monetaryDrag
            - $creditFrictionDrag
            + $fiscalStimulus
            - $capitalDrag
            - $inventoryDrag
            + $housingWealthEffect
            - $netExportDrag
            - $energySupplyDrag
            - $freightSupplyDrag) * $dt;
        $volatility = MacroEngine::OUTPUT_GAP_DIFFUSION_SIGMA * $stressMultiplier * sqrt($dt) * $outZ;

        $newGap = $y + $drift + $volatility;
        return max(-0.12, min(0.10, $newGap));
    }

    /**
     * Hybrid New Keynesian Phillips Curve with Benigno & Eggertsson (2023) Convexity
     * and Shapiro (2022) Sectoral Disaggregation (Supercore Services vs Core Goods vs Commodity).
     *
     * Models non-linear capacity-constrained inflation where output gaps approaching capacity
     * accelerate inflation non-linearly, while downward nominal wage/price rigidity flattens the curve during recessions.
     * Decomposes inflation into wage-push supercore services, freight/materials core goods, and energy/food pass-through.
     *
     * @param MacroState $state            Current macroeconomic state.
     * @param float      $targetInflation Central bank inflation target.
     * @param float      $stressMultiplier Non-linear crisis volatility multiplier.
     * @param float      $dt               Time increment in years.
     * @return float Updated headline inflation rate.
     */
    public function calculateInflation(MacroState $state, float $targetInflation, float $stressMultiplier, float $dt): float
    {
        $infZ = $this->mathUtility->generateStandardNormal();

        // 1. Adaptive Inflation Expectations Unanchoring
        $anchorSlip = ($state->inflationEma - $targetInflation) * MacroEngine::INFLATION_ADAPTIVE_EXPECTATIONS_WEIGHT;

        // 2. Benigno & Eggertsson (2023): Non-linear convex demand-pull curve
        $convexDemandPressure = $this->mathUtility->calculateConvexPhillipsCurve(
            outputGap: $state->outputGap,
            maxCapacity: MacroEngine::PHILLIPS_MAX_CAPACITY,
            kappa: MacroEngine::PHILLIPS_CONVEX_KAPPA,
            downwardRigidityFactor: MacroEngine::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR
        );

        // 3. Shapiro (2022) Sector 1: Supercore Services (Labor / Wage-Push Channel)
        $wageGap = $state->wageGrowth - (MacroEngine::TFP_DRIFT + $targetInflation);
        $wageCostPush = $wageGap * MacroEngine::SUPERCORE_WAGE_TRANSMISSION;
        $targetSupercore = $targetInflation + $anchorSlip + $convexDemandPressure + $wageCostPush;

        // 4. Shapiro (2022) Sector 2: Core Goods (Supply Chain / Freight / Materials)
        $freightShift = ($state->freightRateIndexEma / MacroEngine::FREIGHT_BASELINE) - 1.0;
        $metalsShift = ($state->industrialMetalsIndexEma / MacroEngine::METALS_BASELINE) - 1.0;
        $gscpiFriction = max(-0.01, $state->supplyChainPressureIndexEma * MacroEngine::CORE_GOODS_GSCPI_SENSITIVITY);
        $goodsSupplyFriction = ($freightShift * MacroEngine::CORE_GOODS_FREIGHT_SENSITIVITY) + ($metalsShift * MacroEngine::CORE_GOODS_METALS_SENSITIVITY) + $gscpiFriction;
        $targetCoreGoods = $targetInflation + $anchorSlip + (MacroEngine::CORE_GOODS_DEMAND_SENSITIVITY * $convexDemandPressure) + $goodsSupplyFriction;

        // Dynamic AR(1) state updating for sticky core baskets
        $reversionWeight = 1.0 - exp(-MacroEngine::INFLATION_MEAN_REVERSION * $dt);
        $state->supercoreInflation += $reversionWeight * ($targetSupercore - $state->supercoreInflation);
        $state->supercoreInflation = max(-0.01, min(0.20, $state->supercoreInflation));

        $state->coreGoodsInflation += $reversionWeight * ($targetCoreGoods - $state->coreGoodsInflation);
        $state->coreGoodsInflation = max(-0.02, min(0.20, $state->coreGoodsInflation));

        // 5. Shapiro (2022) Sector 3: Commodity Pass-Through (Energy & Agriculture)
        $rawEnergyCostPush = ($state->energyPriceShock / MacroEngine::ENERGY_BASELINE) * MacroEngine::ENERGY_COST_PUSH_TRANSMISSION;
        $state->energyCostPushLag = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->energyCostPushLag,
            targetValue: $rawEnergyCostPush,
            dt: $dt,
            lagTimeConstant: MacroEngine::ENERGY_COST_PUSH_LAG_YEARS
        );

        $rawAgriCostPush = max(0.0, ($state->agriculturalCommodityIndex / MacroEngine::AGRI_BASELINE) - 1.0) * MacroEngine::AGRI_COST_PUSH_TRANSMISSION;
        $state->agriCostPushLag = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->agriCostPushLag,
            targetValue: $rawAgriCostPush,
            dt: $dt,
            lagTimeConstant: MacroEngine::AGRI_COST_PUSH_LAG_YEARS
        );

        // Scaled to commodity basket weight so headline receives the full intended shock
        $commodityBasketInflation = $targetInflation + $anchorSlip
            + (($state->energyCostPushLag + $state->agriCostPushLag) / MacroEngine::INFLATION_WEIGHT_COMMODITY);

        // 6. Blended Headline Inflation (Shapiro 2022 expenditure basket aggregation)
        $blendedInflation = (MacroEngine::INFLATION_WEIGHT_SUPERCORE * $state->supercoreInflation)
            + (MacroEngine::INFLATION_WEIGHT_GOODS * $state->coreGoodsInflation)
            + (MacroEngine::INFLATION_WEIGHT_COMMODITY * $commodityBasketInflation);

        $newInflation = $blendedInflation + (MacroEngine::INFLATION_DIFFUSION_SIGMA * $stressMultiplier * sqrt($dt) * $infZ);
        return max(-0.02, min(0.25, $newInflation));
    }

    /**
     * Gurkaynak, Sack & Wright (2010) TIPS Breakeven Inflation Expectation Model.
     *
     * Derives market-implied 10Y forward inflation expectations by weighting anchored central bank
     * targets, adaptive core trends, forward Phillips curve capacity, and inflation volatility risk premium.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation Central bank inflation target.
     * @param float      $dt              Time increment in years.
     * @return float 10-Year TIPS breakeven inflation expectation.
     */
    public function calculateTipsBreakeven(MacroState $state, float $targetInflation, float $dt): float
    {
        $cyclicalForecast = $this->mathUtility->calculateConvexPhillipsCurve(
            outputGap: $state->outputGapEma,
            maxCapacity: MacroEngine::PHILLIPS_MAX_CAPACITY,
            kappa: MacroEngine::PHILLIPS_CONVEX_KAPPA,
            downwardRigidityFactor: MacroEngine::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR
        );

        // Pflueger & Viceira (2011): Inflation Risk Premium (IRP) reflects upside inflation uncertainty
        // driven by actual inflation deviations above target and cost-push supply shocks, not equity market crash panic.
        $excessInflation = max(0.0, $state->inflationEma - $targetInflation);
        $costPushStress = $state->energyCostPushLag + $state->agriCostPushLag;
        $inflationRiskPremium = ($excessInflation + $costPushStress) * MacroEngine::TIPS_INFLATION_RISK_PREMIUM_SCALE;

        $fundamentalBreakeven = (MacroEngine::TIPS_TARGET_WEIGHT * $targetInflation)
            + (MacroEngine::TIPS_TREND_WEIGHT * $state->inflationEma)
            + (MacroEngine::TIPS_CYCLICAL_WEIGHT * ($targetInflation + $cyclicalForecast))
            + $inflationRiskPremium;

        return max(-0.01, min(0.15, $fundamentalBreakeven));
    }

    /**
     * Solow-Swan (1956) Potential Output Capacity & Price Deflator Accumulation.
     *
     * Expands constant-dollar real potential GDP capacity via demographic growth and TFP,
     * accumulates the GDP price deflator via headline inflation, and computes nominal GDP.
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $dt            Time increment in years.
     * @param float|null $tfpGrowthRate Optional pre-computed TFP growth rate.
     */
    public function calculatePotentialAndNominalGdp(MacroState $state, float $dt, ?float $tfpGrowthRate = null): void
    {
        if ($tfpGrowthRate === null) {
            $tfpGrowthRate = $this->calculateTotalFactorProductivity($state, $dt);
        }

        $realPotentialGrowth = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + $tfpGrowthRate;
        $currentPotential = $state->potentialGdpIndex > 0.0 ? $state->potentialGdpIndex : 1.0;
        $state->potentialGdpIndex = max(0.10, $currentPotential * exp($realPotentialGrowth * $dt));

        $currentDeflator = $state->gdpDeflator > 0.0 ? $state->gdpDeflator : 1.0;
        $state->gdpDeflator = max(0.01, $currentDeflator * exp($state->inflation * $dt));

        $state->nominalGdpIndex = max(0.10, $state->potentialGdpIndex * (1.0 + $state->outputGap) * $state->gdpDeflator);
    }

    /**
     * Continuous Exponential Moving Average (EMA) Filter for Macroeconomic State Variables.
     *
     * Updates exponential distributed lags representing institutional memory and smoothed
     * trend expectations across rates, yields, spreads, indices, and real activity.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function updateExponentialMovingAverages(MacroState $state, float $dt): void
    {
        $emaWeight = 1.0 - exp(-$dt / MacroEngine::STANDARD_EMA_HORIZON_YEARS);

        $state->outputGapEma += $emaWeight * ($state->outputGap - $state->outputGapEma);
        $state->policyRateEma += $emaWeight * ($state->policyRate - $state->policyRateEma);
        $state->inflationEma += $emaWeight * ($state->inflation - $state->inflationEma);
        $state->tipsBreakevenEma += $emaWeight * ($state->tipsBreakeven - $state->tipsBreakevenEma);
        $state->nsSlopeEma += $emaWeight * ($state->nsSlope - $state->nsSlopeEma);

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
        $state->consumerSentimentIndexEma += $emaWeight * ($state->consumerSentimentIndex - $state->consumerSentimentIndexEma);
        $state->exchangeRateIndexEma += $emaWeight * ($state->exchangeRateIndex - $state->exchangeRateIndexEma);
        $state->industrialMetalsIndexEma += $emaWeight * ($state->industrialMetalsIndex - $state->industrialMetalsIndexEma);
        $state->governmentSpendingIndexEma += $emaWeight * ($state->governmentSpendingIndex - $state->governmentSpendingIndexEma);
        $state->retailDefaultRateEma += $emaWeight * ($state->retailDefaultRate - $state->retailDefaultRateEma);
        $state->agriculturalCommodityIndexEma += $emaWeight * ($state->agriculturalCommodityIndex - $state->agriculturalCommodityIndexEma);
        $state->freightRateIndexEma += $emaWeight * ($state->freightRateIndex - $state->freightRateIndexEma);
        $state->interbankLiquiditySpreadEma += $emaWeight * ($state->interbankLiquiditySpread - $state->interbankLiquiditySpreadEma);
        $state->totalFactorProductivityIndexEma += $emaWeight * ($state->totalFactorProductivityIndex - $state->totalFactorProductivityIndexEma);
        $state->capitalStockOverhangEma += $emaWeight * ($state->capitalStockOverhang - $state->capitalStockOverhangEma);
        $state->residentialPropertyIndexEma += $emaWeight * ($state->residentialPropertyIndex - $state->residentialPropertyIndexEma);
        $state->commercialPropertyIndexEma += $emaWeight * ($state->commercialPropertyIndex - $state->commercialPropertyIndexEma);
        $state->nairuEma += $emaWeight * ($state->nairu - $state->nairuEma);
        $state->sovereignDebtToGdpEma += $emaWeight * ($state->sovereignDebtToGdp - $state->sovereignDebtToGdpEma);
        $state->financialConditionsIndexEma += $emaWeight * ($state->financialConditionsIndex - $state->financialConditionsIndexEma);

        $state->supercoreInflationEma += $emaWeight * ($state->supercoreInflation - $state->supercoreInflationEma);
        $state->coreGoodsInflationEma += $emaWeight * ($state->coreGoodsInflation - $state->coreGoodsInflationEma);
        $state->cumulativeInflationGapEma += $emaWeight * ($state->cumulativeInflationGap - $state->cumulativeInflationGapEma);
        $state->highYieldCreditSpreadEma += $emaWeight * ($state->highYieldCreditSpread - $state->highYieldCreditSpreadEma);
        $state->inventoryStockGapEma += $emaWeight * ($state->inventoryStockGap - $state->inventoryStockGapEma);
        $state->energyInventoryIndexEma += $emaWeight * ($state->energyInventoryIndex - $state->energyInventoryIndexEma);
        $state->capacityUtilizationRateEma += $emaWeight * ($state->capacityUtilizationRate - $state->capacityUtilizationRateEma);
        $state->recessionProbabilityEma += $emaWeight * ($state->recessionProbability - $state->recessionProbabilityEma);
        $state->corporateDefaultRateEma += $emaWeight * ($state->corporateDefaultRate - $state->corporateDefaultRateEma);
        $state->sloosTighteningIndexEma += $emaWeight * ($state->sloosTighteningIndex - $state->sloosTighteningIndexEma);
        $state->supplyChainPressureIndexEma += $emaWeight * ($state->supplyChainPressureIndex - $state->supplyChainPressureIndexEma);
        $state->refiningCrackSpreadEma += $emaWeight * ($state->refiningCrackSpread - $state->refiningCrackSpreadEma);
        $state->dealActivityIndexEma += $emaWeight * ($state->dealActivityIndex - $state->dealActivityIndexEma);

        $state->manufacturingPmiEma += $emaWeight * ($state->manufacturingPmi - $state->manufacturingPmiEma);
        $state->producerPriceInflationEma += $emaWeight * ($state->producerPriceInflation - $state->producerPriceInflationEma);
        $state->tradeBalanceToGdpEma += $emaWeight * ($state->tradeBalanceToGdp - $state->tradeBalanceToGdpEma);
        $state->housingStartsIndexEma += $emaWeight * ($state->housingStartsIndex - $state->housingStartsIndexEma);
        $state->moneySupplyGrowthEma += $emaWeight * ($state->moneySupplyGrowth - $state->moneySupplyGrowthEma);
    }

    /**
     * Federal Reserve G.17 Industrial Capacity Utilization Index.
     *
     * Evaluates real aggregate physical factory, mining, and utility capacity utilization (CU_t)
     * based on macroeconomic output gap demand and capital stock overhang.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateCapacityUtilization(MacroState $state): void
    {
        $state->capacityUtilizationRate = $this->mathUtility->calculateCapacityUtilization(
            outputGap: $state->outputGap,
            capitalStockOverhang: $state->capitalStockOverhang,
            baselineCu: MacroEngine::CU_BASELINE,
            gapSensitivity: MacroEngine::CU_GAP_SENSITIVITY,
            overhangSensitivity: MacroEngine::CU_OVERHANG_SENSITIVITY
        );
    }

    /**
     * ISM / S&P Global Manufacturing Purchasing Managers' Index (PMI).
     *
     * Evaluates the headline diffusion index centered at 50.0 based on real industrial capacity utilization,
     * macroeconomic output gap momentum, Metzler inventory restocking demand, and SLOOS bank credit standards:
     *   Target = 50 + beta_CU * (CU - CU*) + beta_gap * OutputGap + beta_inv * (-InventoryGap) - beta_sloos * SLOOS
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time step in years.
     */
    public function calculateManufacturingPmi(MacroState $state, float $dt): void
    {
        $cuDeviation = $state->capacityUtilizationRate - MacroEngine::CU_BASELINE;
        $gapMomentum = $state->outputGap - $state->outputGapEma;
        $inventoryDemand = - $state->inventoryStockGap; // Shortfall stimulates orders
        $sloosStress = max(0.0, $state->sloosTighteningIndexEma);

        $drivers = [
            ['deviation' => $cuDeviation, 'sensitivity' => MacroEngine::PMI_CU_SENSITIVITY],
            ['deviation' => $gapMomentum + ($state->outputGap * 0.25), 'sensitivity' => MacroEngine::PMI_MOMENTUM_SENSITIVITY],
            ['deviation' => $inventoryDemand, 'sensitivity' => MacroEngine::PMI_INVENTORY_SENSITIVITY],
            ['deviation' => -$sloosStress, 'sensitivity' => MacroEngine::PMI_SLOOS_SENSITIVITY],
        ];

        $targetPmi = $this->mathUtility->calculateDiffusionIndex(
            baseline: MacroEngine::PMI_BASELINE,
            drivers: $drivers,
            min: MacroEngine::MIN_PMI,
            max: MacroEngine::MAX_PMI
        );

        $dW = $this->mathUtility->generateStandardNormal();
        $drift = MacroEngine::PMI_KAPPA * ($targetPmi - $state->manufacturingPmi) * $dt;
        $diffusion = MacroEngine::PMI_SIGMA * sqrt($dt) * $dW;
        $newPmi = $state->manufacturingPmi + $drift + $diffusion;

        $state->manufacturingPmi = max(MacroEngine::MIN_PMI, min(MacroEngine::MAX_PMI, $newPmi));
    }

    /**
     * Stage-of-Processing Producer Price Index (PPI) Wholesale Inflation Pipeline (Clark 1995).
     *
     * Computes wholesale factory-gate price inflation driven by primary commodity input price shocks
     * (metals, energy, agriculture), ocean freight/logistics bottlenecks (GSCPI), Unit Labor Costs (ULC),
     * and cyclical output gap demand pressure.
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $tfpGrowthRate Realized annual trend TFP growth rate.
     * @param float      $dt            Time step in years.
     */
    public function calculateProducerPriceInflation(MacroState $state, float $tfpGrowthRate, float $dt): void
    {
        $metalsShift = ($state->industrialMetalsIndex - MacroEngine::METALS_BASELINE) / MacroEngine::METALS_BASELINE;
        $energyShift = ($state->energyPriceIndex - MacroEngine::ENERGY_BASELINE) / MacroEngine::ENERGY_BASELINE;
        $agriShift = ($state->agriculturalCommodityIndex - MacroEngine::AGRI_BASELINE) / MacroEngine::AGRI_BASELINE;

        $unitLaborCost = $state->wageGrowth - $tfpGrowthRate;

        $weights = [
            'metals' => MacroEngine::PPI_METALS_WEIGHT,
            'energy' => MacroEngine::PPI_ENERGY_WEIGHT,
            'agri' => MacroEngine::PPI_AGRI_WEIGHT,
            'gscpi' => MacroEngine::PPI_GSCPI_SENSITIVITY,
            'ulc' => MacroEngine::PPI_ULC_WEIGHT,
            'demand' => MacroEngine::PPI_DEMAND_SENSITIVITY,
        ];

        $targetPpi = $this->mathUtility->calculateStageOfProcessingPpi(
            metalsInflation: ($metalsShift * 0.50) + MacroEngine::TARGET_INFLATION,
            energyInflation: ($energyShift * 0.40) + MacroEngine::TARGET_INFLATION,
            agriInflation: ($agriShift * 0.30) + MacroEngine::TARGET_INFLATION,
            gscpiZ: $state->supplyChainPressureIndex,
            unitLaborCost: $unitLaborCost,
            outputGap: $state->outputGap,
            weights: $weights,
            min: MacroEngine::MIN_PPI_INFLATION,
            max: MacroEngine::MAX_PPI_INFLATION
        );

        $dW = $this->mathUtility->generateStandardNormal();
        $diffusion = 0.003 * sqrt($dt) * $dW;
        $state->producerPriceInflation = max(MacroEngine::MIN_PPI_INFLATION, min(MacroEngine::MAX_PPI_INFLATION, $targetPpi + $diffusion));
    }
}
