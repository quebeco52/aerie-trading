<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Model\Sector\ReitBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The lease ladder: a landlord cannot reprice its book.
 *
 * Only the leases expiring in a given quarter reset to market; the rest are contractually fixed until
 * their own expiry. That is why a REIT lags the property cycle in BOTH directions — after a boom rents
 * keep catching up for years, and after a bust in-place rents sit above market and grind down as space
 * rolls, which is the negative re-leasing spread that does the real damage to a landlord.
 */
#[Group('unit')]
final class ReitLeaseLadderTest extends TestCase
{
    /**
     * @return array{0: array<string, float>, 1: array<string, float>, 2: ?\App\DTO\ActualFinancialsDTO} final stream state, final KPIs and the last quarter
     */
    private function runQuarters(int $quarters, float $marketRentIndex, array $openingState = [], float $propertyIndex = 100.0): array
    {
        $model = new ReitBusinessModel();
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setBeta('0.8');

        $math = new ScriptedDraws();

        $macro = new MacroStateDTO(
            commercialPropertyIndexEma: $propertyIndex,
            residentialPropertyIndexEma: $propertyIndex,
            commercialMarketRent: $marketRentIndex / 100.0,
            inflationEma: 0.02,
        );

        $state = $openingState;
        $kpis = [];
        $result = null;
        for ($q = 0; $q < $quarters; $q++) {
            $stock->setEarningsMomentumZ($state);
            $result = $model->computeActualFinancials($stock, 1_000_000_000.0, 0.40, 200_000_000.0, 0.10, $macro, $math);
            $state = $result->streamZ;
            $kpis = $result->kpis;
        }

        return [$state, $kpis, $result];
    }

    /** In a flat market, in-place rents sit at market and the spread is nil. */
    public function testFlatMarketLeavesNoReleasingSpread(): void
    {
        [, $kpis] = $this->runQuarters(4, 100.0);

        $this->assertEqualsWithDelta(0.0, $kpis['releasing_spread'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $kpis['in_place_rent_index'], 1e-9);
    }

    /** After a rent boom, in-place rents chase market slowly and stay below it for years. */
    public function testInPlaceRentsChaseARisingMarketSlowly(): void
    {
        // Market rents 30% above baseline, with the roll opening at the old level.
        [, $afterOneYear] = $this->runQuarters(4, 130.0, [ReitBusinessModel::STATE_IN_PLACE_RENT => 0.0]);
        [, $afterFiveYears] = $this->runQuarters(20, 130.0, [ReitBusinessModel::STATE_IN_PLACE_RENT => 0.0]);

        $this->assertGreaterThan(0.0, $afterOneYear['in_place_rent_index'], 'Expiring space must reprice upward.');
        $this->assertLessThan(0.30, $afterOneYear['in_place_rent_index'], 'But the book cannot reprice in a year.');
        $this->assertGreaterThan(
            $afterOneYear['in_place_rent_index'],
            $afterFiveYears['in_place_rent_index'],
            'Each year of rollover carries in-place rents further toward market.',
        );
        $this->assertGreaterThan(0.0, $afterFiveYears['releasing_spread'], 'Space still rolls up while the gap remains.');
    }

    /** The damaging case: a bust leaves in-place rents ABOVE market, rolling down as leases expire. */
    public function testInPlaceRentsGrindDownThroughANegativeReleasingSpread(): void
    {
        // The roll was signed at a 25% premium; the market has since fallen back to baseline.
        [, $kpis] = $this->runQuarters(4, 100.0, [ReitBusinessModel::STATE_IN_PLACE_RENT => 0.25]);

        $this->assertLessThan(0.0, $kpis['releasing_spread'], 'Space coming up is worth less than it currently earns.');
        $this->assertLessThan(0.25, $kpis['in_place_rent_index'], 'Every expiry drags the roll down toward market.');
        $this->assertGreaterThan(0.0, $kpis['in_place_rent_index'], 'And the unexpired leases still hold it above market.');
    }

    /** Convergence, eventually: a permanent shift does fully arrive once the whole roll has turned. */
    public function testRollEventuallyReachesMarket(): void
    {
        [, $kpis] = $this->runQuarters(160, 130.0, [ReitBusinessModel::STATE_IN_PLACE_RENT => 0.0]);

        $this->assertEqualsWithDelta(0.30, $kpis['in_place_rent_index'], 0.01, 'After many lease terms the book is marked to market.');
        $this->assertEqualsWithDelta(0.0, $kpis['releasing_spread'], 0.01);
    }

    /**
     * A lower cap rate lifts what a building is worth, not what a tenant pays: property values 30% up on a rate cut,
     * with market rents unchanged, must leave the rent roll where it is. The ladder used to chase the price index.
     */
    public function testPropertyValuesAloneDoNotRepriceTheRentRoll(): void
    {
        [, $kpis] = $this->runQuarters(8, 100.0, [ReitBusinessModel::STATE_IN_PLACE_RENT => 0.0], propertyIndex: 130.0);

        $this->assertEqualsWithDelta(0.0, $kpis['releasing_spread'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $kpis['in_place_rent_index'], 1e-9);
    }

    /** The lease term is what sets the speed, and it is disclosed. */
    public function testWaltIsReportedAndSetsTheRolloverSpeed(): void
    {
        [, $kpis] = $this->runQuarters(1, 130.0, [ReitBusinessModel::STATE_IN_PLACE_RENT => 0.0]);

        $this->assertSame(ReitBusinessModel::LEASE_WALT_YEARS, $kpis['walt_years']);

        // One quarter moves the roll by one quarter's worth of the ladder: spread / (WALT x 4).
        $expected = 0.30 / (ReitBusinessModel::LEASE_WALT_YEARS * 4.0);
        $this->assertEqualsWithDelta($expected, $kpis['in_place_rent_index'], 1e-9);
    }

    /** The mark-to-market gap is bounded: tenants renegotiate or hand back space long before it runs away. */
    public function testReleasingSpreadIsBounded(): void
    {
        [, $kpis] = $this->runQuarters(1, 500.0, [ReitBusinessModel::STATE_IN_PLACE_RENT => 0.0]);

        $this->assertLessThanOrEqual(ReitBusinessModel::MAX_RELEASING_SPREAD, $kpis['releasing_spread']);
    }

    /**
     * The book bills its level: a roll that has climbed 10% over the old market keeps billing 10% more every quarter
     * until it rolls down again, and the disclosed roll is read in full. Revenue used to add only the quarter's change
     * in the roll, so a lease signed above market lifted one quarter's revenue and then nothing.
     */
    public function testTheRollBillsItsLevelNotItsQuarterlyChange(): void
    {
        // One quarter, so the stream mix still sits at its target weights.
        [, $kpis, $result] = $this->runQuarters(1, 110.0, [ReitBusinessModel::STATE_IN_PLACE_RENT => 0.10]);
        $this->assertNotNull($result);

        $this->assertEqualsWithDelta(0.10, $kpis['in_place_rent_index'], 1e-9);
        $lease = 1_000_000_000.0 * 0.85;
        $this->assertEqualsWithDelta($lease * (1.0 + 0.10 + ReitBusinessModel::expectedTenantVacancy()), $result->streamRevenue['lease'], 1.0);
        // Same space, dearer: the extra rent carries no extra operating cost.
        $this->assertEqualsWithDelta($lease * (0.10 + ReitBusinessModel::expectedTenantVacancy()), $result->priceRevenue, 1.0);
    }
}
