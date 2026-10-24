<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Deal;

use App\Entity\Stock;

/** A listed firm with every operating ledger open, and the balance-sheet check both sides of a deal are held to. */
trait DealFixtures
{
    private function buildLedgeredAcquirer(string $ticker, float $treasury, float $debt, float $shares): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName("{$ticker} Corp");
        $stock->setIndustry('Technology');
        $stock->setPrice('100.00');
        $stock->setSharesOutstanding((string) $shares);
        $stock->setCorporateTreasury((string) $treasury);
        $stock->setWholesaleDebt((string) $debt);
        $stock->setTotalRevenue('20000000000');
        $stock->setOperatingMargin('0.25');
        $stock->setRetainedEarnings('5000000000');
        $stock->setEarningsPerShare('1.00');
        $stock->setBaselineRoic('0.15');
        $stock->setRoicTtm('0.15');

        $stock->setReceivables('3000000000');
        $stock->setReceivablesAllowance('50000000');
        $stock->setInventory('2000000000');
        $stock->setPayables('1500000000');
        $stock->setGrossPpe('12000000000');
        $stock->setAccumulatedDepreciation('4000000000');
        $stock->setPpeTaxBasis('6000000000');
        $stock->setCipBalance('500000000');
        $stock->setGoodwill('1000000000');
        $stock->setDeferredTaxLiability('400000000');

        $stock->setTotalEquity((string) ($stock->getTotalAssets() - $stock->getTotalLiabilities()));

        return $stock;
    }

    /** Assets equal liabilities plus equity. The lease is the same figure on both sides, so it is left out. */
    private function assertBalanced(Stock $stock, string $context): void
    {
        $assets = $stock->getTotalAssets();
        $claims = $stock->getTotalLiabilities() + (float) $stock->getTotalEquity();
        $this->assertEqualsWithDelta($assets, $claims, max(1.0, abs($assets) * 1e-9), "Balance sheet failed to balance after {$context}");
    }
}
