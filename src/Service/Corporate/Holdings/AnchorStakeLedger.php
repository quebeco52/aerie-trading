<?php

declare(strict_types=1);

namespace App\Service\Corporate\Holdings;

use App\Data\Company\AnchorHoldings;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Math\Decimal;
use App\Service\Math\FinancialConstants;
use App\Service\Math\TimeSeries;

/**
 * Marks a permanent-capital sphere's listed anchor stakes at what the market says they are worth.
 *
 * Two cadences, because they are two different real quantities. The BOOKS move quarterly (`markToMarket()`
 * at the report) into equity and NOT retained earnings: an unrealised gain is not distributable, and the
 * earnings line never sees it, so consensus and surprise keep reading operating receipts. The VALUATION
 * moves every tick (`resolveMarkedBookValuePerShare()`) — pricing off the stale filed book would leave the
 * mean reversion pulling towards a NAV that had already changed.
 *
 * Capitalisations are read once per tick, like the order-flow drain, so iteration order cannot matter.
 *
 * A stake opens at the fraction AnchorHoldings declares and moves only when the held company's share count does: the
 * holder buys its part of every issue, and sits out a retirement that reaches the market, so its percentage rises as
 * the float is retired (resolveShareChange()). A held company returns its surplus by mandatory redemption, which
 * pays every holder alike and moves no fraction (AnchorHoldings::redeemsProRata(), settleRedemption()). The fraction
 * lives on the held company, beside the float it is taken from.
 */
final class AnchorStakeLedger
{
    // --- Carried State ---
    /**
     * Carried state key on a held company, suffixed with the holder's ticker: the fraction of it that holder owns now.
     */
    public const STATE_FRACTION = EarningsEngine::CARRIED_STATE_PREFIX . 'anchor:fraction:';

    /**
     * Carried state key on a held company: its tradable float plus every anchor block. A buyback the anchors sit out
     * moves shares from the first to the second and leaves the sum alone.
     */
    public const STATE_FLOAT_BASE = EarningsEngine::CARRIED_STATE_PREFIX . 'anchor:float_base';

    /** Carried state key on a holder: dividends its stakes have paid it since its last report, not yet booked. */
    public const STATE_DIVIDENDS_RECEIVABLE = EarningsEngine::CARRIED_STATE_PREFIX . 'anchor:dividends_receivable';

    /** Carried state key on a holder: the annual rate its stakes pay it dividends at, trailing. */
    public const STATE_DIVIDENDS_TRAILING = EarningsEngine::CARRIED_STATE_PREFIX . 'anchor:dividends_trailing';

    /** Carried state key on a holder: the tax rate its last mark was booked at, which the tick's valuation nets. */
    public const STATE_TAX_RATE = EarningsEngine::CARRIED_STATE_PREFIX . 'anchor:tax_rate';

    // --- Dividends Received ---
    /** Time constant of the trailing dividend rate (1 year): a year of receipts, as a lender reads coverage. */
    public const DIVIDEND_TRAILING_YEARS = 1.0;

    /** @var array<string, float> ticker => market capitalisation at the start of the tick */
    private array $marketCaps = [];

    /** @var array<string, Stock> ticker => the board's company, for the holders a participation is settled on */
    private array $board = [];

    /** @param iterable<Stock> $stocks The whole board, priced for the tick about to run. */
    public function beginTick(iterable $stocks): void
    {
        $caps = [];
        $board = [];

        foreach ($stocks as $stock) {
            $ticker = $stock->getTicker();

            if ($ticker === '') {
                continue;
            }

            // A delisted holding is worth nothing. Stated rather than inferred from the zeroed price:
            // this is the largest single move a sphere's book can make.
            $caps[$ticker] = $stock->isBankrupt()
                ? 0.0
                : (float) $stock->getPrice() * (float) $stock->getSharesOutstanding();
            $board[$ticker] = $stock;
        }

        $this->marketCaps = $caps;
        $this->board = $board;
    }

    /** The fraction of a held company an anchor holder owns now: as declared, until a share change moved it. */
    public static function fraction(Stock $held, string $holderTicker): float
    {
        $declared = AnchorHoldings::STAKES[$holderTicker][$held->getTicker()] ?? null;

        if ($declared === null) {
            return 0.0;
        }

        return (float) (($held->getEarningsMomentumZ() ?? [])[self::STATE_FRACTION . $holderTicker] ?? $declared->fraction());
    }

