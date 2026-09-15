<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Repository\OptionContractRepository;
use App\Service\Market\Gamma\InMemoryDealerGammaStore;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\OptionPricingEngine;
use App\Service\Market\BondPricingEngine;
use App\Service\Market\OptionChainService;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\StockBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The chain panel. What matters here is the SHAPE the table is built in — one row per strike carrying both
 * sides, expiries in order, the spot's own strike flagged — because that layout is the whole reason a chain
 * is readable, and because every one of those is a place a contract can silently go missing from the page.
 */
class OptionChainBuilderTest extends TestCase
{
    private InMemoryDealerGammaStore $gammaStore;

    protected function setUp(): void
    {
        $this->gammaStore = new InMemoryDealerGammaStore();
    }

    /**
     * @param array<int, OptionContract> $chain
     */
    private function builder(array $chain): \App\Service\View\OptionChainBuilder
    {
        $repository = $this->createStub(OptionContractRepository::class);
        $repository->method('findChain')->willReturn($chain);

        $em = $this->createStub(EntityManagerInterface::class);

        $math = new MathUtility();

        return new \App\Service\View\OptionChainBuilder(
            $em,
            $repository,
            new OptionChainService($em, new LiquidityEngine($math)),
            new OptionPricingEngine($math, new BondPricingEngine($math)),
            $this->gammaStore,
        );
    }

    /** A macro snapshot carrying only what the chain is priced against: the clock and the curve. */
    private function macro(float $totalTime): \App\DTO\MacroStateDTO
    {
        return new \App\DTO\MacroStateDTO(totalTime: $totalTime);
    }

    private function stock(float $price = 100.0): Stock
    {
        return StockBuilder::create('VANE')
            ->withPrice($price)
            ->withSharesOutstanding(50_000_000)
            ->withPublicFloatPercentage(1.0)
            ->build();
    }

    private function contract(
        Stock $stock,
        int $serial,
        bool $isCall,
        float $strike,
        int $structuralOpenInterest = 0
    ): OptionContract {
        return (new OptionContract())
            ->setTicker(OptionChainService::contractTicker($stock->getTicker(), $serial, $isCall ? OptionContract::TYPE_CALL : OptionContract::TYPE_PUT, $strike))
            ->setStock($stock)
            ->setOptionType($isCall ? OptionContract::TYPE_CALL : OptionContract::TYPE_PUT)
            ->setStrike((string) $strike)
            ->setExpirySerial($serial)
            ->setExpiresAtTime($serial / 12.0)
            ->setPrice('4.50000000')
            ->setImpliedVolatility($isCall ? '0.300000' : '0.360000')
            ->setDelta($isCall ? '0.45000000' : '-0.55000000')
            ->setStructuralOpenInterest($structuralOpenInterest);
    }

    // --- No Class ---

    public function testANameWithNoContractsExplainsWhyRatherThanRenderingAnEmptyLadder(): void
    {
        $payload = $this->builder([])->build($this->stock(), null, $this->macro(1.0));

        $this->assertFalse($payload['optionsListed']);
        $this->assertNotEmpty($payload['optionsReason']);
        $this->assertSame([], $payload['optionExpiries']);
    }

    public function testASubFloorPriceIsExplainedInTheListingStandardsOwnTerms(): void
    {
        $payload = $this->builder([])->build($this->stock(1.0), null, $this->macro(1.0));

        $this->assertStringContainsString('share price', $payload['optionsReason']);
    }

    public function testABankruptShellSaysItsClassIsClosed(): void
    {
        $stock = $this->stock();
        $stock->setIsBankrupt(true);

        $this->assertStringContainsString('bankrupt', $this->builder([])->build($stock, null, $this->macro(1.0))['optionsReason']);
    }

    // --- The Ladder ---

