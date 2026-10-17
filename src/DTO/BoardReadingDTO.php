<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * What the economy reads of the board, one tick behind so the macro step and the board never feed each other inside a
 * tick. Its levels stand until the board is priced again; its flows are spent on the tick that reads them, so a tick
 * that prices no board cannot replay the last one's.
 */
final readonly class BoardReadingDTO
{
    public function __construct(
        /** What the board is worth, which household equity wealth is; null before the board is first priced. */
        public ?float $equityMarketCap = null,
        /** What a passive fund could buy of the board, which the sovereign fund's index weights read. */
        public ?float $floatCap = null,
        /** Float-weighted price return over the tick, before any split. */
        public float $priceReturn = 0.0,
        /** Float-adjusted dividend cash the board paid over the tick. */
        public float $dividendCash = 0.0,
        /** The companies' own issuance net of buybacks, as float at the tick's price. */
        public float $netIssuance = 0.0,
        /** Stamp duty the tick's trading paid, buyer and seller each. */
        public float $stampDuty = 0.0,
        /** Cash the District's strategic stakes paid it over the tick. */
        public float $strategicStakeCash = 0.0,
        /** What the board's banks owe a year in bank levy: a standing amount, so null keeps the last one booked. */
        public ?float $bankLevy = null,
    ) {}

    /**
     * What this tick's board, StockTracker::updateStocks()'s result, hands the next tick's economy. A board worth
     * nothing keeps the last levels it had.
     *
     * @param array{total_cap: float, board_float_cap: float, board_price_return: float, board_dividend_cash: float, board_net_issuance: float, board_stamp_duty: float, board_bank_levy: float, strategic_stake_cash: float} $board
     */
    public function after(array $board): self
    {
        return new self(
            $board['total_cap'] > 0.0 ? $board['total_cap'] : $this->equityMarketCap,
            $board['board_float_cap'] > 0.0 ? $board['board_float_cap'] : $this->floatCap,
            $board['board_price_return'],
            $board['board_dividend_cash'],
            $board['board_net_issuance'],
            $board['board_stamp_duty'],
            $board['strategic_stake_cash'],
            $board['board_bank_levy'],
        );
    }

    /** What is left once the economy has read it: the levels, without the flows. */
    public function spent(): self
    {
        return new self($this->equityMarketCap, $this->floatCap, bankLevy: $this->bankLevy);
    }
}
