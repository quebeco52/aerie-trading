<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Data\InitialMarket;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\MarketEngine;
use App\Service\Market\OpeningBoardBuilder;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Keeps app:market-reset honest.
 *
 * A reset firm must carry nothing of the market it left. The reset used to write the balance sheet by a
 * hand-listed UPDATE, and a column added to the entity was silently left out of it more than once, carrying stale
 * ledgers and learned parameters into the new market. It now clears the entity back to a new listing and opens it
 * through the seed's own OpeningBoardBuilder, so the guard is the invariant itself: a reset board is the board a
 * seed opens.
 *
 * The fund books are still reopened by SQL, so the fund half keeps the column check.
 */
final class MarketResetCompletenessTest extends TestCase
{
    /**
     * Columns of the fund the reset deliberately leaves alone.
     *
     * @var array<string, string>
     */
    private const FUND_INTENTIONALLY_PRESERVED = [
        'id'         => 'Primary key; the reset updates rows in place.',
        'ticker'     => 'Row identity — the WHERE key.',
        'updated_at' => 'Written by the statement itself, not carried over.',
    ];

    /**
     * @return array<string, string> column name => property name
     */
    private function fundColumns(): array
    {
        $columns = [];

        foreach ((new ReflectionClass(\App\Entity\Etf::class))->getProperties() as $property) {
            foreach ($property->getAttributes(Column::class) as $attribute) {
                $arguments = $attribute->getArguments();
                $columns[$arguments['name'] ?? $this->toSnakeCase($property->getName())] = $property->getName();
            }
        }

        ksort($columns);

        return $columns;
    }

