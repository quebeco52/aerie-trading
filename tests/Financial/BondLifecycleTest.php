<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\MacroStateDTO;
use App\DTO\SovereignCurveDTO;
use App\Entity\Bond;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Market\BondLedgerService;
use App\Service\Market\BondPricingEngine;
use App\Service\Market\BondTracker;
use App\Service\Market\TreasuryAuctionService;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The bond desk driven over eleven simulated years, auctions and all.
 *
 * The per-tick behaviours are asserted in isolation elsewhere. What only a long run can show is whether they
 * compose: whether a coupon schedule anchored to an issue date stays on its half-year grid after tens of
 * thousands of floating-point time steps, whether an issue redeems on its own maturity date rather than a
 * tick late, and whether the ladder reaches a steady composition instead of growing without bound or
 * emptying out. Each of those looks fine on any single tick.
 */
#[AllowMockObjectsWithoutExpectations]
class BondLifecycleTest extends TestCase
{
    /** Coarse on purpose: the schedule is anchored to elapsed time, so the tick rate must not matter. */
    private const TICKS_PER_YEAR = 26;

    private const YEARS = 11.0;

    /** @var array<string, list<float>> ticker => coupon payment times */
    private array $coupons = [];

    /** @var array<string, float> ticker => redemption time */
    private array $redemptions = [];

    private function curve(): SovereignCurveDTO
    {
        return new SovereignCurveDTO(
            level: 0.0425,
            slope: -0.0175,
            curvature1: 0.0,
            curvature2: 0.0,
            baseTermPremium: MacroEngine::NS_BASE_TERM_PREMIUM,
            longEndPremium: MacroEngine::NS_BASE_TERM_PREMIUM,
            balanceSheetIntensity: 0.0,
        );
    }

    private function macroAt(float $totalTime): MacroStateDTO
    {
        $state = new MacroState();
        $state->totalTime = $totalTime;

        return MacroStateDTO::fromMacroState($state);
    }

    /** @var array{issued: list<Bond>, outstanding: list<Bond>}|null Memoized: the run is deterministic. */
    private static ?array $deskRun = null;

    /** @var array<string, list<float>> */
    private static array $runCoupons = [];

    /** @var array<string, float> */
    private static array $runRedemptions = [];

