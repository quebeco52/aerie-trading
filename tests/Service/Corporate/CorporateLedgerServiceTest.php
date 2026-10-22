<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\News\EventPresenter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CorporateLedgerServiceTest extends TestCase
{
    private EntityManagerInterface&Stub $entityManagerMock;
    private Connection&MockObject $connectionMock;
    private CorporateLedgerService $service;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createStub(EntityManagerInterface::class);
        $this->connectionMock = $this->createMock(Connection::class);
        $this->entityManagerMock->method('getConnection')->willReturn($this->connectionMock);

        $this->service = new CorporateLedgerService($this->entityManagerMock);
    }

    public function testProcessDividendPaymentWritesLedgerThenCreditsCashFromIt(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIV_CORP');
        $paidAt = new \DateTimeImmutable('2026-09-10 14:30:00');

        // Two statements: the ledger, then the cash movement derived from it.
        $captured = [];
        $this->connectionMock->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$captured): int {
                $captured[] = ['sql' => $sql, 'params' => $params];

                return 1;
            });

        $this->service->processDividendPayment($stock, 1.25, $paidAt);

        [$insert, $update] = $captured;

        // The ledger is written first, from the holdings snapshot.
        $this->assertStringContainsString('INSERT INTO dividend_payment', $insert['sql']);
        $this->assertStringContainsString('user_stocks', $insert['sql']);
        $this->assertStringContainsString("trade_orders", $insert['sql']);
        $this->assertSame(1.25, $insert['params']['dividend']);
        $this->assertSame('DIV_CORP', $insert['params']['ticker']);
        $this->assertSame('2026-09-10 14:30:00', $insert['params']['paid_at']);

        // Cash is then credited FROM those rows, not from a second copy of the subquery. If this join ever
        // becomes an independent recomputation the ledger stops reconciling to the cash it explains. The
        // addition is signed, so the same statement debits a short's negative row.
        $this->assertStringContainsString('UPDATE users u', $update['sql']);
        $this->assertStringContainsString('INNER JOIN dividend_payment d', $update['sql']);
        $this->assertStringContainsString('u.cash_balance + d.amount', $update['sql']);
        $this->assertStringNotContainsString('d.amount <', $update['sql']);
        $this->assertSame($insert['params']['paid_at'], $update['params']['paid_at']);
        $this->assertSame($insert['params']['ticker'], $update['params']['ticker']);
    }

    /**
     * An open BUY has escrowed cash, not shares. Paying a dividend on it would let anyone park a limit buy
     * far below market and collect income indefinitely on stock they never bought, with the escrow still
     * refundable on cancel. Only the SELL leg belongs in the union, because a SELL has already had its
     * shares removed from user_stocks and would otherwise go unpaid.
     */
    public function testProcessDividendPaymentPaysOpenSellEscrowButNotOpenBuyOrders(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIV_CORP');

        $inserts = [];
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$inserts): int {
                if (str_contains($sql, 'INSERT INTO dividend_payment')) {
                    $inserts[] = $sql;
                }

                return 1;
            });

        $this->service->processDividendPayment($stock, 1.25, new \DateTimeImmutable());

        $this->assertCount(1, $inserts);
        $this->assertStringContainsString("status = 'OPEN' AND action = 'SELL'", $inserts[0]);
        $this->assertStringNotContainsString("action = 'BUY'", $inserts[0]);
    }

    /**
     * A short borrowed the shares from someone still entitled to the distribution, so it pays rather than
     * receives; anything else makes a high-yield name free to be short. It is netted in the same row as any
     * shares the account has escrowed behind a resting SELL: an account can rest a SELL and then go short, and
     * a second row for it on the same ex-date breaks the unique (user, ticker, paid_at) key, which rolled back
     * the whole tick at every payment.
     */
    public function testProcessDividendPaymentNetsAShortAgainstEscrowedSharesInOneRow(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIV_CORP');

        $inserts = [];
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$inserts): int {
                if (str_contains($sql, 'INSERT INTO dividend_payment')) {
                    $inserts[] = $sql;
                }

                return 1;
            });

        $this->service->processDividendPayment($stock, 1.25, new \DateTimeImmutable());

        $this->assertCount(1, $inserts, 'One ledger row per account, or a short with a resting SELL collides.');
        $this->assertStringContainsString('quantity <> 0', $inserts[0], 'Short positions are in the same union.');
        $this->assertStringContainsString('GROUP BY user_id', $inserts[0]);
    }

    public function testProcessDividendPaymentSkipsPositionsRoundingToZero(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIV_CORP');

        $insertSql = null;
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$insertSql): int {
                if ($insertSql === null && str_contains($sql, 'INSERT INTO dividend_payment')) {
                    $insertSql = $sql;
                }

                return 1;
            });

        $this->service->processDividendPayment($stock, 0.0001, new \DateTimeImmutable());

        // WHERE, not HAVING: net_shares is a plain column of the derived table by that point, and HAVING
        // without a GROUP BY in the outer query would collapse every holder into one group. Both signs are
        // kept, since a short's row is negative.
        $this->assertNotNull($insertSql);
        $this->assertStringContainsString('WHERE ROUND(positions.net_shares * :dividend, 2) <> 0', $insertSql);
        $this->assertStringNotContainsString('HAVING', $insertSql);
    }

    /**
     * The tick transaction in MarketTickerCommand already spans both statements. Opening another one here
     * would only nest, and a nested rollBack would mark the whole tick rollback-only.
     */
    public function testProcessDividendPaymentDoesNotOpenItsOwnTransaction(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIV_CORP');

        $this->connectionMock->expects($this->never())->method('beginTransaction');
        $this->connectionMock->expects($this->never())->method('commit');
        $this->connectionMock->method('executeStatement')->willReturn(1);

        $this->service->processDividendPayment($stock, 1.25, new \DateTimeImmutable());
    }

    public function testProcessForwardStockSplitExecutesUpdatesAndCommits(): void
    {
        $stock = new Stock();
        $stock->setTicker('SPLIT_CORP');

        $this->connectionMock->expects($this->once())->method('beginTransaction');
        $this->connectionMock->expects($this->once())->method('commit');
        $this->connectionMock->expects($this->never())->method('rollBack');

        // Forward split executes 6 SQL statements: user_stocks, stock_history, corporate_report, open
        // trade_orders, the filled trade_orders the cost basis is derived from, and the price alerts.
        $this->connectionMock->expects($this->exactly(6))
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE'),
                $this->arrayHasKey('factor'),
            );

        $this->service->processStockSplit($stock, 2.0, false);
    }

    public function testProcessReverseStockSplitCashesOutRemnantsAndCommits(): void
    {
        $stock = new Stock();
        $stock->setTicker('REV_CORP');

        $this->connectionMock->expects($this->once())->method('beginTransaction');
        $this->connectionMock->expects($this->once())->method('commit');

        // Mock 2 users: user 1 has 15 shares (15 / 10 = 1 share, 5 remnant -> cashout), user 2 has 20 shares (0
        // remnant). The other fetches are the name's listed options and the event feed, both empty here.
        $this->connectionMock->expects($this->exactly(3))
            ->method('fetchAllAssociative')
            ->willReturnCallback(static fn(string $sql): array => str_contains($sql, 'stock_events') || str_contains($sql, 'option_contracts') ? [] : [
                ['id' => 1, 'user_id' => 101, 'quantity' => 15],
                ['id' => 2, 'user_id' => 102, 'quantity' => 20],
            ]);

        // 1 holdings cashout + 9 bulk updates (the order book's escrow refund, open orders, cancellations and filled
        // history; the four stock tables; the price alerts) = 10 calls
        $this->connectionMock->expects($this->exactly(10))
            ->method('executeStatement');

        $this->service->processStockSplit($stock, 10.0, true, 2.50);
    }

    /**
     * A split restates every trigger in the new units: a resting stop and a waiting alert keep their distance from
     * the price instead of firing on the split itself.
     */
    public function testAForwardSplitRestatesStopPricesAndAlerts(): void
    {
        $stock = new Stock();
        $stock->setTicker('SPLIT_CORP');

        $statements = [];
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            });

        $this->service->processStockSplit($stock, 2.0, false);

        $openOrders = array_values(array_filter($statements, static fn(array $s): bool => str_contains($s[0], "status = 'OPEN'")));
        $this->assertCount(1, $openOrders);
        $this->assertStringContainsString('stop_price = ROUND(stop_price / :factor, 4)', $openOrders[0][0]);

        $alerts = array_values(array_filter($statements, static fn(array $s): bool => str_contains($s[0], 'price_alerts')));
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('target_price = ROUND(target_price / :factor, 4)', $alerts[0][0]);
        $this->assertSame(2, $alerts[0][1]['factor']);
    }

    /** A buy stop escrowed no cash, so a reverse split's remnant refund pays it nothing; its stop is restated. */
    public function testAReverseSplitRefundsNoRemnantOnABuyStopAndRestatesItsStop(): void
    {
        $stock = new Stock();
        $stock->setTicker('REV_CORP');

        $this->connectionMock->method('fetchAllAssociative')->willReturn([]);
        $statements = [];
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            });

        $this->service->processStockSplit($stock, 10.0, true, 2.50);

        $refund = array_values(array_filter($statements, static fn(array $s): bool => str_contains($s[0], 'remnant_value')));
        $this->assertCount(1, $refund);
        $this->assertStringContainsString("o.action = 'BUY' AND o.order_type NOT IN ('STOP', 'STOP_LIMIT')", $refund[0][0]);

        $restate = array_values(array_filter($statements, static fn(array $s): bool => str_contains($s[0], "status = 'OPEN'") && str_contains($s[0], 'limit_price = ROUND(limit_price * :factor, 4)')));
        $this->assertCount(1, $restate);
        $this->assertStringContainsString('stop_price = ROUND(stop_price * :factor, 4)', $restate[0][0]);

        $alerts = array_values(array_filter($statements, static fn(array $s): bool => str_contains($s[0], 'price_alerts')));
        $this->assertStringContainsString('target_price = ROUND(target_price * :factor, 4)', $alerts[0][0]);
        $this->assertSame(10, $alerts[0][1]['factor']);
    }

    public function testProcessStockSplitRollsBackOnException(): void
    {
        $stock = new Stock();
        $stock->setTicker('ERR_CORP');

        $this->connectionMock->expects($this->once())->method('beginTransaction');
        $this->connectionMock->expects($this->never())->method('commit');
        $this->connectionMock->expects($this->once())->method('rollBack');

        $this->connectionMock->method('executeStatement')
            ->willThrowException(new \RuntimeException('DB Connection Lost'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB Connection Lost');

        $this->service->processStockSplit($stock, 2.0, false);
    }

    /**
     * A reverse split has to settle a SHORT position's fraction too, and round it toward zero.
     *
     * Shorts are held as a negative quantity in the same table as longs. The rewrite rescaled them with
     * FLOOR, which rounds toward NEGATIVE infinity, so a 105-share short at a 1-for-10 came out eleven
     * shares short rather than ten — half a share of extra exposure the borrower never sold and was never
     * paid for. The cashout loop beside it only looked at quantity > 0, so nothing compensated them either.
     */
    /**
     * @var list<array{sql: string, params: array<string, mixed>}> Every statement a split sent, with its parameters.
     */
    private array $statements = [];

    /**
     * Captures every statement a split sends in $statements, and answers the option queries with this chain.
     *
     * @param list<array<string, mixed>> $contracts
     * @param list<array<string, mixed>> $positions
     */
    private function splitWithOptions(array $contracts, array $positions): void
    {
        $this->statements = [];
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []): int {
                $this->statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            });
        $this->connectionMock->method('fetchAllAssociative')
            ->willReturnCallback(static fn(string $sql): array => match (true) {
                str_contains($sql, 'ORDER BY strike') => $contracts,
                str_contains($sql, 'JOIN option_contracts') => $positions,
                str_contains($sql, 'FROM user_options') => array_map(static fn(array $p): array => ['user_id' => $p['user_id'], 'quantity' => abs($p['quantity'])], $positions),
                default => [],
            });
    }

    /**
     * A split restates every listed option as a clearing house does: each holder keeps the same claim. Four-for-one
     * divides strikes and premiums by four and multiplies the contracts by four, renaming in rising strike order so no
     * symbol is taken while its holder is still waiting to move.
     */
    public function testAForwardSplitRestatesTheListedOptions(): void
    {
        $stock = (new Stock())->setTicker('TALL');
        (new \ReflectionProperty(Stock::class, 'id'))->setValue($stock, 9);
        $this->splitWithOptions(
            [
                ['id' => 8, 'option_type' => 'PUT', 'strike' => '100.00000000', 'expiry_serial' => 12, 'price' => '2.00000000'],
                ['id' => 7, 'option_type' => 'CALL', 'strike' => '400.00000000', 'expiry_serial' => 12, 'price' => '20.00000000'],
            ],
            [['user_id' => 101, 'quantity' => 3, 'price' => '20.00000000']],
        );

        $this->service->processStockSplit($stock, 4.0, false);

        $sqls = array_column($this->statements, 'sql');
        $positions = array_values(array_filter($this->statements, static fn(array $s): bool => str_contains($s['sql'], 'UPDATE user_options')));
        $this->assertCount(1, $positions);
        $this->assertStringContainsString('quantity = quantity * :factor', $positions[0]['sql']);
        $this->assertStringContainsString('average_premium = average_premium / :factor', $positions[0]['sql']);
        $this->assertSame(4, $positions[0]['params']['factor']);

        $renames = array_values(array_filter($this->statements, static fn(array $s): bool => str_contains($s['sql'], 'SET ticker = :ticker')));
        $this->assertSame(['TALL-12P25', 'TALL-12C100'], array_map(static fn(array $s): string => $s['params']['ticker'], $renames), 'Renamed in rising strike order.');
        $this->assertSame(['25.00000000', '100.00000000'], array_map(static fn(array $s): string => $s['params']['strike'], $renames));
        $this->assertEqualsWithDelta(0.25, $renames[0]['params']['scale'], 1e-12, 'Premium, vega and theta per share scale with the price.');
        $this->assertNotEmpty(array_filter($sqls, static fn(string $sql): bool => str_contains($sql, 'SET oc.open_interest')), 'Open interest is read back from the positions.');
        $this->assertEmpty(array_filter($sqls, static fn(string $sql): bool => str_contains($sql, ':cashout') && str_contains($sql, 'cash_balance')), 'A forward split leaves no fraction to settle.');
    }

    /**
     * One-for-ten multiplies strikes by ten and divides the contracts by ten, toward zero as the shares are; the
     * fraction left over is old contracts at the old mark, so a holder is paid for its own and a writer pays for what
     * it no longer owes. Renamed in falling strike order, the way the strikes move.
     */
    public function testAReverseSplitRestatesTheListedOptionsAndSettlesTheFraction(): void
    {
        $stock = (new Stock())->setTicker('TINY');
        (new \ReflectionProperty(Stock::class, 'id'))->setValue($stock, 9);
        $this->splitWithOptions(
            [
                ['id' => 7, 'option_type' => 'CALL', 'strike' => '3.00000000', 'expiry_serial' => 12, 'price' => '0.30000000'],
                ['id' => 8, 'option_type' => 'CALL', 'strike' => '1.50000000', 'expiry_serial' => 12, 'price' => '0.60000000'],
            ],
            [
                ['user_id' => 101, 'quantity' => 15, 'price' => '0.30000000'],
                ['user_id' => 102, 'quantity' => -25, 'price' => '0.30000000'],
            ],
        );

        $this->service->processStockSplit($stock, 10.0, true, 1.20);

        $cashouts = [];
        foreach ($this->statements as $s) {
            if (str_contains($s['sql'], 'UPDATE users SET cash_balance = cash_balance + :cashout')) {
                $cashouts[(int) $s['params']['user_id']] = (float) $s['params']['cashout'];
            }
        }
        $this->assertEqualsWithDelta(150.0, $cashouts[101] ?? null, 1e-9, 'Five old contracts at 0.30 a share, a hundred shares each.');
        $this->assertEqualsWithDelta(-150.0, $cashouts[102] ?? null, 1e-9, 'The writer pays for the five it no longer owes.');

        $positions = array_values(array_filter($this->statements, static fn(array $s): bool => str_contains($s['sql'], 'UPDATE user_options')));
        $this->assertStringContainsString('TRUNCATE(quantity / :factor, 0)', $positions[0]['sql']);

        $renames = array_values(array_filter($this->statements, static fn(array $s): bool => str_contains($s['sql'], 'SET ticker = :ticker')));
        $this->assertSame(['TINY-12C30', 'TINY-12C15'], array_map(static fn(array $s): string => $s['params']['ticker'], $renames), 'Renamed in falling strike order.');
    }

    /**
     * A split restates the name's own share counts: the stock it still has to put through the market, and its short
     * interest read back from the restated positions.
     */
    public function testASplitRestatesTheBacklogAndShortInterest(): void
    {
        $stock = (new Stock())->setTicker('TALL')->setCorporateFlowBacklog(1500.0)->setShortInterestShares('100.00');
        $this->splitWithOptions([], []);
        $this->connectionMock->method('fetchOne')->willReturnCallback(
            static fn(string $sql): string => str_contains($sql, 'user_stocks') ? '400' : '0',
        );

        $this->service->processStockSplit($stock, 4.0, false);

        $this->assertSame(6000.0, $stock->getCorporateFlowBacklog());
        $this->assertSame('400.00', $stock->getShortInterestShares());
    }

    public function testReverseSplitSettlesShortFractionsAndRoundsThemTowardZero(): void
    {
        $stock = new Stock();
        $stock->setTicker('SHORTY');

        $statements = [];
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            });

        $fetched = null;
        $this->connectionMock->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$fetched): array {
                if (!str_contains($sql, 'user_stocks')) {
                    return [];
                }
                $fetched = $sql;

                return [
                    ['id' => 1, 'user_id' => 101, 'quantity' => 105],   // long: 10 new shares, 5 old over
                    ['id' => 2, 'user_id' => 102, 'quantity' => -105],  // short: owes 10 new shares, 5 old over
                ];
            });

        $this->service->processStockSplit($stock, 10.0, true, 2.50);

        // Short positions must be in the settlement population at all.
        $this->assertNotNull($fetched);
        $this->assertStringContainsString('quantity <> 0', $fetched, 'A reverse split must settle short positions, not just long ones.');

        $cashouts = [];
        foreach ($statements as $s) {
            if (str_contains($s['sql'], 'cash_balance = cash_balance + :cashout')) {
                $cashouts[(int) $s['params']['user_id']] = (float) $s['params']['cashout'];
            }
        }

        // The long is bought out of its five leftover shares; the short buys its five back. Same price,
        // opposite direction.
        $this->assertEqualsWithDelta(12.50, $cashouts[101] ?? null, 0.0001, 'A long is credited for the fraction bought out of it.');
        $this->assertEqualsWithDelta(-12.50, $cashouts[102] ?? null, 0.0001, 'A short is debited for the fraction it has to buy in.');

        // And the rewrite rounds toward zero, so the debit above matches the position that survives.
        $rewrites = array_values(array_filter(
            $statements,
            static fn(array $s): bool => str_contains($s['sql'], 'UPDATE user_stocks SET quantity'),
        ));
        $this->assertCount(1, $rewrites);
        $this->assertStringContainsString('TRUNCATE(quantity / :factor, 0)', $rewrites[0]['sql']);
        $this->assertStringNotContainsString('FLOOR(quantity / :factor)', $rewrites[0]['sql'], 'FLOOR rounds a short away from zero.');
    }

    /**
     * A reverse split may only refund escrow to the sides that posted some.
     *
     * A resting SHORT locates its borrow and posts margin at the fill; a resting COVER is the closing leg
     * of an already-collateralized position. Neither commits a dollar or a share when it is placed, so
     * neither has anything to refund. Falling through to a share valuation on ELSE paid both of them the
     * fractional remnant at the pre-split price — cash from nothing, and parkable by leaving a resting
     * short on a name heading for a reverse split.
     */
    public function testReverseSplitRefundsEscrowOnlyToSidesThatPostedIt(): void
    {
        $stock = new Stock();
        $stock->setTicker('ESCROW');

        $statements = [];
        $this->connectionMock->method('fetchAllAssociative')->willReturn([]);
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            });

        $this->service->processStockSplit($stock, 10.0, true, 2.50);

        $refunds = array_values(array_filter(
            $statements,
            static fn(array $s): bool => str_contains($s['sql'], 'remnant_value'),
        ));
        $this->assertCount(1, $refunds, 'A reverse split refunds escrow remnants exactly once.');
        $sql = $refunds[0]['sql'];

        $this->assertStringContainsString("WHEN o.action = 'BUY'", $sql, 'A resting BUY holds committed cash.');
        $this->assertStringContainsString("WHEN o.action = 'SELL'", $sql, 'A resting SELL holds shares taken out of user_stocks.');
        $this->assertMatchesRegularExpression(
            '/ELSE\s+0\b/',
            $sql,
            'Every other side — SHORT and COVER — escrows nothing and must be refunded nothing.',
        );
    }

    /**
     * A split restates the event feed with the ledger.
     *
     * Event text carries the quarter's EPS, the surprise against consensus, the dividend rate and the
     * buyback count in the units of its day. Every price and share count in the tables has just moved
     * to the new basis, so left alone the feed read "Q-Earnings: $11.75" one quarter and "$3.56" the
     * next across a 4-for-1 that changed nothing. Dollar totals (EVA, total paid) are not per-share and
     * must be left exactly as written.
     */
    public function testForwardSplitRestatesPerShareFiguresInTheEventFeed(): void
    {
        $stock = new Stock();
        $stock->setTicker('FEED');

        $this->connectionMock->method('fetchAllAssociative')
            ->willReturnCallback(static fn(string $sql): array => str_contains($sql, 'stock_events') ? [
                ['id' => 7, 'description' => "Q-Earnings: $11.75 (Beat expectations by $0.32 | +$80.92B EVA).\n• Paid $0.60/share div ($27.64B total, 1.97% yield).\n• Bought back 410,288,930 shares."],
                ['id' => 8, 'description' => "Q-Earnings: $1.00 (Met expectations exactly | +$5.00M EVA).\n• Launched next-gen AI platform."],
            ] : []);

        $rewrites = [];
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$rewrites): int {
                if (str_contains($sql, 'UPDATE stock_events')) {
                    $rewrites[(int) $params['id']] = $params['description'];
                }

                return 1;
            });

        $this->service->processStockSplit($stock, 4.0, false);

        $this->assertSame(
            "Q-Earnings: $2.94 (Beat expectations by $0.08 | +$80.92B EVA).\n• Paid $0.15/share div ($27.64B total, 1.97% yield).\n• Bought back 1,641,155,720 shares.",
            $rewrites[7] ?? null,
            'EPS, surprise and dividend divide by the factor, the buyback count multiplies, EVA and totals are untouched.',
        );
        $this->assertSame(
            "Q-Earnings: $0.25 (Met expectations exactly | +$5.00M EVA).\n• Launched next-gen AI platform.",
            $rewrites[8] ?? null,
        );

        // The restated text must still be readable by the feed's parser, or the restatement has traded a
        // wrong number for a blank card.
        $event = new StockEvent();
        $event->setEventType('EARNINGS');
        $event->setDescription($rewrites[7]);
        $presented = (new EventPresenter())->present($event);
        $this->assertSame('$2.94', $presented['eps']);
        $this->assertSame('beat', $presented['surpriseType']);
        $this->assertSame('$0.08', $presented['surpriseAmount']);
        $this->assertSame('+$80.92B EVA', $presented['eva']);
        $this->assertStringContainsString('$0.15/sh', $presented['pills'][0]['text']);
        $this->assertStringContainsString('1.64B shares', $presented['pills'][1]['text']);
    }

    /** A reverse split moves the same figures the other way, and keeps a negative EPS negative. */
    /** A filing with special items prints a reported figure beside the adjusted one; both are per share. */
    public function testASplitRestatesTheReportedFigureBesideTheAdjustedOne(): void
    {
        $stock = new Stock();
        $stock->setTicker('FEED');

        $this->connectionMock->method('fetchAllAssociative')
            ->willReturnCallback(static fn(string $sql): array => str_contains($sql, 'stock_events') ? [
                ['id' => 9, 'description' => 'Q-Earnings: $2.84 adjusted, -$4.80 reported (Beat expectations by $0.20 | +$1.20B EVA).'],
            ] : []);

        $rewrites = [];
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$rewrites): int {
                if (str_contains($sql, 'UPDATE stock_events')) {
                    $rewrites[(int) $params['id']] = $params['description'];
                }

                return 1;
            });

        $this->service->processStockSplit($stock, 4.0, false);

        $this->assertSame('Q-Earnings: $0.71 adjusted, -$1.20 reported (Beat expectations by $0.05 | +$1.20B EVA).', $rewrites[9]);
    }

    public function testReverseSplitRestatesTheEventFeedTheOtherWay(): void
    {
        $stock = new Stock();
        $stock->setTicker('REVFEED');

        $this->connectionMock->method('fetchAllAssociative')
            ->willReturnCallback(static fn(string $sql): array => str_contains($sql, 'stock_events') ? [
                ['id' => 3, 'description' => "Q-Earnings: -$0.45 (Missed expectations by $0.10 | -$15.00M EVA).\n• Bought back 1,000 shares."],
            ] : []);

        $rewrites = [];
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$rewrites): int {
                if (str_contains($sql, 'UPDATE stock_events')) {
                    $rewrites[(int) $params['id']] = $params['description'];
                }

                return 1;
            });

        $this->service->processStockSplit($stock, 10.0, true, 2.50);

        $this->assertSame(
            "Q-Earnings: -$4.50 (Missed expectations by $1.00 | -$15.00M EVA).\n• Bought back 100 shares.",
            $rewrites[3] ?? null,
        );
    }

    /** A row with nothing per-share in it is not rewritten at all. */
    public function testEventFeedRestatementLeavesRowsWithoutPerShareFiguresAlone(): void
    {
        $stock = new Stock();
        $stock->setTicker('QUIET');

        $this->connectionMock->method('fetchAllAssociative')
            ->willReturnCallback(static fn(string $sql): array => str_contains($sql, 'stock_events') ? [
                ['id' => 1, 'description' => 'Issued $18.31B in bonds for expansion.'],
            ] : []);

        $eventRewrites = 0;
        $this->connectionMock->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$eventRewrites): int {
                if (str_contains($sql, 'UPDATE stock_events')) {
                    $eventRewrites++;
                }

                return 1;
            });

        $this->service->processStockSplit($stock, 4.0, false);

        $this->assertSame(0, $eventRewrites);
    }
}
