<?php

namespace App\Tests\Service\Math;

use App\Service\Macro\MacroEngine;
use App\Service\Math\FirmEconomics;
use PHPUnit\Framework\TestCase;

/**
 * The formulas in App\Service\Math\FirmEconomics.
 */
class FirmEconomicsTest extends TestCase
{
    public function testCalculateAsymmetricCostStickinessCompressesMarginsOnRevenueDecline(): void
    {
        $baselineVariableCostRatio = 0.70; // 70% variable cost ratio (30% gross margin)

        // Case 1: Revenue grows by 20% (log change = ln(1.20) ≈ +0.1823)
        $growthLogChange = log(1.20);
        $marginOnGrowth = FirmEconomics::calculateAsymmetricCostStickiness($baselineVariableCostRatio, $growthLogChange);
        // On growth, variable costs expand with beta = 0.85, so cost ratio drops (margin expands)
        $this->assertLessThan($baselineVariableCostRatio, $marginOnGrowth, 'Variable cost ratio should drop slightly on revenue growth.');

        // Case 2: Revenue contracts by 20% (log change = ln(0.80) ≈ -0.2231)
        $contractionLogChange = log(0.80);
        $marginOnContraction = FirmEconomics::calculateAsymmetricCostStickiness($baselineVariableCostRatio, $contractionLogChange);
        // On contraction, costs drop sluggishly due to stickiness penalty (beta1 + beta2 = 0.85 - 0.40 = 0.45 < 1.0),
        // causing cost ratio to increase (margin compresses sharply).
        $this->assertGreaterThan($baselineVariableCostRatio, $marginOnContraction, 'Variable cost ratio should increase sharply on revenue contraction.');
        
        // Contraction margin shift magnitude should be significantly larger than growth shift due to asymmetry
        $growthDelta = abs($baselineVariableCostRatio - $marginOnGrowth);
        $contractionDelta = abs($marginOnContraction - $baselineVariableCostRatio);
        $this->assertGreaterThan($growthDelta, $contractionDelta, 'Downward cost stickiness should create larger margin squeeze than upward expansion.');
    }

    public function testCalculateDynamicWorkingCapitalIntensityExpandsUnderStress(): void
    {
        $baseIntensity = 0.15; // 15% NWC / Revenue

        // Normal neutral baseline conditions
        $neutralIntensity = FirmEconomics::calculateDynamicWorkingCapitalIntensity(
            baselineIntensity: $baseIntensity,
            creditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            capacityUtilization: 1.0,
            interbankLiquiditySpread: MacroEngine::INTERBANK_BASELINE_SPREAD
        );
        $this->assertEqualsWithDelta($baseIntensity, $neutralIntensity, 0.0001, 'Under neutral conditions, intensity should equal baseline.');

        // Distressed recession conditions: Credit spread +300bps, Capacity utilization 70%, Interbank spread 100bps
        $stressedIntensity = FirmEconomics::calculateDynamicWorkingCapitalIntensity(
            baselineIntensity: $baseIntensity,
            creditSpread: 0.05, // +300bps DSO stretch
            capacityUtilization: 0.70, // 30% idle capacity DIO stretch
            interbankLiquiditySpread: 0.0100 // +85bps liquidity crunch DPO drain
        );
        $this->assertGreaterThan($baseIntensity, $stressedIntensity, 'Stressed conditions must expand working capital intensity.');
    }

