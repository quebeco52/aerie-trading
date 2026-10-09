<?php

namespace App\Tests\Service\Math;

use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\Valuation;
use PHPUnit\Framework\TestCase;

/**
 * The formulas in App\Service\Math\Valuation.
 */
class ValuationTest extends TestCase
{
    public function testCalculateIntrinsicFairValuePEStandardValuation(): void
    {
        // COE = 10%, ROIC = 15%, Growth = 3%
        // b = 0.03 / 0.15 = 0.20 -> Payout Ratio = 0.80
        // Denominator = 0.10 - 0.03 = 0.07 -> PE = 0.80 / 0.07 ≈ 11.42857
        $pe = Valuation::calculateIntrinsicFairValuePE(0.10, 0.15, 0.03);

        $this->assertEqualsWithDelta(11.42857, $pe, 0.001, 'Standard Gordon Growth PE calculation failed.');
    }

    public function testCalculateIntrinsicFairValuePEClampsToMinWhenValueDestroying(): void
    {
        // COE = 10%, ROIC = 2%, Growth = 5%
        // ROIC < Growth -> b > 1.0 -> Capped at 1.0 -> Payout Ratio = 0.0 -> PE = 0.0 -> Clamped to MIN_INTRINSIC_PE
        $pe = Valuation::calculateIntrinsicFairValuePE(0.10, 0.02, 0.05);

        $this->assertEqualsWithDelta(Valuation::MIN_INTRINSIC_PE, $pe, 0.0001, 'Value-destroying growth must clamp to MIN_INTRINSIC_PE.');
    }

    public function testCalculateIntrinsicFairValuePEConstrainsGrowthBelowCOE(): void
    {
        // COE = 8%, ROIC = 20%, Growth = 15% (Growth >= COE)
        // Growth constrained to 8% - 0.5% = 7.5%
        // b = 0.075 / 0.20 = 0.375 -> Payout Ratio = 0.625 -> PE = 0.625 / 0.005 = 125.0 -> Clamped to MAX_INTRINSIC_PE
        $pe = Valuation::calculateIntrinsicFairValuePE(0.08, 0.20, 0.15);

        $this->assertEqualsWithDelta(Valuation::MAX_INTRINSIC_PE, $pe, 0.0001, 'Growth >= COE must constrain growth and clamp to MAX_INTRINSIC_PE.');
    }

    /**
     * With a sector prior supplied, a well-conditioned firm keeps most of its own Gordon multiple. At a 7%
     * spread the firm's precision (spread^2) dominates the prior's, so it carries ~84% of the weight and the
     * result sits close to the raw 11.43x rather than the 20x sector.
     */
    public function testIntrinsicFairValuePEKeepsFirmEstimateWhenWellConditioned(): void
    {
        $raw = Valuation::calculateIntrinsicFairValuePE(0.10, 0.15, 0.03);
        $shrunk = Valuation::calculateIntrinsicFairValuePE(0.10, 0.15, 0.03, 20.0);

        $this->assertEqualsWithDelta(11.42857, $raw, 0.001);
        $this->assertEqualsWithDelta(12.75862, $shrunk, 0.001, 'A 7% spread must leave the firm estimate dominant.');
        $this->assertLessThan(abs(20.0 - $raw), abs(20.0 - $shrunk), 'Shrinkage must move the estimate toward its sector.');
    }

    /**
     * The case the shrinkage exists for. A 4.5% cost of equity against 6% growth leaves the denominator on
     * its 50bp floor and a raw multiple of 160x, which previously pinned to the 35x ceiling and stayed there
     * for every such firm. The firm's weight falls as spread^2, so its contribution collapses and the
     * estimate lands near its sector instead of on a shared ceiling.
     */
    public function testIntrinsicFairValuePEFallsBackToSectorWhenDenominatorCollapses(): void
    {
        $raw = Valuation::calculateIntrinsicFairValuePE(0.045, 0.20, 0.06);
        $shrunk = Valuation::calculateIntrinsicFairValuePE(0.045, 0.20, 0.06, 16.0);

        $this->assertEqualsWithDelta(Valuation::MAX_INTRINSIC_PE, $raw, 0.0001, 'Unshrunk, this diverges to the ceiling.');
        $this->assertEqualsWithDelta(19.89189, $shrunk, 0.001);
        $this->assertLessThan(Valuation::MAX_INTRINSIC_PE, $shrunk, 'The ceiling must stop being what sets the multiple.');

        // Two firms that both pinned to the ceiling must now separate by their own spreads rather than
        // sharing a single capped multiple.
        $wider = Valuation::calculateIntrinsicFairValuePE(0.08, 0.20, 0.15, 16.0);
        $this->assertEqualsWithDelta(Valuation::MAX_INTRINSIC_PE, Valuation::calculateIntrinsicFairValuePE(0.08, 0.20, 0.15), 0.0001);
        $this->assertGreaterThan($shrunk, $wider, 'The better-conditioned of two ceiling-pinned firms must now price higher.');
    }

