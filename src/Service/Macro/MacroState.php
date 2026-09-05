<?php

namespace App\Service\Macro;

class MacroState
{
    public float $totalTime = 0.0;
    public float $inflation = MacroEngine::TARGET_INFLATION;
    public float $inflationEma = MacroEngine::TARGET_INFLATION;
    public float $outputGap = 0.015;
    public float $outputGapEma = 0.015;
    public float $capitalStockOverhang = 0.0;
    public float $capitalStockOverhangEma = 0.0;

    public float $unemploymentRate = 0.038;
    public float $unemploymentRateEma = 0.038;
    public float $jobVacanciesRate = 0.045;
    public float $jobVacanciesRateEma = 0.045;
    public float $laborTightness = 1.125;
    public float $laborTightnessEma = 1.125;
    public float $wageGrowth = 0.035;
    public float $wageGrowthEma = 0.035;
    public float $nairu = MacroEngine::NATURAL_UNEMPLOYMENT;
    public float $nairuEma = MacroEngine::NATURAL_UNEMPLOYMENT;

    public float $naturalRate = MacroEngine::BASE_NATURAL_RATE;
    public float $naturalRateEma = MacroEngine::BASE_NATURAL_RATE;

    public float $energyPriceIndex = 90.0;
    public float $energyPriceIndexEma = 90.0;
    public float $energyPriceShock = 0.0;
    public float $energyBasePrice = 90.0;
    public float $consumerSentimentIndex = 108.0;
    public float $consumerSentimentIndexEma = 108.0;

    public float $exchangeRateIndex = 100.0;
    public float $exchangeRateIndexEma = 100.0;

    public float $industrialMetalsIndex = 100.0;
    public float $industrialMetalsIndexEma = 100.0;
    public float $metalsChi = 0.0;
    public float $metalsXi = 4.60517;

    public float $governmentSpendingIndex = 100.0;
    public float $governmentSpendingIndexEma = 100.0;

    public float $commercialPropertyIndex = 100.0;
    public float $commercialPropertyIndexEma = 100.0;

    public float $residentialPropertyIndex = 100.0;
    public float $residentialPropertyIndexEma = 100.0;

    public float $retailDefaultRate = 0.0250;
    public float $retailDefaultRateEma = 0.0250;

    public float $agriculturalCommodityIndex = 100.0;
    public float $agriculturalCommodityIndexEma = 100.0;
    public float $agriChi = 0.0;
    public float $agriXi = 4.60517;

    public float $freightRateIndex = 100.0;
    public float $freightRateIndexEma = 100.0;
    public float $freightSupplyEma = 100.0;

    public float $targetRate = 0.0250;
    public float $policyRate = 0.0250;
    public float $policyRateEma = 0.0250;

    public float $tipsBreakeven = MacroEngine::TARGET_INFLATION;
    public float $tipsBreakevenEma = MacroEngine::TARGET_INFLATION;
    public float $energyCostPushLag = 0.0;
    public float $agriCostPushLag = 0.0;

    public float $nsLevel = 0.0425;
    public float $nsSlope = -0.0150;
    public float $nsSlopeEma = -0.0150;
    public float $structuralSlope = -0.0150;
    public float $nsCurvature = 0.0;
    public float $nsCurvature2 = 0.0;

    public float $yield2y = 0.0275;
    public float $yield2yEma = 0.0275;
    public float $yield5y = 0.0325;
    public float $yield5yEma = 0.0325;
    public float $yield10y = 0.0375;
    public float $yield10yEma = 0.0375;
    public float $yield30y = 0.0425;
    public float $yield30yEma = 0.0425;

    public float $termPremium10y = 0.0125;
    public float $termPremium10yEma = 0.0125;
    public float $riskNeutral10y = 0.0250;
    public float $riskNeutral10yEma = 0.0250;

    public bool $qeActive = false;
    public float $qeIntensity = 0.0;
    public bool $qtActive = false;
    public float $qtIntensity = 0.0;
    public float $balanceSheetIntensity = 0.0;
    public float $balanceSheetHoldTimer = 0.0;

