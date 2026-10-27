<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Service\View\MarketWrapBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Markets page's month wrap: breadth, leaders, laggards and sector moves.
 */
#[Group('unit')]
final class MarketWrapBuilderTest extends TestCase
{
    public function testLeadersLaggardsAndBreadthLeaveOutTheFailedAndTheUnpriced(): void
    {
        $moves = MarketWrapBuilder::moves([
            $this->listing('AAA', 'Tech', 100.0, 0.08),
            $this->listing('BBB', 'Tech', 300.0, -0.02),
            $this->listing('CCC', 'Energy', 50.0, 0.0),
            $this->listing('DDD', 'Energy', 50.0, null),
            $this->listing('EEE', 'Energy', 50.0, -0.5, true),
        ]);

        $this->assertSame([1, 1, 1], [$moves['advancing'], $moves['declining'], $moves['unchanged']]);
        $this->assertSame(['AAA'], array_column($moves['leaders'], 'ticker'));
        $this->assertSame(['BBB'], array_column($moves['laggards'], 'ticker'));
    }

    /** A sector moves as its market value does: a large company's fall outweighs a small one's rise. */
    public function testASectorsMoveIsWeightedByMarketValue(): void
    {
        $moves = MarketWrapBuilder::moves([
            $this->listing('AAA', 'Tech', 100.0, 0.08),
            $this->listing('BBB', 'Tech', 300.0, -0.02),
            $this->listing('CCC', 'Energy', 50.0, 0.01),
        ]);

        $this->assertSame(['Energy', 'Tech'], array_column($moves['sectors'], 'name'));
        $this->assertEqualsWithDelta((100.0 * 0.08 - 300.0 * 0.02) / 400.0, $moves['sectors'][1]['change'], 1e-12);
    }

    /**
     * @return array{ticker: string, name: string, sector: string, marketCap: float, isBankrupt: bool, changePercent: float|null}
     */
    private function listing(string $ticker, string $sector, float $cap, ?float $change, bool $failed = false): array
    {
        return ['ticker' => $ticker, 'name' => $ticker, 'sector' => $sector, 'marketCap' => $cap, 'isBankrupt' => $failed, 'changePercent' => $change];
    }
}