    /**
     * The firm keeps more of its own multiple the wider its spread. Asserted on the implied weight rather
     * than on distance from the prior: the raw multiple falls as the spread widens and crosses the sector on
     * the way, so distance is not monotonic even though the weight is.
     */
    public function testIntrinsicFairValuePEShrinksHarderAsTheSpreadNarrows(): void
    {
        $sector = 12.0;
        $previousWeight = null;

        // Spreads of 2.5%, 4%, 6% and 10%, all clear of the 4% cost-of-equity floor that would collapse them.
        foreach ([0.045, 0.06, 0.08, 0.12] as $costOfEquity) {
            $raw = Valuation::calculateIntrinsicFairValuePE($costOfEquity, 0.20, 0.02);
            $shrunk = Valuation::calculateIntrinsicFairValuePE($costOfEquity, 0.20, 0.02, $sector);

            $this->assertNotEqualsWithDelta($sector, $raw, 0.01, 'Test inputs must keep the raw multiple off the prior.');
            $impliedWeight = ($shrunk - $sector) / ($raw - $sector);

            $this->assertGreaterThanOrEqual(0.0, $impliedWeight);
            $this->assertLessThanOrEqual(1.0, $impliedWeight, 'The result must lie between the firm estimate and its sector.');

            if ($previousWeight !== null) {
                $this->assertGreaterThan(
                    $previousWeight,
                    $impliedWeight,
                    'A wider spread is a more reliable estimate and must keep more of the firm-level multiple.'
                );
            }
            $previousWeight = $impliedWeight;
        }
    }

    /** Omitting the prior must reproduce the unshrunk Gordon multiple exactly, for callers without a sector. */
    public function testIntrinsicFairValuePEIsUnchangedWithoutASectorPrior(): void
    {
        foreach ([[0.10, 0.15, 0.03], [0.12, 0.25, 0.05], [0.06, 0.08, 0.01]] as [$coe, $roic, $growth]) {
            $this->assertSame(
                Valuation::calculateIntrinsicFairValuePE($coe, $roic, $growth),
                Valuation::calculateIntrinsicFairValuePE($coe, $roic, $growth, null)
            );
        }
    }

    /** A non-positive sector multiple is not a usable prior and must be ignored rather than dragging the estimate to zero. */
    public function testIntrinsicFairValuePEIgnoresAnUnusableSectorPrior(): void
    {
        $raw = Valuation::calculateIntrinsicFairValuePE(0.10, 0.15, 0.03);

        $this->assertSame($raw, Valuation::calculateIntrinsicFairValuePE(0.10, 0.15, 0.03, 0.0));
        $this->assertSame($raw, Valuation::calculateIntrinsicFairValuePE(0.10, 0.15, 0.03, -5.0));
    }

    public function testCalculateDividendDiscountModel(): void
    {
        // Gordon: next year's dividend 2.0 x 1.05 = 2.10 over 10% - 5% = 42.0.
        $fairValue = Valuation::calculateDividendDiscountModel(2.0, 0.10, 0.05);

        $this->assertEqualsWithDelta(42.0, $fairValue, 0.001, 'DDM fair value calculation failed.');
    }

