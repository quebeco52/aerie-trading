<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * Equity valuation: fair P/E from intrinsic value and quality, expected and fundable growth, the dividend discount
 * model, WACC, CAPM and levered beta, ROIC-implied equity returns, the flexible accelerator, and perpetuity shares.
 * Pure functions of their arguments.
 */
final class Valuation
{
    // --- Gordon Growth & Perpetual Valuation Bounds ---
    /** Absolute minimum hurdle rate (~4% COE) to prevent Gordon Growth divergence under extreme distress. */
    public const MIN_COST_OF_EQUITY = 0.04;
    /** Absolute perpetual growth floor (-5%) for contracting or liquidation-stage firms. */
    public const MIN_PERPETUAL_GROWTH_RATE = -0.05;
    /** Absolute perpetual growth ceiling (6%) to prevent exceeding long-term nominal GDP growth. */
    public const MAX_PERPETUAL_GROWTH_RATE = 0.06;

    // --- Valuation & Multiples ---
    /** Baseline long-term stable GDP growth rate for Gordon Growth valuation. */
    public const DEFAULT_PERPETUAL_GROWTH_RATE = 0.02;

    /** Absolute floor on intrinsic fundamental P/E multiple. */
    public const MIN_INTRINSIC_PE = 4.0;
    /** Absolute ceiling on intrinsic fundamental P/E multiple. */
    public const MAX_INTRINSIC_PE = 35.0;

    // --- Relative Valuation Shrinkage (Vasicek 1973) ---
    /**
     * Spread (cost of equity less growth) at which a firm's own Gordon multiple and its sector's carry equal weight.
     */
    public const INTRINSIC_PE_SHRINKAGE_SPREAD = 0.03;
    /**
     * Real long government yield the sector multiples were quoted at: the founding natural rate plus the founding term
     * premium (2.65%).
     */
    public const FOUNDING_REAL_RISK_FREE_RATE = MacroConstants::BASE_NATURAL_RATE + MacroConstants::NS_BASE_TERM_PREMIUM;
    /** Cap on a Gordon capitalization multiple (~33.3x, a 3% yield). */
    public const MAX_DCF_MULTIPLIER = 33.33;

    // --- Accruals Quality & Sloan Anomaly (Sloan 1996) ---
    /** Valuation multiple discount scalar penalizing stocks with high non-cash accounting accruals. */
    public const ACCRUALS_ANOMALY_PE_PENALTY_SCALE = 8.0;

    // --- Investor Taxes (Brennan 1970; Chetty & Saez 2005) ---
    /**
     * Share of listed equity held by the District's own taxable holders, the holders a tax on dividends reaches: 20%. A
     * financial centre's listed shares are largely foreign-held (identified owners 2017: Singapore 39% foreign against
     * 27% domestic, Hong Kong 62% against 11%, the US 16% against 65%; OECD 2019, Owners of the World's Listed
     * Companies), so below the US's 24.2% in taxable accounts (Rosenthal & Austin 2016).
     */
    public const TAXABLE_EQUITY_SHARE = 0.20;
    /**
     * Tax on dividends at the founding: the US 15% on qualified dividends in 2009, the rate the calibrated equity
     * returns were earned under.
     */
    public const FOUNDING_DIVIDEND_TAX_RATE = 0.15;
    /** Tax on capital gains, the US 15% long-term rate in 2009; the Diet does not set it. */
    public const CAPITAL_GAINS_TAX_RATE = 0.15;
    /**
     * Accrual-equivalent capital gains tax as a share of the statutory rate, deferral and step-up at death lowering it:
     * 3.4-6.6% against 18.1-27.1%, 1960-78 (Protopapadakis 1983), the midpoints' ratio.
     */
    public const CAPITAL_GAINS_ACCRUAL_EQUIVALENT_RATIO = 5.0 / 22.6;
    /**
     * Elasticity of regular dividends to the holders' average net-of-tax rate: the 2003 cut took that rate from 29% to
     * 17% (Poterba 2004) and raised regular dividends 20% (Chetty & Saez 2005), ln 1.20 / ln(0.83 / 0.71).
     */
    public const DIVIDEND_NET_OF_TAX_ELASTICITY = 1.168;

