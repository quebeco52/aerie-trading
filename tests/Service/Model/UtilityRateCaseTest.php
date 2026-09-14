<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\UtilityBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The regulated rate case: attrition, filing, regulatory lag, partial award.
 *
 * A regulated utility does not recover general cost inflation smoothly. Its authorized tariff is frozen
 * between orders while wages, O&M and rate base keep inflating, so its earned return drifts below the
 * allowed one. Management files, waits out the 12 to 18 month lag, and receives an order granting part
 * of the request. Fuel is different — adjustment clauses recover it automatically — and that channel
 * stays in the input-cost basket, untouched by this cycle.
 *
 * The persisted state is the GAP between the authorized tariff and the cost base it is chasing, never the
 * tariff level on its own: the engine deflates the structural cost base by the same pricing multiplier it
 * inflates revenue with, so a multiplier carrying a cumulative level rather than the lag hands the utility
 * free margin on every order instead of squeezing it between them.
 */
#[AllowMockObjectsWithoutExpectations]
final class UtilityRateCaseTest extends TestCase
{
    private const PENDING_KEY = StreamContext::REGIME_STATE_PREFIX . UtilityBusinessModel::REGIME_RATE_CASE;
    private const LAG_KEY = UtilityBusinessModel::STATE_TARIFF_SHORTFALL;

    /**
     * Runs quarters through the model, feeding each quarter's stream state back in as the next one's
     * history. $decideOn lists the 1-based quarters on which a pending case is ruled upon.
     *
     * @param list<int> $decideOn
     * @return array<string, float> the final persisted stream state
     */
    private function runQuarters(int $quarters, array $decideOn = [], float $inflation = 0.03): array
    {
        $model = new UtilityBusinessModel();
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setBeta('0.5');

        $quarter = 0;
        $math = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal', 'checkProbability'])
            ->getMock();
        $math->method('generateStandardNormal')->willReturn(0.0);
        // By reference: an arrow function would capture the counter by value and freeze it at zero.
        $math->method('checkProbability')->willReturnCallback(
            static function () use (&$quarter, $decideOn): bool {
                return in_array($quarter, $decideOn, true);
            }
        );

        $macro = new MacroStateDTO(inflationEma: $inflation);
        $state = [];

        for ($q = 1; $q <= $quarters; $q++) {
            $quarter = $q;
            $stock->setEarningsMomentumZ($state);
            $result = $model->computeActualFinancials($stock, 10_000_000_000.0, 0.60, 2_000_000_000.0, 0.10, $macro, $math);
            $state = $result->streamZ;
        }

        return $state;
    }

    /** A stock carrying a given tariff lag, ready to be priced. */
    private function stockWithLag(?float $lag): Stock
    {
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setBeta('0.5');

        if ($lag !== null) {
            $stock->setEarningsMomentumZ([self::LAG_KEY => $lag]);
        }

        return $stock;
    }

    /** Between orders the tariff does not move, so the gap to the cost base widens for as long as the squeeze runs. */
    public function testTariffLagWidensWhileFrozen(): void
    {
        // Never decide the case, so no order can land.
        $four = $this->runQuarters(4);
        $eight = $this->runQuarters(8);

        $this->assertGreaterThan(0.0, $four[self::LAG_KEY] ?? 0.0, 'Unrecovered cost has to be accumulating.');
        $this->assertGreaterThan($four[self::LAG_KEY], $eight[self::LAG_KEY], 'A frozen tariff falls further behind every quarter.');
    }

    /** Once the gap is material, a case is filed and sits pending. */
    public function testCaseIsFiledOnceAttritionIsMaterial(): void
    {
        $state = $this->runQuarters(6);

        $this->assertGreaterThan(0.0, $state[self::PENDING_KEY] ?? 0.0, 'Sustained attrition must produce a filing.');
    }

    /** No filing while the gap is still trivial. */
    public function testNoFilingWhileAttritionIsTrivial(): void
    {
        // One quarter of very low inflation stays well inside the filing threshold.
        $state = $this->runQuarters(1, [], 0.001);

        $this->assertEqualsWithDelta(0.0, $state[self::PENDING_KEY] ?? 0.0, 1e-9, 'A utility does not file a rate case over a rounding error.');
    }

    /**
     * The order steps the tariff toward its cost base and closes the case, but only part of the way: the
     * disallowed remainder is a cost the utility is still incurring, so it stays outstanding for the next
     * filing. A utility that came out of an order fully caught up would never be squeezed again.
     */
    public function testOrderClosesPartOfTheGapAndCarriesTheRest(): void
    {
        $pending = $this->runQuarters(8);
        $decided = $this->runQuarters(8, [8]);

        $this->assertLessThan($pending[self::LAG_KEY], $decided[self::LAG_KEY], 'An order must actually narrow the gap.');
        $this->assertGreaterThan(0.0, $decided[self::LAG_KEY], 'The disallowed remainder stays outstanding.');
        $this->assertEqualsWithDelta(0.0, $decided[self::PENDING_KEY] ?? 0.0, 1e-9, 'The case is closed once decided.');
    }

