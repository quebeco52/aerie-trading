<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;

/**
 * Models asset pricing, commercial and residential real estate, equity volatility,
 * foreign exchange rates, and behavioral animal spirits.
 */
class AssetMarketSubsystem
{
    public function __construct(
        private readonly MathUtility $mathUtility
    ) {}

    /**
     * DiPasquale-Wheaton (1996) Two-Quadrant Commercial Real Estate (CRE) Econometric Model.
     *
     * Couples the spatial tenant market (unemployment occupancy contraction) with the capital asset
     * market (cap rate = 10Y yield + credit spread + CRE risk premium) with physical market adjustment lag.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateCommercialPropertyIndex(MacroState $state, float $dt): void
    {
        $excessUnemployment = $state->unemploymentRateEma - $state->nairu;
        $occupancyFactor = 1.0 - ($excessUnemployment * MacroEngine::CRE_OCCUPANCY_UNEMPLOYMENT_SENSITIVITY);
        $occupancyFactor = max(0.30, min(1.80, $occupancyFactor));

        $capRate = max(MacroEngine::CRE_MIN_CAP_RATE, $state->yield10yEma + $state->macroCreditSpreadEma + MacroEngine::CRE_CAP_RATE_RISK_PREMIUM);
        $fundamentalValue = MacroEngine::CRE_BASELINE * $occupancyFactor * (MacroEngine::CRE_NEUTRAL_CAP_RATE / $capRate);

        $dW = $this->mathUtility->generateStandardNormal();
        $newIndex = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->commercialPropertyIndex,
            kappa: MacroEngine::CRE_MEAN_REVERSION,
            theta: $fundamentalValue,
            sigma: MacroEngine::CRE_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        $state->commercialPropertyIndex = max(30.0, min(250.0, $newIndex));
    }

    /**
     * Jorgenson (1963) User Cost of Capital & Spatial Housing Affordability Equilibrium Model.
     *
     * Calculates fundamental home prices from user cost of housing capital (mortgage rate + taxes - expected inflation)
     * and household real disposable income affordability, with sticky physical mean reversion.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateResidentialPropertyIndex(MacroState $state, float $dt): void
    {
        $mortgageRate = $state->yield30yEma + MacroEngine::RESIDENTIAL_MORTGAGE_SPREAD;
        $userCost = max(0.015, $mortgageRate + MacroEngine::RESIDENTIAL_DEPRECIATION_TAX_RATE - $state->inflationEma);

        $excessUnemployment = $state->unemploymentRateEma - $state->nairu;
        $laborFactor = 1.0 - ($excessUnemployment * MacroEngine::RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY);
        $incomeFactor = 1.0 + ($state->outputGapEma * MacroEngine::RESIDENTIAL_INCOME_ELASTICITY);
        $demandMultiplier = max(MacroEngine::RESIDENTIAL_MIN_LABOR_FACTOR, min(MacroEngine::RESIDENTIAL_MAX_LABOR_FACTOR, $laborFactor * $incomeFactor));

        $affordabilityFactor = (MacroEngine::RESIDENTIAL_NEUTRAL_USER_COST / $userCost) * $demandMultiplier;
        $fundamentalPrice = MacroEngine::RESIDENTIAL_BASELINE * max(0.30, min(2.50, $affordabilityFactor));

        $dW = $this->mathUtility->generateStandardNormal();
        $newIndex = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->residentialPropertyIndex,
            kappa: MacroEngine::RESIDENTIAL_MEAN_REVERSION,
            theta: $fundamentalPrice,
            sigma: MacroEngine::RESIDENTIAL_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        $state->residentialPropertyIndex = max(MacroEngine::RESIDENTIAL_MIN_INDEX, min(MacroEngine::RESIDENTIAL_MAX_INDEX, $newIndex));
    }

    /**
     * Campbell-Cochrane (1999) Habit Formation Asset Pricing Model.
     *
     * Derives macroeconomic aggregate equity risk premium as an exponential function of surplus consumption:
     * as the output gap contracts, risk aversion surges, demanding a wider required equity risk premium.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateEquityRiskPremium(MacroState $state): void
    {
        $habitErp = MacroEngine::BASE_EQUITY_RISK_PREMIUM * exp(-MacroEngine::HABIT_RISK_AVERSION_COEFF * $state->outputGapEma);
        $state->equityRiskPremium = max(MacroEngine::MIN_EQUITY_RISK_PREMIUM, min(0.12, $habitErp));
    }

    /**
     * Engle, Ghysels & Sohn (2013) Spline-GARCH Macro Link with SVJJ Jump-Diffusion (Bates 1996).
     *
     * Models aggregate equity implied volatility using continuous macroeconomic fundamental scaling
     * (output gap, credit spreads, yield curve slope) driven by a Quadratic Exponential (Broadie-Kaya)
     * variance step and asymmetric Poisson compound jumps (SVJJ).
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     * @return float Implied equity market volatility (clamped between 8% and 80%).
     */
    public function calculateMarketVolatility(MacroState $state, float $dt): float
    {
        $currentMarketVol = $state->marketVolatility;

        $spreadDeviation = max(0.0, $state->macroCreditSpread - MacroEngine::BASE_CREDIT_SPREAD);
        $macroDriver = (-$state->outputGap * MacroEngine::MACRO_VOL_OUTPUT_GAP_SENSITIVITY)
            + ($spreadDeviation * MacroEngine::MACRO_VOL_CREDIT_SENSITIVITY)
            + (-min(0.0, $state->structuralSlope) * MacroEngine::MACRO_VOL_SLOPE_SENSITIVITY);

        $longTermVol = min(
            MacroEngine::MACRO_VOL_MAX_BASELINE,
            max(MacroEngine::MACRO_VOL_MIN_BASELINE, MacroEngine::MACRO_VOL_BASE_ANCHOR * exp($macroDriver))
        );

        $currentVar = $currentMarketVol * $currentMarketVol;
        $longTermVar = $longTermVol * $longTermVol;

        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: MacroEngine::SVJJ_LAMBDA,
            pUp: MacroEngine::SVJJ_P_UP,
            etaUp: MacroEngine::SVJJ_ETA_UP,
            etaDown: MacroEngine::SVJJ_ETA_DOWN,
            muV: MacroEngine::SVJJ_MU_V,
            dt: $dt
        );

