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

    public float $energyPriceIndex = 90.0;
    public float $energyPriceIndexEma = 90.0;
    public float $energyPriceShock = 0.0;
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

    public float $nsLevel = 0.0425;
    public float $nsSlope = -0.0150;
    public float $nsSlopeEma = -0.0150;
    public float $structuralSlope = -0.0150;
    public float $nsCurvature = 0.0;

    public float $yield2y = 0.0275;
    public float $yield2yEma = 0.0275;
    public float $yield5y = 0.0325;
    public float $yield5yEma = 0.0325;
    public float $yield10y = 0.0375;
    public float $yield10yEma = 0.0375;
    public float $yield30y = 0.0425;
    public float $yield30yEma = 0.0425;

    public bool $qeActive = false;
    public float $qeIntensity = 0.0;
    public float $inversionDuration = 0.0;
    public float $corporateTaxRate = MacroEngine::BASE_CORPORATE_TAX_RATE;
    public ?string $eventType = null;
    public float $equityRiskPremium = MacroEngine::BASE_EQUITY_RISK_PREMIUM;

    public float $potentialGdpIndex = 1.0;
    public float $nominalGdpIndex = 1.0;

    public float $marketVolatility = 0.13;
    public float $marketVolatilityEma = 0.13;
    public float $marketZ = 0.0;

    public float $macroCreditSpread = 0.015;
    public float $macroCreditSpreadEma = 0.015;

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

        $state->unemploymentRate = $data['unemployment_rate'] ?? 0.038;
        $state->unemploymentRateEma = $data['unemployment_rate_ema'] ?? $state->unemploymentRate;

        $state->energyPriceIndex = $data['energy_price_index'] ?? 90.0;
        $state->energyPriceIndexEma = $data['energy_price_index_ema'] ?? $state->energyPriceIndex;
        $state->energyPriceShock = $data['energy_price_shock'] ?? 0.0;
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

        $state->nsLevel = $data['ns_level'] ?? 0.0425;
        $state->nsSlope = $data['ns_slope'] ?? -0.0150;
        $state->nsSlopeEma = $data['ns_slope_ema'] ?? $state->nsSlope;
        $state->structuralSlope = $data['structural_slope'] ?? -0.0150;
        $state->nsCurvature = $data['ns_curvature'] ?? 0.0;

        $state->yield2y = $data['yield_2y'] ?? 0.0275;
        $state->yield2yEma = $data['yield_2y_ema'] ?? $state->yield2y;
        $state->yield5y = $data['yield_5y'] ?? 0.0325;
        $state->yield5yEma = $data['yield_5y_ema'] ?? $state->yield5y;
        $state->yield10y = $data['yield_10y'] ?? 0.0375;
        $state->yield10yEma = $data['yield_10y_ema'] ?? $state->yield10y;
        $state->yield30y = $data['yield_30y'] ?? 0.0425;
        $state->yield30yEma = $data['yield_30y_ema'] ?? $state->yield30y;

        $state->qeActive = $data['qe_active'] ?? false;
        $state->qeIntensity = $data['qe_intensity'] ?? 0.0;
        $state->inversionDuration = $data['inversion_duration'] ?? 0.0;
        $state->corporateTaxRate = $data['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
        $state->eventType = $data['event_type'] ?? null;
        $state->equityRiskPremium = $data['equity_risk_premium'] ?? MacroEngine::BASE_EQUITY_RISK_PREMIUM;

        $state->nominalGdpIndex = $data['nominal_gdp_index'] ?? 1.0;
        $state->potentialGdpIndex = $data['potential_gdp_index'] ?? ($state->nominalGdpIndex / (1.0 + $state->outputGap));

        $state->marketVolatility = $data['market_volatility'] ?? 0.13;
        $state->marketVolatilityEma = $data['market_volatility_ema'] ?? $state->marketVolatility;
        $state->marketZ = $data['market_z'] ?? 0.0;

        $state->macroCreditSpread = $data['macro_credit_spread'] ?? 0.015;
        $state->macroCreditSpreadEma = $data['macro_credit_spread_ema'] ?? $state->macroCreditSpread;

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
            'output_gap' => $this->outputGap,
            'output_gap_ema' => $this->outputGapEma,
            'capital_stock_overhang' => $this->capitalStockOverhang,
            'capital_stock_overhang_ema' => $this->capitalStockOverhangEma,
            'unemployment_rate' => $this->unemploymentRate,
            'unemployment_rate_ema' => $this->unemploymentRateEma,
            'energy_price_index' => $this->energyPriceIndex,
            'energy_price_index_ema' => $this->energyPriceIndexEma,
            'energy_price_shock' => $this->energyPriceShock,
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
            'yield_2y' => $this->yield2y,
            'yield_2y_ema' => $this->yield2yEma,
            'yield_5y' => $this->yield5y,
            'yield_5y_ema' => $this->yield5yEma,
            'yield_10y' => $this->yield10y,
            'yield_10y_ema' => $this->yield10yEma,
            'yield_30y' => $this->yield30y,
            'yield_30y_ema' => $this->yield30yEma,
            'qe_active' => $this->qeActive,
            'qe_intensity' => $this->qeIntensity,
            'inversion_duration' => $this->inversionDuration,
            'corporate_tax_rate' => $this->corporateTaxRate,
            'event_type' => $this->eventType,
            'equity_risk_premium' => $this->equityRiskPremium,
            'potential_gdp_index' => $this->potentialGdpIndex,
            'nominal_gdp_index' => $this->nominalGdpIndex,
            'market_volatility' => $this->marketVolatility,
            'market_volatility_ema' => $this->marketVolatilityEma,
            'market_z' => $this->marketZ,
            'macro_credit_spread' => $this->macroCreditSpread,
            'macro_credit_spread_ema' => $this->macroCreditSpreadEma
        ];
    }
}