    public float $inversionDuration = 0.0;
    public float $corporateTaxRate = MacroEngine::BASE_CORPORATE_TAX_RATE;
    public float $sovereignDebtToGdp = MacroEngine::INITIAL_DEBT_TO_GDP;
    public float $sovereignDebtToGdpEma = MacroEngine::INITIAL_DEBT_TO_GDP;
    public ?string $eventType = null;
    public float $equityRiskPremium = MacroEngine::BASE_EQUITY_RISK_PREMIUM;

    public float $potentialGdpIndex = 1.0;
    public float $nominalGdpIndex = 1.0;
    public float $gdpDeflator = 1.0;

    public float $marketVolatility = 0.13;
    public float $marketVolatilityEma = 0.13;
    public float $marketZ = 0.0;
    public float $financialConditionsIndex = 0.0;
    public float $financialConditionsIndexEma = 0.0;

    public float $macroCreditSpread = MacroEngine::BASE_CREDIT_SPREAD;
    public float $macroCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD;

    public float $interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD;
    public float $interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD;

    public float $totalFactorProductivityIndex = MacroEngine::TFP_BASELINE;
    public float $totalFactorProductivityIndexEma = MacroEngine::TFP_BASELINE;

    public float $supercoreInflation = MacroEngine::TARGET_INFLATION;
    public float $supercoreInflationEma = MacroEngine::TARGET_INFLATION;
    public float $coreGoodsInflation = MacroEngine::TARGET_INFLATION;
    public float $coreGoodsInflationEma = MacroEngine::TARGET_INFLATION;

    public float $cumulativeInflationGap = 0.0;
    public float $cumulativeInflationGapEma = 0.0;

    public float $highYieldCreditSpread = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;
    public float $highYieldCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;

    public float $inventoryStockGap = 0.0;
    public float $inventoryStockGapEma = 0.0;

    public float $energyInventoryIndex = MacroEngine::COMMODITY_INVENTORY_BASELINE;
    public float $energyInventoryIndexEma = MacroEngine::COMMODITY_INVENTORY_BASELINE;

    public float $capacityUtilizationRate = MacroEngine::CU_BASELINE;
    public float $capacityUtilizationRateEma = MacroEngine::CU_BASELINE;

    public float $recessionProbability = 0.15;
    public float $recessionProbabilityEma = 0.15;

    public float $corporateDefaultRate = MacroEngine::CORPORATE_DEFAULT_BASELINE;
    public float $corporateDefaultRateEma = MacroEngine::CORPORATE_DEFAULT_BASELINE;

    public float $sloosTighteningIndex = 0.0;
    public float $sloosTighteningIndexEma = 0.0;

    public float $supplyChainPressureIndex = 0.0;
    public float $supplyChainPressureIndexEma = 0.0;

    public float $refiningCrackSpread = MacroEngine::CRACK_SPREAD_BASELINE;
    public float $refiningCrackSpreadEma = MacroEngine::CRACK_SPREAD_BASELINE;

    public float $dealActivityIndex = MacroEngine::DEAL_ACTIVITY_BASELINE;
    public float $dealActivityIndexEma = MacroEngine::DEAL_ACTIVITY_BASELINE;

