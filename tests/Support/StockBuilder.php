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

    public function build(): Stock
    {
        return clone $this->stock;
    }
}
