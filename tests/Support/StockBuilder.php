<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Stock;

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

    public function withSector(string $sector): self
    {
        $this->stock->setSector($sector);
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
        $this->stock->setTotalEquity(is_float($equity) ? number_format($equity, 4, '.', '') : $equity);
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