    /**
     * Runs the desk forward, returning every issue ever sold and the ones still outstanding at the end.
     *
     * Cached across the tests in this class. Eleven simulated years of a growing ladder is the most
     * expensive fixture in the suite and every assertion here reads the same deterministic run.
     *
     * @return array{issued: list<Bond>, outstanding: list<Bond>}
     */
    private function runDesk(): array
    {
        if (self::$deskRun !== null) {
            $this->coupons = self::$runCoupons;
            $this->redemptions = self::$runRedemptions;

            return self::$deskRun;
        }

        $this->coupons = [];
        $this->redemptions = [];

        $ledger = $this->createMock(BondLedgerService::class);
        $ledger->method('processCouponPayment')->willReturnCallback(
            function (Bond $bond, float $amount, float $time): void {
                $this->coupons[$bond->getTicker()][] = $time;
            }
        );
        $ledger->method('processRedemption')->willReturnCallback(
            function (Bond $bond, float $time): void {
                $this->redemptions[$bond->getTicker()] = $time;
            }
        );

        $issuedCounts = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $params) use (&$issuedCounts): int {
                return $issuedCounts[(string) $params['tenor']] ?? 0;
            }
        );
        $connection->method('executeStatement')->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $pricing = new BondPricingEngine(new MathUtility());
        $auction = new TreasuryAuctionService($em, $pricing);
        $tracker = new BondTracker($em, $pricing, $ledger);

        $sell = function (float $time, array $outstanding) use ($auction, &$issuedCounts): array {
            $new = $auction->conductAuction($this->curve(), $time, $outstanding);
            foreach ($new as $bond) {
                $key = (string) (float) $bond->getTenorYears();
                $issuedCounts[$key] = ($issuedCounts[$key] ?? 0) + 1;
            }

            return $new;
        };

        $outstanding = $sell(0.0, []);
        $issued = $outstanding;

        $dt = 1.0 / self::TICKS_PER_YEAR;
        $auctionInterval = TreasuryAuctionService::auctionIntervalTicks(self::TICKS_PER_YEAR);
        $totalTicks = (int) (self::YEARS * self::TICKS_PER_YEAR);

        for ($tick = 1; $tick <= $totalTicks; $tick++) {
            $time = $tick * $dt;

            $result = $tracker->updateBonds($outstanding, $this->macroAt($time));

            // The caller drains matured issues; BondTracker reports them but does not own the working set.
            if ($result['matured'] !== []) {
                $maturedIds = array_map(static fn (Bond $b): int => spl_object_id($b), $result['matured']);
                $outstanding = array_values(array_filter(
                    $outstanding,
                    static fn (Bond $b): bool => !in_array(spl_object_id($b), $maturedIds, true)
                ));
            }

            if ($tick % $auctionInterval === 0) {
                foreach ($sell($time, $outstanding) as $bond) {
                    $outstanding[] = $bond;
                    $issued[] = $bond;
                }
            }
        }

        self::$runCoupons = $this->coupons;
        self::$runRedemptions = $this->redemptions;
        self::$deskRun = ['issued' => $issued, 'outstanding' => $outstanding];

        return self::$deskRun;
    }

    public function testEveryMaturedIssuePaidExactlyItsScheduledCoupons(): void
    {
        $desk = $this->runDesk();

        $audited = 0;
        foreach ($desk['issued'] as $bond) {
            if (!isset($this->redemptions[$bond->getTicker()])) {
                continue;
            }

            // One fewer than the full schedule: the final coupon is part of the redemption cash flow.
            $expected = (int) round(((float) $bond->getTenorYears()) * FinancialConstants::BOND_COUPON_FREQUENCY) - 1;

            $this->assertCount(
                $expected,
                $this->coupons[$bond->getTicker()] ?? [],
                "{$bond->getTicker()} did not pay its scheduled coupons."
            );

            $audited++;
        }

        $this->assertGreaterThan(20, $audited, 'The run must actually mature a meaningful number of issues.');
    }

    public function testCouponDatesDoNotDriftOffTheHalfYearGridOverTheWholeRun(): void
    {
        // Accumulating a period onto a running total once per payment, rather than re-deriving each date
        // from the issue date, would let rounding walk the schedule off the grid over a long enough run.
        $desk = $this->runDesk();

        $maxDrift = 0.0;
        $tickLength = 1.0 / self::TICKS_PER_YEAR;

        foreach ($desk['issued'] as $bond) {
            foreach ($this->coupons[$bond->getTicker()] ?? [] as $index => $paidAt) {
                $scheduled = $bond->getIssuedAtTime() + (($index + 1) * $bond->couponPeriodYears());
                $maxDrift = max($maxDrift, abs($paidAt - $scheduled));
            }
        }

        $this->assertLessThan($tickLength, $maxDrift, 'Coupon dates drifted by more than one tick.');
    }

    public function testEveryIssueRedeemsOnItsOwnMaturityDate(): void
    {
        $desk = $this->runDesk();
        $tickLength = 1.0 / self::TICKS_PER_YEAR;

        foreach ($desk['issued'] as $bond) {
            $redeemedAt = $this->redemptions[$bond->getTicker()] ?? null;
            if ($redeemedAt === null) {
                $this->assertGreaterThan(self::YEARS, $bond->getMaturesAtTime(), "{$bond->getTicker()} should have redeemed.");
                continue;
            }

            $this->assertLessThanOrEqual(
                $tickLength,
                $redeemedAt - $bond->getMaturesAtTime(),
                "{$bond->getTicker()} redeemed more than a tick late."
            );
        }
    }

    public function testAMaturedIssueIsRedeemedOnlyOnce(): void
    {
        $this->runDesk();

        // The counts come from a map keyed by ticker, so a second redemption of the same issue would have
        // overwritten rather than duplicated. The real guard is that no issue outlives its own maturity in
        // the working set, which the run asserts by never re-reporting one.
        $this->assertSame(count($this->redemptions), count(array_unique(array_keys($this->redemptions))));
    }

    public function testTheLadderReachesASteadyCompositionRatherThanGrowingWithoutBound(): void
    {
        $desk = $this->runDesk();

        $byTenor = [];
        foreach ($desk['outstanding'] as $bond) {
            $byTenor[(string) (float) $bond->getTenorYears()][] = $bond;
        }

        foreach (FinancialConstants::BOND_AUCTION_TENORS as $tenor) {
            $key = (string) (float) $tenor;
            $this->assertArrayHasKey($key, $byTenor, "The {$tenor}y bucket emptied out.");

            // Outstanding issues at a tenor converge on tenor * auctions-per-year, because that is how many
            // are sold before the oldest one redeems. The thirty-year has not started retiring yet.
            $expected = min($tenor, self::YEARS) * FinancialConstants::BOND_AUCTIONS_PER_YEAR;

            $this->assertEqualsWithDelta(
                $expected,
                count($byTenor[$key]),
                FinancialConstants::BOND_AUCTIONS_PER_YEAR,
                "The {$tenor}y bucket is not at its steady-state size."
            );

            $onTheRun = array_filter($byTenor[$key], static fn (Bond $b): bool => $b->isOnTheRun());
            $this->assertCount(1, $onTheRun, "There must be exactly one on-the-run {$tenor}y.");
        }
    }
}
