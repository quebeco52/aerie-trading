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
     * Merton (1974) Structural Distance-to-Default Corporate Credit Spread Model
     * with Jarrow, Lando & Turnbull (1997) Dual-Tranche (IG vs HY) Rating Migration Cliff.
     *
     * Models investment-grade (IG) and speculative high-yield (HY) corporate credit spreads
     * over risk-free Treasuries driven by leverage decay, equity volatility, wholesale interbank contagion,
     * and the non-linear "fallen angel" rating migration cliff during contractions.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateMacroCreditSpread(MacroState $state): void
    {
        $interbankStress = max(0.0, $state->interbankLiquiditySpreadEma - MacroEngine::INTERBANK_BASELINE_SPREAD);

        $trancheSpreads = $this->mathUtility->calculateDualTrancheCreditSpreads(
            baseIgSpread: MacroEngine::BASE_CREDIT_SPREAD,
            outputGapEma: $state->outputGapEma,
            marketVolEma: $state->marketVolatilityEma,
            interbankStress: $interbankStress,
            hyBaseMultiplier: MacroEngine::HY_BASE_SPREAD_MULTIPLIER,
            fallenAngelSens: MacroEngine::FALLEN_ANGEL_CLIFF_SENSITIVITY
        );

        // Both tranches are already floored and capped inside the formula (MIN/MAX_CREDIT_SPREAD, MAX_HY_CREDIT_SPREAD).
        $state->macroCreditSpread = $trancheSpreads['ig'];
        $state->highYieldCreditSpread = $trancheSpreads['hy'];
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

        // Systemic credit risk coupling (Brunnermeier 2009, Gorton & Metrick 2012):
        // Interbank lending risk premium rises with wholesale corporate credit spreads
        $excessCreditSpread = max(0.0, $state->macroCreditSpread - MacroEngine::BASE_CREDIT_SPREAD);
        $creditCoupledTheta = MacroEngine::INTERBANK_BASELINE_SPREAD + ($excessCreditSpread * MacroEngine::INTERBANK_CREDIT_COUPLING);

        $dW = $this->mathUtility->generateStandardNormal();
        $baseProcess = $this->mathUtility->calculateCIR(
            currentValue: $currentSpread,
            kappa: MacroEngine::INTERBANK_SPREAD_KAPPA,
            theta: $creditCoupledTheta,
            sigma: MacroEngine::INTERBANK_SPREAD_SIGMA,
            dt: $dt,
            dW: $dW
        );

        $volatilityRatio = max(1.0, $state->marketVolatilityEma / MacroEngine::MACRO_VOL_BASE_ANCHOR);
        $creditRatio = max(1.0, $state->macroCreditSpreadEma / MacroEngine::BASE_CREDIT_SPREAD);
        $jumpProbability = min(0.20, MacroEngine::INTERBANK_JUMP_PROBABILITY * $volatilityRatio * (1.0 + 0.5 * ($creditRatio - 1.0)));

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

        $state->interbankLiquiditySpread = max(
            MacroEngine::INTERBANK_MIN_SPREAD,
            min(MacroEngine::INTERBANK_MAX_SPREAD, $baseProcess + $jumpAmount)
        );
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
        $unemploymentShock = ($state->unemploymentRateEma - $state->nairu) * MacroEngine::RETAIL_UNEMPLOYMENT_SENSITIVITY;
        $inflationShock = ($state->inflationEma - MacroEngine::TARGET_INFLATION) * MacroEngine::RETAIL_INFLATION_SENSITIVITY;

        $borrowingSpreadStress = max(0.0, $state->macroCreditSpreadEma - MacroEngine::BASE_CREDIT_SPREAD);
        $interbankStress = max(0.0, $state->interbankLiquiditySpreadEma - MacroEngine::INTERBANK_BASELINE_SPREAD);
        $debtServiceShock = ($borrowingSpreadStress + $interbankStress) * MacroEngine::RETAIL_DEBT_SERVICE_SENSITIVITY;

        $dW = $this->mathUtility->generateStandardNormal();
        $macroZ = - ($unemploymentShock + $inflationShock + $debtServiceShock) + ($dW * MacroEngine::RETAIL_CREDIT_VOLATILITY);

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

    /**
     * Sovereign Debt-to-GDP Stock Accumulation (Blanchard 2019, Greenwood-Vayanos 2014).
     *
     * Accumulates sovereign debt-to-GDP ratio from primary deficit flow, net interest expenses,
     * and nominal GDP growth erosion:
     *   d(Debt/GDP) = [ (G - T)/GDP + (r_10y - g_nominal) * (Debt/GDP) ] * dt
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateSovereignDebt(MacroState $state, float $dt): void
    {
        $taxRevenue = $state->corporateTaxRate * $state->nominalGdpIndex * (1.0 + $state->outputGap);
        $govtSpendingFlow = ($state->governmentSpendingIndex / MacroEngine::GOVT_SPENDING_BASELINE)
            * MacroEngine::TARGET_CORPORATE_TAX_RATE * $state->nominalGdpIndex;

        // Bohn (1998) Fiscal Reaction Function: primary budget surpluses emerge when debt/GDP exceeds neutral threshold
        $excessDebt = max(0.0, $state->sovereignDebtToGdp - MacroEngine::SOVEREIGN_DEBT_NEUTRAL_THRESHOLD);
        $bohnFiscalAdjustment = MacroEngine::BOHN_FISCAL_REACTION_SENSITIVITY * $excessDebt * $state->nominalGdpIndex;

        $primaryDeficit = ($govtSpendingFlow - $taxRevenue) + (MacroEngine::SOVEREIGN_STRUCTURAL_DEFICIT * $state->nominalGdpIndex) - $bohnFiscalAdjustment;
        $interestCost = $state->yield10yEma * $state->sovereignDebtToGdp;

        // Blanchard (2019): Nominal GDP growth includes real potential growth trend (labor + TFP) + cyclical gap + inflation
        $realPotentialGrowth = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT;
        $nominalGrowthRate = $realPotentialGrowth + $state->outputGap + $state->inflationEma;
        $growthErosion = $nominalGrowthRate * $state->sovereignDebtToGdp;

        $dDebt = ($primaryDeficit / max(0.1, $state->nominalGdpIndex)) + $interestCost - $growthErosion;
        $state->sovereignDebtToGdp += $dDebt * $dt;
        $state->sovereignDebtToGdp = max(0.20, min(2.50, $state->sovereignDebtToGdp));
    }

    /**
     * Federal Reserve Senior Loan Officer Opinion Survey (SLOOS) Credit Standards Index.
     *
     * Evaluates net percentage of commercial banks tightening C&I loan standards
     * based on wholesale credit spreads and macroeconomic output gap.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateSloosCreditStandards(MacroState $state, float $dt): void
    {
        $excessCreditSpread = max(0.0, $state->macroCreditSpread - MacroEngine::BASE_CREDIT_SPREAD);
        $dW = $this->mathUtility->generateStandardNormal();

        $state->sloosTighteningIndex = $this->mathUtility->calculateSloosCreditStandards(
            currentSloos: $state->sloosTighteningIndex,
            outputGap: $state->outputGapEma,
            excessCreditSpread: $excessCreditSpread,
            dt: $dt,
            dW: $dW,
            kappa: MacroEngine::SLOOS_KAPPA,
            creditSensitivity: MacroEngine::SLOOS_CREDIT_SENSITIVITY,
            gapSensitivity: MacroEngine::SLOOS_GAP_SENSITIVITY,
            sigma: MacroEngine::SLOOS_SIGMA
        );
    }

    /**
     * Moody's / S&P Speculative-Grade Corporate Default Rate Model.
     *
     * Derives realized corporate probability of default (CDR) driven by a structural
     * macroeconomic credit factor combining output gap, speculative high-yield credit spreads,
     * and bank lending standards (SLOOS).
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateCorporateDefaultRate(MacroState $state, float $dt): void
    {
        $baseHySpread = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;
        $excessHySpread = max(0.0, $state->highYieldCreditSpread - $baseHySpread);

        $macroZ = ($state->outputGapEma * MacroEngine::CORPORATE_DEFAULT_GAP_SENSITIVITY)
            - ($excessHySpread * MacroEngine::CORPORATE_DEFAULT_SPREAD_SENSITIVITY)
            - ($state->sloosTighteningIndexEma * MacroEngine::CORPORATE_DEFAULT_SLOOS_SENSITIVITY);

        $state->corporateDefaultRate = $this->mathUtility->calculateCorporateDefaultRate(
            macroZ: $macroZ,
            baseDefaultRate: MacroEngine::CORPORATE_DEFAULT_BASELINE,
            rho: MacroEngine::CORPORATE_DEFAULT_RHO
        );
    }
}
