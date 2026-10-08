<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Pricing;

use App\DTO\MacroStateDTO;
use App\Data\Sectors;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Market\Pricing\PolicyCapitalization as Policy;
use App\Service\Math\FinancialConstants;
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
        $macro = $this->macro(
            ['corporateTax' => Policy::LONG_RUN_CORPORATE_TAX_SHIFT, 'bankLevyRate' => Policy::LONG_RUN_BANK_LEVY_RATE, 'extractionStringency' => $this->stringencyAt(Policy::LONG_RUN_EXTRACTION_COST_FACTOR), 'stampDutyRate' => $this->dutyAt(Policy::LONG_RUN_STAMP_DUTY_VOLUME_FACTOR), 'carbonPrice' => $this->carbonAt(Policy::LONG_RUN_CARBON_POWER_UPLIFT)],
        );

        $this->assertEqualsWithDelta(0.0, Policy::corporateTaxShiftGap($macro, self::CAP_RATE), 1e-15);
        $this->assertEqualsWithDelta(0.0, Policy::bankLevyGap($macro, self::CAP_RATE), 1e-15);
        $this->assertEqualsWithDelta(0.0, Policy::extractionCostGap($macro, self::CAP_RATE), 1e-12);
        $this->assertEqualsWithDelta(0.0, Policy::stampDutyVolumeGap($macro, self::CAP_RATE), 1e-12);
        $this->assertEqualsWithDelta(0.0, Policy::carbonPowerUpliftGap($macro, self::CAP_RATE), 1e-12);
        $this->assertSame(100.0, Policy::reprice(100.0, 0.21, 0.21, 0.0, self::CAP_RATE));
    }

    /** A tax rise the market expects counts for the share of the firm's value after the next government's first budget, until its term is over. */
    public function testAnExpectedRiseCountsForTheValueAfterItTakesEffect(): void
    {
        $inForce = Policy::LONG_RUN_CORPORATE_TAX_SHIFT;
        $macro = $this->macro(['corporateTax' => $inForce], expected: ['corporateTax' => $inForce + 0.04], from: 12.5, time: 10.0);
        $this->assertEqualsWithDelta($this->path(0.04, 0.0, 2.5, Policy::CORPORATE_TAX_TERM_PERSISTENCE, MacroEngine::FISCAL_ADJUSTMENT_SPEED), Policy::corporateTaxShiftGap($macro, self::CAP_RATE), 1e-15);
        $nearer = $this->macro(['corporateTax' => $inForce], expected: ['corporateTax' => $inForce + 0.04], from: 10.5, time: 10.0);
        $this->assertLessThan(Policy::corporateTaxShiftGap($nearer, self::CAP_RATE), Policy::corporateTaxShiftGap($macro, self::CAP_RATE), 'Nearer, it counts for more.');
    }

    /**
     * The rules on extraction are priced the same way, measured as the unit costs feel them: the strictest rules the next
     * government is expected to pass count by their cost factor's rise from its first budget, charged in full at once,
     * drifting back toward the long-run factor after its term.
     */
    public function testExpectedExtractionRulesCountByTheirCostFactor(): void
    {
        $longRun = $this->stringencyAt(Policy::LONG_RUN_EXTRACTION_COST_FACTOR);
        $macro = $this->macro(['extractionStringency' => $longRun], expected: ['extractionStringency' => 1.0], from: 12.5, time: 10.0);
        $rise = MathUtility::calculateExtractionCostFactor(1.0) - Policy::LONG_RUN_EXTRACTION_COST_FACTOR;

        $this->assertEqualsWithDelta($this->path($rise, 0.0, 2.5, Policy::EXTRACTION_COST_TERM_PERSISTENCE), Policy::extractionCostGap($macro, self::CAP_RATE), 1e-12);
        $this->assertGreaterThan(0.0, Policy::extractionCostGap($macro, self::CAP_RATE));
    }

    /**
     * The carbon price is priced the same way, measured by what it adds to the power price: a carbon price the next
     * government is expected to pass counts by its uplift from its first budget, in full at once, drifting back toward
     * the long-run uplift after its term.
     */
    public function testAnExpectedCarbonPriceCountsByItsPowerPriceUplift(): void
    {
        $longRun = $this->carbonAt(Policy::LONG_RUN_CARBON_POWER_UPLIFT);
        $macro = $this->macro(['carbonPrice' => $longRun], expected: ['carbonPrice' => 50.0], from: 12.5, time: 10.0);
        $rise = CommodityLogisticsSubsystem::carbonPowerPriceUplift(50.0) - Policy::LONG_RUN_CARBON_POWER_UPLIFT;

        $this->assertEqualsWithDelta($this->path($rise, 0.0, 2.5, Policy::CARBON_POWER_TERM_PERSISTENCE), Policy::carbonPowerUpliftGap($macro, self::CAP_RATE), 1e-12);
        $this->assertGreaterThan(0.0, Policy::carbonPowerUpliftGap($macro, self::CAP_RATE));
    }

    /** A law the market foresaw does not move the price when it is passed: the gap is the same either side of the budget that passes it, and fades only as the earnings take it in. */
    public function testAForeseenLawDoesNotMoveThePriceWhenPassed(): void
    {
        $old = Policy::LONG_RUN_CORPORATE_TAX_SHIFT;
        $new = $old + 0.04;
        $before = $this->macro(['corporateTax' => $old], expected: ['corporateTax' => $new], from: 10.0, time: 10.0);
        $after = $this->macro(['corporateTax' => $new], expected: ['corporateTax' => $new], from: 10.0, time: 10.0, realized: $old, embodied: ['corporateTax' => $old]);

        $this->assertEqualsWithDelta(Policy::corporateTaxShiftGap($before, self::CAP_RATE), Policy::corporateTaxShiftGap($after, self::CAP_RATE), 1e-15);

        // Once the earnings carry it, only the drift back to the long-run law after the term is left to price.
        $takenIn = $this->macro(['corporateTax' => $new], expected: ['corporateTax' => $new], from: 10.0, time: 10.0, realized: $new, embodied: ['corporateTax' => $new]);
        $this->assertEqualsWithDelta($this->path(0.0, 0.04, 0.0, Policy::CORPORATE_TAX_TERM_PERSISTENCE, MacroEngine::FISCAL_ADJUSTMENT_SPEED), Policy::corporateTaxShiftGap($takenIn, self::CAP_RATE), 1e-15);
        $this->assertLessThan(0.0, Policy::corporateTaxShiftGap($takenIn, self::CAP_RATE));

        // The levy, the rules on extraction and the duty likewise, each reaching the accounts in full from the day it passes.
        foreach (['bankLevyRate' => [0.0, 0.002], 'extractionStringency' => [0.0, 1.0], 'stampDutyRate' => [FinancialConstants::STAMP_DUTY_RATE, 0.002], 'carbonPrice' => [0.0, 40.0]] as $lever => [$was, $is]) {
            $foreseen = $this->macro([$lever => $was], expected: [$lever => $is], from: 10.0, time: 10.0);
            $passed = $this->macro([$lever => $is], expected: [$lever => $is], from: 10.0, time: 10.0, embodied: [$lever => $was]);
            $gap = match ($lever) {
                'bankLevyRate' => Policy::bankLevyGap(...),
                'extractionStringency' => Policy::extractionCostGap(...),
                'stampDutyRate' => Policy::stampDutyVolumeGap(...),
                'carbonPrice' => Policy::carbonPowerUpliftGap(...),
            };
            $this->assertNotEqualsWithDelta(0.0, $gap($foreseen, self::CAP_RATE), 1e-6, $lever);
            $this->assertEqualsWithDelta($gap($foreseen, self::CAP_RATE), $gap($passed, self::CAP_RATE), 1e-15, $lever);
        }
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
            sittingLevers: ['corporateTax' => $new],
            sittingPolicyFrom: $from,
            previousSittingLevers: ['corporateTax' => $new],
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

    /**
     * What the other laws expected do to a year's earnings: a bank pays the levy expected beyond the one it pays, not
     * deductible; a miner the extra cost the rules expected put on its units, and a broker gains the turnover a duty
     * expected to fall gives back, both after tax. A firm the laws do not reach is not repriced.
     */
    public function testTheOtherLawsMoveEarningsByWhatTheyAreChargedOn(): void
    {
        $levied = $this->macro(['bankLevyRate' => Policy::LONG_RUN_BANK_LEVY_RATE], expected: ['bankLevyRate' => Policy::LONG_RUN_BANK_LEVY_RATE + 0.001], from: 11.0, time: 10.0);
        $this->assertEqualsWithDelta(-Policy::bankLevyGap($levied, self::CAP_RATE) * 2000.0, Policy::earningsGap($levied, ['bankLevyRate' => 2000.0], 0.21, self::CAP_RATE), 1e-12);
        $this->assertLessThan(0.0, Policy::earningsGap($levied, ['bankLevyRate' => 2000.0], 0.21, self::CAP_RATE));
        $this->assertSame(0.0, Policy::earningsGap($levied, [], 0.21, self::CAP_RATE));

        $strict = $this->macro(['extractionStringency' => 0.0], expected: ['extractionStringency' => 1.0], from: 11.0, time: 10.0);
        $this->assertEqualsWithDelta(-0.79 * Policy::extractionCostGap($strict, self::CAP_RATE) * 50.0, Policy::earningsGap($strict, ['extractionStringency' => 50.0], 0.21, self::CAP_RATE), 1e-12);
        $this->assertLessThan(0.0, Policy::earningsGap($strict, ['extractionStringency' => 50.0], 0.21, self::CAP_RATE));

        $cut = $this->macro(['stampDutyRate' => 0.002], expected: ['stampDutyRate' => FinancialConstants::STAMP_DUTY_RATE], from: 11.0, time: 10.0);
        $this->assertEqualsWithDelta(0.79 * Policy::stampDutyVolumeGap($cut, self::CAP_RATE) * 30.0, Policy::earningsGap($cut, ['stampDutyRate' => 30.0], 0.21, self::CAP_RATE), 1e-12);
        $this->assertGreaterThan(0.0, Policy::earningsGap($cut, ['stampDutyRate' => 30.0], 0.21, self::CAP_RATE), 'A cut expected gives the broker back turnover.');

        // A carbon price expected gains a generator that keeps the power price and pays no carbon, and costs one that pays
        // more carbon than the price passes through.
        $carbon = $this->macro(['carbonPrice' => 0.0], expected: ['carbonPrice' => 40.0], from: 11.0, time: 10.0);
        $this->assertEqualsWithDelta(0.79 * Policy::carbonPowerUpliftGap($carbon, self::CAP_RATE) * 20.0, Policy::earningsGap($carbon, ['carbonPrice' => 20.0], 0.21, self::CAP_RATE), 1e-12);
        $this->assertGreaterThan(0.0, Policy::earningsGap($carbon, ['carbonPrice' => 20.0], 0.21, self::CAP_RATE));
        $this->assertLessThan(0.0, Policy::earningsGap($carbon, ['carbonPrice' => -5.0], 0.21, self::CAP_RATE));

        $this->assertEqualsWithDelta(100.0 - (2.0 / self::CAP_RATE), Policy::reprice(100.0, 0.21, 0.21, -2.0, self::CAP_RATE), 1e-12, 'An earnings gap is worth its perpetuity.');
        $this->assertEqualsWithDelta(100.0 * 0.75 / 0.79, Policy::reprice(100.0, 0.21, 0.25, 0.0, self::CAP_RATE), 1e-12, 'After-tax earnings scale from the rate carried to the one expected.');
    }

    /**
     * The carbon price reaches the accounts only through what a generator sells its power at: no model's input basket
     * buys electricity, the BEA weights all falling under the materiality floor at industrial retail pass-through. A
     * model that bought it would pay a carbon price the market does not price (annualCarbonPowerEarningsBase()).
     */
    public function testNoInputBasketBuysTheCarbonPricedPower(): void
    {
        foreach (array_keys(Sectors::BUSINESS_MODELS) as $id) {
            $model = Sectors::getBusinessModelStrategy($id);
            if (method_exists($model, 'getInputCostExposures')) {
                $this->assertSame(0.0, (float) ($model->getInputCostExposures()['electricity'] ?? 0.0), $model::class);
            }
        }
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
            expectedLevers: ['corporateTax' => $inForce + 0.04],
            expectedPolicyFrom: 12.5,
            previousExpectedLevers: ['corporateTax' => $inForce],
            previousExpectedPolicyFrom: 12.5,
        );

        $this->assertGreaterThan(0.0, Policy::corporateTaxShiftGap($revised, self::CAP_RATE));
        $this->assertEqualsWithDelta(
            Policy::corporateTaxShiftGap($this->macro(['corporateTax' => $inForce], expected: ['corporateTax' => $inForce], from: 12.5, time: 10.0), self::CAP_RATE),
            Policy::corporateTaxShiftGap($revised, self::CAP_RATE, previous: true),
            1e-15
        );
    }

    /**
     * A law's gap by brute force: changed by $change from $untilNext, $departure from the long-run law from then on, each
     * later term keeping the measured persistence of it, as a sum over the terms (the class's closed form).
     */
    private function path(float $change, float $alreadyAway, float $untilNext, float $persistence, float $speed = INF): float
    {
        $term = PoliticsEngine::ELECTION_TERM_YEARS;
        $departure = $change + $alreadyAway;
        $gap = $change * MathUtility::perpetuityShareAfter(self::CAP_RATE, $untilNext, $speed);
        for ($k = 1; $k < 400; ++$k) {
            $gap += $departure * (($persistence ** $k) - ($persistence ** ($k - 1))) * MathUtility::perpetuityShareAfter(self::CAP_RATE, $untilNext + ($k * $term), $speed);
        }

        return $gap;
    }

    /** The stringency whose cost factor is the given one. */
    private function stringencyAt(float $factor): float
    {
        return (1.0 - (1.0 / $factor)) / FinancialConstants::ENVIRONMENTAL_REGULATION_TFP_LOSS;
    }

    /** The carbon price whose power price uplift is the given one. */
    private function carbonAt(float $uplift): float
    {
        return $uplift / CommodityLogisticsSubsystem::carbonPowerPriceUplift(1.0);
    }

    /** The duty whose turnover factor is the given one. */
    private function dutyAt(float $factor): float
    {
        return FinancialConstants::STAMP_DUTY_RATE - (log($factor) / (2.0 * FinancialConstants::STAMP_DUTY_VOLUME_SEMI_ELASTICITY));
    }

    /**
     * The laws in force, expected and carried, by lever: a law expected or carried that is not given is the one in force,
     * a law in force not given the founding one.
     *
     * @param array<string, float> $inForce
     * @param array<string, float> $expected
     * @param array<string, float> $embodied
     */
    private function macro(array $inForce, array $expected = [], float $from = -1.0, float $time = 0.0, ?float $realized = null, array $embodied = []): MacroStateDTO
    {
        $law = $inForce + ['corporateTax' => 0.0, 'bankLevyRate' => 0.0, 'extractionStringency' => 0.0, 'stampDutyRate' => FinancialConstants::STAMP_DUTY_RATE, 'carbonPrice' => 0.0];
        $expected += $law;
        $embodied += $law;

        return new MacroStateDTO(
            totalTime: $time,
            corporateTaxPolicyShift: $law['corporateTax'],
            bankLevyRate: $law['bankLevyRate'],
            extractionStringency: $law['extractionStringency'],
            stampDutyRate: $law['stampDutyRate'],
            carbonPrice: $law['carbonPrice'],
            corporateTaxShiftRealized: $realized ?? $law['corporateTax'],
            corporateTaxShiftEmbodied: $embodied['corporateTax'],
            bankLevyEmbodied: $embodied['bankLevyRate'],
            extractionCostFactorEmbodied: MathUtility::calculateExtractionCostFactor($embodied['extractionStringency']),
            stampDutyVolumeFactorEmbodied: MathUtility::calculateStampDutyVolumeFactor($embodied['stampDutyRate']),
            carbonPowerUpliftEmbodied: CommodityLogisticsSubsystem::carbonPowerPriceUplift($embodied['carbonPrice']),
            expectedLevers: $expected,
            expectedPolicyFrom: $from,
            previousExpectedLevers: $expected,
            previousExpectedPolicyFrom: $from,
        );
    }
}
