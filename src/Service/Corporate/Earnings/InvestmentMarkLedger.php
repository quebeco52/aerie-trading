<?php

declare(strict_types=1);

namespace App\Service\Corporate\Earnings;

use App\DTO\EarningsSimulationContext;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\SecuritiesBookService;
use App\Service\Math\Decimal;
use App\Service\Math\FinancialConstants;
use App\Service\Math\TimeSeries;

/**
 * Marks what the balance sheet holds at market before the quarter is struck on it: the investment securities book
 * against the curve (ASC 320) and the treasury's equity sleeve against the board (FVOCI). Both reach equity through
 * other comprehensive income, never earnings.
 */
final class InvestmentMarkLedger
{
    // --- Treasury Equity Sleeve (FVOCI) ---
    /** Carried reading of the board's price index at the last report, for the treasury's equity sleeve. */
    public const STATE_SLEEVE_PRICE_INDEX = EarningsEngine::CARRIED_STATE_PREFIX . 'equity_sleeve:price_index';
    /** Carried reading of the board's total-return index at the last report, for the treasury's equity sleeve. */
    public const STATE_SLEEVE_TOTAL_RETURN_INDEX = EarningsEngine::CARRIED_STATE_PREFIX . 'equity_sleeve:total_return_index';
    /** Carried annual rate of the after-tax gains the equity sleeve's rebalancing realised over the trailing year. */
    public const STATE_SLEEVE_TRAILING_GAINS = EarningsEngine::CARRIED_STATE_PREFIX . 'equity_sleeve:trailing_gains';
    /** Mean age of a trailing year's flows, half a year: the lag the sleeve's realised gains are carried at. */
    public const SLEEVE_GAINS_TRAILING_LAG_YEARS = 0.5;

    // --- Investment Securities & AOCI (ASC 320 / Basel III) ---
    /**
     * Default portfolio duration in years for a financial with no seeded figure; the middle of the range large lenders
     * run.
     */
    public const DEFAULT_SECURITIES_DURATION_YEARS = 4.5;

    public function __construct(
        private readonly SecuritiesBookService $securitiesBook,
    ) {}

    /**
     * Marks the equity the treasury holds to the board's realised return since the last report (FVOCI, IFRS 9 5.7.5):
     * the price move goes to book value and the treasury the sleeve sits in, net of the tax its quarterly rebalance
     * realises, and never through earnings; the dividends it was paid are booked as income instead of the expected
     * return (EarningsEngine::calculateInterestAndRunRates()). A first report has no earlier reading, so it marks
     * nothing.
     */
    public function markEquitySleeve(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $priceThen = $momentum[self::STATE_SLEEVE_PRICE_INDEX] ?? null;
        $totalReturnThen = $momentum[self::STATE_SLEEVE_TOTAL_RETURN_INDEX] ?? null;
        $priceNow = $ctx->macroState->boardPriceIndex;
        $totalReturnNow = $ctx->macroState->boardTotalReturnIndex;

        $sleeve = $ctx->strategy->resolveEquitySleeve($stock, $ctx->macroState);
        if ($sleeve['value'] <= 0.0 && $priceThen === null) {
            return;
        }

        $years = max(FinancialConstants::QUARTER_YEARS, $ctx->dt);
        $mark = 0.0;
        if ($sleeve['value'] > 0.0 && $priceThen !== null && $totalReturnThen !== null && (float) $priceThen > 0.0 && (float) $totalReturnThen > 0.0) {
            $priceReturn = ($priceNow / (float) $priceThen) - 1.0;
            $ctx->equitySleeveDividendReturn = (($totalReturnNow / (float) $totalReturnThen) - ($priceNow / (float) $priceThen)) / $years;
            $mark = $sleeve['value'] * $priceReturn * (1.0 - $ctx->strategy->getEffectiveTaxRate($ctx->macroState->corporateTaxRate));
        }

        $momentum[self::STATE_SLEEVE_PRICE_INDEX] = $priceNow;
        $momentum[self::STATE_SLEEVE_TOTAL_RETURN_INDEX] = $totalReturnNow;
        $momentum[self::STATE_SLEEVE_TRAILING_GAINS] = TimeSeries::calculateDistributedLag(
            currentLaggedValue: (float) ($momentum[self::STATE_SLEEVE_TRAILING_GAINS] ?? 0.0),
            targetValue: $mark / $years,
            dt: $years,
            lagTimeConstant: self::SLEEVE_GAINS_TRAILING_LAG_YEARS,
        );
        $stock->setEarningsMomentumZ($momentum);
        if ($mark === 0.0) {
            return;
        }
        // Book value takes the mark now, as every downstream valuation reads it; the cash leg lands once the quarter's
        // interest is booked, which accrued on the treasury the quarter opened with
        // (EarningsEngine::calculateInterestAndRunRates()).
        $stock->setTotalEquity(\bcadd($stock->getTotalEquity(), Decimal::format($mark, 4), 4));
        $ctx->equitySleeveMark = $mark;
    }

    /**
     * Remarks the investment securities book against the curve (ASC 320).
     *
     * Available-for-sale moves total equity now; held-to-maturity is disclosed on the report and stays out
     * of reported equity until a sale recognises it. Neither reaches earnings. The distinction is the whole
     * mechanism: a firm can carry an intact capital ratio and a hollowed-out balance sheet at the same time,
     * and only a forced sale collapses the two back together.
     */
    public function rollForwardSecuritiesBook(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $book = $ctx->strategy->resolveSecuritiesBook($stock);
        $opening = (float) $stock->getUnrealizedSecuritiesMark();

        if ($book <= 0.0 && $opening === 0.0) {
            return;
        }

        $mark = $this->securitiesBook->roll(
            bookValue: $book,
            openingMark: $opening,
            carryingYield: $stock->getSecuritiesCarryingYield(),
            curve: $ctx->macroState->sovereignCurve(),
            duration: $stock->getSecuritiesDuration() ?? self::DEFAULT_SECURITIES_DURATION_YEARS,
            floatingShare: $ctx->strategy->getSecuritiesFloatingShare($stock),
            fallbackYield: $ctx->macroState->yield10yEma,
        );

        $stock->setSecuritiesCarryingYield($mark->carryingYield);
        $stock->setUnrealizedSecuritiesMark(Decimal::format($mark->totalMark, 4));

        // The mark is reported in other comprehensive income net of tax (ASC 320; ASC 740-20-45-11): a deferred tax
        // liability on an unrealized gain, an asset on a loss, at today's rate on the mark the sheet recognizes.
        $openingDeferred = $stock->getSecuritiesDeferredTax();
        $closingDeferred = $ctx->strategy->getEffectiveTaxRate($ctx->macroState->corporateTaxRate) * $stock->getRecognizedSecuritiesMark();
        $stock->setSecuritiesDeferredTax($closingDeferred);
        $netOfTax = $mark->afsEquityDelta - ($closingDeferred - $openingDeferred);

        if ($netOfTax !== 0.0) {
            // Other comprehensive income is inside total equity and outside retained earnings, which is why
            // this is a bcadd on the balance sheet and not a line in the income statement.
            $stock->setTotalEquity(
                \bcadd($stock->getTotalEquity(), Decimal::format($netOfTax, 4), 4),
            );
        }

        $ctx->unrealizedSecuritiesMark = $mark->totalMark;
    }
}
