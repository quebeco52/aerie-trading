<?php

declare(strict_types=1);

namespace App\Service\Math;

use App\Service\Macro\MacroEngine;

/**
 * Credit risk: Vasicek loss and systematic factor, collateral LGD and recovery, Merton asset-value solves, tranche
 * spreads, leverage limits, default-rate and lending-standards curves, distress multipliers, and the crisis and
 * recession probabilities (Schularick-Taylor, Estrella-Mishkin). Pure functions of their arguments.
 */
final class CreditRisk
{
    // --- Corporate Credit: Recovery Given Default (Altman, Brady, Resti & Sironi 2005) ---
    /** Aggregate corporate default rate the base recoveries above are quoted at: the long-run average year of the series the macro publishes. */
    public const RECOVERY_BASELINE_DEFAULT_RATE = MacroEngine::CORPORATE_DEFAULT_BASELINE;
    /** Fall in recovery per unit of log excess in the aggregate default rate. Recovery and default are NEGATIVELY correlated: defaults cluster in bad years, distressed assets are sold into a market with no buyers, and the same claim is worth less precisely when more of them are being settled. Ignoring it prices the tail of a credit portfolio far too kindly. */
    public const RECOVERY_DEFAULT_RATE_ELASTICITY = 0.12;
    /** Bounds on recovery. Nothing recovers everything once it has defaulted, and even a wiped-out claim usually salvages something. */
    public const MIN_RECOVERY_RATE = 0.05;
    public const MAX_RECOVERY_RATE = 0.90;

    // --- Corporate Credit: Spread Composition (Longstaff, Mithal & Neis 2005) ---
    /** Non-default component of a corporate spread: what a buyer charges for holding a claim they cannot sell as readily as a sovereign. Measured to be a material minority of an investment-grade spread, so a bond priced on default risk alone quotes through the market. */
    public const CORPORATE_ILLIQUIDITY_SPREAD = 0.0040;
    /** Ceiling on the credit spread a listed issue may be discounted at, matching the cap the Merton spread itself carries. */
    public const MAX_CORPORATE_SPREAD = 1.00;

    /**
     * A leverage (debt-to-equity) limit with a capital buffer added to the equity it implies.
     *
     * A debt-to-equity cap L is a minimum equity share of assets 1/(1+L). Basel III's countercyclical buffer
     * is an add-on to that minimum, so the buffered cap is the leverage that the raised share allows:
     * L' = 1 / (1/(1+L) + b) - 1. At L = 10 the full 2.5% buffer takes the cap to 7.6.
     *
     * @param float $leverageLimit Debt-to-equity cap without the buffer.
     * @param float $capitalBuffer Buffer as a share of assets (0 to 0.025 under Basel III).
     * @return float Debt-to-equity cap with the buffer, never above the unbuffered cap.
     */
    public static function calculateBufferedLeverageLimit(float $leverageLimit, float $capitalBuffer): float
    {
        $equityShare = 1.0 / (1.0 + max(0.0, $leverageLimit));

        return max(0.0, (1.0 / ($equityShare + max(0.0, $capitalBuffer))) - 1.0);
    }

    /**
     * Calculates portfolio Expected Loss using the Basel II/III Asymptotic Single Risk Factor (ASRF) Vasicek model.
     *
     * Formula: PD(Z) = \Phi( (\Phi^{-1}(PD_LRA) - \sqrt{\rho} * Z) / \sqrt{1 - \rho} )
     *          EL(Z) = PD(Z) * LGD
     *
     * @param float $macroZ Macroeconomic credit shock Z-score (Z < 0 is recession/distress; Z > 0 is economic boom).
     * @param float $pdLra  Long-run average (through-the-cycle) probability of default.
     * @param float $rho    Asset correlation factor (\rho \in (0, 1)).
     * @param float $lgd    Loss given default (\in [0, 1]).
     * @return float Expected credit loss rate on the portfolio.
     */
    public static function calculateVasicekExpectedLoss(float $macroZ, float $pdLra, float $rho, float $lgd): float
    {
        $pdLra = max(1e-6, min(0.999, $pdLra));
        $rho   = max(0.001, min(0.999, $rho));
        $lgd   = max(0.0, min(1.0, $lgd));

        $invPd = Distributions::calculateInverseNormalCDF($pdLra);
        $numerator = $invPd - (sqrt($rho) * $macroZ);
        $denominator = sqrt(1.0 - $rho);

        $conditionalPd = Distributions::calculateNormalCDF($numerator / $denominator);

        return $conditionalPd * $lgd;
    }