    public function testBothSidesOfAStrikeLandOnTheSameRow(): void
    {
        $stock = $this->stock();
        $chain = [
            $this->contract($stock, 13, true, 100.0),
            $this->contract($stock, 13, false, 100.0),
        ];

        $payload = $this->builder($chain)->build($stock, null, $this->macro(1.0));

        $this->assertCount(1, $payload['optionExpiries']);
        $this->assertCount(1, $payload['optionExpiries'][0]['rows']);

        $row = $payload['optionExpiries'][0]['rows'][0];
        $this->assertNotNull($row['call']);
        $this->assertNotNull($row['put']);
        $this->assertEqualsWithDelta(100.0, $row['strike'], 1e-9);
    }

    public function testAStrikeListedOnOneSideOnlyLeavesTheOtherSideEmpty(): void
    {
        $stock = $this->stock();
        $payload = $this->builder([$this->contract($stock, 13, true, 120.0)])->build($stock, null, $this->macro(1.0));

        $row = $payload['optionExpiries'][0]['rows'][0];

        $this->assertNotNull($row['call']);
        $this->assertNull($row['put']);
    }

    public function testStrikesAreOrderedUpTheLadder(): void
    {
        $stock = $this->stock();
        $chain = [
            $this->contract($stock, 13, true, 120.0),
            $this->contract($stock, 13, true, 90.0),
            $this->contract($stock, 13, true, 105.0),
        ];

        $strikes = array_column($this->builder($chain)->build($stock, null, $this->macro(1.0))['optionExpiries'][0]['rows'], 'strike');

        $this->assertSame([90.0, 105.0, 120.0], $strikes);
    }

    public function testExpiriesAreOrderedNearestFirst(): void
    {
        $stock = $this->stock();
        $chain = [
            $this->contract($stock, 18, true, 100.0),
            $this->contract($stock, 13, true, 100.0),
            $this->contract($stock, 15, true, 100.0),
        ];

        $serials = array_column($this->builder($chain)->build($stock, null, $this->macro(1.0))['optionExpiries'], 'serial');

        $this->assertSame([13, 15, 18], $serials);
    }

    public function testTheStrikeTheSpotIsNearestIsFlaggedExactlyOnce(): void
    {
        $stock = $this->stock(103.0);
        $chain = [
            $this->contract($stock, 13, true, 95.0),
            $this->contract($stock, 13, true, 105.0),
            $this->contract($stock, 13, true, 115.0),
        ];

        $rows = $this->builder($chain)->build($stock, null, $this->macro(1.0))['optionExpiries'][0]['rows'];
        $flagged = array_values(array_filter($rows, static fn (array $row): bool => $row['isNearestStrike']));

        $this->assertCount(1, $flagged);
        $this->assertEqualsWithDelta(105.0, $flagged[0]['strike'], 1e-9);
    }

    // --- Readings ---

    public function testAnExpirysHeadlineVolatilityIsReadOffTheStrikeNearestTheSpot(): void
    {
        // The spot is never exactly on a round strike, so the expiry's at-the-money reading has to come
        // from the listed strike it is actually closest to. Both strikes are priced off the same surface,
        // and the smile gives them genuinely different volatilities, so picking the wrong one shows up.
        $stock = $this->stock(103.0);

        $far = $this->contract($stock, 13, true, 60.0);
        $near = $this->contract($stock, 13, true, 105.0);

        $math = new MathUtility();
        $quotes = (new OptionPricingEngine($math, new BondPricingEngine($math)))
            ->quoteChain([$far, $near], $this->macro(1.0)->sovereignCurve(), 1.0);

        $this->assertNotEqualsWithDelta(
            $quotes[$far->getTicker()]->impliedVolatility,
            $quotes[$near->getTicker()]->impliedVolatility,
            1e-6,
            'the two strikes should not sit at the same volatility, or this proves nothing'
        );

        $payload = $this->builder([$far, $near])->build($stock, null, $this->macro(1.0));

        $this->assertEqualsWithDelta(
            $quotes[$near->getTicker()]->impliedVolatility,
            $payload['optionExpiries'][0]['atmVolatility'],
            1e-9
        );
    }

