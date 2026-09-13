<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Entity\TradeOrder;
use App\Service\User\CostBasisCalculator;
use App\Service\User\Portfolio;
use PHPUnit\Framework\TestCase;

/**
 * The three things a signed quantity breaks silently.
 *
 * Allowing user_stocks.quantity to go negative makes the mark-to-market arithmetic work in both directions
 * for free, which is exactly what makes the failures here hard to see: nothing throws, the numbers are
 * plausible, and they are wrong. Cost basis inverts, net asset value forgets the borrowed cash, and a
 * dividend gets credited to someone who actually owes it.
 */
class ShortPositionAccountingTest extends TestCase
{
    private CostBasisCalculator $costBasis;

    protected function setUp(): void
    {
        $this->costBasis = new CostBasisCalculator();
    }

    private function order(string $action, int $quantity, string $price, string $ticker = 'APEX'): TradeOrder
    {
        $order = new TradeOrder();
        $order->setTicker($ticker);
        $order->setAction($action);
        $order->setOrderType('MARKET');
        $order->setQuantity($quantity);
        $order->setFilledQuantity($quantity);
        $order->setExecutionPrice($price);
        $order->setStatus('FILLED');

        return $order;
    }

    // --- Cost basis ---

    public function testAShortsBasisIsWhatItWasSoldFor(): void
    {
        // Not zero. A short reporting a zero basis shows its entire market value as profit.
        $basis = $this->costBasis->calculate([$this->order('SHORT', 100, '50.00')]);

        $this->assertSame(50.0, $basis['APEX']);
    }

    public function testAveragingWorksTheSameWayOnTheShortSide(): void
    {
        $basis = $this->costBasis->calculate([
            $this->order('SHORT', 100, '50.00'),
            $this->order('SHORT', 100, '70.00'),
        ]);

        $this->assertSame(60.0, $basis['APEX']);
    }

    public function testCoveringLeavesTheAverageProceedsUnchanged(): void
    {
        // The same property the long side has: closing part of a position at any price does not rewrite
        // what the rest was opened at.
        $basis = $this->costBasis->calculate([
            $this->order('SHORT', 100, '50.00'),
            $this->order('SHORT', 100, '70.00'),
            $this->order('COVER', 50, '90.00'),
        ]);

        $this->assertSame(60.0, $basis['APEX']);
    }

    public function testAFullyCoveredShortLeavesNoPosition(): void
    {
        $basis = $this->costBasis->calculate([
            $this->order('SHORT', 100, '50.00'),
            $this->order('COVER', 100, '40.00'),
        ]);

        $this->assertArrayNotHasKey('APEX', $basis);
    }

    public function testALongAndAShortInDifferentNamesDoNotContaminateEachOther(): void
    {
        $basis = $this->costBasis->calculate([
            $this->order('BUY', 100, '25.00', 'LONGCO'),
            $this->order('SHORT', 100, '80.00', 'SHORTCO'),
        ]);

        $this->assertSame(25.0, $basis['LONGCO']);
        $this->assertSame(80.0, $basis['SHORTCO']);
    }

    public function testCoveringAPositionThatIsNotShortChangesNothing(): void
    {
        // A COVER against a long is not a sale; it should not silently drain the position.
        $basis = $this->costBasis->calculate([
            $this->order('BUY', 100, '25.00'),
            $this->order('COVER', 50, '30.00'),
        ]);

        $this->assertSame(25.0, $basis['APEX']);
    }

    public function testTheAverageIsAPriceNotASignedExposure(): void
    {
        // Cost and quantity carry the same sign on both sides, so the quotient is positive either way.
        foreach ($this->costBasis->calculate([$this->order('SHORT', 100, '50.00')]) as $average) {
            $this->assertGreaterThan(0.0, $average);
        }
    }

    // --- Net asset value ---

    public function testEveryNetWorthQuerySubtractsTheMarginDebit(): void
    {
        // Borrowed cash is spent but still owed. Left in, a leveraged account reads as richer than an
        // identical unleveraged one at exactly the moment the difference matters.
        $sources = [
            'src/Service/User/Portfolio.php',
            'src/Controller/LeaderboardController.php',
            'src/Controller/DashboardController.php',
        ];

        foreach ($sources as $source) {
            $contents = (string) file_get_contents(\dirname(__DIR__, 3) . '/' . $source);

            $this->assertMatchesRegularExpression(
                '/margin_debit|getMarginDebit/',
                $contents,
                "{$source} totals a user's net worth and must subtract borrowed cash."
            );
        }
    }

    public function testTheEscrowFragmentStillValuesEveryAssetClass(): void
    {
        // Guarded here as well as in PortfolioEscrowTest, because the short work touched the same SQL.
        $this->assertStringContainsString('COALESCE(s.price, e.price, b.price, 0)', Portfolio::OPEN_ORDER_ESCROW_SQL);
    }
}