    /**
     * The systematic factor implied by an observed default rate: the Vasicek conditional PD solved for Z, the credit
     * cycle index of Belkin, Suchower & Forest (1998). A lender holding a different book at its own correlation
     * then reads the same cycle through calculateVasicekExpectedLoss().
     *
     * Formula: Z = (\Phi^{-1}(PD_LRA) - \sqrt{1 - \rho} * \Phi^{-1}(PD_t)) / \sqrt{\rho}
     */
    public static function calculateVasicekSystematicFactor(float $observedPd, float $pdLra, float $rho): float
    {
        $observedPd = max(1e-6, min(0.999, $observedPd));
        $pdLra = max(1e-6, min(0.999, $pdLra));
        $rho   = max(0.001, min(0.999, $rho));

        return (Distributions::calculateInverseNormalCDF($pdLra) - (sqrt(1.0 - $rho) * Distributions::calculateInverseNormalCDF($observedPd))) / sqrt($rho);
    }

    /**
     * Loss given default on a secured loan whose recovery scales with its collateral (Frye 2000, "Collateral
     * Damage"): a fall in the collateral's value since origination lowers recovery one for one, so LGD rises in the
     * same downturn that raises defaults.
     *
     * Formula: LGD_t = 1 - (1 - LGD_0) * P_t / P_origination, bounded to [0, 1].
     */
    public static function calculateCollateralLgd(float $baseLgd, float $collateralValue, float $originationValue): float
    {
        if ($originationValue <= 0.0) {
            return max(0.0, min(1.0, $baseLgd));
        }

        return max(0.0, min(1.0, 1.0 - ((1.0 - $baseLgd) * max(0.0, $collateralValue) / $originationValue)));
    }

    /**
     * The market value of a firm's assets implied by its equity, for a known asset volatility.
     *
     * Inverts Merton's (1974) equity-as-a-call, E = V N(d1) - D e^(-rT) N(d2), for V: the KMV market value of
     * assets (Crosbie & Bohn 2003). Newton's method: the call is convex and increasing in V and the start
     * E + D e^(-rT) sits at or above the root, so every step lands between the root and the last iterate and
     * the iteration descends onto it without overshooting.
     *
     * @param float $equityValue     Market value of equity (E).
     * @param float $assetVolatility Annualised volatility of the assets (sigma_V).
     * @param float $debtFaceValue   Face value of debt, the default barrier (D).
     * @param float $riskFreeRate    Continuously compounded risk-free rate (r).
     * @param float $timeToMaturity  Horizon in years (T).
     * @return float Asset value V; 0.0 when equity has no value to solve from.
     */
    public static function solveMertonAssetValue(
        float $equityValue,
        float $assetVolatility,
        float $debtFaceValue,
        float $riskFreeRate,
        float $timeToMaturity
    ): float {
        if ($equityValue <= 0.0) {
            return 0.0;
        }
        if ($debtFaceValue <= 0.0) {
            return $equityValue;
        }

        $discountedDebt = $debtFaceValue * exp(-$riskFreeRate * max(0.0, $timeToMaturity));
        if ($assetVolatility <= 0.0 || $timeToMaturity <= 0.0) {
            return $equityValue + $discountedDebt;
        }

        $rootT = sqrt($timeToMaturity);
        $assetValue = $equityValue + $discountedDebt;
        for ($i = 0; $i < 500; $i++) {
            $d1 = (log($assetValue / $debtFaceValue) + (($riskFreeRate + (0.5 * $assetVolatility * $assetVolatility)) * $timeToMaturity))
                / ($assetVolatility * $rootT);
            $delta = Distributions::calculateNormalCDF($d1);
            $callValue = ($assetValue * $delta) - ($discountedDebt * Distributions::calculateNormalCDF($d1 - ($assetVolatility * $rootT)));
            $step = ($callValue - $equityValue) / max(1.0e-12, $delta);
            $assetValue -= $step;
            if (abs($step) <= 1.0e-12 * $assetValue) {
                break;
            }
        }

        return $assetValue;
    }

