<?php

declare(strict_types=1);

namespace App\Service\Market\Pricing;

use App\Data\Company\IndustryRisks;
use App\Data\Company\IndustryTurnovers;
use App\Data\Company\InitialMarket;
use App\Data\Company\Moat;
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
    /**
     * Derived opening fields a seed row must never state; computed at opening from rating and LGD.
     *
     * @var list<string>
     */
    public const DERIVED_KEYS = ['credit_spread'];

    /** Share of a listing that trades when no founder, mutual or state block holds the rest (85%, insiders aside). */
    public const DEFAULT_PUBLIC_FLOAT = 0.85;

    /** @var list<array<string, mixed>>|null The rows at the opening macro, in the seed's order. */
    private static ?array $opening = null;

    /**
     * One row with the books its model builds at the macro state. A row states only what sets the firm apart: the
     * sector its industry files under, a widely held float, the share count and the industry's depreciation rate are
     * filled in where it is silent. capex_ratio is a lender's
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

        $strategy = Sectors::strategyFor($row['industry'] ?? null);
        $derived = array_intersect(self::DERIVED_KEYS, array_keys($row));
        if ($strategy->derivesOpeningCoupon() && array_key_exists('historical_fixed_rate', $row)) {
            $derived[] = 'historical_fixed_rate';
        }
        if ($strategy->derivesOpeningFloatingShare() && array_key_exists('floating_debt_ratio', $row)) {
            $derived[] = 'floating_debt_ratio';
        }
        if ($derived !== []) {
            throw new \DomainException(sprintf(
                '%s states %s, which is derived at opening rather than seeded.',
                (string) ($row['ticker'] ?? '?'),
                implode(', ', $derived),
            ));
        }

        IndustryRisks::assertRow($row);
        IndustryTurnovers::assertCapitalIntensity($row);

        $row += [
            'sector' => Sectors::sectorFor($row['industry'] ?? null),
            'public_float' => self::DEFAULT_PUBLIC_FLOAT,
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

    /**
     * Refuses a character row its model cannot build from: an input missing, a book stated beside the character (it
     * would be overwritten without a word), an input outside its range, or a moat off the ladder.
     *
     * @param array<string, mixed> $row
     * @param list<string> $characterKeys What the row must state.
     * @param list<string> $bookKeys What the model builds, so the row must not state it.
     * @param array<string, bool> $inRange Each input's range check, by key.
     */
    public static function assertCharacter(array $row, array $characterKeys, array $bookKeys, array $inRange): void
    {
        $missing = array_diff($characterKeys, array_keys($row));
        $stated = array_intersect($bookKeys, array_keys($row));
        if (isset($row['moat']) && Moat::tryFrom((string) $row['moat']) === null) {
            $inRange['moat'] = false;
        }
        $outOfRange = array_keys(array_filter($inRange, static fn(bool $ok): bool => !$ok));

        if ($missing !== [] || $stated !== [] || $outOfRange !== []) {
            throw new \DomainException(sprintf(
                '%s states its character with %s missing, %s stated beside it, %s out of range.',
                (string) ($row['ticker'] ?? '?'),
                implode(', ', $missing) ?: 'nothing',
                implode(', ', $stated) ?: 'no book',
                implode(', ', $outOfRange) ?: 'nothing',
            ));
        }
    }
}
