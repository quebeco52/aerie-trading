<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\OptionContract;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\OptionChainService;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\StockBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The listing rules. These are what bound the surface: a chain that listed every strike on every name would
 * be unbounded, and the exchange rule that stops it — a listing standard, a shared expiry grid, and a round
 * strike ladder around the money — is also what makes the chain read like a real one.
 */
class OptionChainServiceTest extends TestCase
{
    private function service(?EntityManagerInterface $em = null): OptionChainService
    {
        return new OptionChainService(
            $em ?? $this->createStub(EntityManagerInterface::class),
            new LiquidityEngine(new MathUtility()),
        );
    }

    // --- The Expiry Grid ---

    public function testEveryNameExpiresOnTheSameGrid(): void
    {
        // Two moments inside the same month list the same serials, so an expiry is one market-wide event.
        $this->assertSame(
            OptionChainService::listedSerials(4.0),
            OptionChainService::listedSerials(4.0 + (1.0 / 365.0))
        );
    }

    public function testTheListedSerialsAreTheConfiguredMonthsOut(): void
    {
        $serials = OptionChainService::listedSerials(2.0);

        $this->assertSame([25, 26, 27, 30], $serials);
    }

    public function testASerialExpiresAtItsOwnPointOnTheGrid(): void
    {
        $this->assertEqualsWithDelta(2.5, OptionChainService::expiryTime(30), 1e-12);
        $this->assertSame(30, OptionChainService::expirySerial(2.5));
    }

    public function testTheGridRollsForwardWithTime(): void
    {
        $before = OptionChainService::listedSerials(2.0);
        $after = OptionChainService::listedSerials(2.0 + (1.0 / 12.0));

        foreach ($before as $index => $serial) {
            $this->assertSame($serial + 1, $after[$index]);
        }
    }

    // --- The Strike Ladder ---

    public function testTheLadderSnapsToARoundIncrementForThePriceLevel(): void
    {
        // The increment is the smallest listed one that is at least the target spacing of the spot.
        $this->assertSame(0.50, OptionChainService::strikeIncrement(8.0));
        $this->assertSame(5.00, OptionChainService::strikeIncrement(100.0));
        $this->assertSame(50.00, OptionChainService::strikeIncrement(1000.0));
    }

    public function testAnExtremePriceStillGetsTheWidestListedIncrement(): void
    {
        $this->assertSame(
            (float) FinancialConstants::OPTION_STRIKE_INCREMENTS[count(FinancialConstants::OPTION_STRIKE_INCREMENTS) - 1],
            OptionChainService::strikeIncrement(1.0e6)
        );
    }

    public function testEveryListedStrikeSitsOnTheIncrementAndInsideTheLadder(): void
    {
        $spot = 137.0;
        $increment = OptionChainService::strikeIncrement($spot);
        $strikes = OptionChainService::strikeLadder($spot);

        $this->assertNotEmpty($strikes);

        foreach ($strikes as $strike) {
            $this->assertEqualsWithDelta(0.0, fmod($strike, $increment), 1e-6, "strike {$strike} is off the ladder");
            $this->assertGreaterThanOrEqual($spot * (1.0 - FinancialConstants::OPTION_STRIKE_LADDER_WIDTH), $strike);
            $this->assertLessThanOrEqual($spot * (1.0 + FinancialConstants::OPTION_STRIKE_LADDER_WIDTH), $strike);
        }
    }

    public function testTheLadderBracketsTheSpot(): void
    {
        $strikes = OptionChainService::strikeLadder(100.0);

        $this->assertLessThan(100.0, $strikes[0]);
        $this->assertGreaterThan(100.0, $strikes[count($strikes) - 1]);
    }

    public function testTheLadderIsAscendingAndFinite(): void
    {
        $strikes = OptionChainService::strikeLadder(250.0);

        $this->assertSame($strikes, array_values(array_unique($strikes)));

        $sorted = $strikes;
        sort($sorted);
        $this->assertSame($sorted, $strikes);
        $this->assertLessThan(40, count($strikes));
    }

    public function testAWorthlessStockHasNoLadder(): void
    {
        $this->assertSame([], OptionChainService::strikeLadder(0.0));
    }

    // --- Symbols ---

    public function testAContractSymbolNamesItsUnderlyingExpirySideAndStrike(): void
    {
        $this->assertSame('VANE-27C120', OptionChainService::contractTicker('VANE', 27, OptionContract::TYPE_CALL, 120.0));
        $this->assertSame('VANE-27P117.5', OptionChainService::contractTicker('VANE', 27, OptionContract::TYPE_PUT, 117.5));
        $this->assertSame('OWLS-5C7.5', OptionChainService::contractTicker('OWLS', 5, OptionContract::TYPE_CALL, 7.5));
    }

    public function testASymbolFitsTheColumnItIsStoredIn(): void
    {
        $ticker = OptionChainService::contractTicker('WXYZAB', 9999, OptionContract::TYPE_PUT, 999999.25);

        $this->assertLessThanOrEqual(32, strlen($ticker));
    }

    // --- Listing Standards ---

    public function testALiquidNameAboveThePriceFloorIsListable(): void
    {
        $stock = StockBuilder::create('VANE')
            ->withPrice(100.0)
            ->withSharesOutstanding(50_000_000)
            ->withPublicFloatPercentage(1.0)
            ->build();

        $this->assertTrue($this->service()->isListable($stock));
    }

    public function testASubFloorPriceCarriesNoClass(): void
    {
        $stock = StockBuilder::create('PENN')
            ->withPrice(FinancialConstants::OPTION_LISTING_MIN_PRICE - 0.01)
            ->withSharesOutstanding(50_000_000)
            ->build();

        $this->assertFalse($this->service()->isListable($stock));
    }

    public function testANameNobodyTradesCarriesNoClass(): void
    {
        $stock = StockBuilder::create('TINY')
            ->withPrice(100.0)
            ->withSharesOutstanding(10_000)
            ->withPublicFloatPercentage(0.05)
            ->build();

        $this->assertFalse($this->service()->isListable($stock));
    }

    public function testABankruptShellCarriesNoClass(): void
    {
        $stock = StockBuilder::create('DEAD')
            ->withPrice(100.0)
            ->withSharesOutstanding(50_000_000)
            ->build();
        $stock->setIsBankrupt(true);

        $this->assertFalse($this->service()->isListable($stock));
    }
}