    /**
     * Recovers the volatility of a firm's assets from its equity's market value and volatility.
     *
     * Merton (1974) prices equity as a call on the firm's assets struck at the face value of its debt, and
     * Ito's lemma ties the two volatilities through the call's delta. The pair is solved jointly (Jones,
     * Mason & Rosenfeld 1984; the Moody's KMV procedure in Crosbie & Bohn 2003):
     *
     *     E = V N(d1) - D e^(-rT) N(d2)
     *     sigma_E E = N(d1) sigma_V V
     *
     * by fixed-point iteration on sigma_V, recovering V from the first equation at each step.
     *
     * @param float $equityValue      Market value of equity (E).
     * @param float $equityVolatility Annualised volatility of equity (sigma_E).
     * @param float $debtFaceValue    Face value of debt, the default barrier (D).
     * @param float $riskFreeRate     Continuously compounded risk-free rate (r).
     * @param float $timeToMaturity   Horizon in years (T).
     * @return float Asset volatility sigma_V; 0.0 when equity has no value to solve from.
     */
    public static function solveMertonAssetVolatility(
        float $equityValue,
        float $equityVolatility,
        float $debtFaceValue,
        float $riskFreeRate,
        float $timeToMaturity
    ): float {
        if ($equityValue <= 0.0 || $equityVolatility <= 0.0) {
            return 0.0;
        }
        if ($debtFaceValue <= 0.0 || $timeToMaturity <= 0.0) {
            return $equityVolatility;
        }

        $rootT = sqrt($timeToMaturity);
        $assetVolatility = $equityVolatility * $equityValue / ($equityValue + $debtFaceValue);

        for ($outer = 0; $outer < 100; $outer++) {
            $assetValue = self::solveMertonAssetValue($equityValue, $assetVolatility, $debtFaceValue, $riskFreeRate, $timeToMaturity);
            $d1 = (log($assetValue / $debtFaceValue) + (($riskFreeRate + (0.5 * $assetVolatility * $assetVolatility)) * $timeToMaturity))
                / ($assetVolatility * $rootT);
            $next = $equityVolatility * $equityValue / (max(1.0e-12, Distributions::calculateNormalCDF($d1)) * $assetValue);
            if (abs($next - $assetVolatility) <= 1.0e-10) {
                return $next;
            }
            $assetVolatility = $next;
        }

        return $assetVolatility;
    }

