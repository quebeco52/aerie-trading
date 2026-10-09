<?php

declare(strict_types=1);

namespace App\Service\Math;

use App\Service\Macro\MacroEngine;

/**
 * Equity valuation: fair P/E from intrinsic value and quality, expected and fundable growth, the dividend discount
 * model, WACC, CAPM and levered beta, ROIC-implied equity returns, the flexible accelerator, and perpetuity shares.
 * Pure functions of their arguments.
 */
final class Valuation
{
    /**
     * The growth rate a perpetuity may be struck on: bounded to [-5%, +6%] and at least 50bp below the cost of equity
     * (floored at MIN_COST_OF_EQUITY), so no perpetuity divides by a vanishing spread. Every perpetuity struck on the
     * same expected growth reads it through here, so they cannot disagree about where it is clamped.
     */
    public static function perpetualGrowthRate(float $costOfEquity, float $growthRate): float
    {
        $clampedGrowth = max(FinancialConstants::MIN_PERPETUAL_GROWTH_RATE, min(FinancialConstants::MAX_PERPETUAL_GROWTH_RATE, $growthRate));

        return min($clampedGrowth, max(FinancialConstants::MIN_COST_OF_EQUITY, $costOfEquity) - FinancialConstants::MIN_PERPETUAL_GROWTH_SPREAD);
    }

    /**
     * Calculates the Intrinsic Fair Value P/E ratio using fundamental drivers (Gordon Growth Model derivation).
     * P/E = (1 - Reinvestment Rate) / (Cost of Equity - Growth Rate)
     * Where Reinvestment Rate = Growth Rate / ROIC.
     *
     * The Gordon multiple is a ratio whose denominator is a small difference of two estimated rates, so its
     * sampling error explodes as that spread narrows: by the delta method the standard error of the multiple
     * scales with 1/spread^2, and the estimate is near worthless once the spread approaches zero. Vasicek
     * (1973), "A Note on Using Cross-Sectional Information in Bayesian Estimation of Security Betas", is the
     * standard remedy for exactly this shape of problem: shrink the noisy firm-level estimate toward a stable
     * cross-sectional prior, weighting each by its precision. Vasicek shrinks a regression beta toward the
     * market; here the firm's Gordon multiple is shrunk toward its sector's observed trading multiple, whose
     * precision is fixed by INTRINSIC_PE_SHRINKAGE_SPREAD.
     *
     * This also removes the divergence by construction rather than by a hard cap. The firm's contribution is
     * weight x multiple = spread x payout / (spread^2 + tau^2), which tends to zero as the spread does, so a
     * firm whose cost of equity approaches its growth rate lands on its sector multiple instead of infinity.
     *
     * @param float      $costOfEquity   The required rate of return for equity investors (Hurdle Rate).
     * @param float      $roic           The Return on Invested Capital.
     * @param float      $growthRate     The expected perpetual growth rate.
     * @param float|null $sectorMultiple The sector's baseline trading multiple used as the cross-sectional
     *                                   prior. Null skips shrinkage and returns the raw Gordon multiple.
     * @return float The intrinsic fair value P/E multiple.
     */
    public static function calculateIntrinsicFairValuePE(
        float $costOfEquity,
        float $roic,
        float $growthRate = FinancialConstants::DEFAULT_PERPETUAL_GROWTH_RATE,
        ?float $sectorMultiple = null
    ): float {
        // 1. Enforce absolute structural floor on Cost of Equity to prevent divergence under extreme distress
        $effectiveCostOfEquity = max(FinancialConstants::MIN_COST_OF_EQUITY, $costOfEquity);

        // 2-3. The perpetual growth the multiple is struck on: bounded, and held below the hurdle.
        $effectiveGrowth = self::perpetualGrowthRate($costOfEquity, $growthRate);

        // 4. Prevent division by zero or negative ROIC anomalies in perpetual calculations (floor at 0.01)
        $effectiveRoic = max(0.01, $roic);

        // 5. Reinvestment Rate = Growth / ROIC (bounded to [-1.0, 1.0] to prevent extreme negative payout distortions)
        $reinvestmentRate = max(-1.0, min(1.0, $effectiveGrowth / $effectiveRoic));

        $payoutRatio = 1.0 - $reinvestmentRate;

        // 6. Calculate Damodaran P/E multiple with safe spread denominator
        $spread = max(FinancialConstants::MIN_PERPETUAL_GROWTH_SPREAD, $effectiveCostOfEquity - $effectiveGrowth);
        $pe = $payoutRatio / $spread;

        // 7. Vasicek shrinkage toward the sector's multiple, weighted by the precision of each estimate.
        // Firm precision goes as spread^2 (delta method on a 1/spread ratio); the prior's is the fixed
        // tau^2. A well-conditioned firm keeps its own multiple; an ill-conditioned one inherits its sector's.
        if ($sectorMultiple !== null && $sectorMultiple > 0.0) {
            $tau = FinancialConstants::INTRINSIC_PE_SHRINKAGE_SPREAD;
            $firmPrecision = $spread * $spread;
            $priorPrecision = $tau * $tau;

            $firmWeight = $firmPrecision / ($firmPrecision + $priorPrecision);
            $pe = ($firmWeight * $pe) + ((1.0 - $firmWeight) * $sectorMultiple);
        }

        // 8. Enforce structural market boundaries for distressed (4x) and superstar (35x) equities
        return max(FinancialConstants::MIN_INTRINSIC_PE, min(FinancialConstants::MAX_INTRINSIC_PE, $pe));
    }