    /** Commissions disallow part of every request, which is why utilities never fully catch up. */
    public function testCommissionGrantsLessThanRequested(): void
    {
        $requested = $this->runQuarters(7)[self::LAG_KEY];
        $granted = $requested - $this->runQuarters(8, [8])[self::LAG_KEY];

        $this->assertLessThan($requested, $granted, 'A granted increase must be smaller than the amount asked for.');
        $this->assertGreaterThan(0.0, $granted);
    }

    /** The tariff the market prices is the one the orders actually granted: a wider lag is a lower price. */
    public function testPricingReadsTheTariffLag(): void
    {
        $model = new UtilityBusinessModel();
        $macro = new MacroStateDTO(inflationEma: 0.03);

        $this->assertGreaterThan(
            $model->getMacroPhysics($this->stockWithLag(0.08), $macro)['pricing_power_multiplier'],
            $model->getMacroPhysics($this->stockWithLag(0.01), $macro)['pricing_power_multiplier'],
            'A utility that has won a rate order must charge more than one still waiting on its case.'
        );
    }

    /**
     * The regression guard on the whole cycle. The engine builds the structural cost base by deflating
     * revenue with the pricing multiplier, so the selling-price multiplier running above the input-cost
     * one is not a price increase — it is margin created out of nothing, compounding with every order.
     * Cost-of-service regulation recovers cost; it never beats it.
     */
    public function testTariffNeverOutrunsItsCostBase(): void
    {
        $model = new UtilityBusinessModel();
        $macro = new MacroStateDTO(inflationEma: 0.03);

        foreach ([null, 0.0, 0.005, 0.02, 0.05, UtilityBusinessModel::MAX_TARIFF_SHORTFALL, 99.0] as $lag) {
            $physics = $model->getMacroPhysics($this->stockWithLag($lag), $macro);

            $this->assertLessThanOrEqual(
                $physics['input_cost_multiplier'],
                $physics['pricing_power_multiplier'],
                sprintf('A tariff lag of %s produced a selling price above the cost base it recovers.', var_export($lag, true))
            );
            $this->assertGreaterThan(0.0, $physics['pricing_power_multiplier'], 'The authorized tariff can lag, but it cannot go negative.');
        }
    }

    /** With no outstanding case the utility recovers its costs exactly: unit pass-through, no lag, no gift. */
    public function testAFullyRecoveredTariffIsExactlyUnitPassThrough(): void
    {
        $model = new UtilityBusinessModel();
        $physics = $model->getMacroPhysics($this->stockWithLag(0.0), new MacroStateDTO(inflationEma: 0.03));

        $this->assertEqualsWithDelta(
            $physics['input_cost_multiplier'],
            $physics['pricing_power_multiplier'],
            1e-9,
            'PRICING_ELASTICITY of 1.0 means a caught-up tariff recovers the cost base in full and no more.'
        );
    }

    /** Interim relief binds: a commission does not leave a monopoly with a service obligation earning below cost. */
    public function testTariffLagIsBoundedByInterimRelief(): void
    {
        $model = new UtilityBusinessModel();
        $physics = $model->getMacroPhysics($this->stockWithLag(99.0), new MacroStateDTO(inflationEma: 0.03));

        $this->assertEqualsWithDelta(
            $physics['input_cost_multiplier'] * (1.0 - UtilityBusinessModel::MAX_TARIFF_SHORTFALL),
            $physics['pricing_power_multiplier'],
            1e-9,
            'Attrition is capped at the point interim rates are granted.'
        );
    }

    /**
     * Over a long run of filings and orders the gap oscillates around a steady attrition drag rather than
     * ratcheting in either direction: it neither closes to zero (the utility would stop filing) nor walks
     * out to the interim-relief cap under ordinary inflation.
     */
    public function testTheCycleReachesASteadyAttritionDragRatherThanRatcheting(): void
    {
        // An order every fifth quarter, the 12 to 18 month regulatory lag, across fifteen years.
        $decideOn = range(5, 60, 5);
        $state = $this->runQuarters(60, $decideOn, 0.02);
        $lag = $state[self::LAG_KEY];

        $this->assertGreaterThan(0.0, $lag, 'A lagging tariff never fully catches its cost base.');
        $this->assertLessThan(
            UtilityBusinessModel::MAX_TARIFF_SHORTFALL,
            $lag,
            'Ordinary inflation must not drive the utility onto the interim-relief floor.'
        );
    }
}
