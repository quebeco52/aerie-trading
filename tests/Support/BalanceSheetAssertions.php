<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Stock;

/**
 * The accounting identity a firm's balance sheet is held to after any corporate event: assets equal liabilities plus
 * equity. A capitalized lease sits on both sides, so a test passes the one it resolved or leaves it out.
 */
trait BalanceSheetAssertions
{
    /**
     * The tolerance is relative to total assets with an absolute floor, so a $50B sheet and a $1,000 one are held to
     * the same precision; a test that means an absolute tolerance passes a relative one of zero.
     */
    private function assertBalanceSheetBalances(
        Stock $stock,
        string $message = 'assets equal liabilities plus equity',
        float $lease = 0.0,
        float $relativeTolerance = 1e-6,
        float $absoluteTolerance = 1.0,
    ): void {
        $assets = $stock->getTotalAssets($lease);
        $claims = $stock->getTotalLiabilities($lease) + (float) $stock->getTotalEquity();
        $tolerance = max($absoluteTolerance, abs($assets) * $relativeTolerance);
        $this->assertEqualsWithDelta($assets, $claims, $tolerance, $message);
    }
}