    /**
     * Nominal expected growth used to strike a fair-value multiple, the same transmission for everyone
     * who strikes one: secular real growth, the cyclical part scaled by beta, then inflation in full for the
     * nominal rate. No firm outgrows the economy forever (Damodaran, Investment Valuation, ch. 12): the sector's
     * secular excess over trend fades, and is priced at its perpetual equivalent. The cycle is transitory and passes
     * both ways.
     *
     * Management and the market MUST read the same figure. The corporate engines used to strike their
     * buyback, issuance and M&A multiples on a flat 2% while the pricing engine read the cycle, which made
     * repurchases countercyclical by accident: in a boom the market's anchor rose above management's and
     * buybacks stopped, in a bust management's sat above the market's and buybacks ran into the downturn.
     */
    public static function calculateExpectedNominalGrowth(
        float $secularGrowth,
        float $outputGap,
        float $beta,
        float $inflation,
        float $discountRate
    ): float {
        // The secular excess over trend keeps fading (SECULAR_EXCESS_HALF_LIFE_YEARS), so it is priced at its
        // perpetual equivalent rather than as permanent or as nothing.
        $realGrowth = TimeSeries::persistentEquivalentGrowth($secularGrowth, MacroEngine::TREND_REAL_GROWTH, $discountRate - $inflation)
            + ($outputGap * FinancialConstants::CYCLICAL_GROWTH_PASS_THROUGH * $beta);

        // Nominal growth is real growth plus inflation (Fisher): the cash flows are discounted at a nominal rate,
        // so growing them at less than full inflation is the inflation illusion of Modigliani & Cohn (1979). The
        // firms' own earnings pass inflation through in full, so no haircut on it is priced either.
        return max(0.0, $realGrowth + $inflation);
    }

    /**
     * The growth a firm can fund from what it keeps (Damodaran: g = return on capital x reinvestment rate; Higgins
     * 1977): the outlook's growth, capped at inflation plus what its return earns on the share of earnings its
     * payout policy retains. Reinvestment funds REAL growth (Damodaran: reinvestment rate = real g / real ROC);
     * the plant already in place sells at the going price level, so a firm that pays out everything still grows
     * with inflation.
     */
    public static function calculateFundableGrowth(float $expectedGrowth, float $returnOnCapital, float $payoutRatio, float $inflation): float
    {
        $retention = 1.0 - max(0.0, min(1.0, $payoutRatio));

        return min($expectedGrowth, max(0.0, $inflation) + max(0.0, $returnOnCapital * $retention));
    }

    /**
     * Growth of the capital stock a firm plans, per year: the growth of the demand its capital serves plus a
     * share of the log gap between the capital that demand implies and the capital installed (the flexible
     * accelerator, Chenery 1952 and Koyck 1954, in error-correction form). Never negative: plant is not sold
     * back to fund a shortfall in demand, it is left to depreciate (Abel & Eberly 1994).
     */
    public static function flexibleAcceleratorGrowth(float $demandGrowth, float $logCapitalGap, float $adjustmentSpeed): float
    {
        return max(0.0, $demandGrowth + ($adjustmentSpeed * $logCapitalGap));
    }

