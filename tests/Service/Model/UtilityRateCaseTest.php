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
 */
#[AllowMockObjectsWithoutExpectations]
final class UtilityRateCaseTest extends TestCase
{
    private const PENDING_KEY = StreamContext::REGIME_STATE_PREFIX . UtilityBusinessModel::REGIME_RATE_CASE;

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

    /** Between orders the tariff does not move, however long the squeeze runs. */
    public function testAuthorizedTariffIsFrozenBetweenOrders(): void
    {
        // Never decide the case, so no order can land.
        $state = $this->runQuarters(8);

        $this->assertEqualsWithDelta(0.0, $state[UtilityBusinessModel::STATE_AUTHORIZED_TARIFF] ?? 0.0, 1e-9, 'A frozen tariff must not drift upward on its own.');
        $this->assertGreaterThan(0.0, $state[UtilityBusinessModel::STATE_TARIFF_SHORTFALL] ?? 0.0, 'Unrecovered cost has to be accumulating.');
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

    /** The order steps the tariff up and clears the request. */
    public function testOrderStepsTheTariffAndClearsTheRequest(): void
    {
        // File over the first quarters, then rule in quarter 8.
        $state = $this->runQuarters(8, [8]);

        $this->assertGreaterThan(0.0, $state[UtilityBusinessModel::STATE_AUTHORIZED_TARIFF] ?? 0.0, 'An order must actually raise the authorized tariff.');
        $this->assertEqualsWithDelta(0.0, $state[UtilityBusinessModel::STATE_TARIFF_SHORTFALL] ?? 0.0, 1e-9, 'The granted request is no longer outstanding.');
        $this->assertEqualsWithDelta(0.0, $state[self::PENDING_KEY] ?? 0.0, 1e-9, 'The case is closed once decided.');
    }

    /** Commissions disallow part of every request, which is why utilities never fully catch up. */
    public function testCommissionGrantsLessThanRequested(): void
    {
        $pending = $this->runQuarters(7);
        $requested = $pending[UtilityBusinessModel::STATE_TARIFF_SHORTFALL];

        $decided = $this->runQuarters(8, [8]);
        $granted = $decided[UtilityBusinessModel::STATE_AUTHORIZED_TARIFF];

        $this->assertLessThan($requested, $granted, 'A granted increase must be smaller than the amount asked for.');
        $this->assertGreaterThan(0.0, $granted);
    }

    /** The tariff the market prices is the one the orders actually granted. */
    public function testPricingReadsTheAuthorizedTariff(): void
    {
        $model = new UtilityBusinessModel();
        $macro = new MacroStateDTO(inflationEma: 0.03);

        $fresh = new Stock();
        $fresh->setTicker('ZZZZ');
        $fresh->setBeta('0.5');

        $awarded = new Stock();
        $awarded->setTicker('ZZZZ');
        $awarded->setBeta('0.5');
        $awarded->setEarningsMomentumZ([UtilityBusinessModel::STATE_AUTHORIZED_TARIFF => 0.08]);

        $this->assertGreaterThan(
            $model->getMacroPhysics($fresh, $macro)['pricing_power_multiplier'],
            $model->getMacroPhysics($awarded, $macro)['pricing_power_multiplier'],
            'A utility that has won a rate order must charge more than one that has not.'
        );
    }

    /** Affordability binds: cumulative uplift cannot compound forever. */
    public function testAuthorizedUpliftIsCapped(): void
    {
        $model = new UtilityBusinessModel();
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setBeta('0.5');
        $stock->setEarningsMomentumZ([UtilityBusinessModel::STATE_AUTHORIZED_TARIFF => 99.0]);

        $this->assertEqualsWithDelta(
            1.0 + UtilityBusinessModel::MAX_AUTHORIZED_TARIFF_UPLIFT,
            $model->getMacroPhysics($stock, new MacroStateDTO(inflationEma: 0.03))['pricing_power_multiplier'],
            1e-9,
            'Political and affordability limits cap what a commission will ever authorize.'
        );
    }
}