    /** The fraction of a company its anchor holders own between them now; zero for one nobody anchors. */
    public static function anchoredShare(Stock $held): float
    {
        return array_sum(self::holdersOf($held));
    }

    /**
     * Shares of a buyback the company buys in the market: the tradable float's part, and the anchors' too, since they
     * sit it out and the float sells in their place. The District's stake and the other closely held blocks tender
     * off the market, as their cash does.
     *
     * @param float $shares Shares retired, already gone from the company's count.
     */
    public static function marketSharesOfBuyback(Stock $held, float $shares): float
    {
        $tendered = array_sum(self::resolveShareChange($held, 0.0, $shares)['tendered']);

        return max(0.0, ($shares * self::floatBase($held, self::holdersOf($held))) - $tendered);
    }

    /** Shares of an issue sold into the market: the tradable float's part, as every block holder takes its own. */
    public static function marketSharesOfIssue(Stock $held, float $shares): float
    {
        return $shares * max(0.0, min(1.0, (float) $held->getPublicFloatPercentage()));
    }

    /**
     * Settles the anchor holders' part in a held company's change in share count, at the prices it was struck at.
     * Each pays for its fraction of the issue, and is paid for whatever it tendered into the buyback; the stake's
     * carrying value moves by the same cash, and the held company's float and fractions move as resolveShareChange()
     * says. A subscription the holder's cash cannot cover leaves an overdraft, which its next report funds.
     *
     * The tick's capitalisation of the held company moves by the money that changed hands, so a mark later in the tick
     * sees the shares the holder has just paid for, not a loss. A holder not yet marked carries no position to add
     * to: its fraction still moves, as a fact of the held company's register, but no cash does.
     *
     * @param float $issued Shares issued, splits excluded; already in the company's count.
     * @param float $retired Shares bought back; already gone from the count.
     */
    public function settleShareChange(Stock $held, float $issued, float $issuePrice, float $retired, float $retirePrice): void
    {
        $issued = max(0.0, $issued);
        $retired = max(0.0, $retired);
        $value = ($issued * $issuePrice) - ($retired * $retirePrice);

        if (($issued === 0.0 && $retired === 0.0) || !is_finite($value)) {
            return;
        }

        $holders = self::holdersOf($held);

        if ($holders !== []) {
            $resolved = self::resolveShareChange($held, $issued, $retired);
            $state = $held->getEarningsMomentumZ() ?? [];
            $state[self::STATE_FLOAT_BASE] = self::floatBase($held, $holders);

            foreach ($holders as $holderTicker => $fraction) {
                $state[self::STATE_FRACTION . $holderTicker] = $resolved['fractions'][$holderTicker];
                $holder = $this->board[$holderTicker] ?? null;
                $carrying = $holder?->getListedStakesCarrying();

                if ($holder === null || $holder->isBankrupt() || $carrying === null) {
                    continue;
                }

                self::bookStakeCash($holder, $fraction * $issued * $issuePrice, $resolved['tendered'][$holderTicker] * $retirePrice);
            }

            $held->setEarningsMomentumZ($state);
            $held->setPublicFloatPercentage((string) $resolved['float']);
        }

        if (isset($this->marketCaps[$held->getTicker()])) {
            $this->marketCaps[$held->getTicker()] += $value;
        }
    }

    /**
     * Pays the anchor holders their part of a held company's mandatory redemption. Every holder is redeemed alike, so
     * each fraction holds; the cash is a return of the stake rather than income, and the part of the stake redeemed
     * realises its share of the gain, so the tax deferred on it falls due, as on a tender.
     *
     * @param float $sharesOfRecord The count the redemption was paid on, which the fractions are of.
     */
    public function settleRedemption(Stock $held, float $perShare, float $sharesOfRecord): void
    {
        if ($perShare <= 0.0) {
            return;
        }

        foreach (self::holdersOf($held) as $holderTicker => $fraction) {
            $holder = $this->board[$holderTicker] ?? null;

            if ($holder === null || $holder->isBankrupt() || $holder->getListedStakesCarrying() === null) {
                continue;
            }

            self::bookStakeCash($holder, 0.0, $fraction * $perShare * $sharesOfRecord);
        }

        $this->distributed($held, $perShare * $sharesOfRecord);
    }