    public function testCalculateDynamicWorkingCapitalIntensityCompressesNegativeFloatUnderStress(): void
    {
        $negativeFloat = -0.05; // -5% NWC / Revenue float (e.g. Consumer Staples / Fast Food)

        // Normal neutral baseline conditions
        $neutralIntensity = FirmEconomics::calculateDynamicWorkingCapitalIntensity(
            baselineIntensity: $negativeFloat,
            creditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            capacityUtilization: 1.0,
            interbankLiquiditySpread: 0.0015
        );
        $this->assertEqualsWithDelta($negativeFloat, $neutralIntensity, 0.0001, 'Under neutral conditions, negative float should be preserved.');

        // Distressed recession conditions: Credit spread +300bps, Capacity utilization 70%, Interbank spread 100bps
        $stressedIntensity = FirmEconomics::calculateDynamicWorkingCapitalIntensity(
            baselineIntensity: $negativeFloat,
            creditSpread: 0.05,
            capacityUtilization: 0.70,
            interbankLiquiditySpread: 0.0100
        );
        // Under stress, negative float shrinks toward zero (becomes less negative, i.e., -0.05 -> -0.045)
        $this->assertGreaterThan($negativeFloat, $stressedIntensity, 'Stressed conditions must compress negative float toward zero.');
        $this->assertLessThan(0.0, $stressedIntensity, 'Float remains negative during stress.');
    }

    public function testCalculateDynamicWorkingCapitalIntensityTightensDuringBoom(): void
    {
        $baseIntensity = 0.15;

        // Roaring boom: credit spreads 50 bps inside baseline, high interbank liquidity (-50bps), 100% capacity utilization
        $boomIntensity = FirmEconomics::calculateDynamicWorkingCapitalIntensity(
            baselineIntensity: $baseIntensity,
            creditSpread: MacroEngine::BASE_CREDIT_SPREAD - 0.005,
            capacityUtilization: 1.0,
            interbankLiquiditySpread: 0.0010
        );

        $this->assertLessThan($baseIntensity, $boomIntensity, 'Boom conditions should tighten working capital intensity below baseline.');
    }

    public function testPerUnitCostsFallAsAShareOfRevenueWhenThePriceRises(): void
    {
        // Revenue P x Q, cost c x Q: at twice the price the same tonnes cost half as much per dollar.
        $this->assertEqualsWithDelta(0.10, FirmEconomics::calculatePerUnitCostRatio(0.20, 2.0), 1e-12);
        $this->assertEqualsWithDelta(0.40, FirmEconomics::calculatePerUnitCostRatio(0.20, 0.5), 1e-12);
        $this->assertEqualsWithDelta(0.20, FirmEconomics::calculatePerUnitCostRatio(0.20, 1.0), 1e-12);
        // A price at zero cannot divide by zero.
        $this->assertTrue(is_finite(FirmEconomics::calculatePerUnitCostRatio(0.20, 0.0)));
    }

    public function testCalculateCapacityUtilization(): void
    {
        // Neutral conditions (0 output gap, 0 overhang)
        $baselineCu = FirmEconomics::calculateCapacityUtilization(
            outputGap: 0.0,
            capitalStockOverhang: 0.0
        );
        $this->assertEqualsWithDelta(0.785, $baselineCu, 0.0001, 'Neutral conditions must match Fed G.17 baseline utilization.');

        // Economic boom (+3% output gap, 0 overhang)
        $boomCu = FirmEconomics::calculateCapacityUtilization(
            outputGap: 0.03,
            capitalStockOverhang: 0.0
        );
        $this->assertGreaterThan($baselineCu, $boomCu, 'Positive output gap must increase capacity utilization.');

        // Heavy capital overhang (+10% overhang, 0 output gap)
        $slackCu = FirmEconomics::calculateCapacityUtilization(
            outputGap: 0.0,
            capitalStockOverhang: 0.10
        );
        $this->assertLessThan($baselineCu, $slackCu, 'Excess capital stock overhang must depress capacity utilization.');

        // Extreme bounds check
        $extremeBoom = FirmEconomics::calculateCapacityUtilization(0.50, 0.0);
        $this->assertLessThanOrEqual(0.92, $extremeBoom);

        $extremeBust = FirmEconomics::calculateCapacityUtilization(-0.50, 0.50);
        $this->assertGreaterThanOrEqual(0.60, $extremeBust);
    }