    /**
     * Calculates Investment Grade (IG) and High Yield (HY) corporate credit spreads.
     *
     * The IG spread is Gilchrist & Zakrajsek's (2012) decomposition: a default-risk part (Merton leverage on the
     * output gap and equity volatility) plus the excess bond premium lenders charge on top of it, plus interbank
     * contagion. HY is a fixed multiple of it: at the December 2008 peak ICE HY and IG OAS stood near 21.8% and
     * 6.5%, the same ~3.3x as in calm years, so a blowout rides on IG's volatility and premium legs, which fade
     * within a year of a crash, rather than on a separate cliff that held HY wide for as long as the gap stayed low.
     *
     * @param float $baseIgSpread      Baseline investment-grade spread (e.g. 0.020).
     * @param float $outputGapEma      Smoothed macroeconomic output gap.
     * @param float $marketVolEma      Smoothed equity market volatility.
     * @param float $interbankStress   Wholesale interbank liquidity stress above baseline.
     * @param float $excessBondPremium Excess bond premium (GZ units, signed).
     * @param float $premiumLoading    IG spread per unit of excess bond premium.
     * @param float $hyBaseMultiplier  Multiple of HY spread over IG spread (e.g. 3.3x).
     * @param float $leverageSens     Merton distance-to-default sensitivity of the IG spread to the output gap.
     * @param float $volSens          IG spread widening per unit of equity volatility above the threshold.
     * @param float $volThreshold     Equity volatility below which no volatility premium is charged.
     * @param float $contagionSens    IG spread widening per unit of interbank stress.
     * @param float $minIgSpread      Floor on the IG spread.
     * @param float $maxIgSpread      Cap on the IG spread.
     * @return array{ig: float, hy: float} Calculated IG and HY credit spreads.
     */
    public static function calculateDualTrancheCreditSpreads(
        float $baseIgSpread,
        float $outputGapEma,
        float $marketVolEma,
        float $interbankStress,
        float $excessBondPremium = 0.0,
        float $premiumLoading = MacroEngine::CREDIT_SPREAD_PREMIUM_LOADING,
        float $hyBaseMultiplier = MacroEngine::HY_BASE_SPREAD_MULTIPLIER,
        float $leverageSens = MacroEngine::MERTON_LEVERAGE_SENSITIVITY,
        float $volSens = MacroEngine::MERTON_VOL_SENSITIVITY,
        float $volThreshold = MacroEngine::CREDIT_SPREAD_EXCESS_VOL_THRESHOLD,
        float $contagionSens = MacroEngine::INTERBANK_CREDIT_CONTAGION_SENSITIVITY,
        float $minIgSpread = MacroEngine::MIN_CREDIT_SPREAD,
        float $maxIgSpread = MacroEngine::MAX_CREDIT_SPREAD
    ): array {
        $cycleSpread = $baseIgSpread * exp(-$leverageSens * $outputGapEma);
        $excessVol = max(0.0, $marketVolEma - $volThreshold);
        $volSpread = $volSens * $excessVol;
        $contagionSpread = $interbankStress * $contagionSens;
        $premiumSpread = $excessBondPremium * $premiumLoading;

        $igSpread = max($minIgSpread, min($maxIgSpread, $cycleSpread + $volSpread + $premiumSpread + $contagionSpread));

        // The IG caps bound it: at the IG record the tranche sits at the ~21.8% HY record of December 2008.
        $hySpread = $igSpread * $hyBaseMultiplier;

        return [
            'ig' => $igSpread,
            'hy' => $hySpread,
        ];
    }

    /**
     * Calculates the 12-month forward recession probability using the Estrella & Mishkin (1998) probit model.
     *
     * Evaluates market-implied recession probability based on yield curve slope (10Y minus policy rate),
     * term premium, and the Financial Conditions Index:
     *   P(Recession) = NormalCDF(beta0 + betaSlope * slope + betaTp * termPremium + betaFci * FCI)
     *
     * @param float $slope       Yield curve slope (Yield10Y minus policy rate).
     * @param float $termPremium Sovereign 10Y term premium.
     * @param float $fci         Financial Conditions Index (FCI > 0 is restrictive).
     * @param float $beta0       Probit intercept parameter (calibrated to ~15% baseline probability at neutral slope).
     * @param float $betaSlope   Sensitivity to yield curve slope.
     * @param float $betaTp      Sensitivity to term premium compression.
     * @param float $betaFci     Sensitivity to financial conditions tightening.
     * @return float 12-month forward recession probability clamped between 1% and 99%.
     */
    public static function calculateEstrellaMishkinProbability(
        float $slope,
        float $termPremium,
        float $fci,
        float $beta0 = MacroEngine::RECESSION_PROBIT_BETA_0,
        float $betaSlope = MacroEngine::RECESSION_PROBIT_BETA_SLOPE,
        float $betaTp = MacroEngine::RECESSION_PROBIT_BETA_TP,
        float $betaFci = MacroEngine::RECESSION_PROBIT_BETA_FCI
    ): float {
        $probitIndex = $beta0 + ($betaSlope * $slope) + ($betaTp * $termPremium) + ($betaFci * $fci);
        $probability = Distributions::calculateNormalCDF($probitIndex);
        return max(0.01, min(0.99, $probability));
    }