    /**
     * Return on equity implied by a return on invested capital and the debt financing the rest of it:
     * ROE = ROIC + (D/E)(ROIC - kd(1 - t)), the leverage identity of Modigliani & Miller (1958, Proposition II
     * in accounting returns). Equity at or below zero has no ratio to lever by and returns ROIC unlevered.
     *
     * @param float $returnOnCapital    ROIC.
     * @param float $investedCapital    Debt plus equity financing the operating assets (any scale; per share or total).
     * @param float $equity             Book equity, on the same scale.
     * @param float $afterTaxCostOfDebt kd (1 - t).
     */
    public static function equityReturnFromRoic(float $returnOnCapital, float $investedCapital, float $equity, float $afterTaxCostOfDebt): float
    {
        if ($equity <= 0.0) {
            return $returnOnCapital;
        }

        $debt = max(0.0, $investedCapital - $equity);

        return $returnOnCapital + (($debt / $equity) * ($returnOnCapital - $afterTaxCostOfDebt));
    }

    /**
     * The intrinsic fair-value P/E net of the earnings-quality discount: Damodaran multiple shrunk toward
     * the sector prior, less the Sloan (1996) accruals penalty, floored at the distressed multiple.
     * One function so the market's anchor and management's are the same number.
     */
    public static function calculateQualityAdjustedFairValuePE(
        float $hurdleRate,
        float $structuralRoic,
        float $expectedGrowth,
        ?float $sectorMultiple,
        float $accrualsRatio
    ): float {
        $fairValuePE = self::calculateIntrinsicFairValuePE($hurdleRate, $structuralRoic, $expectedGrowth, $sectorMultiple);
        $accrualsPenalty = max(0.0, $accrualsRatio * FinancialConstants::ACCRUALS_ANOMALY_PE_PENALTY_SCALE);

        return max(FinancialConstants::MIN_INTRINSIC_PE, $fairValuePE - $accrualsPenalty);
    }

    /**
     * Gordon (1962) growth model: next year's dividend, the current one grown once, over the spread of the
     * required return above perpetual growth.
     *
     * @param float $annualDividend The current annual dividend.
     * @param float $discountRate   The required rate of return (Cost of Equity).
     * @param float $growthRate     The expected perpetual dividend growth rate.
     * @return float The intrinsic value of the stock based purely on its dividend stream.
     */
    public static function calculateDividendDiscountModel(
        float $annualDividend,
        float $discountRate,
        float $growthRate = 0.01
    ): float {
        if ($annualDividend <= 0.0) {
            return 0.0;
        }

        $effectiveDiscountRate = max(FinancialConstants::MIN_COST_OF_EQUITY, $discountRate);
        $clampedGrowthRate = max(FinancialConstants::MIN_PERPETUAL_GROWTH_RATE, min(FinancialConstants::MAX_PERPETUAL_GROWTH_RATE, $growthRate));
        $effectiveGrowthRate = min($clampedGrowthRate, $effectiveDiscountRate - FinancialConstants::MIN_PERPETUAL_GROWTH_SPREAD);

        $denominator = max(FinancialConstants::MIN_PERPETUAL_GROWTH_SPREAD, $effectiveDiscountRate - $effectiveGrowthRate);
        $multiplier = (1.0 + $effectiveGrowthRate) / $denominator;

        $clampedMultiplier = min(FinancialConstants::MAX_DCF_MULTIPLIER, $multiplier);

        return $annualDividend * $clampedMultiplier;
    }

    /**
     * Present value of the gap between a firm's current dividend and its target while partial adjustment closes
     * it (Lintner 1956): each quarter the dividend moves the given share of the remaining distance, so the gap
     * decays geometrically and is discounted at the required return. Positive while the dividend runs above
     * target, negative while it lags behind.
     *
     * @param float $annualGap      Current annual dividend less the target annual dividend.
     * @param float $discountRate   Annual required return (cost of equity).
     * @param float $quarterlySpeed Share of the remaining gap closed each quarter, in [0, 1].
     */
    public static function calculateDividendAdjustmentValue(float $annualGap, float $discountRate, float $quarterlySpeed): float
    {
        $quarterlyRate = ((1.0 + max(FinancialConstants::MIN_COST_OF_EQUITY, $discountRate)) ** 0.25) - 1.0;
        $persistence = (1.0 - max(0.0, min(1.0, $quarterlySpeed))) / (1.0 + $quarterlyRate);

        return ($annualGap / 4.0) * $persistence / (1.0 - $persistence);
    }