    /**
     * Lintner partial adjustment: a gap that closes at once is worth nothing, one that never closes is a
     * perpetuity of the quarterly gap, and in between the value is the geometric sum of the shrinking gap.
     */
    public function testDividendAdjustmentValueDiscountsTheClosingGap(): void
    {
        $quarterlyRate = (1.10 ** 0.25) - 1.0;

        $this->assertEqualsWithDelta(0.0, Valuation::calculateDividendAdjustmentValue(-4.0, 0.10, 1.0), 1e-12);
        $this->assertEqualsWithDelta(-1.0 / $quarterlyRate, Valuation::calculateDividendAdjustmentValue(-4.0, 0.10, 0.0), 1e-9);

        $persistence = 0.9 / (1.0 + $quarterlyRate);
        $expected = 0.0;
        for ($quarter = 1; $quarter <= 2000; $quarter++) {
            $expected += -1.0 * $persistence ** $quarter;
        }
        $this->assertEqualsWithDelta($expected, Valuation::calculateDividendAdjustmentValue(-4.0, 0.10, 0.10), 1e-9);
    }

    public function testCalculateIntrinsicFairValuePEWithExtremeDistressAndNegativeGrowth(): void
    {
        // Extreme negative growth (-20%) and extreme low COE (1%) should be floored
        // to MIN_COST_OF_EQUITY (0.04) and MIN_PERPETUAL_GROWTH_RATE (-0.05)
        $pe = Valuation::calculateIntrinsicFairValuePE(0.01, 0.10, -0.20);

        $this->assertGreaterThanOrEqual(Valuation::MIN_INTRINSIC_PE, $pe);
        $this->assertLessThanOrEqual(Valuation::MAX_INTRINSIC_PE, $pe);
    }

    public function testCalculateWACC(): void
    {
        // 100% Equity
        $wacc100Eq = Valuation::calculateWACC(1.0, 0.10, 0.0, 0.05);
        $this->assertEqualsWithDelta(0.10, $wacc100Eq, 0.0001);

        // 100% Debt
        $wacc100Debt = Valuation::calculateWACC(0.0, 0.10, 1.0, 0.05);
        $this->assertEqualsWithDelta(0.05, $wacc100Debt, 0.0001);

        // 60% Equity / 40% Debt (Cost of Equity 10%, Post-Tax Cost of Debt 4%) -> 0.6 * 0.10 + 0.4 * 0.04 = 0.076 (7.6%)
        $waccMix = Valuation::calculateWACC(0.60, 0.10, 0.40, 0.04);
        $this->assertEqualsWithDelta(0.076, $waccMix, 0.0001);
    }

    public function testCalculateCAPM(): void
    {
        $rf = 0.04;
        $erp = 0.05;

        // Beta = 1.0 -> Rf + ERP = 0.09
        $coe1 = Valuation::calculateCAPM($rf, 1.0, $erp);
        $this->assertEqualsWithDelta(0.09, $coe1, 0.0001);

        // Beta = 0.0 -> Rf = 0.04
        $coe0 = Valuation::calculateCAPM($rf, 0.0, $erp);
        $this->assertEqualsWithDelta(0.04, $coe0, 0.0001);

        // Beta = 1.5 -> 0.04 + 1.5 * 0.05 = 0.115
        $coe15 = Valuation::calculateCAPM($rf, 1.5, $erp);
        $this->assertEqualsWithDelta(0.115, $coe15, 0.0001);

        // Negative Beta = -0.5 -> 0.04 - 0.025 = 0.015
        $coeNeg = Valuation::calculateCAPM($rf, -0.5, $erp);
        $this->assertEqualsWithDelta(0.015, $coeNeg, 0.0001);
    }

    public function testCalculateLeveredBeta(): void
    {
        $unleveredBeta = 1.0;
        $taxRate = 0.20; // (1 - 0.20) = 0.80

        // Zero debt -> levered beta = unlevered beta
        $betaZeroDebt = Valuation::calculateLeveredBeta($unleveredBeta, $taxRate, 0.0);
        $this->assertEqualsWithDelta(1.0, $betaZeroDebt, 0.0001);

        // D/E = 1.0, dampening = 1.0 -> 1.0 * (1 + 0.8 * 1.0) = 1.80
        $betaStandard = Valuation::calculateLeveredBeta($unleveredBeta, $taxRate, 1.0, 1.0);
        $this->assertEqualsWithDelta(1.80, $betaStandard, 0.0001);

        // D/E = 1.0, dampening = 0.5 -> 1.0 * (1 + 0.8 * 0.5) = 1.40
        $betaDampened = Valuation::calculateLeveredBeta($unleveredBeta, $taxRate, 1.0, 0.5);
        $this->assertEqualsWithDelta(1.40, $betaDampened, 0.0001);
    }

