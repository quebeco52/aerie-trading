<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * One quarter's roll-forward of a financial's investment securities book.
 *
 * The book is carried at amortized cost and the curve moves underneath it. Two numbers come out of that,
 * and they are not the same number: the part that reaches reported equity, and the part that does not.
 *
 * Available-for-sale is remarked through other comprehensive income (ASC 320): it is inside total equity,
 * so book value per share and every P/B test see it immediately. Held-to-maturity is carried at cost and
 * the loss is disclosed and nothing else — it impairs the firm economically while reported equity stands
 * exactly where it did. That gap is the whole point of the split. A firm can be adequately capitalized on
 * every published figure right up to the quarter it is forced to sell, and selling is what converts the
 * disclosure into a realized loss.
 *
 * None of this is income. The mark never reaches EBIT, EPS or the consensus surprise until a sale
 * crystallizes it.
 */
final readonly class SecuritiesMarkDTO
{
    /**
     * @param float $bookValue        Amortized cost of the duration-bearing securities book this quarter.
     * @param float $totalMark        Mark on the whole book against amortized cost; negative is a loss.
     * @param float $afsMark          The available-for-sale share of $totalMark, which belongs in total equity.
     * @param float $htmMark          The held-to-maturity share, disclosed only and absent from reported equity.
     * @param float $afsEquityDelta   Change in the AFS mark since the last roll: what to move on total equity now.
     * @param float $carryingYield    Blended yield the book is carried at after this quarter's reinvestment.
     * @param float $effectiveDuration Modified duration actually used for the mark, after the floating-rate share.
     */
    public function __construct(
        public float $bookValue,
        public float $totalMark,
        public float $afsMark,
        public float $htmMark,
        public float $afsEquityDelta,
        public float $carryingYield,
        public float $effectiveDuration,
    ) {}
}
