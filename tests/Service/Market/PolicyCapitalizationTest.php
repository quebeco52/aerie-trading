<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Market\PolicyCapitalization as Policy;
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;
use PHPUnit\Framework\TestCase;

class PolicyCapitalizationTest extends TestCase
{
    /** A firm discounted at 9% growing at 4%. */
    private const CAP_RATE = 0.05;

    /** The share of a growing perpetuity's value after a date is its discounted tail, and a change phasing in keeps lambda / (lambda + k - g) of it, as the integral has it. */
    public function testThePerpetuityShareIsTheDiscountedTail(): void
    {
        $this->assertSame(1.0, MathUtility::perpetuityShareAfter(self::CAP_RATE, 0.0));
        $this->assertEqualsWithDelta(exp(-self::CAP_RATE * 3.0), MathUtility::perpetuityShareAfter(self::CAP_RATE, 3.0), 1e-15);

        // Midpoint sum of c e^{-ct} (1 - e^{-lambda (t - t0)}) from t0 = 2 on.
        $speed = MacroEngine::FISCAL_ADJUSTMENT_SPEED;
        $sum = 0.0;
        $step = 0.001;
        for ($t = 2.0 + ($step / 2.0); $t < 400.0; $t += $step) {
            $sum += self::CAP_RATE * exp(-self::CAP_RATE * $t) * (1.0 - exp(-$speed * ($t - 2.0))) * $step;
        }
        $this->assertEqualsWithDelta($sum, MathUtility::perpetuityShareAfter(self::CAP_RATE, 2.0, $speed), 1e-6);
    }

    /** With the laws carried in the earnings expected to hold for good, nothing is repriced. */
    public function testLawsAlreadyCarriedAreNotPricedAgain(): void
    {
        $macro = $this->macro(inForce: Policy::LONG_RUN_CORPORATE_TAX_SHIFT, levy: Policy::LONG_RUN_BANK_LEVY_RATE);

        $this->assertEqualsWithDelta(0.0, Policy::corporateTaxShiftGap($macro, self::CAP_RATE), 1e-15);
        $this->assertEqualsWithDelta(0.0, Policy::bankLevyGap($macro, self::CAP_RATE), 1e-15);
        $this->assertSame(100.0, Policy::reprice(100.0, 0.21, 0.21, 0.0, 50.0, self::CAP_RATE));
    }

    /** A tax rise the market expects counts for the share of the firm's value after the next government's first budget, until its term is over. */
    public function testAnExpectedRiseCountsForTheValueAfterItTakesEffect(): void
    {
        $inForce = Policy::LONG_RUN_CORPORATE_TAX_SHIFT;
        $macro = $this->macro(inForce: $inForce, expected: $inForce + 0.04, from: 12.5, time: 10.0);
        $this->assertEqualsWithDelta($this->taxPath(0.04, 0.0, 2.5), Policy::corporateTaxShiftGap($macro, self::CAP_RATE), 1e-15);
        $this->assertLessThan(Policy::corporateTaxShiftGap($this->macro(inForce: $inForce, expected: $inForce + 0.04, from: 10.5, time: 10.0), self::CAP_RATE), Policy::corporateTaxShiftGap($macro, self::CAP_RATE), 'Nearer, it counts for more.');
    }

    /** A law the market foresaw does not move the price when it is passed: the gap is the same either side of the budget that passes it, and fades only as the earnings take it in. */
    public function testAForeseenLawDoesNotMoveThePriceWhenPassed(): void
    {
        $old = Policy::LONG_RUN_CORPORATE_TAX_SHIFT;
        $new = $old + 0.04;
        $before = $this->macro(inForce: $old, expected: $new, from: 10.0, time: 10.0);
        $after = $this->macro(inForce: $new, expected: $new, from: 10.0, time: 10.0, realized: $old, embodied: $old);

        $this->assertEqualsWithDelta(Policy::corporateTaxShiftGap($before, self::CAP_RATE), Policy::corporateTaxShiftGap($after, self::CAP_RATE), 1e-15);

        // Once the earnings carry it, only the drift back to the long-run law after the term is left to price.
        $takenIn = $this->macro(inForce: $new, expected: $new, from: 10.0, time: 10.0, realized: $new, embodied: $new);
        $this->assertEqualsWithDelta($this->taxPath(0.0, 0.04, 0.0), Policy::corporateTaxShiftGap($takenIn, self::CAP_RATE), 1e-15);
        $this->assertLessThan(0.0, Policy::corporateTaxShiftGap($takenIn, self::CAP_RATE));

        // The levy likewise, charged in full from the day it passes.
        $levyBefore = $this->macro(levy: 0.0, expectedLevy: 0.002, from: 10.0, time: 10.0);
        $levyAfter = $this->macro(levy: 0.002, expectedLevy: 0.002, from: 10.0, time: 10.0, levyEmbodied: 0.0);
        $this->assertEqualsWithDelta(Policy::bankLevyGap($levyBefore, self::CAP_RATE), Policy::bankLevyGap($levyAfter, self::CAP_RATE), 1e-15);
    }

