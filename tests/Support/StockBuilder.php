<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Stock;
use App\Service\Math\Decimal;

/**
 * Fluent builder for creating isolated, valid Stock entity instances in tests.
 */
class StockBuilder
{
    private Stock $stock;

    public function __construct(string $ticker = 'TEST', string $name = 'Test Corporation')
    {
        $this->stock = new Stock();
        $this->stock->setTicker($ticker);
        $this->stock->setName($name);
        $this->stock->setSector('Technology');
        $this->stock->setPrice('100.00000000');
        $this->stock->setSharesOutstanding('10000000');
        $this->stock->setCorporateTreasury('50000000.0000');
        $this->stock->setTotalEquity('500000000.0000');
        $this->stock->setWholesaleDebt('100000000.0000');
        $this->stock->setBeta('1.00');
        $this->stock->setVolatility('0.2000');

        // A listed company that earns money, because that is the ordinary case and index eligibility now
        // depends on it. A test about what happens to a loss-maker says so with withQuarterlyNetIncome().
        $this->withQuarterlyNetIncome(25_000_000.0);
    }

    public static function create(string $ticker = 'TEST', string $name = 'Test Corporation'): self
    {
        return new self($ticker, $name);
    }

    /** A bare entity with only its ticker, for a fixture whose arithmetic needs every other field at its default. */
    public static function blank(string $ticker): self
    {
        $builder = new self($ticker);
        $builder->stock = (new Stock())->setTicker($ticker);

        return $builder;
    }

    /**
     * A mature auto maker on a bare entity: $80B of revenue at a 12% margin with 40% of costs fixed, $50B of equity,
     * $20B of debt and a 30% payout target. The structural variable cost ratio stays null, so the engine derives it
     * from the margin and the fixed cost ratio.
     */
    public static function matureIndustrial(string $ticker): self
    {
        return self::blank($ticker)
            ->withIndustry('Auto Manufacturers')
            ->withEarningsPerShare('2.50')
            ->withSharesOutstanding('1000000000')
            ->withPrice('50.00')
            ->withVolatility('0.15')
            ->withCurrentVolatility('0.15')
            ->withBeta('1.0')
            ->withTotalEquity('50000000000')
            ->withWholesaleDebt('20000000000')
            ->withCorporateTreasury('15000000000')
            ->withRetainedEarnings('10000000000')
            ->withRevenue('80000000000')
            ->withOperatingMargin('0.12')
            ->withTargetPayoutRatio('0.30')
            ->withDividendSpeed('1.00')
            ->withLastDividend('0.00')
            ->withFixedCostRatio(0.40)
            ->withCapexRatio('0.20');
    }

    public function withSector(string $sector): self
    {
        $this->stock->setSector($sector);
        return $this;
    }

    /** The industry, which picks the firm's business model and its sector's covenants and depreciation. */
    public function withIndustry(string $industry): self
    {
        $this->stock->setIndustry($industry);
        return $this;
    }

    public function withCreditRating(string $rating): self
    {
        $this->stock->setCreditRating($rating);
        return $this;
    }

    /** Annual revenue. */
    public function withRevenue(float|string $revenue): self
    {
        $this->stock->setTotalRevenue(Decimal::of($revenue, 4));
        return $this;
    }

    public function withOperatingMargin(float|string $margin): self
    {
        $this->stock->setOperatingMargin(Decimal::of($margin, 4));
        return $this;
    }

    /** The share of the cost base that does not move with revenue. */
    public function withFixedCostRatio(float $ratio): self
    {
        $this->stock->setFixedCostRatio($ratio);
        return $this;
    }

    /** Sets net income from EPS on the shares outstanding at the time, so it goes after withSharesOutstanding(). */
    public function withEarningsPerShare(float|string $eps): self
    {
        $this->stock->setEarningsPerShare(Decimal::of($eps, 8));
        return $this;
    }

    public function withRetainedEarnings(float|string $retained): self
    {
        $this->stock->setRetainedEarnings(Decimal::of($retained, 4));
        return $this;
    }

    public function withBaselineRoic(float|string $roic): self
    {
        $this->stock->setBaselineRoic(Decimal::of($roic, 4));
        return $this;
    }

    public function withCapexRatio(float|string $ratio): self
    {
        $this->stock->setCapexRatio(Decimal::of($ratio, 4));
        return $this;
    }

    public function withTargetPayoutRatio(float|string $ratio): self
    {
        $this->stock->setTargetPayoutRatio(Decimal::of($ratio, 4));
        return $this;
    }

