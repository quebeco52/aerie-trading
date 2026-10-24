<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ScreenerController;
use PHPUnit\Framework\TestCase;

/**
 * The screener flags leverage past the covenant zone and profit running ahead of cash, and nothing it cannot measure.
 */
final class ScreenerControllerUnitTest extends TestCase
{
    public function testHighLeverageAndWeakCashAreFlagged(): void
    {
        $this->assertSame(['leverageCaution' => true, 'cashCaution' => true], ScreenerController::cautions(4.5, 0.6));
        $this->assertSame(['leverageCaution' => false, 'cashCaution' => false], ScreenerController::cautions(4.0, 0.8), 'The lines themselves are not past them.');
    }

    public function testABlankRatioFlagsNothing(): void
    {
        $this->assertSame(['leverageCaution' => false, 'cashCaution' => false], ScreenerController::cautions(null, null));
    }
}
