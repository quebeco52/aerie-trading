<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\TestCase;

/**
 * Nominal totals compound with the economy for as long as the simulation runs: at ~4% a year a titan bank's balance
 * sheet crossed DECIMAL(20,4)'s ten quadrillion after 136 simulated years and the ticker died on the insert. Every
 * dollar total is stored at DECIMAL(30,4), and the guards that clamp a total rebuilt from a per-share figure sit at
 * that same bound rather than at the old one.
 */
final class MoneyColumnCapacityTest extends TestCase
{
    /** Wide decimal columns that hold a per-share price or a share count, not a dollar total. */
    private const PER_UNIT_COLUMNS = [Stock::class => ['price', 'shortInterestShares'], CorporateReport::class => ['consensusEps', 'reportedEps']];

    public function testEveryDollarTotalColumnIsThirtyDigitsWide(): void
    {
        foreach ([Stock::class, CorporateReport::class] as $class) {
            $checked = 0;
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                foreach ($property->getAttributes(Column::class) as $attribute) {
                    $column = $attribute->newInstance();
                    if ($column->precision === null || $column->precision < 20 || in_array($property->getName(), self::PER_UNIT_COLUMNS[$class] ?? [], true)) {
                        continue;
                    }
                    ++$checked;
                    $this->assertSame(30, $column->precision, "{$class}::\${$property->getName()} holds a dollar total and must not overflow a long run.");
                }
            }
            $this->assertGreaterThan(25, $checked, "{$class} should carry its balance sheet and income statement in wide columns.");
        }
    }

    public function testTheEarningsBridgeKeepsATotalPastTheOldCeilingAndClampsAtTheColumn(): void
    {
        $stock = new Stock();
        $stock->setSharesOutstanding('1000000000000');

        $stock->setEarningsPerShare('50000.00000000');
        $this->assertSame('50000000000000000.0000', $stock->getTotalNetIncome(), 'Fifty quadrillion is past the old 1e15 guard and must survive intact.');

        $stock->setEarningsPerShare('1000000000000000.00000000');
        $this->assertSame(Stock::MAX_MONEY_AMOUNT, $stock->getTotalNetIncome());

        $this->assertLessThan((float) Stock::MAX_MONEY_AMOUNT, EarningsEngine::MAX_ABSOLUTE_NET_INCOME);
        $this->assertGreaterThan(1.0e16, EarningsEngine::MAX_ABSOLUTE_NET_INCOME);
    }
}