    public function testCalculateRefiningCrackSpreadStep(): void
    {
        // Neutral equilibrium (dW = 0)
        $nextSpread = FirmEconomics::calculateRefiningCrackSpreadStep(
            currentCrack: 15.0,
            outputGap: 0.0,
            energyInventoryIndex: 100.0,
            dt: 0.25,
            dW: 0.0,
            baselineCrack: 22.0
        );
        $this->assertGreaterThan(15.0, $nextSpread, 'Crack spread below equilibrium must revert upwards.');
        $this->assertLessThanOrEqual(22.0, $nextSpread);

        // Positive demand shock (+3% output gap, tight inventory at 70)
        $boomSpread = FirmEconomics::calculateRefiningCrackSpreadStep(
            currentCrack: 22.0,
            outputGap: 0.03,
            energyInventoryIndex: 70.0,
            dt: 0.25,
            dW: 0.0,
            baselineCrack: 22.0
        );
        $this->assertGreaterThan(22.0, $boomSpread, 'Strong distillate demand with tight energy inventory expands crack spread.');

        // Floor constraint
        $floored = FirmEconomics::calculateRefiningCrackSpreadStep(
            currentCrack: 2.0,
            outputGap: -0.10,
            energyInventoryIndex: 150.0,
            dt: 0.25,
            dW: -5.0,
            baselineCrack: 22.0
        );
        $this->assertGreaterThanOrEqual(4.0, $floored, 'Refining crack spread must not fall below $4.00/bbl floor.');
    }

    /**
     * The three cash-conversion-cycle legs move on separate drivers and are not interchangeable.
     */
    public function testWorkingCapitalDayShiftsRespondToTheirOwnDriverOnly(): void
    {
        $neutral = FirmEconomics::calculateWorkingCapitalDayShifts(
            MacroEngine::BASE_CREDIT_SPREAD,
            1.0,
            MacroEngine::INTERBANK_BASELINE_SPREAD
        );

        $this->assertEqualsWithDelta(0.0, $neutral['dso'], 0.0000001, 'At the baseline spread receivables do not age.');
        $this->assertEqualsWithDelta(0.0, $neutral['dio'], 0.0000001, 'At full capacity inventory does not build.');
        $this->assertEqualsWithDelta(0.0, $neutral['dpo'], 0.0000001, 'At baseline liquidity payables do not contract.');

        // Dear credit stretches customer payment: DSO rises with the spread over baseline.
        $creditStress = FirmEconomics::calculateWorkingCapitalDayShifts(
            MacroEngine::BASE_CREDIT_SPREAD + 0.010,
            1.0,
            MacroEngine::INTERBANK_BASELINE_SPREAD
        );
        $this->assertEqualsWithDelta(0.010 * FirmEconomics::CCC_DSO_CREDIT_SPREAD_SENSITIVITY, $creditStress['dso'], 0.0000001, 'DSO must track the credit spread.');
        $this->assertEqualsWithDelta(0.0, $creditStress['dio'], 0.0000001, 'A credit shock must not move inventory days.');
        $this->assertEqualsWithDelta(0.0, $creditStress['dpo'], 0.0000001, 'A credit shock must not move payable days.');

        // Slack plants pile up unsold goods: DIO rises as utilisation falls.
        $slack = FirmEconomics::calculateWorkingCapitalDayShifts(
            MacroEngine::BASE_CREDIT_SPREAD,
            0.80,
            MacroEngine::INTERBANK_BASELINE_SPREAD
        );
        $this->assertEqualsWithDelta(0.20 * FirmEconomics::CCC_DIO_CAPACITY_SENSITIVITY, $slack['dio'], 0.0000001, 'DIO must track idle capacity.');
        $this->assertEqualsWithDelta(0.0, $slack['dso'], 0.0000001, 'Idle capacity must not move receivable days.');

        // Interbank stress makes vendors demand cash sooner, so DPO contracts (negative shift).
        $liquidityStress = FirmEconomics::calculateWorkingCapitalDayShifts(
            MacroEngine::BASE_CREDIT_SPREAD,
            1.0,
            MacroEngine::INTERBANK_BASELINE_SPREAD + 0.004
        );
        $this->assertEqualsWithDelta(-0.004 * FirmEconomics::CCC_DPO_LIQUIDITY_SENSITIVITY, $liquidityStress['dpo'], 0.0000001, 'DPO must contract under interbank stress.');
        $this->assertLessThan(0.0, $liquidityStress['dpo'], 'Vendors demanding faster payment shortens the payable cycle.');
    }

