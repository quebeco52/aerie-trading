<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Company\Sectors;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Firms whose suppliers or customers fund more than they tie up still hold the stock and the receivables their
 * streams carry, and a firm only partly in stores leases floor space for that part. Each case is a listed firm,
 * read through its own tuning.
 */
class WorkingCapitalByStreamTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> ticker, industry, whether its streams hold goods */
    public static function negativeCycleFirms(): array
    {
        return [
            'WEAV marketplace with a shop' => ['WEAV', 'Internet Retail', true],
            'JDAW marketplace only' => ['JDAW', 'Internet Retail', false],
            'HUMM ads and cloud' => ['HUMM', 'Internet Content & Information', false],
            'TICK terminals' => ['TICK', 'Financial Data & Stock Exchanges', false],
            'SHRK ratings' => ['SHRK', 'Financial Data & Stock Exchanges', false],
            'BREW company cafes' => ['BREW', 'Restaurants', true],
            'SWFT franchisor' => ['SWFT', 'Restaurants', true],
            'LARK household brands' => ['LARK', 'Household & Personal Products', true],
            'PHIL tobacco' => ['PHIL', 'Tobacco', true],
            'PINT distiller' => ['PINT', 'Beverages - Wineries & Distilleries', true],
        ];
    }

    /**
     * Receivables are what a customer owes, inventory what a stream holds to sell. Neither vanishes because the
     * payables float is larger: only a stream that sells no goods carries no stock.
     */
    #[DataProvider('negativeCycleFirms')]
    public function testAFirmHoldsTheBalancesItsStreamsCarry(string $ticker, string $industry, bool $holdsGoods): void
    {
        $stock = self::firm($ticker, $industry);
        $days = Sectors::strategyFor($industry)->getWorkingCapitalDays($stock);

        $this->assertGreaterThan(3.0, $days['dso'], "{$ticker} is owed by its customers.");
        $this->assertGreaterThan(0.0, $days['dpo'], "{$ticker} owes its suppliers.");
        if ($holdsGoods) {
            $this->assertGreaterThan(0.0, $days['dio'], "{$ticker} holds stock to sell.");
        } else {
            $this->assertSame(0.0, $days['dio'], "{$ticker} sells no goods and holds none.");
        }
    }

    /**
     * The net intensity the merger and opening paths read is the same cycle the ledger books: the balances built
     * from the days at the firm's margin net to it.
     */
    #[DataProvider('negativeCycleFirms')]
    public function testTheLedgerNetsToTheDeclaredIntensity(string $ticker, string $industry, bool $holdsGoods): void
    {
        $stock = self::firm($ticker, $industry);
        $strategy = Sectors::strategyFor($industry);

        $net = (new CorporateMetrics())->buildWorkingCapitalBalances($stock, $strategy->getWorkingCapitalDays($stock), 1_000.0, 1_000.0 * (1.0 - 0.25));

        $this->assertEqualsWithDelta($strategy->getWorkingCapitalIntensity($stock) * 1_000.0, $net, 1e-6);
    }

    /** The fulfilment centres are leased for the shop, so a marketplace that holds no stock leases offices only. */
    public function testOnlineLeasesFollowTheFirstPartyShop(): void
    {
        $strategy = Sectors::strategyFor('Internet Retail');
        $marketplace = $strategy->getLeaseIntensity(self::firm('JDAW', 'Internet Retail'));
        $withShop = $strategy->getLeaseIntensity(self::firm('WEAV', 'Internet Retail'));

        // JDAW: 80% marketplace at the default, 20% ads on data-centre leases.
        $this->assertEqualsWithDelta(0.80 * FinancialConstants::DEFAULT_LEASE_LIABILITY_INTENSITY + 0.20 * 0.10, $marketplace, 1e-12);
        $this->assertGreaterThan($marketplace + 0.05, $withShop, 'A 30% first-party shop adds its warehouses.');
    }

    /** A mill and a wholesale book lease little; only the stores carry a retailer's lease. */
    public function testApparelLeasesFollowTheStores(): void
    {
        $lease = Sectors::strategyFor('Apparel Manufacturing')->getLeaseIntensity(self::firm('SHER', 'Apparel Manufacturing'));

        // SHER: 30% direct to consumer, 70% wholesale and contract supply.
        $this->assertEqualsWithDelta(0.30 * 0.30 + 0.70 * FinancialConstants::DEFAULT_LEASE_LIABILITY_INTENSITY, $lease, 1e-12);
    }

    /** A brand owner makes goods and ships them to retailers; it does not lease the shelves they sit on. */
    public function testABrandOwnerLeasesAtAManufacturersLevel(): void
    {
        $this->assertSame(
            FinancialConstants::DEFAULT_LEASE_LIABILITY_INTENSITY,
            Sectors::strategyFor('Household & Personal Products')->getLeaseIntensity(self::firm('LARK', 'Household & Personal Products'))
        );
    }

    private static function firm(string $ticker, string $industry): Stock
    {
        return (new Stock())->setTicker($ticker)->setIndustry($industry)->setOperatingMargin('0.25');
    }
}
