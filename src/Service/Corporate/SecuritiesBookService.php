<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\DTO\SecuritiesMarkDTO;
use App\DTO\SovereignCurveDTO;
use App\Service\Market\Bond\BondPricingEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FixedIncome;

/**
 * Rolls a financial's investment securities book forward one quarter and marks it against the curve.
 *
 * The models already hold long-duration paper: an insurer puts three quarters of its investable float into
 * a long bond tranche and a bank runs a four-and-a-half year asset book against one-and-a-half year
 * funding. Until now both earned the coupon and neither owned the price. The curve was a flow and never a
 * stock, so a hiking cycle was unambiguously good for every financial in the market and no lender could be
 * made insolvent by rates — only by credit.
 *
 * What comes out of here is a valuation, not income. The mark reaches equity through other comprehensive
 * income and never through EBIT, EPS or the consensus surprise; see SecuritiesMarkDTO. The one place it
 * becomes an earnings event is a forced sale, which is TreasuryEngine's job, not this one's.
 *
 * Pure arithmetic against a curve and a book — no persistence, no entity writes.
 */
final class SecuritiesBookService
{
    // --- Investment Securities & AOCI (ASC 320 / Basel III) ---
    /** Floor on the carrying yield, so a book struck at the zero bound still discounts rather than dividing by nothing. */
    public const MIN_SECURITIES_CARRYING_YIELD = 0.001;

    public function __construct(
        private readonly BondPricingEngine $bondPricingEngine,
    ) {}

    /**
     * Mark a book against the curve after reinvesting the quarter's maturities.
     *
     * The book is a ladder of maturing paper, repriced exactly at the move (FixedIncome::calculateLadderPriceChange).
     * Its convexity is not decoration: without it a rally and a selloff of the same size are worth the same in
     * opposite directions, and the asymmetry that makes a bond a bond disappears. A second-order expansion at the
     * ladder's real convexity turns back up past a move of 0.75/D, so a long book showed a gain on a large selloff.
     *
     * Note on the book's size: it is re-read from the balance sheet each quarter rather than carried, so
     * net new money is treated as part of the same roll-down that maturities are, instead of being tracked
     * as its own tranche bought at today's curve. At a 6.25% quarterly roll-down against the few percent a
     * year a book actually grows, that understates dilution by a second-order amount, and it errs toward
     * carrying more mark rather than less.
     *
     * @param float             $bookValue     Amortized cost of the duration-bearing securities book.
     * @param float             $openingMark   Total mark carried out of the previous quarter; negative is a loss.
     * @param float|null        $carryingYield Yield the book was struck at; null opens it at today's curve, marked flat.
     * @param SovereignCurveDTO $curve         The fitted term structure as of this report.
     * @param float             $duration      Portfolio Macaulay duration in years, before the floating-rate share.
     * @param float             $floatingShare Share of the book that reprices with the curve and carries no duration.
     * @param float             $fallbackYield Published benchmark yield to discount against when no curve has been
     *                                         fitted. MacroStateDTO rebuilds an absent curve off the policy rate for
     *                                         the bond desk for the same reason: an unfitted curve evaluates near
     *                                         zero, and marking a book against zero prints an enormous phantom gain
     *                                         on every financial in the market.
     * @param float             $htmShare      Share carried as held-to-maturity, disclosed rather than marked to equity.
     */
    public function roll(
        float $bookValue,
        float $openingMark,
        ?float $carryingYield,
        SovereignCurveDTO $curve,
        float $duration,
        float $floatingShare,
        float $fallbackYield = 0.05,
        float $htmShare = FinancialConstants::DEFAULT_HTM_BOOK_SHARE,
    ): SecuritiesMarkDTO {
        $book = max(0.0, $bookValue);
        $htm = max(0.0, min(1.0, $htmShare));

        // Floating-rate paper reprices to the curve and owns none of its moves. What is left is the part
        // that is still paying a coupon struck at a yield the market has since left behind.
        $fixedShare = max(0.0, min(1.0, 1.0 - $floatingShare));
        $effectiveDuration = max(0.0, $duration) * $fixedShare;
        // A level of zero is not a zero-rate world, it is a curve nobody fitted — a bare DTO in a harness or
        // a test. Evaluating it would put the whole market's paper at a yield of nothing.
        $marketYield = max(
            self::MIN_SECURITIES_CARRYING_YIELD,
            $curve->level > 0.0
                ? $this->bondPricingEngine->zeroYield($curve, max(0.25, $effectiveDuration))
                : $fallbackYield,
        );

        if ($book <= 0.0 || $effectiveDuration <= 0.0) {
            // No duration, nothing to mark. The carrying yield still tracks the curve so that a book opened
            // later does not inherit a stale one from whenever the firm last held paper.
            return new SecuritiesMarkDTO(
                bookValue: $book,
                totalMark: 0.0,
                afsMark: 0.0,
                htmMark: 0.0,
                afsEquityDelta: -$openingMark * (1.0 - $htm),
                carryingYield: $marketYield,
                effectiveDuration: 0.0,
            );
        }

        // A book with no history opens at today's curve: it was bought at the prevailing yield, so it is
        // worth what was paid for it. Striking it at anything else would book a loss on the first report
        // that the firm never took.
        $opening = $carryingYield ?? $marketYield;

        // Portfolio rolldown: roll maturing paper into prevailing market yield paced by effective duration.
        $rolldown = 1.0 / (4.0 * max(1.0, $effectiveDuration));
        $rolled = ($opening * (1.0 - $rolldown)) + ($marketYield * $rolldown);
        $newCarryingYield = max(self::MIN_SECURITIES_CARRYING_YIELD, $rolled);

        // The fixed sleeve is a rolling ladder of maturing paper, repriced exactly at the move; the floating sleeve
        // owns none of it.
        $deltaYield = $marketYield - $newCarryingYield;
        $markRatio = $fixedShare * FixedIncome::calculateLadderPriceChange($duration, $deltaYield);

        $totalMark = $book * $markRatio;
        $afsMark = $totalMark * (1.0 - $htm);

        return new SecuritiesMarkDTO(
            bookValue: $book,
            totalMark: $totalMark,
            afsMark: $afsMark,
            htmMark: $totalMark * $htm,
            // Equity already carries the previous quarter's AFS mark, so only the move belongs on it now.
            afsEquityDelta: $afsMark - ($openingMark * (1.0 - $htm)),
            carryingYield: $newCarryingYield,
            effectiveDuration: $effectiveDuration,
        );
    }

    /**
     * The loss a sale crystallizes, and what leaves the carried mark with it.
     *
     * Selling a share of the book realizes that share of the mark on BOTH tranches. The held-to-maturity
     * part is the half that matters: it was never in reported equity, so recognising it on the way out is
     * a fresh hit to book value, which is exactly why an institution that can avoid selling does.
     *
     * @param float $carriedMark Total mark on the book before the sale; negative is a loss.
     * @param float $bookValue   Amortized cost of the whole book.
     * @param float $soldValue   Amortized cost of the part being sold.
     * @return float The realized gain or loss, signed the same way as the mark.
     */
    public function realizeOnSale(float $carriedMark, float $bookValue, float $soldValue): float
    {
        if ($bookValue <= 0.0 || $soldValue <= 0.0) {
            return 0.0;
        }

        return $carriedMark * min(1.0, $soldValue / $bookValue);
    }
}
