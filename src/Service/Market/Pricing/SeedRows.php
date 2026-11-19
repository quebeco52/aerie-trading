<?php

declare(strict_types=1);

namespace App\Service\Market\Pricing;

use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;

/**
 * The seed rows as the board opens them. A row of App\Data\Company\InitialMarket states a firm's character; where its
 * business model builds the books that character implies (resolveOpeningBooks()), the balance sheet, margin and return
 * are filled in here, so the seed, the reset and every test read one set of books.
 */
final class SeedRows
{
    /**
     * Marks a row whose books are resolved, so resolving it again hands it back as it is: a caller that changes a book
     * on a resolved row keeps its change.
     */
    public const RESOLVED = 'books_resolved';

    // --- Row Defaults ---
    /** Shares every firm lists with (1bn): price levels differ by size, the share count does not. */
    public const SHARES_OUTSTANDING = 1_000_000_000;

    /** @var list<array<string, mixed>>|null The rows at the opening macro, in the seed's order. */
    private static ?array $opening = null;

    /**
     * One row with the books its model builds at the macro state. A row states only what sets the firm apart: the
     * share count and the industry's depreciation rate are filled in where it is silent. capex_ratio is a lender's
     * reinvestment rate and stays absent on an operating firm, whose plant the capital budget plans.
     *
     * @param array<string, mixed> $row A row of InitialMarket::STOCKS.
     * @return array<string, mixed>
     */
    public static function resolve(array $row, ?MacroStateDTO $openingMacro = null): array
    {
        if (!empty($row[self::RESOLVED])) {
            return $row;
        }

        $row += [
            'shares_outstanding' => self::SHARES_OUTSTANDING,
            'depreciation_rate' => Sectors::metricsFor($row['industry'] ?? null)['depreciation'],
        ];

        return [self::RESOLVED => true] + Sectors::strategyFor($row['industry'] ?? null)->resolveOpeningBooks($row, $openingMacro ?? new MacroStateDTO());
    }

    /**
     * Every row at the opening macro, in the seed's order.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$opening === null) {
            $opening = new MacroStateDTO();
            self::$opening = array_map(static fn(array $row): array => self::resolve($row, $opening), InitialMarket::STOCKS);
        }

        return self::$opening;
    }

    /**
     * One ticker's row at the opening macro.
     *
     * @return array<string, mixed>
     */
    public static function for(string $ticker): array
    {
        foreach (self::all() as $row) {
            if ($row['ticker'] === $ticker) {
                return $row;
            }
        }

        throw new \InvalidArgumentException("No seed row for {$ticker}.");
    }
}
