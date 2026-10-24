<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\BoardReadingDTO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * What the economy reads of the board, one tick behind: levels stand until the board is priced again, flows are
 * spent on the tick that reads them, and the bank levy is a standing amount.
 */
#[Group('unit')]
final class BoardReadingTest extends TestCase
{
    /**
     * @return array{total_cap: float, board_float_cap: float, board_price_return: float, board_dividend_cash: float, board_net_issuance: float, board_stamp_duty: float, board_bank_levy: float, strategic_stake_cash: float}
     */
    private static function board(float $cap, float $floatCap): array
    {
        return [
            'total_cap' => $cap,
            'board_float_cap' => $floatCap,
            'board_price_return' => 0.01,
            'board_dividend_cash' => 2.0e6,
            'board_net_issuance' => -3.0e6,
            'board_stamp_duty' => 4.0e5,
            'board_bank_levy' => 5.0e7,
            'strategic_stake_cash' => 6.0e5,
        ];
    }

    public function testAPricedBoardHandsOverItsLevelsAndFlows(): void
    {
        $reading = (new BoardReadingDTO())->after(self::board(1.0e12, 8.0e11));

        $this->assertSame(1.0e12, $reading->equityMarketCap);
        $this->assertSame(8.0e11, $reading->floatCap);
        $this->assertSame(0.01, $reading->priceReturn);
        $this->assertSame(2.0e6, $reading->dividendCash);
        $this->assertSame(-3.0e6, $reading->netIssuance);
        $this->assertSame(4.0e5, $reading->stampDuty);
        $this->assertSame(6.0e5, $reading->strategicStakeCash);
        $this->assertSame(5.0e7, $reading->bankLevy);
    }

    public function testABoardWorthNothingKeepsTheLastLevels(): void
    {
        $reading = (new BoardReadingDTO())->after(self::board(1.0e12, 8.0e11))->after(self::board(0.0, 0.0));

        $this->assertSame(1.0e12, $reading->equityMarketCap);
        $this->assertSame(8.0e11, $reading->floatCap);
    }

    public function testASpentReadingKeepsTheLevelsAndTheLevyButNotTheFlows(): void
    {
        $spent = (new BoardReadingDTO())->after(self::board(1.0e12, 8.0e11))->spent();

        $this->assertSame(1.0e12, $spent->equityMarketCap);
        $this->assertSame(8.0e11, $spent->floatCap);
        $this->assertSame(5.0e7, $spent->bankLevy, 'The levy is a standing bill, not a flow.');
        $this->assertSame(0.0, $spent->priceReturn);
        $this->assertSame(0.0, $spent->dividendCash);
        $this->assertSame(0.0, $spent->netIssuance);
        $this->assertSame(0.0, $spent->stampDuty);
        $this->assertSame(0.0, $spent->strategicStakeCash);
    }

    public function testNoBoardYetReportsNoLevels(): void
    {
        $empty = new BoardReadingDTO();

        $this->assertNull($empty->equityMarketCap);
        $this->assertNull($empty->floatCap);
        $this->assertNull($empty->bankLevy, 'Null keeps whatever levy the economy last booked.');
    }
}
