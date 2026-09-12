<?php

declare(strict_types=1);

namespace App\Tests\Service\District;

use App\Entity\Stock;
use App\Entity\UserStock;
use App\Service\District\DistrictHoldingsFeed;
use App\Service\User\CostBasisCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Pins the one thing the district page must get right about a position that UserStock does not
 * hand it: the sign. A short is stored as a negative quantity, and every client-side reader
 * (pennant, tooltip, ticket "Max") wants the unsigned size plus a direction flag.
 */
#[AllowMockObjectsWithoutExpectations]
class DistrictHoldingsFeedTest extends TestCase
{
    private DistrictHoldingsFeed $feed;

    protected function setUp(): void
    {
        $this->feed = new DistrictHoldingsFeed(
            $this->createMock(EntityManagerInterface::class),
            new CostBasisCalculator(),
        );
    }

    public function testShortPositionIsReportedUnsignedWithTheDirectionFlagSet(): void
    {
        $holdings = $this->feed->describe(
            [$this->makeUserStock('TALN', -250), $this->makeUserStock('LAKE', 40)],
            ['TALN' => 12.5, 'LAKE' => 80.0],
        );

        $this->assertSame(['quantity' => 250, 'isShort' => true, 'averageCost' => 12.5], $holdings['TALN']);
        $this->assertSame(['quantity' => 40, 'isShort' => false, 'averageCost' => 80.0], $holdings['LAKE']);
    }

    public function testFlatPositionsAreDroppedAndMissingCostBasisIsNull(): void
    {
        $holdings = $this->feed->describe(
            [$this->makeUserStock('FLAT', 0), $this->makeUserStock('NOCB', 7)],
            [],
        );

        $this->assertArrayNotHasKey('FLAT', $holdings, 'A flat position is not a position');
        $this->assertSame(['quantity' => 7, 'isShort' => false, 'averageCost' => null], $holdings['NOCB']);
    }

    private function makeUserStock(string $ticker, int $quantity): UserStock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);

        $userStock = new UserStock();
        $userStock->setStock($stock);
        $userStock->setQuantity($quantity);

        return $userStock;
    }
}