    /**
     * Calculates the Weighted Average Cost of Capital (WACC).
     *
     * @param float $weightEquity The proportion of equity in the capital structure.
     * @param float $costOfEquity The required return on equity (CAPM).
     * @param float $weightDebt   The proportion of debt in the capital structure.
     * @param float $costOfDebt   The effective, post-tax cost of debt.
     * @return float The weighted average cost of capital.
     */
    public static function calculateWACC(float $weightEquity, float $costOfEquity, float $weightDebt, float $costOfDebt): float
    {
        return ($weightEquity * $costOfEquity) + ($weightDebt * $costOfDebt);
    }

    /**
     * Calculates the Cost of Equity using the Capital Asset Pricing Model (CAPM).
     *
     * @param float $riskFreeRate      The baseline risk-free rate.
     * @param float $beta              The stock's levered beta.
     * @param float $equityRiskPremium The market equity risk premium.
     * @return float The expected return on equity.
     */
    public static function calculateCAPM(float $riskFreeRate, float $beta, float $equityRiskPremium): float
    {
        return $riskFreeRate + ($beta * $equityRiskPremium);
    }

    /**
     * Levers a company's Beta using the Hamada equation.
     *
     * @param float $unleveredBeta The baseline asset beta.
     * @param float $taxRate       The corporate tax rate.
     * @param float $debtToEquity  The debt-to-equity ratio of the company.
     * @param float $dampening     A modifier to reduce double-counting if the baseline beta is already partially levered.
     * @return float The levered equity beta.
     */
    public static function calculateLeveredBeta(float $unleveredBeta, float $taxRate, float $debtToEquity, float $dampening = 1.0): float
    {
        return $unleveredBeta * (1.0 + ((1.0 - $taxRate) * ($debtToEquity * $dampening)));
    }

    /**
     * Share of a growing perpetuity's value that a lasting change starting some years from now captures, phasing in at a
     * given speed from then. A stream growing at g and discounted at k accrues its value at the density
     * (k - g) e^{-(k - g) t} (Gordon 1959), so the part after t0 is e^{-(k - g) t0}; a change that closes the gap to its
     * full size at speed lambda keeps lambda / (lambda + k - g) of that.
     *
     * @param float $capRate     The discount rate less growth, k - g.
     * @param float $years       Years until the change starts.
     * @param float $phaseInSpeed Speed at which the change reaches its full size once started, a year; INF for at once.
     */
    public static function perpetuityShareAfter(float $capRate, float $years, float $phaseInSpeed = INF): float
    {
        $atStart = exp(-$capRate * max(0.0, $years));

        return is_infinite($phaseInSpeed) ? $atStart : $atStart * $phaseInSpeed / ($phaseInSpeed + $capRate);
    }

    /**
     * Calculates the Earnings Response Coefficient (ERC) based on empirical models.
     * The ERC determines how strongly a stock price reacts to an earnings surprise.
     * 
     * @param float $sue           Standardized Unexpected Earnings (Surprise %).
     * @param float $beta          The stock's levered beta (risk).
     * @param float $growthPremium The valuation premium or growth expectations.
     * @return float The price drift percentage resulting from the earnings surprise.
     */
    public static function calculateEarningsResponseCoefficient(float $sue, float $beta, float $growthPremium): float
    {
        // High beta (risk) means more noise, reducing the ERC
        // High growth premium implies higher persistence of earnings, increasing the ERC
        $ercBeta = max(0.1, 1.0 + (FinancialConstants::ERC_BETA_SENSITIVITY * $beta) + (FinancialConstants::ERC_GROWTH_SENSITIVITY * $growthPremium));

        return FinancialConstants::ERC_BASE_ALPHA + ($ercBeta * $sue);
    }

    /**
     * Updates analyst consensus using a Bayesian Inference Model.
     * 
     * @param float $priorEstimate  The previous analyst consensus estimate.
     * @param float $priorVariance  The uncertainty (variance) of the prior estimate.
     * @param float $newSignal      The new fundamental signal (e.g., actual structural revenue).
     * @param float $signalVariance The uncertainty (variance) of the new signal.
     * @return float The posterior (updated) analyst consensus.
     */
    public static function calculateBayesianAnalystUpdate(float $priorEstimate, float $priorVariance, float $newSignal, float $signalVariance): float
    {
        // Prevent division by zero
        $priorPrecision = 1.0 / max(0.0001, $priorVariance);
        $signalPrecision = 1.0 / max(0.0001, $signalVariance);

        // Posterior is the precision-weighted average of the prior and the new signal
        $posteriorEstimate = (($priorEstimate * $priorPrecision) + ($newSignal * $signalPrecision)) / ($priorPrecision + $signalPrecision);

        return $posteriorEstimate;
    }
}