    /**
     * Cash between a holder and its stakes: what it paid in for shares and what it was paid out for them. What leaves
     * the stakes realises their share of the portfolio's gain, so the tax deferred on that share falls due.
     */
    private static function bookStakeCash(Stock $holder, float $paidIn, float $proceeds): void
    {
        $carrying = (float) $holder->getListedStakesCarrying();
        $taxDue = $carrying > 0.0 ? min(1.0, $proceeds / $carrying) * $holder->getListedStakesDeferredTax() : 0.0;
        $holder->setListedStakesDeferredTax($holder->getListedStakesDeferredTax() - $taxDue);
        $holder->setCorporateTreasury(self::decimal((float) $holder->getCorporateTreasury() - $paidIn + $proceeds - $taxDue));
        $holder->setListedStakesCarrying(self::decimal($carrying + $paidIn - $proceeds));
    }

    /**
     * The cash a held company paid out leaves its value for the rest of the tick, as its price fell ex-date by it: the
     * capitalisations were read before the report, and a holder marked against them would count the cash twice.
     */
    private function distributed(Stock $held, float $cash): void
    {
        if (isset($this->marketCaps[$held->getTicker()])) {
            $this->marketCaps[$held->getTicker()] -= $cash;
        }
    }

    /**
     * Pays the anchor holders their fraction of a held company's dividend. It is income at the holder's next report
     * (collectDividends()), when its cash arrives with the rest of the quarter's; until then the holder's marked book
     * carries it as a receivable, so the stake's fall ex-dividend moves no value.
     *
     * @param float $sharesOfRecord The count the dividend was paid on, which the fractions are of: a buyback filed in
     *                              the same report settles later in the tick (settleShareChange()).
     */
    public function accrueDividends(Stock $held, float $dividendPerShare, float $sharesOfRecord): void
    {
        if ($dividendPerShare <= 0.0) {
            return;
        }

        foreach (self::holdersOf($held) as $holderTicker => $fraction) {
            $holder = $this->board[$holderTicker] ?? null;

            if ($holder === null || $holder->isBankrupt()) {
                continue;
            }

            $state = $holder->getEarningsMomentumZ() ?? [];
            $state[self::STATE_DIVIDENDS_RECEIVABLE] = (float) ($state[self::STATE_DIVIDENDS_RECEIVABLE] ?? 0.0)
                + ($fraction * $dividendPerShare * $sharesOfRecord);
            $holder->setEarningsMomentumZ($state);
        }

        $this->distributed($held, $dividendPerShare * $sharesOfRecord);
    }

    /**
     * The dividends a holder's stakes paid it since its last report, for the report to book as investment income. The
     * receivable clears, and the quarter rolls into the trailing rate (trailingDividends()).
     */
    public function collectDividends(Stock $holder): float
    {
        $state = $holder->getEarningsMomentumZ() ?? [];
        $received = max(0.0, (float) ($state[self::STATE_DIVIDENDS_RECEIVABLE] ?? 0.0));

        if (AnchorHoldings::forHolder($holder->getTicker()) === [] && $received === 0.0) {
            return 0.0;
        }

        unset($state[self::STATE_DIVIDENDS_RECEIVABLE]);
        $state[self::STATE_DIVIDENDS_TRAILING] = TimeSeries::calculateDistributedLag(
            currentLaggedValue: (float) ($state[self::STATE_DIVIDENDS_TRAILING] ?? ($received / FinancialConstants::QUARTER_YEARS)),
            targetValue: $received / FinancialConstants::QUARTER_YEARS,
            dt: FinancialConstants::QUARTER_YEARS,
            lagTimeConstant: self::DIVIDEND_TRAILING_YEARS,
        );
        $holder->setEarningsMomentumZ($state);

        return $received;
    }

