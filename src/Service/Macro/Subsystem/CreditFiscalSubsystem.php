<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;

/**
 * Handles corporate and retail credit spreads, interbank liquidity (TED spread),
 * government spending appropriations, and countercyclical corporate tax policy.
 */
class CreditFiscalSubsystem
{
    public function __construct(
        private readonly MathUtility $mathUtility
    ) {}

    /**
     * Merton (1974) Structural Distance-to-Default Corporate Credit Spread Model.
     *
     * Models aggregate investment-grade corporate credit spread over risk-free Treasuries
     * driven by leverage decay during downturns, equity volatility, and wholesale interbank contagion.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateMacroCreditSpread(MacroState $state): void
    {
        $cycleSpread = MacroEngine::BASE_CREDIT_SPREAD * exp(-MacroEngine::MERTON_LEVERAGE_SENSITIVITY * $state->outputGapEma);
        $excessVol = max(0.0, $state->marketVolatilityEma - 0.20);
        $volSpread = MacroEngine::MERTON_VOL_SENSITIVITY * $excessVol;

        $interbankStress = max(0.0, $state->interbankLiquiditySpreadEma - MacroEngine::INTERBANK_BASELINE_SPREAD);
        $contagionSpread = $interbankStress * MacroEngine::INTERBANK_CREDIT_CONTAGION_SENSITIVITY;

        $state->macroCreditSpread = max(0.008, min(MacroEngine::MAX_CREDIT_SPREAD, $cycleSpread + $volSpread + $contagionSpread));
    }

    /**
     * Cox-Ingersoll-Ross (CIR 1985) Square-Root Diffusion with Systemic TED Freeze Jumps (Kou 2002).
     *
     * Models wholesale interbank lending liquidity spreads (TED / Libor-OIS spread) using a strictly
     * positive mean-reverting CIR square-root process compounded with volatility-sensitive Poisson panic jumps.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateInterbankLiquiditySpread(MacroState $state, float $dt): void
    {
        $currentSpread = $state->interbankLiquiditySpread ?? MacroEngine::INTERBANK_BASELINE_SPREAD;

        $dW = $this->mathUtility->generateStandardNormal();
        $baseProcess = $this->mathUtility->calculateCIR(
            currentValue: $currentSpread,
            kappa: MacroEngine::INTERBANK_SPREAD_KAPPA,
            theta: MacroEngine::INTERBANK_BASELINE_SPREAD,
            sigma: MacroEngine::INTERBANK_SPREAD_SIGMA,
            dt: $dt,
            dW: $dW
        );

        $volatilityRatio = max(1.0, $state->marketVolatilityEma / MacroEngine::MACRO_VOL_BASE_ANCHOR);
        $jumpProbability = min(0.10, MacroEngine::INTERBANK_JUMP_PROBABILITY * $volatilityRatio);

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: $jumpProbability,
            jumpMean: MacroEngine::INTERBANK_JUMP_MEAN,
            jumpVol: MacroEngine::INTERBANK_JUMP_VOL,
            dt: $dt
        );

        $jumpAmount = 0.0;
        if ($jumpData['multiplier'] !== 1.0) {
            $jumpAmount = $baseProcess * ($jumpData['multiplier'] - 1.0);
        }

        $state->interbankLiquiditySpread = max(0.0001, min(0.10, $baseProcess + $jumpAmount));
    }

    /**
     * Basel II / III Vasicek Asymptotic Single Risk Factor (ASRF) Consumer Credit Portfolio Model.
     *
     * Derives conditional retail loan default probability (PD) driven by a macroeconomic systemic factor
     * reflecting unemployment shocks (Okun's Law) and inflation-induced disposable income erosion.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateRetailDefaultRate(MacroState $state, float $dt): void
    {
        $unemploymentShock = ($state->unemploymentRateEma - MacroEngine::NATURAL_UNEMPLOYMENT) * MacroEngine::RETAIL_UNEMPLOYMENT_SENSITIVITY;
        $inflationShock = ($state->inflationEma - MacroEngine::TARGET_INFLATION) * MacroEngine::RETAIL_INFLATION_SENSITIVITY;

        $dW = $this->mathUtility->generateStandardNormal();
        $macroZ = - ($unemploymentShock + $inflationShock) + ($dW * MacroEngine::RETAIL_CREDIT_VOLATILITY);

        $conditionalPd = $this->mathUtility->calculateVasicekExpectedLoss(
            macroZ: $macroZ,
            pdLra: MacroEngine::RETAIL_DEFAULT_BASELINE,
            rho: MacroEngine::RETAIL_ASRF_RHO,
            lgd: 1.0
        );

        $state->retailDefaultRate = max(0.005, min(0.20, $conditionalPd));
    }

    /**
     * Keynesian Countercyclical Fiscal Spending Rule with Geopolitical Defense Jumps.
     *
     * Adjusts sovereign government spending countercyclically against GDP output gap deviations,
     * combined with Schwartz (1997) mean reversion and Poisson geopolitical conflict appropriation jumps.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateGovernmentSpending(MacroState $state, float $dt): void
    {
        $cyclicalTarget = MacroEngine::GOVT_SPENDING_BASELINE - ($state->outputGapEma * MacroEngine::GOVT_COUNTERCYCLICAL_SENSITIVITY);
        $targetSpending = max(60.0, min(160.0, $cyclicalTarget));

        $dW = $this->mathUtility->generateStandardNormal();
        $baseProcess = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->governmentSpendingIndex,
            kappa: MacroEngine::GOVT_SPENDING_MEAN_REVERSION,
            theta: $targetSpending,
            sigma: MacroEngine::GOVT_SPENDING_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: MacroEngine::GEOPOLITICAL_JUMP_PROBABILITY,
            jumpMean: MacroEngine::GEOPOLITICAL_JUMP_MEAN,
            jumpVol: MacroEngine::GEOPOLITICAL_JUMP_VOL,
            dt: $dt
        );

        $jumpAmount = 0.0;
        if ($jumpData['multiplier'] !== 1.0) {
            $jumpAmount = $baseProcess * ($jumpData['multiplier'] - 1.0);
        }

        $newSpending = $baseProcess + $jumpAmount;
        $state->governmentSpendingIndex = max(60.0, min(200.0, $newSpending));
    }

    /**
     * Barro (1979) Tax-Smoothing Hypothesis & Automatic Fiscal Stabilizers.
     *
     * Adjusts the corporate tax rate continuously via an Ornstein-Uhlenbeck institutional process:
     * raises effective tax burden during economic booms to cool demand, and cuts taxes during recessions.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateDynamicFiscalPolicy(MacroState $state, float $dt): void
    {
        $targetTaxRate = MacroEngine::TARGET_CORPORATE_TAX_RATE + (MacroEngine::FISCAL_STABILIZER_SENSITIVITY * $state->outputGapEma);
        $targetTaxRate = max(MacroEngine::MIN_CORPORATE_TAX_RATE, min(MacroEngine::MAX_CORPORATE_TAX_RATE, $targetTaxRate));

        $state->corporateTaxRate += MacroEngine::FISCAL_ADJUSTMENT_SPEED * ($targetTaxRate - $state->corporateTaxRate) * $dt;
    }
}