    /**
     * Annual financial-crisis hazard from the credit cycle (Schularick & Taylor 2012 "Credit Booms Gone Bust").
     *
     * Schularick and Taylor estimate a logit of crisis onset on the lagged credit cycle over the long run of
     * advanced-economy data; here the regressor is the credit gap, the stock over its slow one-sided trend:
     *   h = 1 / (1 + exp(-(beta0 + betaGap * creditGap)))
     *
     * @param float $creditGap Credit gap (stock over its slow one-sided trend), in the units betaGap is quoted in.
     * @param float $beta0     Logit intercept (log-odds of a crisis in a year with no boom).
     * @param float $betaGap   Logit sensitivity to the credit gap.
     * @return float Annual crisis hazard in [0, 0.99].
     */
    public static function calculateSchularickTaylorCrisisHazard(
        float $creditGap,
        float $beta0,
        float $betaGap
    ): float {
        $logit = $beta0 + ($betaGap * $creditGap);
        return min(0.99, ResponseCurves::logisticUnitInterval($logit, 0.0, 1.0));
    }

    /**
     * Calculates the aggregate all-rated corporate default rate (Moody's issuer-weighted series).
     *
     * Derives realized corporate defaults via the Merton/Vasicek structural credit portfolio transition:
     *   CDR = NormalCDF( (InverseNormalCDF(baseDefault) - sqrt(rho) * macroZ) / sqrt(1 - rho) )
     *
     * @param float $macroZ          Composite macroeconomic credit Z-score.
     * @param float $baseDefaultRate Long-run average all-rated corporate default rate (~1.6%).
     * @param float $rho             Asset correlation of the default rate (~0.1 on the post-1983 record).
     * @return float Realized annual corporate default rate clamped between 0.2% and 18%.
     */
    public static function calculateCorporateDefaultRate(
        float $macroZ,
        float $baseDefaultRate = MacroEngine::CORPORATE_DEFAULT_BASELINE,
        float $rho = \App\Service\Macro\Subsystem\CreditFiscalSubsystem::CORPORATE_DEFAULT_RHO
    ): float {
        $invPd = Distributions::calculateInverseNormalCDF($baseDefaultRate);
        $sqrtRho = sqrt($rho);
        $numerator = $invPd - ($sqrtRho * $macroZ);
        $denominator = sqrt(1.0 - $rho);
        $conditionalPd = Distributions::calculateNormalCDF($numerator / $denominator);

        return max(0.002, min(0.18, $conditionalPd));
    }

    /**
     * Models the Federal Reserve Senior Loan Officer Opinion Survey (SLOOS) Credit Standards Index.
     *
     * Evaluates the net percentage of domestic commercial banks tightening lending standards for C&I loans
     * via an Ornstein-Uhlenbeck continuous adjustment process driven by the credit risk premium and the output gap:
     *   Target = creditSens * excessCreditSpread - gapSens * outputGap
     *
     * @param float $currentSloos        Current net tightening percentage.
     * @param float $outputGap           Current output gap.
     * @param float $excessCreditSpread  Credit risk premium above baseline (the macro passes the excess bond premium).
     * @param float $dt                  Time increment in years.
     * @param float $dW                  Standard normal random shock.
     * @param float $kappa               Speed of adjustment toward target standards.
     * @param float $creditSensitivity   Net tightening per unit of credit risk premium.
     * @param float $gapSensitivity      Sensitivity to economic contraction.
     * @param float $sigma               Diffusion volatility of bank underwriting standards.
     * @return float Updated net tightening fraction clamped between -40% (easing) and +85% (severe credit crunch).
     */
    public static function calculateSloosCreditStandards(
        float $currentSloos,
        float $outputGap,
        float $excessCreditSpread,
        float $dt,
        float $dW,
        float $kappa = 1.80,
        float $creditSensitivity = 15.0,
        float $gapSensitivity = 3.0,
        float $sigma = 0.08
    ): float {
        $targetSloos = ($creditSensitivity * $excessCreditSpread) - ($gapSensitivity * $outputGap);
        $drift = $kappa * ($targetSloos - $currentSloos) * $dt;
        $diffusion = $sigma * sqrt($dt) * $dW;
        $newSloos = $currentSloos + $drift + $diffusion;

        return max(-0.40, min(0.85, $newSloos));
    }