    /**
     * Metzler inventory dynamics: an involuntary build on a demand miss, then correction toward a cyclical target.
     */
    public function testInventoryCycleStepBuildsOnDemandMissesAndRevertsToTheCyclicalTarget(): void
    {
        // Closed form: (surpriseSens * (ema - gap) + -speed * (current - -cyclicalSens * gap)) * dt, added to current.
        $this->assertEqualsWithDelta(
            0.006,
            FirmEconomics::calculateInventoryCycleStep(0.0, -0.03, 0.0, 0.5, 0.4, 0.25),
            0.0000001,
            'An unexpected demand miss must build inventory by the closed form.'
        );

        // A demand surprise to the upside draws inventory down instead.
        $this->assertLessThan(
            0.0,
            FirmEconomics::calculateInventoryCycleStep(0.0, 0.03, 0.0, 0.5, 0.4, 0.25),
            'Unexpectedly strong demand must deplete inventory.'
        );

        // With no surprise and a closed gap the target is zero, so any overhang decays toward it.
        $decayed = FirmEconomics::calculateInventoryCycleStep(0.10, 0.0, 0.0, 0.5, 0.4, 0.25);
        $this->assertLessThan(0.10, $decayed, 'An overhang must dissipate when demand is at trend.');
        $this->assertGreaterThan(0.0, $decayed, 'Dissipation must be gradual, not instant.');

        // The cyclical target is signed against the output gap: contractions raise the desired inventory-to-sales ratio.
        $contraction = FirmEconomics::calculateInventoryCycleStep(0.0, -0.05, -0.05, 0.5, 0.4, 0.25);
        $expansion = FirmEconomics::calculateInventoryCycleStep(0.0, 0.05, 0.05, 0.5, 0.4, 0.25);
        $this->assertGreaterThan(0.0, $contraction, 'A contraction must lift the inventory target.');
        $this->assertLessThan(0.0, $expansion, 'An expansion must lean inventories out.');

        // The gap is hard-clamped so a violent step cannot run away.
        $this->assertSame(0.15, FirmEconomics::calculateInventoryCycleStep(0.14, -0.20, 0.20, 0.5, 1.0, 1.0), 'The overhang must clamp at its ceiling.');
        $this->assertSame(-0.15, FirmEconomics::calculateInventoryCycleStep(-0.14, 0.20, -0.20, 0.5, 1.0, 1.0), 'The shortage must clamp at its floor.');
    }

