<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\Bond;
use App\Entity\Etf;
use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Entity\UserBond;
use App\Entity\UserEtf;
use App\Entity\UserOption;
use App\Entity\UserStock;
use App\Service\Math\FinancialConstants;
use App\Service\View\DashboardPageBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's arithmetic: each position against its cost, the book by sector gross of shorts, and net worth by
 * asset class adding up to the whole.
 */
final class DashboardPageBuilderTest extends TestCase
{
    private static function stock(string $ticker, string $sector, string $price): Stock
    {
        return (new Stock())->setTicker($ticker)->setName($ticker . ' Holdings')->setSector($sector)->setPrice($price);
    }

    /** A long and a short in one sector are both exposure to it, and the shares of the book add to 100. */
    public function testTheSectorsAreGrossOfShorts(): void
    {
        $rows = DashboardPageBuilder::stockRows([
            (new UserStock())->setStock(self::stock('APX', 'Tech', '50.00'))->setQuantity(100),
            (new UserStock())->setStock(self::stock('BRT', 'Tech', '20.00'))->setQuantity(-100),
            (new UserStock())->setStock(self::stock('CLM', 'Energy', '10.00'))->setQuantity(300),
        ], ['APX' => 40.0, 'BRT' => 25.0], []);

        $sectors = array_column(DashboardPageBuilder::sectorBreakdown($rows), null, 'name');

        $this->assertSame(7000.0, $sectors['Tech']['value'], 'A $2,000 short adds to the sector, not nets against it.');
        $this->assertSame(3000.0, $sectors['Energy']['value']);
        $this->assertEqualsWithDelta(100.0, $sectors['Tech']['percent'] + $sectors['Energy']['percent'], 1e-9);

        $short = array_column($rows, null, 'ticker')['BRT'];
        $this->assertTrue($short['isShort']);
        $this->assertSame(-2000.0, $short['marketValue'], 'A short is marked as the obligation.');
        $this->assertSame(500.0, $short['unrealizedPnL'], 'Sold at 25, worth 20: a gain.');
        $this->assertEqualsWithDelta(20.0, $short['unrealizedPnLPercent'], 1e-9, 'Against what the short raised, not a negative cost.');
    }

    /** A written contract is a liability whose decay is profit, and it ties up its premium in the invested cost. */
    public function testAWrittenOptionIsALiabilityThatGainsAsItDecays(): void
    {
        $contract = (new OptionContract())->setTicker('APX-C-60')->setStock(self::stock('APX', 'Tech', '50.00'))
            ->setOptionType(OptionContract::TYPE_CALL)->setStrike('60.00')->setPrice('1.50');
        $rows = DashboardPageBuilder::optionRows([(new UserOption())->setContract($contract)->setQuantity(-2)->setAveragePremium('2.50')]);

        $shares = 2 * FinancialConstants::OPTION_CONTRACT_MULTIPLIER;
        $this->assertSame(-1.50 * $shares, $rows[0]['marketValue']);
        $this->assertSame(1.00 * $shares, $rows[0]['unrealizedPnL'], 'Written at 2.50, worth 1.50.');
        $this->assertEqualsWithDelta(40.0, $rows[0]['unrealizedPnLPercent'], 1e-9);
        $this->assertSame('APX Call $60.00', $rows[0]['name']);

        $totals = DashboardPageBuilder::markedTotals($rows);
        $this->assertSame(2.50 * $shares, $totals['cost'], 'Premium written counts at what it tied up.');
        $this->assertSame(-1.50 * $shares, $totals['value']);
        $this->assertSame(1.00 * $shares, $totals['pnl'], 'The header gain is the decay, not the liability less the premium.');
    }

    /**
     * A hedged book earns on both legs. Netting the short's proceeds against the long's cost left a basis of nothing,
     * and the header printed +0.00% on a real gain; the return is on the gross capital the positions put at risk.
     */
    public function testAHedgedBookShowsItsReturnOnGrossCapital(): void
    {
        $rows = DashboardPageBuilder::stockRows([
            (new UserStock())->setStock(self::stock('LNG', 'Tech', '110.00'))->setQuantity(100),
            (new UserStock())->setStock(self::stock('SHT', 'Tech', '90.00'))->setQuantity(-100),
        ], ['LNG' => 100.0, 'SHT' => 100.0], []);

        $totals = DashboardPageBuilder::markedTotals($rows);

        $this->assertSame(2000.0, $totals['pnl'], 'Up 1,000 on the long, 1,000 on the short.');
        $this->assertSame(20000.0, $totals['basis']);
    }