    /**
     * Initializes the MacroState from a decoded JSON array payload.
     */
    public static function fromArray(array $data): self
    {
        $state = new self();

        $state->totalTime = (float) ($data['total_time'] ?? 0.0);
        $state->inflation = $data['inflation'] ?? MacroEngine::TARGET_INFLATION;
        $state->inflationEma = $data['inflation_ema'] ?? $state->inflation;
        $state->outputGap = $data['output_gap'] ?? 0.015;
        $state->outputGapEma = $data['output_gap_ema'] ?? $state->outputGap;
        $state->capitalStockOverhang = (float) ($data['capital_stock_overhang'] ?? 0.0);
        $state->capitalStockOverhangEma = (float) ($data['capital_stock_overhang_ema'] ?? $state->capitalStockOverhang);

        $state->unemploymentRate = (float) ($data['unemployment_rate'] ?? 0.038);
        $state->unemploymentRateEma = (float) ($data['unemployment_rate_ema'] ?? $state->unemploymentRate);
        $state->jobVacanciesRate = (float) ($data['job_vacancies_rate'] ?? 0.045);
        $state->jobVacanciesRateEma = (float) ($data['job_vacancies_rate_ema'] ?? $state->jobVacanciesRate);
        $state->laborTightness = (float) ($data['labor_tightness'] ?? 1.125);
        $state->laborTightnessEma = (float) ($data['labor_tightness_ema'] ?? $state->laborTightness);
        $state->wageGrowth = (float) ($data['wage_growth'] ?? 0.035);
        $state->wageGrowthEma = (float) ($data['wage_growth_ema'] ?? $state->wageGrowth);
        $state->nairu = (float) ($data['nairu'] ?? MacroEngine::NATURAL_UNEMPLOYMENT);
        $state->nairuEma = (float) ($data['nairu_ema'] ?? $state->nairu);

        $state->naturalRate = (float) ($data['natural_rate'] ?? MacroEngine::BASE_NATURAL_RATE);
        $state->naturalRateEma = (float) ($data['natural_rate_ema'] ?? $state->naturalRate);

        $state->energyPriceIndex = $data['energy_price_index'] ?? 90.0;
        $state->energyPriceIndexEma = $data['energy_price_index_ema'] ?? $state->energyPriceIndex;
        $state->energyPriceShock = $data['energy_price_shock'] ?? 0.0;
        $state->energyBasePrice = (float) ($data['energy_base_price'] ?? $state->energyPriceIndex);
        $state->energyCostPushLag = (float) ($data['energy_cost_push_lag'] ?? 0.0);
        $state->agriCostPushLag = (float) ($data['agri_cost_push_lag'] ?? 0.0);
        $state->consumerSentimentIndex = $data['consumer_sentiment_index'] ?? 108.0;
        $state->consumerSentimentIndexEma = $data['consumer_sentiment_index_ema'] ?? $state->consumerSentimentIndex;

        $state->exchangeRateIndex = $data['exchange_rate_index'] ?? 100.0;
        $state->exchangeRateIndexEma = $data['exchange_rate_index_ema'] ?? $state->exchangeRateIndex;

        $state->industrialMetalsIndex = $data['industrial_metals_index'] ?? 100.0;
        $state->industrialMetalsIndexEma = $data['industrial_metals_index_ema'] ?? $state->industrialMetalsIndex;
        $state->metalsChi = $data['metals_chi'] ?? 0.0;
        $state->metalsXi = $data['metals_xi'] ?? 4.60517;

        $state->governmentSpendingIndex = $data['government_spending_index'] ?? 100.0;
        $state->governmentSpendingIndexEma = $data['government_spending_index_ema'] ?? $state->governmentSpendingIndex;

        $state->commercialPropertyIndex = $data['commercial_property_index'] ?? 100.0;
        $state->commercialPropertyIndexEma = $data['commercial_property_index_ema'] ?? $state->commercialPropertyIndex;

        $state->residentialPropertyIndex = $data['residential_property_index'] ?? 100.0;
        $state->residentialPropertyIndexEma = $data['residential_property_index_ema'] ?? $state->residentialPropertyIndex;

        $state->retailDefaultRate = $data['retail_default_rate'] ?? 0.0250;
        $state->retailDefaultRateEma = $data['retail_default_rate_ema'] ?? $state->retailDefaultRate;

        $state->agriculturalCommodityIndex = $data['agricultural_commodity_index'] ?? 100.0;
        $state->agriculturalCommodityIndexEma = $data['agricultural_commodity_index_ema'] ?? $state->agriculturalCommodityIndex;
        $state->agriChi = $data['agri_chi'] ?? 0.0;
        $state->agriXi = $data['agri_xi'] ?? 4.60517;

        $state->freightRateIndex = $data['freight_rate_index'] ?? 100.0;
        $state->freightRateIndexEma = $data['freight_rate_index_ema'] ?? $state->freightRateIndex;
        $state->freightSupplyEma = $data['freight_supply_ema'] ?? 100.0;

        $state->targetRate = $data['target_rate'] ?? 0.0250;
        $state->policyRate = $data['policy_rate'] ?? 0.0250;
        $state->policyRateEma = $data['policy_rate_ema'] ?? $state->policyRate;

        $state->tipsBreakeven = (float) ($data['tips_breakeven'] ?? MacroEngine::TARGET_INFLATION);
        $state->tipsBreakevenEma = (float) ($data['tips_breakeven_ema'] ?? $state->tipsBreakeven);

        $state->nsLevel = $data['ns_level'] ?? 0.0425;
        $state->nsSlope = $data['ns_slope'] ?? -0.0150;
        $state->nsSlopeEma = $data['ns_slope_ema'] ?? $state->nsSlope;
        $state->structuralSlope = $data['structural_slope'] ?? -0.0150;
        $state->nsCurvature = $data['ns_curvature'] ?? 0.0;
        $state->nsCurvature2 = (float) ($data['ns_curvature2'] ?? 0.0);

        $state->yield2y = $data['yield_2y'] ?? 0.0275;
        $state->yield2yEma = $data['yield_2y_ema'] ?? $state->yield2y;
        $state->yield5y = $data['yield_5y'] ?? 0.0325;
        $state->yield5yEma = $data['yield_5y_ema'] ?? $state->yield5y;
        $state->yield10y = $data['yield_10y'] ?? 0.0375;
        $state->yield10yEma = $data['yield_10y_ema'] ?? $state->yield10y;
        $state->yield30y = $data['yield_30y'] ?? 0.0425;
        $state->yield30yEma = $data['yield_30y_ema'] ?? $state->yield30y;

        $state->termPremium10y = (float) ($data['term_premium_10y'] ?? 0.0125);
        $state->termPremium10yEma = (float) ($data['term_premium_10y_ema'] ?? $state->termPremium10y);
        $state->riskNeutral10y = (float) ($data['risk_neutral_10y'] ?? 0.0250);
        $state->riskNeutral10yEma = (float) ($data['risk_neutral_10y_ema'] ?? $state->riskNeutral10y);

        $state->balanceSheetIntensity = (float) ($data['balance_sheet_intensity'] ?? ($data['qe_intensity'] ?? 0.0));
        $state->balanceSheetHoldTimer = (float) ($data['balance_sheet_hold_timer'] ?? 0.0);
        $state->qeActive = (bool) ($data['qe_active'] ?? ($state->balanceSheetIntensity > 0.0005));
        $state->qeIntensity = (float) ($data['qe_intensity'] ?? max(0.0, $state->balanceSheetIntensity));
        $state->qtActive = (bool) ($data['qt_active'] ?? ($state->balanceSheetIntensity < -0.0005));
        $state->qtIntensity = (float) ($data['qt_intensity'] ?? max(0.0, -$state->balanceSheetIntensity));

        $state->inversionDuration = $data['inversion_duration'] ?? 0.0;
        $state->corporateTaxRate = $data['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
        $state->sovereignDebtToGdp = (float) ($data['sovereign_debt_to_gdp'] ?? MacroEngine::INITIAL_DEBT_TO_GDP);
        $state->sovereignDebtToGdpEma = (float) ($data['sovereign_debt_to_gdp_ema'] ?? $state->sovereignDebtToGdp);
        $state->eventType = $data['event_type'] ?? null;
        $state->equityRiskPremium = $data['equity_risk_premium'] ?? MacroEngine::BASE_EQUITY_RISK_PREMIUM;

        $state->nominalGdpIndex = $data['nominal_gdp_index'] ?? 1.0;
        $state->potentialGdpIndex = $data['potential_gdp_index'] ?? ($state->nominalGdpIndex / (1.0 + $state->outputGap));
        $state->gdpDeflator = (float) ($data['gdp_deflator'] ?? 1.0);

        $state->marketVolatility = $data['market_volatility'] ?? 0.13;
        $state->marketVolatilityEma = $data['market_volatility_ema'] ?? $state->marketVolatility;
        $state->marketZ = $data['market_z'] ?? 0.0;
        $state->financialConditionsIndex = (float) ($data['financial_conditions_index'] ?? 0.0);
        $state->financialConditionsIndexEma = (float) ($data['financial_conditions_index_ema'] ?? $state->financialConditionsIndex);

        $state->macroCreditSpread = (float) ($data['macro_credit_spread'] ?? MacroEngine::BASE_CREDIT_SPREAD);
        $state->macroCreditSpreadEma = (float) ($data['macro_credit_spread_ema'] ?? $state->macroCreditSpread);

        $state->interbankLiquiditySpread = $data['interbank_liquidity_spread'] ?? MacroEngine::INTERBANK_BASELINE_SPREAD;
        $state->interbankLiquiditySpreadEma = $data['interbank_liquidity_spread_ema'] ?? $state->interbankLiquiditySpread;

        $state->totalFactorProductivityIndex = (float) ($data['total_factor_productivity_index'] ?? MacroEngine::TFP_BASELINE);
        $state->totalFactorProductivityIndexEma = (float) ($data['total_factor_productivity_index_ema'] ?? $state->totalFactorProductivityIndex);

        $state->supercoreInflation = (float) ($data['supercore_inflation'] ?? $state->inflation);
        $state->supercoreInflationEma = (float) ($data['supercore_inflation_ema'] ?? $state->supercoreInflation);
        $state->coreGoodsInflation = (float) ($data['core_goods_inflation'] ?? $state->inflation);
        $state->coreGoodsInflationEma = (float) ($data['core_goods_inflation_ema'] ?? $state->coreGoodsInflation);

        $state->cumulativeInflationGap = (float) ($data['cumulative_inflation_gap'] ?? 0.0);
        $state->cumulativeInflationGapEma = (float) ($data['cumulative_inflation_gap_ema'] ?? $state->cumulativeInflationGap);

        $state->highYieldCreditSpread = (float) ($data['high_yield_credit_spread'] ?? ($state->macroCreditSpread * MacroEngine::HY_BASE_SPREAD_MULTIPLIER));
        $state->highYieldCreditSpreadEma = (float) ($data['high_yield_credit_spread_ema'] ?? $state->highYieldCreditSpread);

        $state->inventoryStockGap = (float) ($data['inventory_stock_gap'] ?? 0.0);
        $state->inventoryStockGapEma = (float) ($data['inventory_stock_gap_ema'] ?? $state->inventoryStockGap);

        $state->energyInventoryIndex = (float) ($data['energy_inventory_index'] ?? MacroEngine::COMMODITY_INVENTORY_BASELINE);
        $state->energyInventoryIndexEma = (float) ($data['energy_inventory_index_ema'] ?? $state->energyInventoryIndex);

        $state->capacityUtilizationRate = (float) ($data['capacity_utilization_rate'] ?? MacroEngine::CU_BASELINE);
        $state->capacityUtilizationRateEma = (float) ($data['capacity_utilization_rate_ema'] ?? $state->capacityUtilizationRate);

        $state->recessionProbability = (float) ($data['recession_probability'] ?? 0.15);
        $state->recessionProbabilityEma = (float) ($data['recession_probability_ema'] ?? $state->recessionProbability);

        $state->corporateDefaultRate = (float) ($data['corporate_default_rate'] ?? MacroEngine::CORPORATE_DEFAULT_BASELINE);
        $state->corporateDefaultRateEma = (float) ($data['corporate_default_rate_ema'] ?? $state->corporateDefaultRate);

        $state->sloosTighteningIndex = (float) ($data['sloos_tightening_index'] ?? 0.0);
        $state->sloosTighteningIndexEma = (float) ($data['sloos_tightening_index_ema'] ?? $state->sloosTighteningIndex);

        $state->supplyChainPressureIndex = (float) ($data['supply_chain_pressure_index'] ?? 0.0);
        $state->supplyChainPressureIndexEma = (float) ($data['supply_chain_pressure_index_ema'] ?? $state->supplyChainPressureIndex);

        $state->refiningCrackSpread = (float) ($data['refining_crack_spread'] ?? MacroEngine::CRACK_SPREAD_BASELINE);
        $state->refiningCrackSpreadEma = (float) ($data['refining_crack_spread_ema'] ?? $state->refiningCrackSpread);

        $state->dealActivityIndex = (float) ($data['deal_activity_index'] ?? MacroEngine::DEAL_ACTIVITY_BASELINE);
        $state->dealActivityIndexEma = (float) ($data['deal_activity_index_ema'] ?? $state->dealActivityIndex);

        return $state;
    }

    /**
     * Converts the MacroState back to the array format required for Redis and database persistence.
     */
    public function toArray(): array
    {
        return [
            'total_time' => $this->totalTime,
            'inflation' => $this->inflation,
            'inflation_ema' => $this->inflationEma,
            'tips_breakeven' => $this->tipsBreakeven,
            'tips_breakeven_ema' => $this->tipsBreakevenEma,
            'energy_cost_push_lag' => $this->energyCostPushLag,
            'agri_cost_push_lag' => $this->agriCostPushLag,
            'output_gap' => $this->outputGap,
            'output_gap_ema' => $this->outputGapEma,
            'capitalStockOverhang' => $this->capitalStockOverhang,
            'capital_stock_overhang' => $this->capitalStockOverhang,
            'capital_stock_overhang_ema' => $this->capitalStockOverhangEma,
            'unemployment_rate' => $this->unemploymentRate,
            'unemployment_rate_ema' => $this->unemploymentRateEma,
            'job_vacancies_rate' => $this->jobVacanciesRate,
            'job_vacancies_rate_ema' => $this->jobVacanciesRateEma,
            'labor_tightness' => $this->laborTightness,
            'labor_tightness_ema' => $this->laborTightnessEma,
            'wage_growth' => $this->wageGrowth,
            'wage_growth_ema' => $this->wageGrowthEma,
            'nairu' => $this->nairu,
            'nairu_ema' => $this->nairuEma,
            'natural_rate' => $this->naturalRate,
            'natural_rate_ema' => $this->naturalRateEma,
            'energy_price_index' => $this->energyPriceIndex,
            'energy_price_index_ema' => $this->energyPriceIndexEma,
            'energy_price_shock' => $this->energyPriceShock,
            'energy_base_price' => $this->energyBasePrice,
            'consumer_sentiment_index' => $this->consumerSentimentIndex,
            'consumer_sentiment_index_ema' => $this->consumerSentimentIndexEma,
            'exchange_rate_index' => $this->exchangeRateIndex,
            'exchange_rate_index_ema' => $this->exchangeRateIndexEma,
            'industrial_metals_index' => $this->industrialMetalsIndex,
            'industrial_metals_index_ema' => $this->industrialMetalsIndexEma,
            'metals_chi' => $this->metalsChi,
            'metals_xi' => $this->metalsXi,
            'government_spending_index' => $this->governmentSpendingIndex,
            'government_spending_index_ema' => $this->governmentSpendingIndexEma,
            'commercial_property_index' => $this->commercialPropertyIndex,
            'commercial_property_index_ema' => $this->commercialPropertyIndexEma,
            'residential_property_index' => $this->residentialPropertyIndex,
            'residential_property_index_ema' => $this->residentialPropertyIndexEma,
            'retail_default_rate' => $this->retailDefaultRate,
            'retail_default_rate_ema' => $this->retailDefaultRateEma,
            'agricultural_commodity_index' => $this->agriculturalCommodityIndex,
            'agricultural_commodity_index_ema' => $this->agriculturalCommodityIndexEma,
            'agri_chi' => $this->agriChi,
            'agri_xi' => $this->agriXi,
            'freight_rate_index' => $this->freightRateIndex,
            'freight_rate_index_ema' => $this->freightRateIndexEma,
            'freight_supply_ema' => $this->freightSupplyEma,
            'target_rate' => $this->targetRate,
            'policy_rate' => $this->policyRate,
            'policy_rate_ema' => $this->policyRateEma,
            'ns_level' => $this->nsLevel,
            'ns_slope' => $this->nsSlope,
            'ns_slope_ema' => $this->nsSlopeEma,
            'structural_slope' => $this->structuralSlope,
            'ns_curvature' => $this->nsCurvature,
            'ns_curvature2' => $this->nsCurvature2,
            'yield_2y' => $this->yield2y,
            'yield_2y_ema' => $this->yield2yEma,
            'yield_5y' => $this->yield5y,
            'yield_5y_ema' => $this->yield5yEma,
            'yield_10y' => $this->yield10y,
            'yield_10y_ema' => $this->yield10yEma,
            'yield_30y' => $this->yield30y,
            'yield_30y_ema' => $this->yield30yEma,
            'term_premium_10y' => $this->termPremium10y,
            'term_premium_10y_ema' => $this->termPremium10yEma,
            'risk_neutral_10y' => $this->riskNeutral10y,
            'risk_neutral_10y_ema' => $this->riskNeutral10yEma,
            'balance_sheet_intensity' => $this->balanceSheetIntensity,
            'balance_sheet_hold_timer' => $this->balanceSheetHoldTimer,
            'qe_active' => $this->qeActive,
            'qe_intensity' => $this->qeIntensity,
            'qt_active' => $this->qtActive,
            'qt_intensity' => $this->qtIntensity,
            'inversion_duration' => $this->inversionDuration,
            'corporate_tax_rate' => $this->corporateTaxRate,
            'sovereign_debt_to_gdp' => $this->sovereignDebtToGdp,
            'sovereign_debt_to_gdp_ema' => $this->sovereignDebtToGdpEma,
            'event_type' => $this->eventType,
            'equity_risk_premium' => $this->equityRiskPremium,
            'potential_gdp_index' => $this->potentialGdpIndex,
            'nominal_gdp_index' => $this->nominalGdpIndex,
            'gdp_deflator' => $this->gdpDeflator,
            'market_volatility' => $this->marketVolatility,
            'market_volatility_ema' => $this->marketVolatilityEma,
            'market_z' => $this->marketZ,
            'financial_conditions_index' => $this->financialConditionsIndex,
            'financial_conditions_index_ema' => $this->financialConditionsIndexEma,
            'macro_credit_spread' => $this->macroCreditSpread,
            'macro_credit_spread_ema' => $this->macroCreditSpreadEma,
            'interbank_liquidity_spread' => $this->interbankLiquiditySpread,
            'interbank_liquidity_spread_ema' => $this->interbankLiquiditySpreadEma,
            'total_factor_productivity_index' => $this->totalFactorProductivityIndex,
            'total_factor_productivity_index_ema' => $this->totalFactorProductivityIndexEma,
            'supercore_inflation' => $this->supercoreInflation,
            'supercore_inflation_ema' => $this->supercoreInflationEma,
            'core_goods_inflation' => $this->coreGoodsInflation,
            'core_goods_inflation_ema' => $this->coreGoodsInflationEma,
            'cumulative_inflation_gap' => $this->cumulativeInflationGap,
            'cumulative_inflation_gap_ema' => $this->cumulativeInflationGapEma,
            'high_yield_credit_spread' => $this->highYieldCreditSpread,
            'high_yield_credit_spread_ema' => $this->highYieldCreditSpreadEma,
            'inventory_stock_gap' => $this->inventoryStockGap,
            'inventory_stock_gap_ema' => $this->inventoryStockGapEma,
            'energy_inventory_index' => $this->energyInventoryIndex,
            'energy_inventory_index_ema' => $this->energyInventoryIndexEma,
            'capacity_utilization_rate' => $this->capacityUtilizationRate,
            'capacity_utilization_rate_ema' => $this->capacityUtilizationRateEma,
            'recession_probability' => $this->recessionProbability,
            'recession_probability_ema' => $this->recessionProbabilityEma,
            'corporate_default_rate' => $this->corporateDefaultRate,
            'corporate_default_rate_ema' => $this->corporateDefaultRateEma,
            'sloos_tightening_index' => $this->sloosTighteningIndex,
            'sloos_tightening_index_ema' => $this->sloosTighteningIndexEma,
            'supply_chain_pressure_index' => $this->supplyChainPressureIndex,
            'supply_chain_pressure_index_ema' => $this->supplyChainPressureIndexEma,
            'refining_crack_spread' => $this->refiningCrackSpread,
            'refining_crack_spread_ema' => $this->refiningCrackSpreadEma,
            'deal_activity_index' => $this->dealActivityIndex,
            'deal_activity_index_ema' => $this->dealActivityIndexEma,
        ];
    }
}