    /**
     * The growth rate a perpetuity may be struck on: bounded to [-5%, +6%] and at least 50bp below the cost of equity
     * (floored at MIN_COST_OF_EQUITY), so no perpetuity divides by a vanishing spread. Every perpetuity struck on the
     * same expected growth reads it through here, so they cannot disagree about where it is clamped.
     */
    public static function perpetualGrowthRate(float $costOfEquity, float $growthRate): float
    {
        $clampedGrowth = max(self::MIN_PERPETUAL_GROWTH_RATE, min(self::MAX_PERPETUAL_GROWTH_RATE, $growthRate));

        return min($clampedGrowth, max(self::MIN_COST_OF_EQUITY, $costOfEquity) - FinancialConstants::MIN_PERPETUAL_GROWTH_SPREAD);
    }

    /**
     * What a unit of plant a company builds into a new industry is worth over its cost: the perpetuity of what it earns
     * above the hurdle once it has learned the trade, growing at the economy's long-run rate, raised by the extra
     * growth its industry still has, which fades on its own mean life; less the learning it pays for first, a cost that
     * fades e-folding over the learning time. The spread under each perpetuity is floored as every other one is.
     *
     * @param float $learnedReturn  The after-tax return on the plant once learned.
     * @param float $longRunGrowth  The nominal growth every business settles to.
     * @param float $excessGrowth   How far above that the industry grows now.
     * @param float $excessLife     Mean life of that excess, in years.
     * @param float $learningCost   The first year's after-tax learning cost per unit of plant.
     */
    public static function ventureValue(float $learnedReturn, float $hurdle, float $longRunGrowth, float $excessGrowth, float $excessLife, float $learningCost, float $learningYears): float
    {
        $spread = max(FinancialConstants::MIN_PERPETUAL_GROWTH_SPREAD, $hurdle - self::perpetualGrowthRate($hurdle, $longRunGrowth));
        $lift = 1.0 + ($excessGrowth / ($spread + (1.0 / max(1e-9, $excessLife))));

        return (($learnedReturn - $hurdle) / $spread * $lift) - ($learningCost / ($spread + (1.0 / max(1e-9, $learningYears))));
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
     * The sector's multiple is quoted at the founding discount rate and at no firm's catch-up, so before it serves as
     * the prior it is re-struck at today's: its earnings yield moves with the firm's real hurdle and against the
     * firm's catch-up growth (sectorPriorYieldShift()). A fixed prior would dilute every firm's response to rates, and
     * to the market it is expected to reach, by its weight on it.
     *
     * @param float      $costOfEquity   The required rate of return for equity investors (Hurdle Rate).
     * @param float      $roic           The Return on Invested Capital.
     * @param float      $growthRate     The expected perpetual growth rate.
     * @param float|null $sectorMultiple The sector's baseline trading multiple used as the cross-sectional
     *                                   prior. Null skips shrinkage and returns the raw Gordon multiple.
     * @param float      $priorYieldShift How far the prior's earnings yield moves before the blend
     *                                    (sectorPriorYieldShift()).
     * @return float The intrinsic fair value P/E multiple.
     */
    public static function calculateIntrinsicFairValuePE(
        float $costOfEquity,
        float $roic,
        float $growthRate = self::DEFAULT_PERPETUAL_GROWTH_RATE,
        ?float $sectorMultiple = null,
        float $priorYieldShift = 0.0,
    ): float {
        // 1. Enforce absolute structural floor on Cost of Equity to prevent divergence under extreme distress
        $effectiveCostOfEquity = max(self::MIN_COST_OF_EQUITY, $costOfEquity);

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
            $sectorMultiple = 1.0 / max(1.0 / self::MAX_INTRINSIC_PE, (1.0 / $sectorMultiple) + $priorYieldShift);
            $tau = self::INTRINSIC_PE_SHRINKAGE_SPREAD;
            $firmPrecision = $spread * $spread;
            $priorPrecision = $tau * $tau;

            $firmWeight = $firmPrecision / ($firmPrecision + $priorPrecision);
            $pe = ($firmWeight * $pe) + ((1.0 - $firmWeight) * $sectorMultiple);
        }

        // 8. Enforce structural market boundaries for distressed (4x) and superstar (35x) equities
        return max(self::MIN_INTRINSIC_PE, min(self::MAX_INTRINSIC_PE, $pe));
    }