    /** How fast the dividend closes on its target each quarter, 1.0 being at once. */
    public function withDividendSpeed(float|string $speed): self
    {
        $this->stock->setDividendSpeed(Decimal::of($speed, 4));
        return $this;
    }

    public function withCustomerDeposits(float|string $deposits): self
    {
        $this->stock->setCustomerDeposits(Decimal::of($deposits, 4));
        return $this;
    }

    /** The blended coupon on the debt already issued. */
    public function withHistoricalFixedRate(float|string $rate): self
    {
        $this->stock->setHistoricalFixedRate(Decimal::of($rate, 4));
        return $this;
    }

    /** The firm's credit spread over the risk-free curve. */
    public function withCreditSpread(float|string $spread): self
    {
        $this->stock->setCreditSpread(Decimal::of($spread, 4));
        return $this;
    }

    /**
     * Sets four identical reported quarters, which is what most tests mean by "this company earns this".
     *
     * Trailing twelve-month net income is the sum of the four, so a negative figure here is a company that
     * has lost money in every quarter of the last year — the unambiguous end of the index's viability
     * screen. Pass the quarters explicitly where the distinction between the trailing figure and the latest
     * quarter is the point of the test.
     */
    public function withQuarterlyNetIncome(float $perQuarter): self
    {
        return $this->withQuarterlyNetIncomeHistory(array_fill(0, 4, $perQuarter));
    }

    /**
     * Sets the reported quarters directly, oldest first.
     *
     * @param list<float> $quarters
     */
    public function withQuarterlyNetIncomeHistory(array $quarters): self
    {
        $this->stock->setQuarterlyNetIncomeHistory($quarters);
        $this->stock->setTotalNetIncome((string) array_sum($quarters));

        return $this;
    }

    public function withPrice(float|string $price): self
    {
        $this->stock->setPrice(is_float($price) ? number_format($price, 8, '.', '') : $price);
        return $this;
    }

    public function withSharesOutstanding(int|string $shares): self
    {
        $this->stock->setSharesOutstanding((string) $shares);
        return $this;
    }

    public function withCorporateTreasury(float|string $cash): self
    {
        $this->stock->setCorporateTreasury(is_float($cash) ? number_format($cash, 4, '.', '') : $cash);
        return $this;
    }

    public function withCash(float|string $cash): self
    {
        return $this->withCorporateTreasury($cash);
    }

    public function withTotalEquity(float|string $equity): self
    {
        $this->stock->setTotalEquity(Decimal::of($equity, 4));
        return $this;
    }

    public function withWholesaleDebt(float|string $debt): self
    {
        $this->stock->setWholesaleDebt(is_float($debt) ? number_format($debt, 4, '.', '') : $debt);
        return $this;
    }

    public function withTotalDebt(float|string $debt): self
    {
        return $this->withWholesaleDebt($debt);
    }

    public function withBeta(float|string $beta): self
    {
        $this->stock->setBeta(is_float($beta) ? number_format($beta, 2, '.', '') : $beta);
        return $this;
    }

    public function withVolatility(float|string $vol): self
    {
        $this->stock->setVolatility(is_float($vol) ? number_format($vol, 4, '.', '') : $vol);
        return $this;
    }

    public function withCurrentVolatility(float|string $vol): self
    {
        $this->stock->setCurrentVolatility(is_float($vol) ? number_format($vol, 4, '.', '') : $vol);
        return $this;
    }

    /** The volatility the name has REALIZED over the trailing window, which is what an index screen ranks on. */
    public function withRealizedVolatility(float $vol): self
    {
        $this->stock->setRealizedVarianceEma($vol * $vol);
        return $this;
    }

    public function withJumpIntensity(float|string $lambda): self
    {
        $this->stock->setJumpIntensity(is_float($lambda) ? number_format($lambda, 2, '.', '') : $lambda);
        return $this;
    }

    public function withJumpVol(float|string $jumpVol): self
    {
        $this->stock->setJumpVol(is_float($jumpVol) ? number_format($jumpVol, 4, '.', '') : $jumpVol);
        return $this;
    }

    public function withLastDividend(float|string $dividend): self
    {
        $this->stock->setLastDividend(is_float($dividend) ? number_format($dividend, 4, '.', '') : $dividend);
        return $this;
    }

    public function withPublicFloatPercentage(float|string $share): self
    {
        $this->stock->setPublicFloatPercentage(is_float($share) ? number_format($share, 4, '.', '') : $share);
        return $this;
    }

    public function build(): Stock
    {
        return clone $this->stock;
    }
}