    public function testTheChainIsPricedOnReadRatherThanFromTheStoredMark(): void
    {
        // The stored columns are only maintained for contracts somebody holds. A chain row must ignore them
        // entirely, or an unheld contract would render whatever it was worth the last time anyone had a
        // position in it — possibly years stale.
        $stock = $this->stock(100.0);

        $contract = $this->contract($stock, 13, true, 100.0)
            ->setPrice('999.00000000')
            ->setImpliedVolatility('9.000000')
            ->setDelta('0.99000000');

        $leg = $this->builder([$contract])->build($stock, null, $this->macro(1.0))['optionExpiries'][0]['rows'][0]['call'];

        $this->assertLessThan(100.0, $leg['mark']);
        $this->assertGreaterThan(0.0, $leg['mark']);
        $this->assertLessThan(2.0, $leg['impliedVolatility']);
        $this->assertLessThan(0.99, $leg['delta']);
    }

    public function testMoneynessIsMarkedFromTheSideTheContractIsOn(): void
    {
        $stock = $this->stock(100.0);
        $chain = [
            $this->contract($stock, 13, true, 90.0),
            $this->contract($stock, 13, false, 90.0),
        ];

        $row = $this->builder($chain)->build($stock, null, $this->macro(1.0))['optionExpiries'][0]['rows'][0];

        // Spot above the strike: the call is in the money and the put, at the same strike, is not.
        $this->assertTrue($row['call']['inTheMoney']);
        $this->assertFalse($row['put']['inTheMoney']);
    }

    public function testTimeToExpiryIsMeasuredFromNowAndNeverGoesNegative(): void
    {
        $stock = $this->stock();
        $payload = $this->builder([$this->contract($stock, 13, true, 100.0)])->build($stock, null, $this->macro(1.0));

        $this->assertEqualsWithDelta(((13.0 / 12.0) - 1.0) * 365.0, $payload['optionExpiries'][0]['daysToExpiry'], 1e-6);

        $settled = $this->builder([$this->contract($stock, 12, true, 100.0)])->build($stock, null, $this->macro(5.0));
        $this->assertSame(0.0, $settled['optionExpiries'][0]['daysToExpiry']);
    }

    // --- What The Desk Carries ---

    public function testOpenInterestCountsBothSidesOfTheChain(): void
    {
        $stock = $this->stock();
        $chain = [
            $this->contract($stock, 13, true, 100.0, 400),
            $this->contract($stock, 13, false, 100.0, 250),
        ];

        $this->assertSame(650, $this->builder($chain)->build($stock, null, $this->macro(1.0))['optionOpenInterest']);
    }

    public function testAWrittenPublicPositionStillCountsAsOpenInterest(): void
    {
        // Open interest is contracts outstanding, not a net exposure: a public short is still a position
        // somebody has to settle, and netting it to zero would hide the whole of it from the panel.
        $stock = $this->stock();
        $chain = [$this->contract($stock, 13, true, 100.0, -300)];

        $this->assertSame(300, $this->builder($chain)->build($stock, null, $this->macro(1.0))['optionOpenInterest']);
    }

    public function testDealerGammaIsReportedPerOnePercentMoveAsWellAsPerUnit(): void
    {
        $stock = $this->stock(120.0);
        $this->gammaStore->record('VANE', 5000.0, 120.0);

        $payload = $this->builder([$this->contract($stock, 13, true, 120.0, 100)])->build($stock, null, $this->macro(1.0));

        $this->assertEqualsWithDelta(5000.0, $payload['optionDealerGamma'], 1e-9);
        $this->assertEqualsWithDelta(5000.0 * 120.0 * 0.01, $payload['optionDealerGammaPerPercent'], 1e-9);
    }

    public function testANameTheDeskHasNotWalkedYetReportsNoExposureRatherThanFailing(): void
    {
        $stock = $this->stock();

        $this->assertSame(0.0, $this->builder([$this->contract($stock, 13, true, 100.0)])->build($stock, null, $this->macro(1.0))['optionDealerGamma']);
    }

    public function testTheContractMultiplierReachesTheTemplate(): void
    {
        $stock = $this->stock();

        $this->assertSame(
            FinancialConstants::OPTION_CONTRACT_MULTIPLIER,
            $this->builder([$this->contract($stock, 13, true, 100.0)])->build($stock, null, $this->macro(1.0))['optionMultiplier']
        );
    }
}
