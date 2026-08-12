<?php

namespace App\Service\Macro;

class MacroState
{
    public float $inflation = MacroEngine::TARGET_INFLATION;
    public float $inflationEma = MacroEngine::TARGET_INFLATION;
    public float $outputGap = 0.015;
    public float $outputGapEma = 0.015;

    public float $unemploymentRate = 0.038;
    public float $unemploymentRateEma = 0.038;

    public float $energyPriceIndex = 90.0;
    public float $energyPriceIndexEma = 90.0;
    public float $energyPriceShock = 0.0;
    public float $consumerSentimentIndex = 108.0;
    public float $consumerSentimentIndexEma = 108.0;

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

        $state->inflation = $data['inflation'] ?? MacroEngine::TARGET_INFLATION;
        $state->inflationEma = $data['inflation_ema'] ?? $state->inflation;
        $state->outputGap = $data['output_gap'] ?? 0.015;
        $state->outputGapEma = $data['output_gap_ema'] ?? $state->outputGap;

        $state->unemploymentRate = $data['unemployment_rate'] ?? 0.038;
        $state->unemploymentRateEma = $data['unemployment_rate_ema'] ?? $state->unemploymentRate;

        $state->energyPriceIndex = $data['energy_price_index'] ?? 90.0;
        $state->energyPriceIndexEma = $data['energy_price_index_ema'] ?? $state->energyPriceIndex;
        $state->energyPriceShock = $data['energy_price_shock'] ?? 0.0;
        $state->consumerSentimentIndex = $data['consumer_sentiment_index'] ?? 108.0;
        $state->consumerSentimentIndexEma = $data['consumer_sentiment_index_ema'] ?? $state->consumerSentimentIndex;

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
            'inflation' => $this->inflation,
            'inflation_ema' => $this->inflationEma,
            'output_gap' => $this->outputGap,
            'output_gap_ema' => $this->outputGapEma,
            'unemployment_rate' => $this->unemploymentRate,
            'unemployment_rate_ema' => $this->unemploymentRateEma,
            'energy_price_index' => $this->energyPriceIndex,
            'energy_price_index_ema' => $this->energyPriceIndexEma,
            'energy_price_shock' => $this->energyPriceShock,
            'consumer_sentiment_index' => $this->consumerSentimentIndex,
            'consumer_sentiment_index_ema' => $this->consumerSentimentIndexEma,
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