    /**
     * Opens a holder's trailing dividend rate at what its stakes pay now, the held companies' last declared dividends,
     * so its first year is not read as a year of no income.
     */
    public function openDividendRate(Stock $holder): void
    {
        $rate = 0.0;

        foreach (array_keys(AnchorHoldings::forHolder($holder->getTicker())) as $heldTicker) {
            $held = $this->board[$heldTicker] ?? null;

            if ($held !== null && !$held->isBankrupt()) {
                $rate += self::fraction($held, $holder->getTicker()) * (float) $held->getLastDividend()
                    * (float) $held->getSharesOutstanding() / FinancialConstants::QUARTER_YEARS;
            }
        }

        $holder->setEarningsMomentumZ([self::STATE_DIVIDENDS_TRAILING => $rate] + ($holder->getEarningsMomentumZ() ?? []));
    }

    /** The annual rate a holder's stakes pay it dividends at, trailing; zero for a firm holding none. */
    public static function trailingDividends(Stock $holder): float
    {
        return max(0.0, (float) (($holder->getEarningsMomentumZ() ?? [])[self::STATE_DIVIDENDS_TRAILING] ?? 0.0));
    }

    /** The rate a holder's stake income and gains are taxed at, as its last mark struck it; zero before one. */
    public static function taxRate(Stock $holder): float
    {
        return (float) (($holder->getEarningsMomentumZ() ?? [])[self::STATE_TAX_RATE] ?? 0.0);
    }

    /** Dividends the holder has been paid and not yet booked. */
    public static function dividendsReceivable(Stock $holder): float
    {
        return max(0.0, (float) (($holder->getEarningsMomentumZ() ?? [])[self::STATE_DIVIDENDS_RECEIVABLE] ?? 0.0));
    }

    /**
     * How a held company's change in share count falls on its anchor holders. Each buys its fraction of an issue, so
     * its percentage holds, and sits a buyback out, so its percentage rises as the float is retired: permanent capital
     * is the holder that does not sell. It tenders only what keeps MIN_TRADABLE_FLOAT of the company trading, and
     * never more than its pro-rata part.
     *
     * @return array{fractions: array<string, float>, tendered: array<string, float>, float: float}
     *         each holder's fraction after, the shares it tendered, and the tradable float after
     */
    private static function resolveShareChange(Stock $held, float $issued, float $retired): array
    {
        $holders = self::holdersOf($held);
        $floatBase = self::floatBase($held, $holders);
        $sharesAfter = (float) $held->getSharesOutstanding();
        $sharesBefore = $sharesAfter - $issued + $retired;
        $tendered = array_map(static fn(): float => 0.0, $holders);

        if ($holders === [] || $sharesAfter <= 0.0) {
            return ['fractions' => $holders, 'tendered' => $tendered, 'float' => $floatBase - array_sum($holders)];
        }

        // What each would hold having bought its part of the issue and sold none of the buyback.
        $kept = array_map(static fn(float $fraction): float => $fraction * (max(0.0, $sharesBefore) + $issued), $holders);
        $ceiling = max(0.0, $floatBase - AnchorHoldings::MIN_TRADABLE_FLOAT) * $sharesAfter;
        $scale = array_sum($kept) > $ceiling ? $ceiling / array_sum($kept) : 1.0;

        $fractions = [];
        foreach ($holders as $holderTicker => $fraction) {
            $sharesKept = max($fraction * $sharesAfter, $kept[$holderTicker] * $scale);
            $tendered[$holderTicker] = max(0.0, $kept[$holderTicker] - $sharesKept);
            $fractions[$holderTicker] = $sharesKept / $sharesAfter;
        }

        return ['fractions' => $fractions, 'tendered' => $tendered, 'float' => max(0.0, $floatBase - array_sum($fractions))];
    }

    /** @return array<string, float> holder ticker => the fraction of the held company it owns now */
    private static function holdersOf(Stock $held): array
    {
        $fractions = [];

        foreach (AnchorHoldings::STAKES as $holderTicker => $stakes) {
            if (isset($stakes[$held->getTicker()])) {
                $fractions[$holderTicker] = self::fraction($held, $holderTicker);
            }
        }

        return $fractions;
    }