    private function toSnakeCase(string $property): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));
    }

    /** @return list<string> Every persisted property of Stock. */
    private function stockProperties(): array
    {
        $properties = [];
        foreach ((new ReflectionClass(Stock::class))->getProperties() as $property) {
            if ($property->getAttributes(Column::class) !== []) {
                $properties[] = $property->getName();
            }
        }

        return $properties;
    }

    /** Overwrites every persisted field but the row identity with a value no new listing starts with. */
    private function trade(Stock $stock): void
    {
        foreach ((new ReflectionClass(Stock::class))->getProperties() as $property) {
            if ($property->getAttributes(Column::class) === [] || in_array($property->getName(), ['id', 'ticker'], true)) {
                continue;
            }

            $current = $property->isInitialized($stock) ? $property->getValue($stock) : null;
            $property->setValue($stock, match ((string) $property->getType()) {
                'bool' => !$current,
                'int', '?int' => 7,
                '?float' => 7.77,
                '?array' => ['old_market' => 1.0],
                default => '777.7700',
            });
        }
    }

    /** @return array<string, array<string, mixed>> ticker => persisted property => value */
    private function snapshot(array $board): array
    {
        $rows = [];
        foreach ($board as $ticker => $stock) {
            foreach ($this->stockProperties() as $property) {
                if ($property !== 'id') {
                    $rows[$ticker][$property] = (new \ReflectionProperty(Stock::class, $property))->getValue($stock);
                }
            }
        }

        return $rows;
    }

    /**
     * Opens the whole seeded board the way both commands do, on a fixed draw sequence and a builder of its own, so
     * the two runs differ only in the rows they start from.
     *
     * @param array<string, Stock> $board
     * @return array<string, Stock>
     */
    private function openBoard(array $board): array
    {
        mt_srand(20260929);
        $math = new MathUtility();
        $builder = new OpeningBoardBuilder($math, new DebtEngine($math, CorporateMetrics::getInstance()), new MarketEngine($math), new AnchorStakeLedger());
        $openingMacro = new MacroStateDTO();
        $spheres = [];

        foreach (InitialMarket::STOCKS as $stockData) {
            $sphere = $builder->open($board[$stockData['ticker']], $stockData, $openingMacro);
            if ($sphere !== null) {
                $spheres[] = $sphere;
            }
        }
        $builder->openSpheres($spheres, $board, $openingMacro);

        return $board;
    }

    /** @return array<string, Stock> A new listing per seed row, as the seed creates them. */
    private function newListings(): array
    {
        $board = [];
        foreach (InitialMarket::STOCKS as $stockData) {
            $board[$stockData['ticker']] = (new Stock())->setTicker($stockData['ticker']);
        }

        return $board;
    }

    public function testAReopenedFirmCarriesNothingOfTheOldMarket(): void
    {
        $stock = (new Stock())->setTicker('OLD');
        $this->trade($stock);
        $stock->resetToUnseeded();

        $unseeded = (new Stock())->setTicker('OLD');
        foreach ($this->stockProperties() as $property) {
            if (in_array($property, ['id', 'name'], true)) {
                continue;
            }

            $reflection = new \ReflectionProperty(Stock::class, $property);
            $this->assertSame($reflection->getValue($unseeded), $reflection->getValue($stock), sprintf('"%s" survives a reset.', $property));
        }
        $this->assertSame('OLD', $stock->getTicker(), 'The ticker is the row identity and must survive.');
    }

    /** The invariant the reset exists for: every firm on a reset board is, field for field, the firm a seed opens. */
    public function testAResetBoardIsTheBoardASeedOpens(): void
    {
        $seeded = $this->snapshot($this->openBoard($this->newListings()));

        $traded = $this->openBoard($this->newListings());
        foreach ($traded as $stock) {
            $this->trade($stock);
            $stock->resetToUnseeded();
        }
        $reset = $this->snapshot($this->openBoard($traded));

        $this->assertCount(count(InitialMarket::STOCKS), $reset);
        foreach ($seeded as $ticker => $row) {
            foreach ($row as $property => $value) {
                $this->assertEquals($value, $reset[$ticker][$property], sprintf('%s opens with a different "%s" after a reset.', $ticker, $property));
            }
        }
    }

    /**
     * Parameters the engine learns from the firm's own reports open unset, so the first report strikes them.
     *
     * structural_variable_margin is the CIR variable-cost process state. Its long-run mean is derived in
     * EarningsEngine from cash costs AFTER the depreciation carve-out; deriving it at the opening as
     * (1 - margin) x (1 - fixed_cost_ratio) ignores that carve-out and overstates the cost ratio by a median 2% and
     * up to 16% on capital-intensive firms, always in the same direction.
     */
    public function testLearnedParametersOpenUnset(): void
    {
        foreach ($this->openBoard($this->newListings()) as $ticker => $stock) {
            foreach (['structuralVariableMargin', 'inflationPassThrough', 'assetTurnover', 'reportedOperatingMargin'] as $property) {
                $this->assertNull((new \ReflectionProperty(Stock::class, $property))->getValue($stock), sprintf('%s opens with "%s" already set.', $ticker, $property));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function fundResetAssignedColumns(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Command/MarketResetCommand.php');

        $this->assertSame(
            1,
            preg_match('/UPDATE etfs\s+SET(.*?)WHERE ticker = :ticker/s', $source, $statement),
            'Could not find the fund reset UPDATE — if the reset was restructured, update this test with it.'
        );

        preg_match_all('/^\s*([a-z_]+)\s*=/m', $statement[1], $assignments);

        return $assignments[1];
    }

    /**
     * The same guard, for the fund.
     *
     * The reset already reopens a fund's books by hand-listed SQL, and it had been missing
     * `cumulative_trading_costs` since that column was added: a new market opened with the rebalance
     * spread of the old one already charged against it. A column added to Etf and not to the statement is
     * the same failure, and nothing was checking for it.
     */
    public function testResetWritesEveryPersistedFundColumn(): void
    {
        $missing = array_diff(
            array_keys($this->fundColumns()),
            $this->fundResetAssignedColumns(),
            array_keys(self::FUND_INTENTIONALLY_PRESERVED)
        );

        $this->assertSame([], array_values($missing), sprintf(
            "app:market-reset does not clear these Etf columns, so the old market's fund books survive into the new one: %s",
            implode(', ', $missing)
        ));
    }

}
