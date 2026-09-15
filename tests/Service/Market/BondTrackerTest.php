<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Bond;
use App\Service\Market\BondLedgerService;
use App\Service\Market\BondPricingEngine;
use App\Service\Market\BondTracker;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\MacroStateBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Coupon scheduling and redemption in BondTracker.
 *
 * These are the failures that do not show up on a chart. A missed coupon is a permanent cash shortfall to
 * every holder with nothing later to detect it; a doubled one mints money. Both leave the price series
 * looking perfectly reasonable, so the schedule is asserted directly against the ledger calls rather than
 * inferred from a mark.
 */
class BondTrackerTest extends TestCase
{
    private function tracker(BondLedgerService $ledger): BondTracker
    {
        // The tracker only persists through the entities it mutates and the ledger it delegates to, so the
        // EntityManager is a stub: nothing here has an expectation to place on it.
        return new BondTracker(
            $this->createStub(EntityManagerInterface::class),
            new BondPricingEngine(new MathUtility()),
            $ledger
        );
    }

    private function bond(float $tenor, float $couponRate, float $issuedAt = 0.0): Bond
    {
        return (new Bond())
            ->setTicker('G10-001')
            ->setName('test issue')
            ->setTenorYears((string) $tenor)
            ->setCouponRate((string) $couponRate)
            ->setFaceValue((string) FinancialConstants::BOND_FACE_VALUE)
            ->setIssuedAtTime($issuedAt)
            ->setMaturesAtTime($issuedAt + $tenor)
            ->setLastCouponTime($issuedAt);
    }

    private function macroAt(float $totalTime): \App\DTO\MacroStateDTO
    {
        $state = MacroStateBuilder::create()->build();
        $state->totalTime = $totalTime;

        return \App\DTO\MacroStateDTO::fromMacroState($state);
    }

    public function testNoCouponIsPaidBeforeTheFirstPaymentDate(): void
    {
        $ledger = $this->createMock(BondLedgerService::class);
        $ledger->expects($this->never())->method('processCouponPayment');

        $this->tracker($ledger)->updateBonds([$this->bond(10.0, 0.05)], $this->macroAt(0.4));
    }

    public function testOneCouponIsPaidOnceThePaymentDateHasPassed(): void
    {
        $ledger = $this->createMock(BondLedgerService::class);
        $ledger->expects($this->once())
            ->method('processCouponPayment')
            ->with($this->anything(), 25.0, 0.5, $this->anything());

        $bond = $this->bond(10.0, 0.05);
        $this->tracker($ledger)->updateBonds([$bond], $this->macroAt(0.55));

        $this->assertEqualsWithDelta(0.5, $bond->getLastCouponTime(), 1e-9);
    }

    public function testACouponIsNotPaidASecondTimeOnTheFollowingTick(): void
    {
        $ledger = $this->createMock(BondLedgerService::class);
        $ledger->expects($this->once())->method('processCouponPayment');

        $bond = $this->bond(10.0, 0.05);
        $tracker = $this->tracker($ledger);

        $tracker->updateBonds([$bond], $this->macroAt(0.55));
        $tracker->updateBonds([$bond], $this->macroAt(0.60));
        $tracker->updateBonds([$bond], $this->macroAt(0.75));
    }

    public function testEveryCouponInASpannedIntervalIsPaid(): void
    {
        // A restart, a lag spike or a coarse tick rate can advance simulation time past several payment
        // dates at once. An equality check against "the next coupon date" silently drops the rest.
        $ledger = $this->createMock(BondLedgerService::class);
        $ledger->expects($this->exactly(4))->method('processCouponPayment');

        $bond = $this->bond(10.0, 0.05);
        $this->tracker($ledger)->updateBonds([$bond], $this->macroAt(2.1));

        $this->assertEqualsWithDelta(2.0, $bond->getLastCouponTime(), 1e-9);
    }

    public function testTheFinalCouponIsNotPaidSeparatelyFromTheRedemption(): void
    {
        // The last coupon is part of the redemption cash flow the pricing engine discounts, so paying it
        // here as well would credit it twice.
        $ledger = $this->createMock(BondLedgerService::class);
        $ledger->expects($this->exactly(3))->method('processCouponPayment');
        $ledger->expects($this->once())->method('processRedemption');

        $bond = $this->bond(2.0, 0.05);
        $this->tracker($ledger)->updateBonds([$bond], $this->macroAt(2.0));
    }