    /** The sitting government's coming budget is priced before it passes, so the round that passes it moves nothing. */
    public function testTheSittingGovernmentsBudgetIsPricedBeforeItPasses(): void
    {
        $old = Policy::LONG_RUN_CORPORATE_TAX_SHIFT;
        $new = $old + 0.03;
        $sitting = static fn(float $inForce, float $from): MacroStateDTO => new MacroStateDTO(
            totalTime: 10.0,
            corporateTaxPolicyShift: $inForce,
            corporateTaxShiftRealized: $old,
            corporateTaxShiftEmbodied: $old,
            sittingCorporateTaxPolicyShift: $new,
            sittingPolicyFrom: $from,
            previousSittingCorporateTaxPolicyShift: $new,
            previousSittingPolicyFrom: $from,
        );

        $ahead = Policy::corporateTaxShiftGap($sitting($old, 10.4), self::CAP_RATE);
        $this->assertGreaterThan(0.0, $ahead, 'The rise is priced before the round.');
        $this->assertEqualsWithDelta(
            Policy::corporateTaxShiftGap($sitting($old, 10.0), self::CAP_RATE),
            Policy::corporateTaxShiftGap($sitting($new, 10.0), self::CAP_RATE),
            1e-15,
            'On the day it passes, passing it changes nothing.'
        );
    }

    /** A bank is repriced by the present value of the levy it expects beyond the one it pays; a firm with nothing levied is not. */
    public function testABankPaysThePresentValueOfTheExpectedLevy(): void
    {
        $repriced = Policy::reprice(100.0, 0.21, 0.21, 0.001, 2000.0, self::CAP_RATE);
        $this->assertEqualsWithDelta(100.0 - (0.001 * 2000.0 / self::CAP_RATE), $repriced, 1e-12);
        $this->assertSame(100.0, Policy::reprice(100.0, 0.21, 0.21, 0.001, 0.0, self::CAP_RATE));

        $taxed = Policy::reprice(100.0, 0.21, 0.25, 0.0, 0.0, self::CAP_RATE);
        $this->assertEqualsWithDelta(100.0 * 0.75 / 0.79, $taxed, 1e-12, 'After-tax earnings scale from the rate carried to the one expected.');
    }

    /** The forecast as it stood the tick before is read back, so a revision can be told from the price's drift. */
    public function testTheForecastBeforeARevisionIsKept(): void
    {
        $inForce = Policy::LONG_RUN_CORPORATE_TAX_SHIFT;
        $revised = new MacroStateDTO(
            totalTime: 10.0,
            corporateTaxPolicyShift: $inForce,
            corporateTaxShiftRealized: $inForce,
            corporateTaxShiftEmbodied: $inForce,
            expectedCorporateTaxPolicyShift: $inForce + 0.04,
            expectedPolicyFrom: 12.5,
            previousExpectedCorporateTaxPolicyShift: $inForce,
            previousExpectedPolicyFrom: 12.5,
        );

        $this->assertGreaterThan(0.0, Policy::corporateTaxShiftGap($revised, self::CAP_RATE));
        $this->assertEqualsWithDelta(
            Policy::corporateTaxShiftGap($this->macro(inForce: $inForce, expected: $inForce, from: 12.5, time: 10.0), self::CAP_RATE),
            Policy::corporateTaxShiftGap($revised, self::CAP_RATE, previous: true),
            1e-15
        );
    }

    /**
     * The tax path's gap by brute force: a law changed by $change from $untilNext, $departure from the long-run law from
     * then on, each later term keeping the measured persistence of it, as a sum over the terms (the class's closed form).
     */
    private function taxPath(float $change, float $alreadyAway, float $untilNext): float
    {
        $speed = MacroEngine::FISCAL_ADJUSTMENT_SPEED;
        $term = PoliticsEngine::ELECTION_TERM_YEARS;
        $rho = Policy::CORPORATE_TAX_TERM_PERSISTENCE;
        $departure = $change + $alreadyAway;
        $gap = $change * MathUtility::perpetuityShareAfter(self::CAP_RATE, $untilNext, $speed);
        for ($k = 1; $k < 400; ++$k) {
            $gap += $departure * (($rho ** $k) - ($rho ** ($k - 1))) * MathUtility::perpetuityShareAfter(self::CAP_RATE, $untilNext + ($k * $term), $speed);
        }

        return $gap;
    }

    private function macro(
        float $inForce = 0.0,
        ?float $expected = null,
        float $from = -1.0,
        float $time = 0.0,
        ?float $realized = null,
        ?float $embodied = null,
        float $levy = 0.0,
        ?float $expectedLevy = null,
        ?float $levyEmbodied = null,
    ): MacroStateDTO {
        return new MacroStateDTO(
            totalTime: $time,
            corporateTaxPolicyShift: $inForce,
            bankLevyRate: $levy,
            corporateTaxShiftRealized: $realized ?? $inForce,
            corporateTaxShiftEmbodied: $embodied ?? $inForce,
            bankLevyEmbodied: $levyEmbodied ?? $levy,
            expectedCorporateTaxPolicyShift: $expected ?? $inForce,
            expectedBankLevyRate: $expectedLevy ?? $levy,
            expectedPolicyFrom: $from,
            previousExpectedCorporateTaxPolicyShift: $expected ?? $inForce,
            previousExpectedBankLevyRate: $expectedLevy ?? $levy,
            previousExpectedPolicyFrom: $from,
        );
    }
}
