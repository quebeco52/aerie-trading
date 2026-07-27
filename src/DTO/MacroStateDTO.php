<?php

declare(strict_types=1);

namespace App\DTO;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;

/**
 * Immutable Data Transfer Object representing a snapshot of the macroeconomic state.
 * Replaces weakly-typed associative arrays ($macroState) across models and engines.
 */
readonly class MacroStateDTO
{
    public function __construct(
        public float $outputGap = 0.0,
        public float $outputGapEma = 0.0,
        public float $unemploymentRate = 0.04,
        public float $unemploymentRateEma = 0.04,
        public float $energyPriceIndex = 100.0,
        public float $energyPriceShock = 0.0,
        public float $inflation = 0.02,
        public float $inflationEma = 0.02,
        public float $policyRate = 0.02,
        public float $policyRateEma = 0.02,
        public float $targetRate = 0.02,
        public float $yield2y = 0.04,
        public float $yield2yEma = 0.04,
        public float $yield5y = 0.045,
        public float $yield5yEma = 0.045,
        public float $yield10y = 0.05,
        public float $yield10yEma = 0.05,
        public float $yield30y = 0.055,
        public float $yield30yEma = 0.055,
        public float $marketVolatility = 0.15,
        public float $marketVolatilityEma = 0.15,
        public float $marketZ = 0.0,
        public float $corporateTaxRate = MacroEngine::BASE_CORPORATE_TAX_RATE,
        public float $equityRiskPremium = MacroEngine::BASE_EQUITY_RISK_PREMIUM,
        public float $macroCreditSpread = 0.02,
        public float $macroCreditSpreadEma = 0.02,
        public bool $qeActive = false,
        public float $qeIntensity = 0.0,
        public float $inversionDuration = 0.0,
        public float $nsLevel = 0.0,
        public float $nsSlope = 0.0,
        public float $nsSlopeEma = 0.0,
        public float $nsCurvature = 0.0,
        public float $potentialGdpIndex = 1.0,
        public float $nominalGdpIndex = 1.0,
        public ?string $eventType = null,
    ) {}

    /**
     * Constructs a MacroStateDTO from an associative array payload with defaults.
     */
    public static function fromArray(array $data): self
    {
        $inflation = (float) ($data['inflation'] ?? MacroEngine::TARGET_INFLATION);
        $inflationEma = (float) ($data['inflation_ema'] ?? $inflation);
        $outputGap = (float) ($data['output_gap'] ?? 0.02);
        $outputGapEma = (float) ($data['output_gap_ema'] ?? $outputGap);
        
        $unemploymentRate = (float) ($data['unemployment_rate'] ?? 0.04);
        $unemploymentRateEma = (float) ($data['unemployment_rate_ema'] ?? $unemploymentRate);
        
        $energyPriceIndex = (float) ($data['energy_price_index'] ?? 100.0);
        $energyPriceShock = (float) ($data['energy_price_shock'] ?? 0.0);
        
        $policyRate = (float) ($data['policy_rate'] ?? 0.02);
        $policyRateEma = (float) ($data['policy_rate_ema'] ?? $policyRate);
        $targetRate = (float) ($data['target_rate'] ?? 0.02);

        $yield2y = (float) ($data['yield_2y'] ?? $policyRate);
        $yield2yEma = (float) ($data['yield_2y_ema'] ?? $yield2y);
        $yield5y = (float) ($data['yield_5y'] ?? ($policyRate + 0.005));
        $yield5yEma = (float) ($data['yield5y_ema'] ?? ($data['yield_5y_ema'] ?? $yield5y));
        $yield10y = (float) ($data['yield_10y'] ?? ($policyRate + 0.01));
        $yield10yEma = (float) ($data['yield_10y_ema'] ?? $yield10y);
        $yield30y = (float) ($data['yield_30y'] ?? ($policyRate + 0.015));
        $yield30yEma = (float) ($data['yield_30y_ema'] ?? $yield30y);

        $marketVolatility = (float) ($data['market_volatility'] ?? 0.15);
        $marketVolatilityEma = (float) ($data['market_volatility_ema'] ?? $marketVolatility);
        $marketZ = (float) ($data['market_z'] ?? 0.0);

        $corporateTaxRate = (float) ($data['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE);
        $equityRiskPremium = (float) ($data['equity_risk_premium'] ?? MacroEngine::BASE_EQUITY_RISK_PREMIUM);
        $macroCreditSpread = (float) ($data['macro_credit_spread'] ?? 0.02);
        $macroCreditSpreadEma = (float) ($data['macro_credit_spread_ema'] ?? $macroCreditSpread);

        $qeActive = (bool) ($data['qe_active'] ?? false);
        $qeIntensity = (float) ($data['qe_intensity'] ?? 0.0);
        $inversionDuration = (float) ($data['inversion_duration'] ?? 0.0);
        $nsLevel = (float) ($data['ns_level'] ?? 0.0);
        $nsSlope = (float) ($data['ns_slope'] ?? 0.0);
        $nsSlopeEma = (float) ($data['ns_slope_ema'] ?? $nsSlope);
        $nsCurvature = (float) ($data['ns_curvature'] ?? 0.0);

        $nominalGdpIndex = (float) ($data['nominal_gdp_index'] ?? 1.0);
        $potentialGdpIndex = (float) ($data['potential_gdp_index'] ?? ($nominalGdpIndex / (1.0 + $outputGap)));
        $eventType = isset($data['event_type']) ? (string) $data['event_type'] : null;

        return new self(
            outputGap: $outputGap,
            outputGapEma: $outputGapEma,
            unemploymentRate: $unemploymentRate,
            unemploymentRateEma: $unemploymentRateEma,
            energyPriceIndex: $energyPriceIndex,
            energyPriceShock: $energyPriceShock,
            inflation: $inflation,
            inflationEma: $inflationEma,
            policyRate: $policyRate,
            policyRateEma: $policyRateEma,
            targetRate: $targetRate,
            yield2y: $yield2y,
            yield2yEma: $yield2yEma,
            yield5y: $yield5y,
            yield5yEma: $yield5yEma,
            yield10y: $yield10y,
            yield10yEma: $yield10yEma,
            yield30y: $yield30y,
            yield30yEma: $yield30yEma,
            marketVolatility: $marketVolatility,
            marketVolatilityEma: $marketVolatilityEma,
            marketZ: $marketZ,
            corporateTaxRate: $corporateTaxRate,
            equityRiskPremium: $equityRiskPremium,
            macroCreditSpread: $macroCreditSpread,
            macroCreditSpreadEma: $macroCreditSpreadEma,
            qeActive: $qeActive,
            qeIntensity: $qeIntensity,
            inversionDuration: $inversionDuration,
            nsLevel: $nsLevel,
            nsSlope: $nsSlope,
            nsSlopeEma: $nsSlopeEma,
            nsCurvature: $nsCurvature,
            potentialGdpIndex: $potentialGdpIndex,
            nominalGdpIndex: $nominalGdpIndex,
            eventType: $eventType,
        );
    }

    /**
     * Creates a MacroStateDTO from a MacroState entity/model object.
     */
    public static function fromMacroState(MacroState $state): self
    {
        return new self(
            outputGap: $state->outputGap,
            outputGapEma: $state->outputGapEma,
            unemploymentRate: $state->unemploymentRate,
            unemploymentRateEma: $state->unemploymentRateEma,
            energyPriceIndex: $state->energyPriceIndex,
            energyPriceShock: $state->energyPriceShock,
            inflation: $state->inflation,
            inflationEma: $state->inflationEma,
            policyRate: $state->policyRate,
            policyRateEma: $state->policyRateEma,
            targetRate: $state->targetRate,
            yield2y: $state->yield2y,
            yield2yEma: $state->yield2yEma,
            yield5y: $state->yield5y,
            yield5yEma: $state->yield5yEma,
            yield10y: $state->yield10y,
            yield10yEma: $state->yield10yEma,
            yield30y: $state->yield30y,
            yield30yEma: $state->yield30yEma,
            marketVolatility: $state->marketVolatility,
            marketVolatilityEma: $state->marketVolatilityEma,
            marketZ: $state->marketZ,
            corporateTaxRate: $state->corporateTaxRate,
            equityRiskPremium: $state->equityRiskPremium,
            macroCreditSpread: $state->macroCreditSpread,
            macroCreditSpreadEma: $state->macroCreditSpreadEma,
            qeActive: $state->qeActive,
            qeIntensity: $state->qeIntensity,
            inversionDuration: $state->inversionDuration,
            nsLevel: $state->nsLevel,
            nsSlope: $state->nsSlope,
            nsSlopeEma: $state->nsSlopeEma,
            nsCurvature: $state->nsCurvature,
            potentialGdpIndex: $state->potentialGdpIndex,
            nominalGdpIndex: $state->nominalGdpIndex,
            eventType: $state->eventType,
        );
    }

    /**
     * Converts the DTO to an associative array for backward compatibility and serialization.
     */
    public function toArray(): array
    {
        return [
            'output_gap' => $this->outputGap,
            'output_gap_ema' => $this->outputGapEma,
            'unemployment_rate' => $this->unemploymentRate,
            'unemployment_rate_ema' => $this->unemploymentRateEma,
            'energy_price_index' => $this->energyPriceIndex,
            'energy_price_shock' => $this->energyPriceShock,
            'inflation' => $this->inflation,
            'inflation_ema' => $this->inflationEma,
            'policy_rate' => $this->policyRate,
            'policy_rate_ema' => $this->policyRateEma,
            'target_rate' => $this->targetRate,
            'yield_2y' => $this->yield2y,
            'yield_2y_ema' => $this->yield2yEma,
            'yield_5y' => $this->yield5y,
            'yield_5y_ema' => $this->yield5yEma,
            'yield_10y' => $this->yield10y,
            'yield_10y_ema' => $this->yield10yEma,
            'yield_30y' => $this->yield30y,
            'yield_30y_ema' => $this->yield30yEma,
            'market_volatility' => $this->marketVolatility,
            'market_volatility_ema' => $this->marketVolatilityEma,
            'market_z' => $this->marketZ,
            'corporate_tax_rate' => $this->corporateTaxRate,
            'equity_risk_premium' => $this->equityRiskPremium,
            'macro_credit_spread' => $this->macroCreditSpread,
            'macro_credit_spread_ema' => $this->macroCreditSpreadEma,
            'qe_active' => $this->qeActive,
            'qe_intensity' => $this->qeIntensity,
            'inversion_duration' => $this->inversionDuration,
            'ns_level' => $this->nsLevel,
            'ns_slope' => $this->nsSlope,
            'ns_slope_ema' => $this->nsSlopeEma,
            'ns_curvature' => $this->nsCurvature,
            'potential_gdp_index' => $this->potentialGdpIndex,
            'nominal_gdp_index' => $this->nominalGdpIndex,
            'event_type' => $this->eventType,
        ];
    }
}