    public function testCalculateEarningsResponseCoefficient(): void
    {
        // Moderate SUE (+5% surprise)
        $erc = Valuation::calculateEarningsResponseCoefficient(0.05, 1.0, 0.0);
        $this->assertGreaterThan(0.0, $erc, 'Positive SUE should yield positive ERC price reaction.');

        // High growth premium amplifies the reaction
        $ercHighGrowth = Valuation::calculateEarningsResponseCoefficient(0.05, 1.0, 2.0);
        $this->assertGreaterThan($erc, $ercHighGrowth, 'High growth premium must amplify ERC.');
    }

    public function testCalculateBayesianAnalystUpdate(): void
    {
        // Equal uncertainty -> straight average
        $updated = Valuation::calculateBayesianAnalystUpdate(
            priorEstimate: 100.0,
            priorVariance: 1.0,
            newSignal: 120.0,
            signalVariance: 1.0
        );
        $this->assertEqualsWithDelta(110.0, $updated, 0.0001);

        // Highly confident prior (low variance) -> stays close to prior
        $updatedConfidentPrior = Valuation::calculateBayesianAnalystUpdate(
            priorEstimate: 100.0,
            priorVariance: 0.01,
            newSignal: 150.0,
            signalVariance: 1.00
        );
        $this->assertLessThan(101.0, $updatedConfidentPrior);

        // Highly confident signal (low variance) -> moves close to signal
        $updatedConfidentSignal = Valuation::calculateBayesianAnalystUpdate(
            priorEstimate: 100.0,
            priorVariance: 1.00,
            newSignal: 150.0,
            signalVariance: 0.01
        );
        $this->assertGreaterThan(149.0, $updatedConfidentSignal);
    }

    public function testExpectedNominalGrowthCarriesTheCycleAndPricesTheSecularExcessAsItFades(): void
    {
        $trend = MacroEngine::TREND_REAL_GROWTH;
        // Boom: trend secular growth + half of a 2% gap at beta 1, plus 2% inflation. The cycle is transitory and passes.
        $this->assertEqualsWithDelta($trend + 0.01 + 0.02, Valuation::calculateExpectedNominalGrowth($trend, 0.02, 1.0, 0.02, 0.09), 1e-12);
        // Bust at the same beta takes the same amount off.
        $this->assertEqualsWithDelta($trend - 0.01 + 0.02, Valuation::calculateExpectedNominalGrowth($trend, -0.02, 1.0, 0.02, 0.09), 1e-12);
        // No sector outgrows the economy forever: an excess over trend fades at the secular half-life, so a 10% secular
        // rate is priced at its perpetual equivalent, above trend and well below 10% (Fuller & Hsia 1984).
        $priced = Valuation::calculateExpectedNominalGrowth(0.10, 0.0, 1.0, 0.02, 0.09) - 0.02;
        $spread = 0.09 - 0.02 - $trend;
        $fade = M_LN2 / FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS;
        $this->assertEqualsWithDelta($trend + ((0.10 - $trend) * $spread / ($spread + $fade)), $priced, 1e-12);
        $this->assertGreaterThan($trend, $priced);
        $this->assertLessThan($trend + (0.5 * (0.10 - $trend)), $priced);
        $this->assertSame(0.0, Valuation::calculateExpectedNominalGrowth(-0.10, 0.0, 1.0, 0.0, 0.09));
    }

    /**
     * Damodaran's fundamental growth in real terms: retention funds real growth at the return on capital, and the
     * plant already in place grows with the price level. A firm that keeps nothing still grows with inflation; the
     * outlook stands while retention funds it.
     */
    public function testFundableGrowthIsTheOutlookCappedAtInflationPlusWhatRetentionFunds(): void
    {
        $this->assertEqualsWithDelta(0.045, Valuation::calculateFundableGrowth(0.045, 0.15, 0.40, 0.02), 1e-12);
        $this->assertEqualsWithDelta(0.02 + (0.15 * 0.10), Valuation::calculateFundableGrowth(0.045, 0.15, 0.90, 0.02), 1e-12);
        $this->assertEqualsWithDelta(0.02, Valuation::calculateFundableGrowth(0.045, 0.15, 1.20, 0.02), 1e-12);
        $this->assertEqualsWithDelta(0.02, Valuation::calculateFundableGrowth(0.045, -0.05, 0.40, 0.02), 1e-12);
    }