    /**
     * The held company's float plus its anchor blocks, which a share change moves shares between: opened at the first
     * change from the float the board declared, and carried so no rounding of the float accumulates.
     *
     * @param array<string, float> $holders
     */
    private static function floatBase(Stock $held, array $holders): float
    {
        $state = $held->getEarningsMomentumZ() ?? [];

        return (float) ($state[self::STATE_FLOAT_BASE]
            ?? (max(0.0, min(1.0, (float) $held->getPublicFloatPercentage())) + array_sum($holders)));
    }

    /**
     * What the holder's stakes are worth now, or null when any of them is missing from the primed board.
     * Null rather than a partial sum, which would be short by whatever was absent and book that as a loss.
     */
    public function resolveStakeValue(Stock $holder): ?float
    {
        $stakes = AnchorHoldings::forHolder($holder->getTicker());

        if ($stakes === []) {
            return null;
        }

        $value = 0.0;

        foreach (array_keys($stakes) as $ticker) {
            if (!isset($this->marketCaps[$ticker], $this->board[$ticker])) {
                return null;
            }

            $value += $this->marketCaps[$ticker] * self::fraction($this->board[$ticker], $holder->getTicker());
        }

        return $value;
    }

    /**
     * Books the change in the portfolio and carries the new value. The first mark opens the position and
     * moves nothing — the stakes are already inside the seeded book.
     *
     * An unrealised gain has not been taxed either, and a sphere cannot sell a control block without paying
     * on everything it has appreciated by. So the mark splits: tax accrues as the stakes' own deferred balance,
     * the rest reaches shareholders. Signed, as the securities book's is: a fall below cost is an asset, so a
     * fall and the recovery from it leave the book where it started. Apart from the plant's timing difference,
     * which reverses on its own schedule, while this is paid only when a stake is sold (settleShareChange()).
     *
     * @return float The change in the portfolio's value, before the tax split.
     */
    public function markToMarket(Stock $holder, float $capitalGainsTaxRate = 0.0): float
    {
        $marketValue = $this->resolveStakeValue($holder);

        if ($marketValue === null) {
            return 0.0;
        }

        $rate = max(0.0, min(1.0, $capitalGainsTaxRate));
        $holder->setEarningsMomentumZ([self::STATE_TAX_RATE => $rate] + ($holder->getEarningsMomentumZ() ?? []));
        $carrying = $holder->getListedStakesCarrying();
        $marked = self::decimal($marketValue);
        $holder->setListedStakesCarrying($marked);

        if ($carrying === null) {
            return 0.0;
        }

        // Differenced at the column's scale: fractions against caps in the hundreds of billions leave a
        // last-bit residue, and marking on that writes a fraction of a cent onto a book that never moved.
        $unrealised = \bcsub($marked, $carrying, 4);

        if ((float) $unrealised === 0.0) {
            return 0.0;
        }

        // Double entry: the asset moved by the whole mark, so liability and equity together must too.
        $deferredChange = (float) $unrealised * $rate;
        $holder->setListedStakesDeferredTax($holder->getListedStakesDeferredTax() + $deferredChange);

        $holder->setTotalEquity(
            \bcsub(\bcadd($holder->getTotalEquity(), $unrealised, 4), self::decimal($deferredChange), 4),
        );

        return (float) $unrealised;
    }

    /**
     * Book value per share including the portfolio's move since the last report and the dividends it has been paid
     * since, each net of the tax the report books on it; null for a firm holding none.
     */
    public function resolveMarkedBookValuePerShare(Stock $holder): ?float
    {
        $carrying = $holder->getListedStakesCarrying();

        if ($carrying === null) {
            return null;
        }

        $marketValue = $this->resolveStakeValue($holder);

        if ($marketValue === null) {
            return null;
        }

        $shares = max(1.0, (float) $holder->getSharesOutstanding());

        $rate = self::taxRate($holder);
        $unbooked = $marketValue - (float) $carrying + self::dividendsReceivable($holder);

        return ((float) $holder->getTotalEquity() + ($unbooked * (1.0 - $rate))) / $shares;
    }

    /**
     * Fixed-point at the balance sheet's scale, so bcmath never sees an exponent.
     *
     * @return numeric-string
     */
    private static function decimal(float $value): string
    {
        return Decimal::format($value, 4);
    }
}
