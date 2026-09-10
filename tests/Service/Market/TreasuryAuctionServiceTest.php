<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\SovereignCurveDTO;
use App\Entity\Bond;
use App\Service\Macro\MacroEngine;
use App\Service\Market\BondPricingEngine;
use App\Service\Market\TreasuryAuctionService;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Quarterly refunding: the rotation that keeps a benchmark maturity available to trade.
 *
 * Without it, the only ten-year on the desk is the one sold when the simulation started, which is a
 * nine-year a year later and never a ten-year again.
 */
#[AllowMockObjectsWithoutExpectations]
class TreasuryAuctionServiceTest extends TestCase
{
    /** @var list<Bond> */
    private array $persisted = [];

    /** @var list<array{sql: string, params: array<string, mixed>}> */
    private array $statements = [];

    private function service(int $existingAtTenor = 0): TreasuryAuctionService
    {
        $this->persisted = [];
        $this->statements = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($existingAtTenor);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            }
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Bond) {
                $this->persisted[] = $entity;
            }
        });

        return new TreasuryAuctionService($em, new BondPricingEngine(new MathUtility()));
    }

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

    public function testAnAuctionSellsOneIssueAtEveryOfferedTenor(): void
    {
        $issued = $this->service()->conductAuction($this->curve(), 4.0);

        $this->assertCount(count(FinancialConstants::BOND_AUCTION_TENORS), $issued);
        $this->assertSame($issued, $this->persisted, 'Every new issue must be persisted.');

        foreach (FinancialConstants::BOND_AUCTION_TENORS as $index => $tenor) {
            $bond = $issued[$index];

            $this->assertEqualsWithDelta((float) $tenor, (float) $bond->getTenorYears(), 1e-9);
            $this->assertEqualsWithDelta(4.0, $bond->getIssuedAtTime(), 1e-9);
            $this->assertEqualsWithDelta(4.0 + $tenor, $bond->getMaturesAtTime(), 1e-9);
            $this->assertTrue($bond->isOnTheRun());
            $this->assertSame(Bond::STATUS_ACTIVE, $bond->getStatus());
        }
    }

    public function testEachTenorsPreviousBenchmarkIsDemotedBeforeTheNewOneIsSold(): void
    {
        $this->service()->conductAuction($this->curve(), 4.0);

        $demotions = array_values(array_filter(
            $this->statements,
            static fn (array $statement): bool => str_contains($statement['sql'], 'is_on_the_run = 0')
        ));

        $this->assertCount(count(FinancialConstants::BOND_AUCTION_TENORS), $demotions);

        foreach (FinancialConstants::BOND_AUCTION_TENORS as $index => $tenor) {
            $this->assertSame($tenor, $demotions[$index]['params']['tenor']);
        }
    }

    public function testTickersAreSequencedFromTheExistingRowCountSoARestartCannotCollide(): void
    {
        // An in-memory counter would restart at one after a ticker reboot and violate the unique index.
        $issued = $this->service(existingAtTenor: 7)->conductAuction($this->curve(), 0.0);

        $this->assertSame('G02-008', $issued[0]->getTicker());
        $this->assertSame('G30-008', $issued[count($issued) - 1]->getTicker());
    }

    public function testANewIssueIsMarkedAndPricedAtAuctionRatherThanLeftAtItsDefault(): void
    {
        $bond = $this->service()->conductAuction($this->curve(), 0.0)[2];

        $this->assertEqualsWithDelta(10.0, (float) $bond->getTenorYears(), 1e-9);
        $this->assertGreaterThan(0.0, (float) $bond->getCouponRate());
        $this->assertGreaterThan(0.0, (float) $bond->getYieldToMaturity());
        $this->assertGreaterThan(0.0, (float) $bond->getModifiedDuration());
        $this->assertEqualsWithDelta((string) FinancialConstants::BOND_ISSUE_SIZE, (float) $bond->getOutstandingFace(), 1.0);

        // Struck at par, so it opens near face rather than at the entity's placeholder price.
        $this->assertEqualsWithDelta(FinancialConstants::BOND_FACE_VALUE, (float) $bond->getCleanPrice(), 15.0);
    }

    public function testTheCouponIsStruckOffTheLiveCurveSoAHigherCurveSellsAHigherCoupon(): void
    {
        $low = $this->service()->conductAuction($this->curve(), 0.0)[2];

        $steep = new SovereignCurveDTO(
            level: 0.0725,
            slope: -0.0175,
            curvature1: 0.0,
            curvature2: 0.0,
            baseTermPremium: MacroEngine::NS_BASE_TERM_PREMIUM,
            longEndPremium: MacroEngine::NS_BASE_TERM_PREMIUM,
            balanceSheetIntensity: 0.0,
        );

        $high = $this->service()->conductAuction($steep, 0.0)[2];

        $this->assertGreaterThan((float) $low->getCouponRate(), (float) $high->getCouponRate());
    }

    public function testTheFirstCouponIsDueOnePeriodAfterIssue(): void
    {
        $bond = $this->service()->conductAuction($this->curve(), 4.0)[0];

        $this->assertEqualsWithDelta(4.0, $bond->getLastCouponTime(), 1e-9);
        $this->assertEqualsWithDelta(0.5, $bond->couponPeriodYears(), 1e-9);
    }

    /**
     * The caller's in-memory bonds must be demoted, not just the rows.
     *
     * The demotion is a bulk UPDATE, which goes around Doctrine's identity map. The ticker holds its bond
     * entities across ticks and publishes is_on_the_run in the tick payload, so relying on the UPDATE alone
     * leaves the table right and the quote wrong until the next EntityManager clear.
     */
    public function testTheCallersInMemoryBenchmarkIsDemotedAndNotOnlyItsRow(): void
    {
        $service = $this->service();

        $outgoing = (new Bond())->setTicker('G10-001')->setTenorYears('10')->setIsOnTheRun(true);
        $unrelatedTenor = (new Bond())->setTicker('G02-001')->setTenorYears('2')->setIsOnTheRun(true);
        $alreadyOffTheRun = (new Bond())->setTicker('G10-000')->setTenorYears('10')->setIsOnTheRun(false);

        $issued = $service->conductAuction($this->curve(), 1.0, [$outgoing, $unrelatedTenor, $alreadyOffTheRun]);

        $this->assertFalse($outgoing->isOnTheRun(), 'The outgoing ten-year benchmark must be demoted.');
        $this->assertFalse($alreadyOffTheRun->isOnTheRun());

        // Every tenor is auctioned in the same pass, so the two-year is demoted by its own leg, not left alone.
        $this->assertFalse($unrelatedTenor->isOnTheRun());

        foreach ($issued as $bond) {
            $this->assertTrue($bond->isOnTheRun(), 'Each new issue becomes its tenor\'s benchmark.');
        }
    }

    public function testAnAuctionWithNoWorkingSetStillUpdatesTheTable(): void
    {
        // The seed and reset paths hold no working set, so the SQL leg has to stand on its own.
        $this->service()->conductAuction($this->curve(), 0.0);

        $demotions = array_filter(
            $this->statements,
            static fn (array $statement): bool => str_contains($statement['sql'], 'is_on_the_run = 0')
        );

        $this->assertCount(count(FinancialConstants::BOND_AUCTION_TENORS), $demotions);
    }

    public function testAuctionIntervalDividesTheTickRateIntoTheConfiguredNumberOfAuctions(): void
    {
        $this->assertSame(3600, TreasuryAuctionService::auctionIntervalTicks(14400));
        $this->assertSame(63, TreasuryAuctionService::auctionIntervalTicks(252));

        // A tick rate below the auction frequency must still auction rather than divide to zero and
        // trigger a modulo-by-zero every tick.
        $this->assertSame(1, TreasuryAuctionService::auctionIntervalTicks(1));
    }
}