    /** Index funds and bonds take their own sector lines, and a bond's line names its issuer's kind. */
    public function testFundsAndBondsTakeTheirOwnLines(): void
    {
        $rows = [
            ...DashboardPageBuilder::etfRows([(new UserEtf())->setEtf((new Etf())->setTicker('LB30')->setName('Lakebird 30')->setPrice('100.00'))->setQuantity(10)], [], ['LB30' => 12.0]),
            ...DashboardPageBuilder::bondRows([
                (new UserBond())->setBond((new Bond())->setTicker('AT-10Y')->setName('Treasury 10y')->setPrice('98.00'))->setQuantity(5),
                (new UserBond())->setBond((new Bond())->setTicker('APX-5Y')->setName('Apex 5y')->setPrice('101.00')->setIssuer(self::stock('APX', 'Tech', '50.00')))->setQuantity(5),
            ], ['AT-10Y' => 100.0], ['AT-10Y' => 3.0]),
        ];

        $names = array_column(DashboardPageBuilder::sectorBreakdown($rows), 'name');
        sort($names);
        $this->assertSame(['Corporate debt', 'Market Index (ETF)', 'Sovereign debt'], $names);

        $byTicker = array_column($rows, null, 'ticker');
        $this->assertSame(12.0, $byTicker['LB30']['dividendsReceived']);
        $this->assertSame(3.0, $byTicker['AT-10Y']['dividendsReceived'], 'Coupons are a bond\'s income.');
        $this->assertSame(-10.0, $byTicker['AT-10Y']['unrealizedPnL']);
        $this->assertSame(0.0, $byTicker['APX-5Y']['unrealizedPnL'], 'With no recorded cost, a position is held at its price.');
    }

    /** Net worth by asset class adds up to the whole, the margin loan a negative slice, and empty classes hidden. */
    public function testTheAllocationAddsUpToNetWorth(): void
    {
        $netWorth = ['cash_balance' => 4000.0, 'margin_debit' => 1000.0, 'stock_value' => 6000.0, 'etf_value' => 0.0, 'bond_value' => 500.0,
            'option_value' => -300.0, 'escrow_value' => 800.0, 'total_value' => 10000.0];

        $allocation = DashboardPageBuilder::allocation($netWorth);

        $this->assertSame(['Stocks', 'Bonds', 'Options', 'In open orders', 'Cash', 'Margin loan'], array_column($allocation, 'label'));
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($allocation, 'share')), 1e-12);
        $this->assertSame(-0.1, array_column($allocation, 'share', 'label')['Margin loan']);
        $this->assertSame('bg-tertiary', array_column($allocation, 'colour', 'label')['Margin loan']);
        $this->assertNull(DashboardPageBuilder::allocation(['total_value' => 0.0] + $netWorth)[0]['share'], 'No net worth, no shares.');
    }

    /** Each position's weight is its share of net worth, and the table runs largest first. */
    public function testPositionsAreWeightedOnNetWorthLargestFirst(): void
    {
        $rows = DashboardPageBuilder::weighted(DashboardPageBuilder::stockRows([
            (new UserStock())->setStock(self::stock('SML', 'Tech', '10.00'))->setQuantity(10),
            (new UserStock())->setStock(self::stock('BIG', 'Tech', '10.00'))->setQuantity(400),
        ], [], []), 8000.0);

        $this->assertSame(['BIG', 'SML'], array_column($rows, 'ticker'));
        $this->assertSame(50.0, $rows[0]['weight']);
    }

    /** A short is sized by what it owes: a large short is the largest position, not the last. */
    public function testALargeShortIsTheLargestPosition(): void
    {
        $rows = DashboardPageBuilder::weighted(DashboardPageBuilder::stockRows([
            (new UserStock())->setStock(self::stock('LNG', 'Tech', '10.00'))->setQuantity(1000),
            (new UserStock())->setStock(self::stock('SHT', 'Tech', '10.00'))->setQuantity(-5000),
        ], [], []), 60000.0);

        $this->assertSame(['SHT', 'LNG'], array_column($rows, 'ticker'));
        $this->assertEqualsWithDelta(-83.33, $rows[0]['weight'], 0.01);
    }
}
