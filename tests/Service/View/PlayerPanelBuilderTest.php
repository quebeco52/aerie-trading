<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\PriceAlert;
use App\Entity\User;
use App\Service\View\PlayerPanelBuilder;
use PHPUnit\Framework\TestCase;

/** The portfolio page's watchlist reads each name's waiting alerts from one list grouped by ticker. */
final class PlayerPanelBuilderTest extends TestCase
{
    public function testAlertsAreGroupedByTickerInTheOrderTheyCame(): void
    {
        $user = new User();
        $above = new PriceAlert($user, 'HUMM', 'ABOVE', '45.00', '41.07');
        $below = new PriceAlert($user, 'HUMM', 'BELOW', '38.00', '41.07');
        $other = new PriceAlert($user, 'LAKE', 'ABOVE', '90.00', '84.21');

        $byTicker = PlayerPanelBuilder::alertsByTicker([$above, $other, $below]);

        $this->assertSame(['HUMM', 'LAKE'], array_keys($byTicker));
        $this->assertSame([$above, $below], $byTicker['HUMM']);
        $this->assertSame([$other], $byTicker['LAKE']);
        $this->assertSame([], PlayerPanelBuilder::alertsByTicker([]));
    }
}
