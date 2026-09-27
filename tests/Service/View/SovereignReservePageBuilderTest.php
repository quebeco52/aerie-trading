<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\StrategicHoldings;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\MathUtility;
use App\Service\View\SovereignReservePageBuilder;
use PHPUnit\Framework\TestCase;

class SovereignReservePageBuilderTest extends TestCase
{
    private const TARGET_WEIGHT = 0.0314;

    /** @param list<Stock> $board */
    private function builder(array $board = []): SovereignReservePageBuilder
    {
        $stocks = $this->createStub(StockRepository::class);
        $stocks->method('findAll')->willReturn($board);

        return new SovereignReservePageBuilder($stocks, new SovereignFundSubsystem(new MathUtility()));
    }

    private function listing(string $ticker, float $price, float $shares, float $float = 1.0, bool $bankrupt = false): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker . ' Co');
        $stock->setSector('Industrials');
        $stock->setPrice((string) $price);
        $stock->setSharesOutstanding((string) $shares);
        $stock->setPublicFloatPercentage((string) $float);
        $stock->setIsBankrupt($bankrupt);

        return $stock;
    }

    private function incepted(float $weight = 0.0268, float $equityShare = 0.66, float $monthsLeft = 0.0, float $rebalanceShare = 0.0, float $lastRebalanceAt = -1.0): MacroStateDTO
    {
        return new MacroStateDTO(
            totalTime: 10.5,
            nominalGdpIndex: 120.0,
            lastSovereignRebalanceAt: $lastRebalanceAt,
            sovereignFundTargetWeight: self::TARGET_WEIGHT,
            sovereignFundDollarsPerGdp: 1.0e10,
            sovereignFundAnnualDraw: 2.3e10,
            sovereignFundRebalanceMonthsLeft: $monthsLeft,
            sovereignFundRebalanceShare: $rebalanceShare,
            sovereignFundToGdp: 1.6,
            sovereignFundDomesticWeight: $weight,
            sovereignFundEquityShare: $equityShare,
            sovereignFundOwnershipShare: 0.058,
            sovereignFundDrawToGdp: 0.0192,
            sovereignFundStampDutyYearToDate: 1.2e9,
            sovereignFundStampDutyToGdp: 0.0017,
            sovereignDebtToGdp: 0.81,
            sovereignNetDebtToGdp: 0.29,
            sovereignFundReturnIndex: 131.5,
            sovereignFundRealReturnIndex: 112.25,
            sovereignFundExpectedRealReturn: 0.0324,
            foreignBondYield: 0.0331,
        );
    }

    public function testBeforeInceptionThereIsNothingToHoldOrBand(): void
    {
        $stocks = $this->createMock(StockRepository::class);
        $stocks->expects($this->never())->method('findAll');

        $page = (new SovereignReservePageBuilder($stocks, new SovereignFundSubsystem(new MathUtility())))->build(new MacroStateDTO());

        $this->assertFalse($page['incepted']);
        $this->assertSame([], $page['sleeves']);
        $this->assertSame([], $page['bands']);
        $this->assertSame([], $page['holdings']);
        $this->assertSame([], $page['strategic']);
        $this->assertSame(0.0, $page['summary']['value']);
        $this->assertNull($page['programme']['monthsSinceLast']);
    }

    public function testValueIsThePublishedSizeCarriedBackThroughTheCurrencyBridge(): void
    {
        $page = $this->builder()->build($this->incepted());

        // sovereignFundToGdp = fund / (dollarsPerGdp x nominalGdpIndex), inverted.
        $this->assertEqualsWithDelta(1.6 * 1.0e10 * 120.0, $page['summary']['value'], 1.0);
        $this->assertSame(2.3e10, $page['summary']['annualDraw']);
        $this->assertSame(0.0017, $page['summary']['stampDutyToGdp']);
    }

    public function testReturnsAndDebtAreTheFundsPublishedReadings(): void
    {
        $page = $this->builder()->build($this->incepted());

        $this->assertSame(131.5, $page['returns']['index']);
        $this->assertSame(112.25, $page['returns']['realIndex']);
        $this->assertSame(0.0324, $page['returns']['assumedReal'], 'The return the draw was set from, to read the realized one against.');
        $this->assertSame(0.0331, $page['returns']['bondYield']);
        $this->assertSame(0.81, $page['summary']['grossDebtToGdp']);
        $this->assertSame(0.29, $page['summary']['netDebtToGdp']);
        $this->assertSame(SovereignFundSubsystem::FOREIGN_BOND_DURATION, $page['mandate']['foreignBondDuration']);
        $this->assertSame(MacroEngine::SOVEREIGN_DEBT_FLOOR, $page['mandate']['debtFloor']);
    }

    public function testSleevesPartitionTheFundAndItsPolicy(): void
    {
        $page = $this->builder()->build($this->incepted(0.0268, 0.66));
        $sleeves = array_column($page['sleeves'], null, 'key');

        $this->assertEqualsWithDelta(1.0, array_sum(array_column($page['sleeves'], 'weight')), 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($page['sleeves'], 'policy')), 1e-12);
        $this->assertEqualsWithDelta($page['summary']['value'], array_sum(array_column($page['sleeves'], 'value')), 1e-3);

        $this->assertSame(0.0268, $sleeves['district']['weight']);
        $this->assertEqualsWithDelta(0.66 - 0.0268, $sleeves['foreign-equities']['weight'], 1e-12);
        $this->assertEqualsWithDelta(0.34, $sleeves['foreign-bonds']['weight'], 1e-12);

        // Off the board the policy is the GIC 65/35 split of what is left.
        $this->assertSame(self::TARGET_WEIGHT, $sleeves['district']['policy']);
        $foreign = 1.0 - self::TARGET_WEIGHT;
        $this->assertEqualsWithDelta(SovereignFundSubsystem::FOREIGN_EQUITY_SHARE * $foreign, $sleeves['foreign-equities']['policy'], 1e-12);
        $this->assertEqualsWithDelta((1.0 - SovereignFundSubsystem::FOREIGN_EQUITY_SHARE) * $foreign, $sleeves['foreign-bonds']['policy'], 1e-12);
    }

    public function testBandsAreTheFundsOwn(): void
    {
        $fund = new SovereignFundSubsystem(new MathUtility());
        $page = $this->builder()->build($this->incepted());
        $bands = array_column($page['bands'], null, 'key');

        $equityPolicy = $fund->policyEquityShare(self::TARGET_WEIGHT);
        $this->assertSame(self::TARGET_WEIGHT, $bands['district']['policy']);
        $this->assertSame($fund->rebalanceBand(self::TARGET_WEIGHT), $bands['district']['band']);
        $this->assertSame($equityPolicy, $bands['equity']['policy']);
        $this->assertSame($fund->equityBand($equityPolicy), $bands['equity']['band']);
    }

    /** The quoted move is the one that breaches GPIF's own limit on GPIF's own weight: T - T(1-d)/(1-Td) = L. */
    public function testBreachingMoveTakesGpifsWeightExactlyToItsLimit(): void
    {
        $cases = [
            [SovereignFundSubsystem::GPIF_DOMESTIC_EQUITY_TARGET, SovereignFundSubsystem::GPIF_DOMESTIC_EQUITY_DEVIATION_LIMIT],
            [SovereignFundSubsystem::GPIF_GLOBAL_EQUITY_TARGET, SovereignFundSubsystem::GPIF_GLOBAL_EQUITY_DEVIATION_LIMIT],
        ];
        foreach ($cases as [$target, $limit]) {
            $move = SovereignFundSubsystem::breachingMove($target, $limit);
            $fallen = $target * (1.0 - $move) / (1.0 - ($target * $move));

            $this->assertEqualsWithDelta($limit, $target - $fallen, 1e-12);
        }

        $page = $this->builder()->build($this->incepted());
        $bands = array_column($page['bands'], null, 'key');
        $this->assertEqualsWithDelta(0.296, $bands['district']['breachingMove'], 0.001);
        $this->assertEqualsWithDelta(0.305, $bands['equity']['breachingMove'], 0.001);
    }

    public function testHoldingsAreTheOwnershipShareOfEveryFloatLargestFirst(): void
    {
        $board = [
            $this->listing('SMAL', 10.0, 1.0e6),
            $this->listing('BIGG', 50.0, 4.0e6, 0.5),
            $this->listing('DEAD', 30.0, 9.0e6, 1.0, true),
            $this->listing('MIDL', 20.0, 3.0e6),
        ];
        $page = $this->builder($board)->build($this->incepted());
        $holdings = $page['holdings'];

        $this->assertSame(['BIGG', 'MIDL', 'SMAL'], array_column($holdings, 'ticker'), 'Bankrupt names hold no float; the rest rank by float cap.');
        $this->assertSame([1, 2, 3], array_column($holdings, 'rank'));

        // BIGG: 50 x 4m x 0.5 = 100m of float.
        $this->assertEqualsWithDelta(0.058 * 1.0e8, $holdings[0]['stakeValue'], 1e-3);
        $this->assertEqualsWithDelta(0.058 * 2.0e6, $holdings[0]['stakeShares'], 1e-6);
        $this->assertEqualsWithDelta(1.0e8 / 1.7e8, $holdings[0]['sleeveWeight'], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($holdings, 'sleeveWeight')), 1e-12);
        $this->assertEqualsWithDelta(0.058 * 1.7e8, array_sum(array_column($holdings, 'stakeValue')), 1e-3);
    }

    public function testARunningProgrammeReadsItsDirectionAndWhatIsLeft(): void
    {
        $buying = $this->builder()->build($this->incepted(monthsLeft: 2.0, rebalanceShare: 0.004, lastRebalanceAt: 10.4))['programme'];
        $this->assertTrue($buying['active']);
        $this->assertTrue($buying['buying']);
        $this->assertSame(0.004, $buying['share']);
        $this->assertSame(2.0, $buying['monthsLeft']);

        $trimming = $this->builder()->build($this->incepted(monthsLeft: 3.0, rebalanceShare: -0.003, lastRebalanceAt: 10.5))['programme'];
        $this->assertTrue($trimming['active']);
        $this->assertFalse($trimming['buying']);
        $this->assertSame(0.003, $trimming['share']);
    }

    public function testAnIdleFundReportsHowLongSinceItsLastProgramme(): void
    {
        $idle = $this->builder()->build($this->incepted(lastRebalanceAt: 9.25))['programme'];
        $this->assertFalse($idle['active']);
        $this->assertEqualsWithDelta(15.0, $idle['monthsSinceLast'], 1e-9);

        $never = $this->builder()->build($this->incepted())['programme'];
        $this->assertNull($never['monthsSinceLast'], 'The -1 sentinel means no programme yet, not one 11 years ago.');
    }

    public function testTheStrategicStakeIsValuedOnEveryShareAndPaysTheFundAtTheCurrentRate(): void
    {
        $clearinghouse = $this->listing('ACC', 80.0, 1.0e9, 0.77);
        $clearinghouse->setLastDividend('0.60');
        $page = $this->builder([$clearinghouse, $this->listing('BIGG', 50.0, 4.0e6, 0.5)])->build($this->incepted());

        $this->assertCount(1, $page['strategic'], 'Only the companies the District holds.');
        $row = $page['strategic'][0];
        $shares = StrategicHoldings::CLEARINGHOUSE_STAKE * 1.0e9;
        $this->assertSame('ACC', $row['ticker']);
        $this->assertEqualsWithDelta($shares, $row['shares'], 1e-3);
        $this->assertEqualsWithDelta($shares * 80.0, $row['value'], 1e-3);
        $this->assertEqualsWithDelta($shares * 0.60 * 4.0, $row['annualDividend'], 1e-3, 'Four quarterly payments a year.');
        $this->assertEqualsWithDelta(0.058 * 0.77, $row['fundStake'], 1e-12, 'The fund holds its share of the float, not of the District\'s block.');

        // The fund's own holding of the same company is its float stake only.
        $holding = array_column($page['holdings'], null, 'ticker')['ACC'];
        $this->assertEqualsWithDelta(0.058 * 80.0 * 1.0e9 * 0.77, $holding['stakeValue'], 1e-3);
    }

    public function testAFailedCompanyCarriesNoStrategicStake(): void
    {
        $page = $this->builder([$this->listing('ACC', 80.0, 1.0e9, 0.77, true)])->build($this->incepted());

        $this->assertSame([], $page['strategic']);
    }
}