    /**
     * Calculates a capacity-constrained counter-cyclical revenue expansion multiplier for distressed debt funds.
     *
     * In empirical corporate finance and distressed debt workouts (Acharya, Shin, & Yorulmazer 2011; Oaktree Capital),
     * asset recovery opportunities surge during credit crises but are bounded by total market inventory and
     * market impact friction. This is modeled via a Michaelis-Menten / Hill capacity saturation function:
     * ΔM = M_max * (S / (S + K_s)).
     *
     * @param float $rawDistressSignal Aggregate credit spread, default rate, and output gap distress intensity signal.
     * @param float $maxMultiplier     Maximum asymptotic revenue expansion multiplier (e.g. 1.25 = +125%).
     * @param float $halfSaturation    Distress intensity signal at which half of the maximum surge is realized.
     * @return float Diminishing counter-cyclical revenue expansion multiplier in [0, maxMultiplier].
     */
    public static function calculateDiminishingDistressMultiplier(
        float $rawDistressSignal,
        float $maxMultiplier = 1.25,
        float $halfSaturation = 1.0
    ): float {
        if ($rawDistressSignal <= 0.0) {
            return 0.0;
        }

        $ks = max(0.001, $halfSaturation);
        return $maxMultiplier * ($rawDistressSignal / ($rawDistressSignal + $ks));
    }

    /**
     * Calculates the mark-to-market revaluation of a credit portfolio under a spread move (first-order duration approximation).
     *
     * Standard fixed income sensitivity: \Delta P / P \approx -D_s * \Delta s, where D_s is the spread duration
     * of the book. Spreads gapping wider reprice held bonds and preferreds downward in the current period,
     * while spread compression books an unrealized gain. This is distinct from carry: the loss lands immediately,
     * the higher yield is only earned over subsequent periods.
     *
     * @param float $spreadChange   Change in credit spread over the period (positive = widening).
     * @param float $spreadDuration Spread duration of the credit book in years.
     * @return float Portfolio revaluation as a fraction of book value, bounded in [-0.40, 0.40].
     */
    public static function calculateCreditSpreadMarkToMarket(float $spreadChange, float $spreadDuration): float
    {
        $duration = max(0.0, $spreadDuration);
        return max(-0.40, min(0.40, -$spreadChange * $duration));
    }

    /**
     * Recovery on a defaulted claim, as a share of face.
     *
     * Recovery is not a constant, and treating it as one is the single most common way a credit model
     * understates its own tail. Altman, Brady, Resti & Sironi (2005) measure what practitioners had long
     * suspected: recovery rates and default rates are NEGATIVELY correlated. Defaults cluster in bad years,
     * which is exactly when distressed assets are being sold into a market with no buyers for them, so the
     * same claim is worth less precisely when more of them are being settled. A portfolio priced at an
     * average recovery is therefore priced at a recovery it will not get in the year it needs one.
     *
     * Specified log-linearly in the aggregate default rate around the year the base recoveries are quoted
     * for, which is the shape their regressions take:
     *
     *     recovery = base + elasticity * ln(baseline default rate / realized default rate)
     *
     * so a default rate at the long-run average returns the base unchanged, a rate at twice the average
     * takes off elasticity * ln(2), and an unusually quiet year recovers slightly more than the base.
     *
     * @param float $baseRecovery        Recovery for the claim's seniority in an average default year.
     * @param float $defaultRate         The realized aggregate corporate default rate.
     * @param float $baselineDefaultRate The rate the base recovery is quoted at.
     * @return float Share of face recovered, bounded.
     */
    public static function calculateRecoveryGivenDefault(
        float $baseRecovery,
        float $defaultRate,
        float $baselineDefaultRate = self::RECOVERY_BASELINE_DEFAULT_RATE
    ): float {
        if ($defaultRate <= 0.0 || $baselineDefaultRate <= 0.0) {
            return max(self::MIN_RECOVERY_RATE, min(self::MAX_RECOVERY_RATE, $baseRecovery));
        }

        $cyclical = self::RECOVERY_DEFAULT_RATE_ELASTICITY
            * log($baselineDefaultRate / $defaultRate);

        return max(
            self::MIN_RECOVERY_RATE,
            min(self::MAX_RECOVERY_RATE, $baseRecovery + $cyclical)
        );
    }

