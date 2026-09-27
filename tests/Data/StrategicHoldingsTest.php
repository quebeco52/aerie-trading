<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\AnchorHoldings;
use App\Data\InitialMarket;
use App\Data\StrategicHoldings;
use PHPUnit\Framework\TestCase;

class StrategicHoldingsTest extends TestCase
{
    public function testEveryStakeIsInACompanyOnTheBoard(): void
    {
        $listed = array_column(InitialMarket::STOCKS, 'ticker');

        foreach (array_keys(StrategicHoldings::STAKES) as $ticker) {
            $this->assertContains($ticker, $listed);
        }
    }

    /** The stake and the float are one fact: the clearinghouse's float is what the District does not hold. */
    public function testTheClearinghouseFloatIsWhatTheDistrictDoesNotHold(): void
    {
        $rows = array_column(InitialMarket::STOCKS, null, 'ticker');
        $declared = (float) $rows['ACC']['public_float'];

        $this->assertSame(StrategicHoldings::CLEARINGHOUSE_STAKE, StrategicHoldings::stake('ACC'));
        $this->assertSame(StrategicHoldings::CLEARINGHOUSE_STAKE, AnchorHoldings::closelyHeldShare('ACC'));
        $this->assertEqualsWithDelta(1.0 - StrategicHoldings::CLEARINGHOUSE_STAKE, AnchorHoldings::tradableFloat('ACC', $declared), 1e-12);
    }

    public function testACompanyTheDistrictDoesNotHoldCarriesNoStake(): void
    {
        $this->assertSame(0.0, StrategicHoldings::stake('HUMM'));
    }
}
