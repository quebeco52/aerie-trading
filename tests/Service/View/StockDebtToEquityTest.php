<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\Stock;
use PHPUnit\Framework\TestCase;

/** The debt-to-equity figure the stock page, the screener and the live feed show. */
class StockDebtToEquityTest extends TestCase
{
    public function testItIsDebtOverBookEquity(): void
    {
        $stock = (new Stock())->setTotalEquity('4000000000')->setWholesaleDebt('6000000000');

        $this->assertEqualsWithDelta((float) $stock->getTotalDebt() / 4.0e9, $stock->getDebtToEquity(), 1e-12);
    }

    /** Debt over negative equity once printed as debt over $1, a ratio in the billions. */
    public function testNegativeEquityHasNoRatio(): void
    {
        $stock = (new Stock())->setTotalEquity('-500000000')->setWholesaleDebt('5000000000');

        $this->assertNull($stock->getDebtToEquity());
        $this->assertGreaterThan(1.0e9, (float) $stock->getDebtToEquityRatio(), 'the deal screen keeps reading it as maximum leverage');
    }
}