    /**
     * Calculates the Distance to Default (DD) using Merton's Structural Model.
     *
     * @param float $assetValue      The total value of the firm's assets (V).
     * @param float $debtFaceValue   The face value of the firm's debt (D).
     * @param float $assetVolatility The volatility of the firm's assets (sigma_V).
     * @param float $riskFreeRate    The risk-free rate (r).
     * @param float $timeToMaturity  The time to maturity of the debt in years (T).
     * @return float The Distance to Default in standard deviations.
     */
    public static function calculateDistanceToDefault(float $assetValue, float $debtFaceValue, float $assetVolatility, float $riskFreeRate, float $timeToMaturity = 1.0): float
    {
        if ($debtFaceValue <= 0.0 || $assetValue <= 0.0 || $assetVolatility <= 0.0 || $timeToMaturity <= 0.0) {
            return 10.0; // Effectively no default risk
        }

        $d1 = (log($assetValue / $debtFaceValue) + ($riskFreeRate + 0.5 * pow($assetVolatility, 2.0)) * $timeToMaturity)
            / ($assetVolatility * sqrt($timeToMaturity));

        // In the Merton model, the actual Distance to Default is d2
        $d2 = $d1 - ($assetVolatility * sqrt($timeToMaturity));

        return $d2;
    }

    /**
     * Calculates the theoretical credit spread based on Merton's Structural Model.
     *
     * @param float $distanceToDefault The distance to default (d2).
     * @param float $lossGivenDefault  The expected loss percentage if default occurs (LGD).
     * @param float $timeToMaturity    The time to maturity of the debt in years (T).
     * @return float The theoretical credit spread in decimal (e.g. 0.02 for 2%).
     */
    public static function calculateMertonCreditSpread(float $distanceToDefault, float $lossGivenDefault = 0.40, float $timeToMaturity = 1.0): float
    {
        // Probability of Default (PD) is N(-DD)
        $probabilityOfDefault = Distributions::calculateNormalCDF(-$distanceToDefault);

        // Failsafe: Cap PD slightly below 1.0 to prevent log(0) in the spread formula
        $probabilityOfDefault = min(0.9999, $probabilityOfDefault);

        $spread = - (1.0 / $timeToMaturity) * log(1.0 - ($probabilityOfDefault * $lossGivenDefault));

        // Failsafe: Prevent negative spreads or astronomical blowout
        return max(0.0, min(1.0, $spread)); // Max spread capped at 10,000 bps
    }

    /**
     * The spread a corporate issue is discounted at, over the sovereign curve.
     *
     * Two components, because a corporate spread is not all compensation for default. Longstaff, Mithal &
     * Neis (2005) separate the two by comparing bond spreads with credit default swap premia and find a
     * material non-default residual: a buyer charges for holding a claim they cannot sell as readily as a
     * sovereign, whether or not the issuer is ever going to miss a payment. A bond priced on default risk
     * alone quotes through the market at every rating, and worst at the safe end, where the default
     * component is nearly nothing and the residual is nearly all of it.
     *
     * The default component is the issuer's own Merton spread at the claim's horizon, so it carries the
     * term structure of default risk rather than a flat number: a firm close to the barrier is far riskier
     * over ten years than over one, and a distressed one is riskier over one year than over ten because it
     * either survives that year or does not.
     *
     * @param float $distanceToDefault The issuer's Merton d2.
     * @param float $lossGivenDefault  One minus the recovery on this claim.
     * @param float $timeToMaturity    Years to maturity.
     * @param float $illiquidityPremium Non-default component.
     * @return float Continuously compounded spread over the sovereign curve.
     */
    public static function calculateCorporateSpread(
        float $distanceToDefault,
        float $lossGivenDefault,
        float $timeToMaturity,
        float $illiquidityPremium = self::CORPORATE_ILLIQUIDITY_SPREAD
    ): float {
        $defaultComponent = self::calculateMertonCreditSpread(
            $distanceToDefault,
            $lossGivenDefault,
            max(1.0e-6, $timeToMaturity)
        );

        return max(0.0, min(
            self::MAX_CORPORATE_SPREAD,
            $defaultComponent + max(0.0, $illiquidityPremium)
        ));
    }
}