    public function testAZeroCouponIssuePaysNoCouponsButStillRedeems(): void
    {
        $ledger = $this->createMock(BondLedgerService::class);
        $ledger->expects($this->never())->method('processCouponPayment');
        $ledger->expects($this->once())->method('processRedemption');

        $this->tracker($ledger)->updateBonds([$this->bond(2.0, 0.0)], $this->macroAt(2.0));
    }

    public function testMaturityMarksTheIssueAndReportsIt(): void
    {
        $ledger = $this->createStub(BondLedgerService::class);

        $bond = $this->bond(5.0, 0.04);
        $result = $this->tracker($ledger)->updateBonds([$bond], $this->macroAt(5.0));

        $this->assertSame(Bond::STATUS_MATURED, $bond->getStatus());
        $this->assertFalse($bond->isOnTheRun());
        $this->assertSame([$bond], $result['matured']);
        $this->assertSame([], $result['updates'], 'A redeemed issue no longer quotes.');

        // No residual rate risk may be left sitting in a portfolio against a bond that has been cashed out.
        $this->assertEqualsWithDelta((float) $bond->getFaceValue(), (float) $bond->getPrice(), 1e-9);
        $this->assertSame(0.0, (float) $bond->getModifiedDuration());
    }

    public function testAMaturedIssueIsSkippedEntirelyOnLaterTicks(): void
    {
        $ledger = $this->createMock(BondLedgerService::class);
        $ledger->expects($this->once())->method('processRedemption');

        $bond = $this->bond(5.0, 0.04);
        $tracker = $this->tracker($ledger);

        $tracker->updateBonds([$bond], $this->macroAt(5.0));
        $result = $tracker->updateBonds([$bond], $this->macroAt(5.5));

        $this->assertSame([], $result['matured']);
        $this->assertSame([], $result['updates']);
    }

    /**
     * The curve is published with the tick so no client has to rebuild it.
     *
     * The browser drawing its own curve from the raw factors would be a second authority on the term
     * structure, and its copied lambda constants would drift from MacroEngine's the first time those were
     * retuned. Publishing the evaluated points keeps the drawn curve and the priced bonds the same curve.
     */
    public function testTheSampledCurveIsPublishedWithEveryTick(): void
    {
        $ledger = $this->createStub(BondLedgerService::class);
        $macro = $this->macroAt(1.0);

        $result = $this->tracker($ledger)->updateBonds([$this->bond(10.0, 0.05)], $macro);

        $this->assertCount(count(FinancialConstants::BOND_CURVE_SAMPLE_TENORS), $result['curve']);

        $engine = new BondPricingEngine(new MathUtility());
        foreach ($result['curve'] as $index => $point) {
            $tenor = (float) FinancialConstants::BOND_CURVE_SAMPLE_TENORS[$index];

            $this->assertSame($tenor, $point['tenor']);
            $this->assertEqualsWithDelta(
                $engine->zeroYield($macro->sovereignCurve(), $tenor),
                $point['yield'],
                1e-12
            );
        }
    }

    public function testTheCurveIsStillPublishedWhenNothingIsOutstanding(): void
    {
        // The ladder page draws a curve before the first auction has sold anything.
        $result = $this->tracker($this->createStub(BondLedgerService::class))->updateBonds([], $this->macroAt(0.0));

        $this->assertSame([], $result['updates']);
        $this->assertCount(count(FinancialConstants::BOND_CURVE_SAMPLE_TENORS), $result['curve']);
    }

    public function testAnActiveIssueQuotesAndOptionallyRecordsHistory(): void
    {
        $ledger = $this->createStub(BondLedgerService::class);
        $bond = $this->bond(10.0, 0.05);

        $withoutHistory = $this->tracker($ledger)->updateBonds([$bond], $this->macroAt(1.25), false);
        $this->assertCount(1, $withoutHistory['updates']);
        $this->assertSame([], $withoutHistory['history']);

        $update = $withoutHistory['updates'][0];
        $this->assertSame('BOND', $update['asset_type']);
        $this->assertGreaterThan(0.0, $update['price']);
        $this->assertEqualsWithDelta(8.75, $update['years_to_maturity'], 1e-9);

        // Mid-period, the dirty price carries accrued interest the clean quote does not.
        $this->assertGreaterThan($update['clean_price'], $update['price']);

        $withHistory = $this->tracker($ledger)->updateBonds([$bond], $this->macroAt(1.25), true);
        $this->assertCount(1, $withHistory['history']);
        $this->assertArrayHasKey('yield_to_maturity', $withHistory['history'][0]);
    }