    /**
     * Nominal expected growth used to strike a fair-value multiple, the same transmission for everyone
     * who strikes one: secular real growth, then inflation in full for the nominal rate. The inflation is the one the
     * discount rate carries, the ten-year breakeven, not the latest print: a perpetuity grows at what is expected over
     * its life. No firm outgrows the economy forever (Damodaran, Investment Valuation, ch. 12): the sector's secular
     * excess over trend fades, and is priced at its perpetual equivalent. The cycle adds no perpetual growth: a boom's
     * earnings reach value through the filed return, which fades, and its gap closes.
     *
     * Management and the market MUST read the same figure, or buybacks, issuance and M&A are struck against a
     * different multiple from the one the market prices.
     */
    public static function calculateExpectedNominalGrowth(
        float $secularGrowth,
        float $inflation,
        float $discountRate,
    ): float {
        // The secular excess over trend keeps fading (SECULAR_EXCESS_HALF_LIFE_YEARS), so it is priced at its
        // perpetual equivalent rather than as permanent or as nothing.
        $realGrowth = TimeSeries::persistentEquivalentGrowth($secularGrowth, MacroConstants::TREND_REAL_GROWTH, $discountRate - $inflation);

        // Nominal growth is real growth plus inflation (Fisher): the cash flows are discounted at a nominal rate,
        // so growing them at less than full inflation is the inflation illusion of Modigliani & Cohn (1979). The
        // firms' own earnings pass inflation through in full, so no haircut on it is priced either.
        return max(0.0, $realGrowth + $inflation);
    }

    /**
     * The perpetual-growth equivalent of closing a gap between the plant a firm runs and the plant its expected market
     * calls for: a log gap G closed at speed k is an excess kG fading at k, worth G k / (s + k) of base value at the
     * real spread s (persistentEquivalentGrowth()). A firm short of its market closes the gap by building, at the
     * accelerator's speed; one with plant to spare closes it only as demand grows into the plant, at trend growth.
     *
     * @param float $gap              Log of the plant the expected market calls for over the plant in use.
     * @param float $buildSpeed       Share of a positive gap closed a year (the flexible accelerator's).
     * @param float $realDiscountRate The real rate the cash flows are discounted at.
     */
    public static function capacityCatchUpGrowth(float $gap, float $buildSpeed, float $realDiscountRate): float
    {
        $speed = $gap >= 0.0 ? $buildSpeed : MacroConstants::TREND_REAL_GROWTH;

        return TimeSeries::persistentEquivalentGrowth($speed * $gap, 0.0, $realDiscountRate - MacroConstants::TREND_REAL_GROWTH, $speed);
    }