    public function testFringeAdjustedPriceLevelReducesToCournotWithNoFringeResponseAndSoftensItOtherwise(): void
    {
        $cournot = FirmEconomics::calculateCournotPriceLevel(1.2, 1.25);

        // No fringe elasticity, or no fringe at all, is the plain Cournot level.
        $this->assertEqualsWithDelta($cournot, FirmEconomics::calculateFringeAdjustedPriceLevel(1.2, 0.4, 1.25, 0.0), 1e-12);
        $this->assertEqualsWithDelta($cournot, FirmEconomics::calculateFringeAdjustedPriceLevel(1.2, 1.0, 1.25, 1.0), 1e-12);
        // A balanced industry clears at one whatever the fringe does.
        $this->assertEqualsWithDelta(1.0, FirmEconomics::calculateFringeAdjustedPriceLevel(1.0, 0.4, 1.25, 1.0), 1e-9);

        // With a responsive fringe the overbuild is partly absorbed by fringe exit: the price sits between
        // the Cournot level and one, and the solution satisfies the market-clearing identity exactly.
        $adjusted = FirmEconomics::calculateFringeAdjustedPriceLevel(1.2, 0.4, 1.25, 1.0);
        $this->assertGreaterThan($cournot, $adjusted);
        $this->assertLessThan(1.0, $adjusted);
        $this->assertEqualsWithDelta(0.4 + 0.2 + 0.6 * $adjusted, $adjusted ** -1.25, 1e-9, 'roster + excess + fringe supply = demand');

        // A more elastic fringe absorbs more; a larger fringe absorbs more.
        $this->assertGreaterThan($adjusted, FirmEconomics::calculateFringeAdjustedPriceLevel(1.2, 0.4, 1.25, 2.0));
        $this->assertGreaterThan($adjusted, FirmEconomics::calculateFringeAdjustedPriceLevel(1.2, 0.2, 1.25, 1.0));
        // A hole (capacity short of trend) is likewise partly refilled: dearer than one, cheaper than Cournot.
        $short = FirmEconomics::calculateFringeAdjustedPriceLevel(0.8, 0.4, 1.25, 1.0);
        $this->assertGreaterThan(1.0, $short);
        $this->assertLessThan(FirmEconomics::calculateCournotPriceLevel(0.8, 1.25), $short);
        $this->assertSame(1.0, FirmEconomics::calculateFringeAdjustedPriceLevel(0.0, 0.4, 1.25, 1.0));
    }

    public function testCournotMarginalRevenueFactorIsTheLernerConditionScaledBySubstitutability(): void
    {
        // MR/P = 1 - s/e for a firm selling its whole output into one price.
        $this->assertEqualsWithDelta(1.0 - 0.4 / 1.25, FirmEconomics::calculateCournotMarginalRevenueFactor(0.4, 1.25, 1.0), 1e-12);
        // Output that is only partly substitutable moves the industry price only partly.
        $this->assertEqualsWithDelta(1.0 - 0.4 * 0.5 / 1.25, FirmEconomics::calculateCournotMarginalRevenueFactor(0.4, 1.25, 0.5), 1e-12);
        // Non-substitutable output, or a firm with no share, is a price-taker.
        $this->assertSame(1.0, FirmEconomics::calculateCournotMarginalRevenueFactor(0.4, 1.25, 0.0));
        $this->assertSame(1.0, FirmEconomics::calculateCournotMarginalRevenueFactor(0.0, 1.25, 1.0));
        // A closed loop with inelastic demand can go no lower than zero; share is capped at the whole market.
        $this->assertSame(0.0, FirmEconomics::calculateCournotMarginalRevenueFactor(3.0, 0.8, 1.0));
        $this->assertSame(1.0, FirmEconomics::calculateCournotMarginalRevenueFactor(0.4, 0.0, 1.0));
    }

    public function testCournotPriceLevelFallsWithExcessCapacityAtTheInverseElasticity(): void
    {
        $this->assertEqualsWithDelta(1.0, FirmEconomics::calculateCournotPriceLevel(1.0, 1.25), 1e-12);
        $this->assertEqualsWithDelta(1.2 ** (-0.8), FirmEconomics::calculateCournotPriceLevel(1.2, 1.25), 1e-12);
        $this->assertGreaterThan(1.0, FirmEconomics::calculateCournotPriceLevel(0.8, 1.25));
        $this->assertSame(1.0, FirmEconomics::calculateCournotPriceLevel(0.0, 1.25));
        $this->assertSame(1.0, FirmEconomics::calculateCournotPriceLevel(1.2, 0.0));
    }

