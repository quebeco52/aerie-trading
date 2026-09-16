<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Stock;
use Doctrine\DBAL\Connection;

/**
 * The columns of `stocks` that move on every tick, written as data rather than through the unit of work.
 *
 * Eight fields change on every name on every tick: the price, the instantaneous volatility, the impact
 * variance, the realized variance, the momentum trend, the credit spread, the corporate flow backlog, and
 * the incumbent CEO's tenure — that last because the succession hazard ages every board on every tick.
 * Because they always change, every stock was a dirty entity at every flush, and Doctrine sends one UPDATE per dirty entity —
 * sixty-odd synchronous round trips inside the tick transaction, several times a second, for the same eight
 * numbers on every row. That was the history tick's whole overrun.
 *
 * So these columns are mapped `updatable: false` on the entity (see Stock): the unit of work still tracks
 * them, the setters still keep the in-process entity current, but an UPDATE never carries them, and a stock
 * whose only changes are these eight is not written at all. Whatever flushes the working set must write them
 * here first, in a few bulk statements, or the database keeps yesterday's price. The ticker does so on
 * every history tick, before its flush; anything else that flushes stocks after ticking them must do the
 * same. A stock without an id has no row yet and is left to the INSERT, which carries every column.
 */
final class StockTickColumns
{
    // --- Columns ---
    /** The per-tick columns, in the order the row values are laid out. */
    public const COLUMNS = [
        'price',
        'current_volatility',
        'impact_variance_ema',
        'realized_variance_ema',
        'price_momentum_trend',
        'dynamic_credit_spread',
        'corporate_flow_backlog',
        'ceo_tenure_years',
    ];

    /**
     * Writes the per-tick columns of every persisted stock in the working set.
     *
     * @param array<int, Stock> $stocks
     * @return int Statements sent.
     */
    public static function write(Connection $connection, array $stocks): int
    {
        $rows = self::rows($stocks);

        if ($rows === []) {
            return 0;
        }

        return BulkRowUpdate::apply($connection, 'stocks', self::COLUMNS, $rows);
    }

    /**
     * The row set one write carries: id => values aligned with COLUMNS.
     *
     * @param array<int, Stock> $stocks
     * @return array<int, array<int, mixed>>
     */
    public static function rows(array $stocks): array
    {
        $rows = [];

        foreach ($stocks as $stock) {
            $id = $stock->getId();

            if ($id === null) {
                continue;
            }

            $rows[$id] = [
                $stock->getPrice(),
                $stock->getCurrentVolatility(),
                $stock->getImpactVarianceEma(),
                $stock->getRealizedVarianceEma(),
                $stock->getPriceMomentumTrend(),
                $stock->getDynamicCreditSpread(),
                $stock->getCorporateFlowBacklog(),
                $stock->getCeoTenureYears(),
            ];
        }

        return $rows;
    }
}