    /**
     * The growth a firm can fund from what it keeps (Damodaran: g = return on capital x reinvestment rate; Higgins
     * 1977): the outlook's growth, capped at inflation plus what its return earns on the share of earnings its
     * payout policy retains. Reinvestment funds REAL growth (Damodaran: reinvestment rate = real g / real ROC);
     * the plant already in place sells at the going price level, so a firm that pays out everything still grows
     * with inflation.
     *
     * The catch-up to the firm's expected market (capacityCatchUpGrowth()) is added outside the cap: plant is built
     * from operating cash before anything is paid out (CapitalBudgetEngine), and the multiple charges its reinvestment
     * through the payout it leaves (calculateIntrinsicFairValuePE()).
     */
    public static function calculateFundableGrowth(float $expectedGrowth, float $returnOnCapital, float $payoutRatio, float $inflation, float $catchUpGrowth = 0.0): float
    {
        $retention = 1.0 - max(0.0, min(1.0, $payoutRatio));

        return max(0.0, min($expectedGrowth, max(0.0, $inflation) + max(0.0, $returnOnCapital * $retention)) + $catchUpGrowth);
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
        float $accrualsRatio,
        float $priorYieldShift = 0.0,
    ): float {
        $fairValuePE = self::calculateIntrinsicFairValuePE($hurdleRate, $structuralRoic, $expectedGrowth, $sectorMultiple, $priorYieldShift);
        $accrualsPenalty = max(0.0, $accrualsRatio * self::ACCRUALS_ANOMALY_PE_PENALTY_SCALE);

        return max(self::MIN_INTRINSIC_PE, $fairValuePE - $accrualsPenalty);
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
        float $growthRate = 0.01,
    ): float {
        if ($annualDividend <= 0.0) {
            return 0.0;
        }

        $effectiveDiscountRate = max(self::MIN_COST_OF_EQUITY, $discountRate);
        $clampedGrowthRate = max(self::MIN_PERPETUAL_GROWTH_RATE, min(self::MAX_PERPETUAL_GROWTH_RATE, $growthRate));
        $effectiveGrowthRate = min($clampedGrowthRate, $effectiveDiscountRate - FinancialConstants::MIN_PERPETUAL_GROWTH_SPREAD);

        $denominator = max(FinancialConstants::MIN_PERPETUAL_GROWTH_SPREAD, $effectiveDiscountRate - $effectiveGrowthRate);
        $multiplier = (1.0 + $effectiveGrowthRate) / $denominator;

        $clampedMultiplier = min(self::MAX_DCF_MULTIPLIER, $multiplier);

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
        $quarterlyRate = ((1.0 + max(self::MIN_COST_OF_EQUITY, $discountRate)) ** 0.25) - 1.0;
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
     * Levers an asset beta to the equity beta of a firm with this capital structure (Hamada 1972, "The Effect of the
     * Firm's Capital Structure on the Systematic Risk of Common Stocks"): beta_E = beta_U x (1 + (1 - t) x D/E).
     *
     * @param float $unleveredBeta The asset beta.
     * @param float $taxRate       The tax rate the interest is shielded at.
     * @param float $debtToEquity  Debt over the market value of equity.
     * @return float The levered equity beta.
     */
    public static function calculateLeveredBeta(float $unleveredBeta, float $taxRate, float $debtToEquity): float
    {
        return $unleveredBeta * (1.0 + ((1.0 - $taxRate) * $debtToEquity));
    }

    /**
     * What operating capital is worth on its own, before any financing (the value-driver formula, Koller et al.,
     * "Valuation"): capital C earning return R and growing at g reinvests g / R of its profit, so it is worth
     * C (R - g) / (k - g) at the unlevered cost of capital k. Never below zero; the perpetuity is bounded as every
     * other one is (perpetualGrowthRate()), with k floored at MIN_COST_OF_EQUITY.
     *
     * @param float $investedCapital  The operating capital employed.
     * @param float $returnOnCapital  The after-tax return it is expected to earn.
     * @param float $costOfCapital    The unlevered cost of capital (CAPM on the asset beta).
     * @param float $growth           Expected nominal growth.
     */
    public static function operatingCapitalValue(float $investedCapital, float $returnOnCapital, float $costOfCapital, float $growth): float
    {
        $discountRate = max(self::MIN_COST_OF_EQUITY, $costOfCapital);
        $perpetualGrowth = self::perpetualGrowthRate($discountRate, $growth);

        return max(0.0, $investedCapital * ($returnOnCapital - $perpetualGrowth) / ($discountRate - $perpetualGrowth));
    }

    /**
     * Share of a growing perpetuity's value that a lasting change starting some years from now captures, phasing in at
     * a given speed from then. A stream growing at g and discounted at k accrues its value at the density (k - g)
     * e^{-(k - g) t} (Gordon 1959), so the part after t0 is e^{-(k - g) t0}; a change that closes the gap to its full
     * size at speed lambda keeps lambda / (lambda + k - g) of that.
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
     * Share of a permanent change in operating profit that a change fading at speed f is worth: discounted at r - g the
     * fading one is worth 1 / (r - g + f) a unit, the permanent one 1 / (r - g). A franchise fading over a decade at a
     * 5% cap rate is worth ~0.4 of the same profit held for good.
     *
     * @param float $capRate   The discount rate less growth, r - g.
     * @param float $fadeSpeed The change's fade per year.
     */
    public static function fadingProfitShare(float $capRate, float $fadeSpeed): float
    {
        return $capRate > 0.0 ? $capRate / ($capRate + max(0.0, $fadeSpeed)) : 0.0;
    }

    /**
     * Fair value as a multiple of its value without the franchise, when operating profit runs at a multiple of its
     * structural level that fades: 1 + s (multiple - 1), s the fading share (fadingProfitShare()). Profit never turns
     * negative on the franchise, so neither does the multiple.
     */
    public static function franchiseValueMultiple(float $fadingShare, float $profitMultiple): float
    {
        return 1.0 + ($fadingShare * ($profitMultiple - 1.0));
    }

    /**
     * Equity value as a multiple of itself when the operating value under it moves by a multiple, at a leverage L (the
     * rate equity return moves per unit of operating return): up, the levered identity 1 + L (multiple - 1); down,
     * multiple^L, which leaves the same slope at one and, as limited liability does, never reaches zero.
     *
     * @param float $operatingMultiple The operating value over its value at no change.
     * @param float $leverage          d(equity return) / d(operating return), at least one.
     */
    public static function leveredValueMultiple(float $operatingMultiple, float $leverage): float
    {
        $leverage = max(1.0, $leverage);

        return $operatingMultiple >= 1.0 ? 1.0 + ($leverage * ($operatingMultiple - 1.0)) : max(0.0, $operatingMultiple) ** $leverage;
    }

    /**
     * d log(equity value) / d power at a franchise power: the rate the power moves fair value, where it stands. At no
     * franchise s L (1 - m); on a thin margin it rises toward 1 - m' as profit runs at e^p, and it falls to zero as the
     * margin nears either end, where the power no longer moves profit.
     *
     * @param float $fadingShare      fadingProfitShare() at the firm's cap rate.
     * @param float $leverage         d(equity return) / d(operating return), at least one.
     * @param float $structuralMargin The margin the power shifts, in (0, 1).
     */
    public static function franchiseValueElasticity(float $fadingShare, float $leverage, float $structuralMargin, float $franchisePower): float
    {
        if ($structuralMargin <= 0.0 || $structuralMargin >= 1.0) {
            return 0.0;
        }
        $leverage = max(1.0, $leverage);
        $margin = FirmEconomics::franchiseMargin($structuralMargin, $franchisePower);
        $operatingMultiple = self::franchiseValueMultiple($fadingShare, $margin / $structuralMargin);
        $operatingSlope = $fadingShare * $margin * (1.0 - $margin) / $structuralMargin;

        // Up, d log(1 + L (x - 1)) = L dx / (1 + L (x - 1)); down, d log(x^L) = L dx / x.
        return $leverage * $operatingSlope / ($operatingMultiple >= 1.0 ? self::leveredValueMultiple($operatingMultiple, $leverage) : max(1e-12, $operatingMultiple));
    }

    /**
     * The return a dividend tax adds to a stock's required return over the founding rate's, on the after-tax CAPM
     * (Brennan 1970): holders price after tax, so with the taxable share w of them the stock must earn
     * w (t_d - t_g) y / (1 - w t_g) more on a dividend yield y, the gains tax at its accrual equivalent. The founding
     * rate's premium is already in the returns the market was calibrated on, so only the change is added.
     *
     * @param float $dividendYield   The stock's dividend yield, a year.
     * @param float $dividendTaxRate The tax on dividends.
     */
    public static function dividendTaxReturnPremium(float $dividendYield, float $dividendTaxRate): float
    {
        $taxable = self::TAXABLE_EQUITY_SHARE;
        $gainsTax = self::CAPITAL_GAINS_TAX_RATE * self::CAPITAL_GAINS_ACCRUAL_EQUIVALENT_RATIO;

        return $taxable * ($dividendTaxRate - self::FOUNDING_DIVIDEND_TAX_RATE) * max(0.0, $dividendYield) / (1.0 - ($taxable * $gainsTax));
    }

    /**
     * What a stock is worth under a dividend tax against the founding rate, as a multiple: the value spread at its
     * cap rate, k - g, is spread at k - g plus the tax's premium instead (dividendTaxReturnPremium()). A stock paying
     * none is untouched; one whose whole cash return is dividends loses w (t_d - t_0) / (1 - w t_g) of its value.
     *
     * @param float $dividendYield   The stock's dividend yield, a year.
     * @param float $capRate         The discount rate less growth its value is spread at.
     * @param float $dividendTaxRate The tax on dividends.
     */
    public static function dividendTaxValueScale(float $dividendYield, float $capRate, float $dividendTaxRate): float
    {
        return $capRate / ($capRate + self::dividendTaxReturnPremium(min($dividendYield, $capRate), $dividendTaxRate));
    }

    /**
     * How far firms' regular dividends sit from their founding level under a dividend tax: the holders' average
     * net-of-tax rate, 1 - w t_d, against the founding rate's, to the measured elasticity (Chetty & Saez 2005).
     * Buybacks take up the cash a lower dividend leaves.
     *
     * @param float $dividendTaxRate The tax on dividends.
     */
    public static function dividendTaxPayoutScale(float $dividendTaxRate): float
    {
        $netOfTax = 1.0 - (self::TAXABLE_EQUITY_SHARE * $dividendTaxRate);
        $foundingNetOfTax = 1.0 - (self::TAXABLE_EQUITY_SHARE * self::FOUNDING_DIVIDEND_TAX_RATE);

        return ($netOfTax / $foundingNetOfTax) ** self::DIVIDEND_NET_OF_TAX_ELASTICITY;
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

    /**
     * The fair-value P/E management strikes its own capital decisions on: the market's anchor, exactly. Takes the
     * firm's inputs as primitives so every corporate engine can call it; the growth transmission and the accruals
     * discount are the two functions above.
     */
    public static function calculateManagementFairValuePE(
        float $hurdleRate,
        float $trueReturn,
        float $secularGrowth,
        float $beta,
        float $inflation,
        ?float $sectorMultiple,
        float $accrualsRatio,
        float $payoutRatio,
        float $catchUpGrowth = 0.0,
    ): float {
        $expectedGrowth = Valuation::calculateFundableGrowth(
            Valuation::calculateExpectedNominalGrowth($secularGrowth, $inflation, $hurdleRate),
            $trueReturn,
            $payoutRatio,
            $inflation,
            $catchUpGrowth,
        );

        return Valuation::calculateQualityAdjustedFairValuePE($hurdleRate, $trueReturn, $expectedGrowth, $sectorMultiple, $accrualsRatio, self::sectorPriorYieldShift($hurdleRate, $beta, $inflation, $catchUpGrowth));
    }

    /**
     * How far the sector prior's earnings yield moves before it is blended with the firm's own multiple: up with the
     * firm's real hurdle (sectorHurdleShift()) and down by its catch-up growth, one for one as in the Gordon spread, so
     * a firm on the prior still prices the market it is expected to reach.
     */
    public static function sectorPriorYieldShift(float $hurdleRate, float $beta, float $expectedInflation, float $catchUpGrowth = 0.0): float
    {
        return self::sectorHurdleShift($hurdleRate, $beta, $expectedInflation) - $catchUpGrowth;
    }

    /**
     * How far the real hurdle a firm faces has moved from the one its sector's multiple was quoted at: today's cost of
     * equity less expected inflation, against the founding real yield plus the founding premium at the firm's beta.
     * Real, because nominal growth carries expected inflation as the yield does, so inflation alone moves no multiple.
     */
    public static function sectorHurdleShift(float $hurdleRate, float $beta, float $expectedInflation): float
    {
        return ($hurdleRate - $expectedInflation) - (self::FOUNDING_REAL_RISK_FREE_RATE + ($beta * MacroConstants::BASE_EQUITY_RISK_PREMIUM));
    }
}
