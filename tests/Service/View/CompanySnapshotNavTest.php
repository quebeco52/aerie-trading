<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\AnchorHoldings;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Macro\MacroEngine;
use App\Service\Market\MarketEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\Service\View\CompanySnapshotBuilder;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Premium and discount to net asset value: the headline number on every closed-end factsheet, and the one
 * reading a player needs to trade the class at all.
 */
#[AllowMockObjectsWithoutExpectations]
class CompanySnapshotNavTest extends TestCase
{
    /** @param list<Stock> $held */
    private function builder(array $held): CompanySnapshotBuilder
    {
        $math = new MathUtility();
        $stocks = $this->createMock(StockRepository::class);
        $stocks->method('findBy')->willReturn($held);

        return new CompanySnapshotBuilder(
            new MarketEngine($math),
            new DebtEngine($math, CorporateMetrics::getInstance()),
            new AnchorStakeLedger(),
            $stocks
        );
    }

    /** @return list<Stock> */
    private function board(float $capEach): array
    {
        $board = [];

        foreach (array_keys(AnchorHoldings::forHolder('BRKW')) as $ticker) {
            $board[] = StockBuilder::create($ticker)
                ->withPrice($capEach / 1_000_000_000.0)
                ->withSharesOutstanding(1_000_000_000)
                ->build();
        }

        return $board;
    }

    private function sphere(float $price): Stock
    {
        return StockBuilder::create('BRKW')
            ->withPrice($price)
            ->withSharesOutstanding(1_000_000_000)
            ->withTotalEquity(1_880_000_000_000.0)
            ->build()
            ->setIndustry('Investment Companies');
    }

    public function testASphereIsQuotedAgainstItsAssets(): void
    {
        $sphere = $this->sphere(1_316.0);
        $sphere->setListedStakesCarrying('1118700000000');

        $snapshot = $this->builder($this->board(200_000_000_000.0))->build($sphere, new MacroStateDTO());

        $this->assertNotNull($snapshot['netAssetValue']);
        $this->assertGreaterThan(0.0, $snapshot['netAssetValue']['perShare']);
        // Trading below its assets reads as a discount, signed the way a factsheet signs it.
        $this->assertLessThan(0.0, $snapshot['netAssetValue']['premium']);
    }

    /** The portfolio is priced off the board, not read from the last filing, because it has moved since. */
    public function testTheQuotedNavFollowsTheHoldingsNotTheLastFiling(): void
    {
        $sphere = $this->sphere(1_316.0);
        $sphere->setListedStakesCarrying('1118700000000');

        $filed = $this->builder($this->board(200_000_000_000.0))->build($sphere, new MacroStateDTO());
        $crashed = $this->builder($this->board(120_000_000_000.0))->build($sphere, new MacroStateDTO());

        $this->assertLessThan(
            $filed['netAssetValue']['perShare'],
            $crashed['netAssetValue']['perShare'],
            'A 40% fall in the holdings must show up in the quoted net asset value.'
        );
    }

    /** An operating company has no net asset value to be quoted against, and is shown none. */
    public function testAnOperatingCompanyIsShownNoNetAssetValue(): void
    {
        $ordinary = StockBuilder::create('CBIL')->withPrice(100.0)->build()->setIndustry('Tools & Accessories');

        $snapshot = $this->builder([])->build($ordinary, new MacroStateDTO());

        $this->assertNull($snapshot['netAssetValue']);
    }

    /** A delisted shell has no claim left to value, so it is quoted nothing at all. */
    public function testABankruptShellIsQuotedNothing(): void
    {
        $sphere = $this->sphere(1_316.0);
        $sphere->setListedStakesCarrying('1118700000000');
        $sphere->setIsBankrupt(true);

        $snapshot = $this->builder($this->board(200_000_000_000.0))->build($sphere, new MacroStateDTO());

        $this->assertNull($snapshot['netAssetValue']);
    }
}