    public function testGrowthContributionsSumToTotalGrowth(): void
    {
        $previous = ['net_interest_income' => 80.0, 'fee_income' => 20.0];
        $current = ['net_interest_income' => 100.0, 'fee_income' => 30.0];

        $contributions = FirmEconomics::calculateGrowthContributions($current, $previous);

        // 130 against 100 is 30% growth, and the segments must account for all of it.
        $this->assertEqualsWithDelta(0.30, array_sum($contributions), 1e-9);
        $this->assertEqualsWithDelta(0.20, $contributions['net_interest_income'], 1e-9);
        $this->assertEqualsWithDelta(0.10, $contributions['fee_income'], 1e-9);
    }

    public function testASmallSegmentsOwnGrowthRateIsNotItsContribution(): void
    {
        $previous = ['core' => 95.0, 'venture' => 5.0];
        $current = ['core' => 95.0, 'venture' => 7.0];

        $contributions = FirmEconomics::calculateGrowthContributions($current, $previous);

        // Venture grew 40% on its own base but carried 2 points of the firm — the whole reason
        // the panel prints contribution alongside the segment's own rate.
        $this->assertEqualsWithDelta(0.02, $contributions['venture'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $contributions['core'], 1e-9);
    }

    public function testDiscontinuedAndNewSegmentsBothCountTowardsGrowth(): void
    {
        $previous = ['legacy' => 40.0, 'core' => 60.0];
        $current = ['core' => 60.0, 'platform' => 30.0];

        $contributions = FirmEconomics::calculateGrowthContributions($current, $previous);

        $this->assertEqualsWithDelta(-0.40, $contributions['legacy'], 1e-9);
        $this->assertEqualsWithDelta(0.30, $contributions['platform'], 1e-9);
        $this->assertEqualsWithDelta(-0.10, array_sum($contributions), 1e-9);
    }

    public function testGrowthContributionsAreUndefinedWithoutAPriorPeriod(): void
    {
        $this->assertSame([], FirmEconomics::calculateGrowthContributions(['core' => 100.0], []));
        $this->assertSame([], FirmEconomics::calculateGrowthContributions(['core' => 100.0], ['core' => 0.0]));
    }

    public function testHerfindahlIndexRunsFromEvenSplitToSingleSegment(): void
    {
        $this->assertEqualsWithDelta(1.0, FirmEconomics::calculateHerfindahlIndex(['only' => 1.0]), 1e-9);
        $this->assertEqualsWithDelta(0.5, FirmEconomics::calculateHerfindahlIndex(['a' => 0.5, 'b' => 0.5]), 1e-9);
        $this->assertEqualsWithDelta(0.25, FirmEconomics::calculateHerfindahlIndex(array_fill_keys(['a', 'b', 'c', 'd'], 0.25)), 1e-9);
    }

    public function testHerfindahlIndexNormalisesSharesThatDoNotSumToOne(): void
    {
        // Raw revenues carry the same concentration as the shares they imply.
        $this->assertEqualsWithDelta(
            FirmEconomics::calculateHerfindahlIndex(['a' => 0.5, 'b' => 0.5]),
            FirmEconomics::calculateHerfindahlIndex(['a' => 400.0, 'b' => 400.0]),
            1e-9,
        );
    }

    public function testHerfindahlIndexIsZeroForAnEmptyMix(): void
    {
        $this->assertSame(0.0, FirmEconomics::calculateHerfindahlIndex([]));
        $this->assertSame(0.0, FirmEconomics::calculateHerfindahlIndex(['a' => 0.0]));
    }

    public function testEffectiveSegmentCountIsTheInverseOfTheIndex(): void
    {
        $hhi = FirmEconomics::calculateHerfindahlIndex(['a' => 0.5, 'b' => 0.3, 'c' => 0.2]);

        // Three booked segments that behave like about 2.6 of them.
        $this->assertEqualsWithDelta(2.632, FirmEconomics::calculateEffectiveSegmentCount($hhi), 0.001);
        $this->assertSame(0.0, FirmEconomics::calculateEffectiveSegmentCount(0.0));
    }
}