    /**
     * A tracker whose connection is observed: the mark goes to the database as data, so the assertion is
     * on the statement, not on the entity.
     *
     * @param list<array{0: string, 1: array<int, mixed>}> $sent Statement and parameters, in the order sent.
     */
    private function observedTracker(BondLedgerService $ledger, array &$sent): BondTracker
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$sent): int {
                $sent[] = [$sql, $params];

                return 1;
            }
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new BondTracker($em, new BondPricingEngine(new MathUtility()), $ledger);
    }

    private function persisted(Bond $bond, int $id): Bond
    {
        $property = new \ReflectionProperty(Bond::class, 'id');
        $property->setValue($bond, $id);

        return $bond;
    }

    public function testAMarkIsWrittenInBulkAndNeverThroughTheEntity(): void
    {
        $sent = [];
        $tracker = $this->observedTracker($this->createStub(BondLedgerService::class), $sent);

        $first = $this->persisted($this->bond(10.0, 0.05), 11)->setPrice('1.00')->setCleanPrice('1.00');
        $second = $this->persisted($this->bond(2.0, 0.03), 12)->setPrice('1.00')->setCleanPrice('1.00');
        $second->setTicker('G02-001');

        $result = $tracker->updateBonds([$first, $second], $this->macroAt(1.25), false, [], true);

        // One statement carries both rows: id, then the seven mark columns and the timestamp for each.
        $this->assertCount(1, $sent);
        [$sql, $params] = $sent[0];
        $this->assertStringStartsWith('UPDATE bonds t JOIN (SELECT ? AS id, ? AS price, ? AS clean_price', $sql);
        $this->assertCount(2 * 9, $params);
        $this->assertSame(11, $params[0]);
        $this->assertSame(12, $params[9]);
        $this->assertEqualsWithDelta($result["updates"][0]["price"], (float) $params[1], 1e-4);
        $this->assertEqualsWithDelta($result["updates"][1]["clean_price"], (float) $params[11], 1e-4);

        // The entity's mark fields are untouched, so the unit of work has nothing to flush for them.
        $this->assertSame('1.00', $first->getPrice());
        $this->assertSame('1.00', $second->getCleanPrice());
    }

    public function testBetweenMarksTheLastValuationIsQuotedAndNothingIsWritten(): void
    {
        $sent = [];
        $tracker = $this->observedTracker($this->createStub(BondLedgerService::class), $sent);
        $bond = $this->persisted($this->bond(10.0, 0.05), 11);

        $marked = $tracker->updateBonds([$bond], $this->macroAt(1.25), false, [], true);
        $quoted = $tracker->updateBonds([$bond], $this->macroAt(1.26), false, [], false);

        $this->assertCount(1, $sent, 'The pass between marks must not write.');
        $this->assertSame($marked['updates'][0]['price'], $quoted['updates'][0]['price']);
        $this->assertSame($marked['updates'][0]['yield_to_maturity'], $quoted['updates'][0]['yield_to_maturity']);
        // Time still moves between marks: what is quoted is the mark, not a frozen calendar.
        $this->assertEqualsWithDelta(8.74, $quoted['updates'][0]['years_to_maturity'], 1e-9);
    }

    public function testAnIssueNeverMarkedInThisProcessIsValuedRatherThanQuotedFromNothing(): void
    {
        $sent = [];
        $tracker = $this->observedTracker($this->createStub(BondLedgerService::class), $sent);
        $bond = $this->persisted($this->bond(10.0, 0.05), 11);

        $result = $tracker->updateBonds([$bond], $this->macroAt(1.25), false, [], false);

        $this->assertCount(1, $result['updates']);
        $this->assertGreaterThan(0.0, $result['updates'][0]['price']);
        $this->assertSame([], $sent, 'Valued for the quote, but a pass that is not a mark writes nothing.');
    }

    public function testAnIssueWithoutARowYetIsQuotedButLeftForTheNextMarkToWrite(): void
    {
        $sent = [];
        $tracker = $this->observedTracker($this->createStub(BondLedgerService::class), $sent);
        $unflushed = $this->bond(10.0, 0.05);

        $result = $tracker->updateBonds([$unflushed], $this->macroAt(1.25), false, [], true);

        $this->assertCount(1, $result['updates']);
        $this->assertSame([], $sent);
    }
}
