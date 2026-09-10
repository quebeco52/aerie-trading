<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Service\User\Portfolio;
use PHPUnit\Framework\TestCase;

/**
 * Net asset value has to include what is working in open limit orders.
 *
 * Placing a limit order moves the assets out of the balances a naive NAV sums: a BUY debits the cash to
 * escrow and a SELL removes the shares from user_stocks (TradeExecutionService). Every query that totals
 * a user's net worth therefore has to join the open order book back in, or placing an order destroys net
 * worth on the chart and on the leaderboard and cancelling it creates net worth back.
 *
 * These are source guards rather than integration tests: the queries are raw SQL against a MySQL schema
 * the fast suite has no connection to, so what is checkable here is that no NAV query is written without
 * the escrow leg. That is exactly the mistake being guarded against — the dividend payout SQL already
 * unioned open SELL orders, so the escrow was known about and simply missed in these three places.
 */
final class PortfolioEscrowTest extends TestCase
{
    private const NAV_SOURCES = [
        'src/Service/User/Portfolio.php',
        'src/Controller/LeaderboardController.php',
        'src/Controller/DashboardController.php',
    ];

    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 3) . '/' . $relativePath;
        $contents = file_get_contents($path);
        self::assertIsString($contents, "Unable to read {$relativePath}");

        return $contents;
    }

    /** The shared fragment values a BUY at the cash committed and a SELL at the asset's live price. */
    public function testEscrowFragmentValuesBothSidesOfTheBook(): void
    {
        $sql = Portfolio::OPEN_ORDER_ESCROW_SQL;

        $this->assertStringContainsString("o.status = 'OPEN'", $sql);
        $this->assertStringContainsString("WHEN o.action = 'BUY'", $sql);
        $this->assertStringContainsString('o.limit_price', $sql, 'A resting BUY is held at the price the cash was committed at.');
        $this->assertStringContainsString('COALESCE(s.price, e.price, b.price, 0)', $sql, 'Escrowed units are held at the live price.');
        $this->assertStringContainsString('GROUP BY o.user_id', $sql);
    }

    /**
     * Only the two sides that actually reserve something are valued.
     *
     * A resting SHORT escrows nothing — the borrow is located when it fills — and a resting COVER escrows
     * nothing either, so valuing them at the live price credited an account for an asset it does not have.
     * Working an offer inflated net worth, and cancelling it took the phantom back.
     */
    public function testTheSidesThatReserveNothingAreValuedAtNothing(): void
    {
        $sql = Portfolio::OPEN_ORDER_ESCROW_DETAIL_SQL;

        $this->assertStringNotContainsString("'SHORT'", $sql, 'A resting short reserves nothing to value.');
        $this->assertStringNotContainsString("'COVER'", $sql, 'Neither does a resting cover.');
        $this->assertStringContainsString("WHEN o.action = 'SELL'", $sql, 'Escrowed shares are named explicitly, not left to an ELSE.');
    }

    /**
     * The margin surface reads the open book from the same fragment net asset value does.
     *
     * The two disagreeing is what let a legal limit order call the account that placed it: NAV counted the
     * escrow, MarginEngine did not, and the surface that triggers liquidation was the one that was short.
     */
    public function testTheMarginSurfaceReadsTheSameOpenBook(): void
    {
        $this->assertStringContainsString(
            'Portfolio::OPEN_ORDER_ESCROW_DETAIL_SQL',
            $this->read('src/Service/Market/MarginEngine.php'),
            'MarginEngine must value the open book on the same definition as net asset value.'
        );
    }

    /** Every asset class resolves, since one order table serves them all and the ticker is the only key. */
    public function testEscrowFragmentResolvesEveryAssetClass(): void
    {
        $sql = Portfolio::OPEN_ORDER_ESCROW_SQL;

        $this->assertStringContainsString('LEFT JOIN stocks s ON s.ticker = o.ticker', $sql);
        $this->assertStringContainsString('LEFT JOIN etfs   e ON e.ticker = o.ticker', $sql);
        $this->assertStringContainsString('LEFT JOIN bonds  b ON b.ticker = o.ticker', $sql);
    }

    /**
     * Every surface that totals net worth values every asset class.
     *
     * The escrow bug this class was written for was one omission repeated across three files. Adding an
     * asset class is the same shape of mistake: a holding the snapshot counts but the leaderboard does not
     * ranks a bond investor below where their money actually is, and nothing reports a discrepancy.
     */
    public function testEveryNetWorthQueryValuesBondHoldings(): void
    {
        foreach (self::NAV_SOURCES as $source) {
            // Either spelling counts: the snapshot and leaderboard queries are raw SQL against user_bonds,
            // while the dashboard reaches the same table through DQL on the entity.
            $this->assertMatchesRegularExpression(
                '/user_bonds|UserBond/',
                $this->read($source),
                "{$source} totals a user's net worth and must value bond holdings."
            );
        }
    }

    /** Every surface that totals net worth accounts for the open book. */
    public function testEveryNetWorthQueryAccountsForOpenOrders(): void
    {
        foreach (self::NAV_SOURCES as $source) {
            $this->assertMatchesRegularExpression(
                "/escrow/i",
                $this->read($source),
                "{$source} totals a user's net worth and must include open-order escrow."
            );
        }
    }

    /** The two shared-SQL callers use the one fragment rather than each writing their own join. */
    public function testSharedQueriesUseTheOneFragment(): void
    {
        $this->assertStringContainsString(
            'self::OPEN_ORDER_ESCROW_SQL',
            $this->read('src/Service/User/Portfolio.php'),
            'Both Portfolio snapshots must build on the shared fragment.'
        );
        $this->assertStringContainsString(
            'Portfolio::OPEN_ORDER_ESCROW_SQL',
            $this->read('src/Controller/LeaderboardController.php'),
            'The leaderboard ranks on the same definition of net worth the history chart records.'
        );
    }

    /**
     * Both snapshot paths carry it — the bulk sweep and the single-user one written after every trade.
     *
     * The two are allowed to differ in shape (the per-user path filters rather than grouping over the whole
     * roster, since it runs on every trade), but neither may omit the open book.
     */
    public function testBothSnapshotPathsCarryEscrow(): void
    {
        $source = $this->read('src/Service/User/Portfolio.php');

        foreach (['recordBulkSnapshots', 'recordUserSnapshot'] as $method) {
            $start = strpos($source, "function {$method}(");
            $this->assertIsInt($start, "Portfolio::{$method}() has gone missing.");

            $end = strpos($source, "\n    public function ", $start + 1) ?: strlen($source);
            $body = substr($source, $start, $end - $start);

            // Either by joining the shared fragment or by inlining the same aggregate.
            $this->assertMatchesRegularExpression(
                "/OPEN_ORDER_ESCROW_SQL|trade_orders/",
                $body,
                "{$method}() must value the open order book."
            );
        }
    }
}
