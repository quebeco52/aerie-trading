<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Stock;
use App\Service\Market\CreditRatingAgency;
use PHPUnit\Framework\TestCase;

class CreditRatingAgencyTest extends TestCase
{
    private CreditRatingAgency $agency;

    protected function setUp(): void
    {
        $this->agency = new CreditRatingAgency();
    }

    public function testConvertDistanceToRatingBrackets(): void
    {
        $this->assertSame('AAA', $this->agency->convertDistanceToRating(4.0));
        $this->assertSame('AAA', $this->agency->convertDistanceToRating(3.5, 'AAA'));
        $this->assertSame('AA', $this->agency->convertDistanceToRating(3.2));
        $this->assertSame('A', $this->agency->convertDistanceToRating(2.7));
        $this->assertSame('BBB', $this->agency->convertDistanceToRating(2.1));
        $this->assertSame('BB', $this->agency->convertDistanceToRating(1.8));
        $this->assertSame('B', $this->agency->convertDistanceToRating(1.2));
        $this->assertSame('CCC', $this->agency->convertDistanceToRating(0.6));
        $this->assertSame('D', $this->agency->convertDistanceToRating(0.2));
        $this->assertSame('D', $this->agency->convertDistanceToRating(-1.0));
    }

    public function testEvaluateRatingUpdatesStockWhenRatingChanges(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setCreditRating('BBB');

        // High Distance to Default -> Upgrade is clamped to 1 notch per evaluation ('A')
        $transition = $this->agency->evaluateRating($stock, 3.8);

        $this->assertSame('A', $transition);
        $this->assertSame('A', $stock->getCreditRating());
        $this->assertFalse($this->agency->isDowngrade('BBB', 'A'));

        // Next evaluation continues climbing to AA
        $transitionNext = $this->agency->evaluateRating($stock, 3.8);
        $this->assertSame('AA', $transitionNext);
        $this->assertSame('AA', $stock->getCreditRating());
    }

    public function testEvaluateRatingReturnsNullWhenNoChange(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setCreditRating('BBB');

        // Distance to Default in BBB bracket (2.0 <= d2 < 2.5) -> No change
        $transition = $this->agency->evaluateRating($stock, 2.3);

        $this->assertNull($transition);
        $this->assertSame('BBB', $stock->getCreditRating());
    }

    public function testIsDowngradeDetection(): void
    {
        $this->assertTrue($this->agency->isDowngrade('BBB', 'BB'));
        $this->assertTrue($this->agency->isDowngrade('AA', 'CCC'));
        $this->assertFalse($this->agency->isDowngrade('BB', 'BBB'));
        $this->assertFalse($this->agency->isDowngrade('A', 'AAA'));
    }
}