    public function testManagementAndTheMarketStrikeTheSameFairValueMultiple(): void
    {
        $growth = Valuation::calculateFundableGrowth(Valuation::calculateExpectedNominalGrowth(0.03, 0.01, 1.2, 0.025, 0.09), 0.18, 0.40, 0.025);
        $market = Valuation::calculateQualityAdjustedFairValuePE(0.09, 0.18, $growth, 22.0, 0.04);
        $management = Valuation::calculateManagementFairValuePE(0.09, 0.18, 0.03, 0.01, 1.2, 0.025, 22.0, 0.04, 0.40);

        $this->assertSame($market, $management);
        // A payout that leaves too little to fund the outlook lowers management's multiple exactly as the market's.
        $this->assertLessThan($management, Valuation::calculateManagementFairValuePE(0.09, 0.18, 0.03, 0.01, 1.2, 0.025, 22.0, 0.04, 0.95));
        // The Sloan discount is inside the shared figure, floored at the distressed multiple.
        $clean = Valuation::calculateQualityAdjustedFairValuePE(0.09, 0.18, $growth, 22.0, 0.0);
        $this->assertEqualsWithDelta($clean - 0.04 * Valuation::ACCRUALS_ANOMALY_PE_PENALTY_SCALE, $market, 1e-12);
    }

    /** ROE = ROIC + D/E (ROIC - kd(1-t)), and an equity at or below zero is not levered by. */
    public function testEquityReturnFromRoicIsTheLeverageIdentity(): void
    {
        self::assertEqualsWithDelta(0.10 + (1.0 * (0.10 - 0.04)), Valuation::equityReturnFromRoic(0.10, 100.0, 50.0, 0.04), 1e-12);
        self::assertEqualsWithDelta(0.10, Valuation::equityReturnFromRoic(0.10, 50.0, 50.0, 0.04), 1e-12);
        self::assertEqualsWithDelta(0.02 + (3.0 * (0.02 - 0.04)), Valuation::equityReturnFromRoic(0.02, 100.0, 25.0, 0.04), 1e-12);
        self::assertSame(0.10, Valuation::equityReturnFromRoic(0.10, 100.0, 0.0, 0.04));
    }

    /** Nominal growth is real growth plus all of inflation; at target inflation and a closed gap nothing else enters. */
    public function testExpectedNominalGrowthCarriesInflationInFull(): void
    {
        $target = MacroEngine::TARGET_INFLATION;

        self::assertEqualsWithDelta(0.02 + $target, Valuation::calculateExpectedNominalGrowth(0.02, 0.0, 1.0, $target, 0.09), 1e-12);
        // Above target too: no firm's priced growth loses real growth to inflation its earnings pass through.
        self::assertEqualsWithDelta(0.02 + 0.04, Valuation::calculateExpectedNominalGrowth(0.02, 0.0, 1.0, 0.04, 0.09), 1e-12);
    }

    /** The flexible accelerator: demand growth plus a share of the log capital gap, never a negative build. */
    public function testTheFlexibleAcceleratorGrowsWithDemandAndClosesItsGapWithoutDisinvesting(): void
    {
        self::assertEqualsWithDelta(0.04, Valuation::flexibleAcceleratorGrowth(0.04, 0.0, 0.062), 1e-12);
        self::assertEqualsWithDelta(0.04 + (0.062 * log(1.5)), Valuation::flexibleAcceleratorGrowth(0.04, log(1.5), 0.062), 1e-12);
        self::assertEqualsWithDelta(0.04 - (0.062 * 0.5), Valuation::flexibleAcceleratorGrowth(0.04, -0.5, 0.062), 1e-12);
        self::assertSame(0.0, Valuation::flexibleAcceleratorGrowth(0.04, -1.0, 0.062), 'an overbuilt firm stops building; it does not sell plant');
    }
}