        $expectedVarJump = (MacroEngine::SVJJ_P_UP * MacroEngine::SVJJ_MU_V * 0.5) + ((1.0 - MacroEngine::SVJJ_P_UP) * MacroEngine::SVJJ_MU_V);
        $jumpVarianceDrag = (MacroEngine::SVJJ_LAMBDA * $expectedVarJump) / 3.0;
        $adjustedTheta = max(0.0001, $longTermVar - $jumpVarianceDrag);

        $nextVar = $this->mathUtility->calculateQEVarianceStep($currentVar, $adjustedTheta, MacroEngine::MACRO_VOL_KAPPA, MacroEngine::MACRO_VOL_SIGMA, $dt);
        $nextVar += $jumpData['var_jump'];

        return max(0.08, min(0.80, sqrt($nextVar)));
    }

    /**
     * Mundell-Fleming Open Economy (IS-LM-BOP) & Uncovered Interest Parity (Dornbusch 1976).
     *
     * Models currency exchange rate index against global trading partners based on domestic-to-foreign
     * interest rate differentials (UIP equilibrium target) with Schwartz (1997) commodity mean reversion.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateExchangeRate(MacroState $state, float $dt): void
    {
        $rateDiff = $state->policyRate - MacroEngine::GLOBAL_BASELINE_RATE;
        $targetFx = MacroEngine::EXCHANGE_RATE_BASELINE * exp(MacroEngine::UIP_SENSITIVITY * $rateDiff);

        $dW = $this->mathUtility->generateStandardNormal();
        $newFx = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->exchangeRateIndex,
            kappa: MacroEngine::EXCHANGE_RATE_MEAN_REVERSION,
            theta: $targetFx,
            sigma: MacroEngine::EXCHANGE_RATE_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        $state->exchangeRateIndex = max(60.0, min(160.0, $newFx));
    }

    /**
     * University of Michigan Sentiment & Okun Misery Index with Animal Spirits OU Diffusion (Akerlof-Shiller 2009).
     *
     * Derives rational consumer confidence from inflation, unemployment, interest rates, and energy costs,
     * compounded with an Ornstein-Uhlenbeck stochastic diffusion simulating psychological animal spirits.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateConsumerSentiment(MacroState $state, float $dt): void
    {
        $excessInflation = max(0.0, $state->inflation - MacroEngine::TARGET_INFLATION);
        $excessUnemployment = max(0.0, $state->unemploymentRate - $state->nairu);
        $miseryPenalty = ($excessInflation + $excessUnemployment) * MacroEngine::SENTIMENT_MISERY_MULTIPLIER;

        $inflationMomentum = max(0.0, $state->inflation - $state->inflationEma);
        $unemploymentMomentum = max(0.0, $state->unemploymentRate - $state->unemploymentRateEma);
        $momentumPenalty = ($inflationMomentum + $unemploymentMomentum) * MacroEngine::SENTIMENT_MOMENTUM_MULTIPLIER;

        $excessVolatility = max(0.0, $state->marketVolatility - MacroEngine::MACRO_VOL_BASE_ANCHOR);
        $fearPenalty = $excessVolatility * MacroEngine::SENTIMENT_VOLATILITY_MULTIPLIER;

        $neutral10yYield = $state->naturalRate + MacroEngine::TARGET_INFLATION + MacroEngine::NS_BASE_TERM_PREMIUM;
        $excessYield = max(0.0, $state->yield10y - $neutral10yYield);
        $ratePenalty = $excessYield * MacroEngine::SENTIMENT_RATE_MULTIPLIER;

        $gasPanic = max(0.0, $state->energyPriceShock) * MacroEngine::SENTIMENT_ENERGY_PANIC_SCALE;

        $fundamentalSentiment = MacroEngine::SENTIMENT_BASELINE - $miseryPenalty - $momentumPenalty - $fearPenalty - $ratePenalty - $gasPanic;
        if ($state->outputGap > 0.0) {
            $fundamentalSentiment += ($state->outputGap * MacroEngine::SENTIMENT_EXPANSION_MULTIPLIER);
        } else {
            $fundamentalSentiment += ($state->outputGap * MacroEngine::SENTIMENT_CONTRACTION_MULTIPLIER);
        }

        $currentSentiment = $state->consumerSentimentIndex ?? MacroEngine::SENTIMENT_BASELINE;
        $dW = $this->mathUtility->generateStandardNormal();

        $drift = MacroEngine::ANIMAL_SPIRITS_MEAN_REVERSION * ($fundamentalSentiment - $currentSentiment) * $dt;
        $diffusion = MacroEngine::ANIMAL_SPIRITS_VOLATILITY * sqrt($dt) * $dW;

        $newSentiment = $currentSentiment + $drift + $diffusion;
        $state->consumerSentimentIndex = max(40.0, min(120.0, $newSentiment));
    }

    /**
     * Financial Conditions Index (FCI) Composite Model (Goldman Sachs / Chicago Fed).
     *
     * Constructs a normalized macroeconomic financial conditions index tracking wholesale credit spreads,
     * equity risk premium, real exchange rate deviations, term structure slope, and equity market volatility:
     *   FCI > 0 indicates restrictive financial conditions; FCI < 0 indicates accommodative conditions.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateFinancialConditionsIndex(MacroState $state, float $dt): void
    {
        $creditZ = ($state->macroCreditSpreadEma - MacroEngine::FCI_CREDIT_MEAN) / MacroEngine::FCI_CREDIT_STD;
        $erpZ = ($state->equityRiskPremium - MacroEngine::FCI_ERP_MEAN) / MacroEngine::FCI_ERP_STD;
        $fxZ = ($state->exchangeRateIndexEma - MacroEngine::EXCHANGE_RATE_BASELINE) / MacroEngine::FCI_FX_STD;
        $slopeZ = -($state->nsSlopeEma - MacroEngine::FCI_SLOPE_MEAN) / MacroEngine::FCI_SLOPE_STD;
        $volZ = ($state->marketVolatilityEma - MacroEngine::FCI_VOL_MEAN) / MacroEngine::FCI_VOL_STD;

        $fundamentalFci = (MacroEngine::FCI_CREDIT_SPREAD_WEIGHT * $creditZ)
            + (MacroEngine::FCI_ERP_WEIGHT * $erpZ)
            + (MacroEngine::FCI_EXCHANGE_RATE_WEIGHT * $fxZ)
            + (MacroEngine::FCI_YIELD_SLOPE_WEIGHT * $slopeZ)
            + (MacroEngine::FCI_VOLATILITY_WEIGHT * $volZ);

        $state->financialConditionsIndex += MacroEngine::FCI_MEAN_REVERSION
            * ($fundamentalFci - $state->financialConditionsIndex) * $dt;
    }
}
