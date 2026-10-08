<?php

namespace App\Command;

use App\Entity\User;
use App\Data\InitialMarket;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Service\Math\FinancialConstants;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Market\Ticker\StockTickColumns;

#[AsCommand(
    name: 'app:market-reset',
    description: 'Soft resets the market timeline but keeps Admin Panel edits safe.',
)]
class MarketResetCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private \Redis $redis,
        private UserPasswordHasherInterface $passwordHasher,
        private \App\Service\Market\Bond\TreasuryAuctionService $treasuryAuction,
        private OpeningBoardBuilder $openingBoard
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $conn = $this->entityManager->getConnection();

        $io->title('Initiating Market Soft Reset');

        $io->text('1. Wiping historical charts and events...');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->executeStatement('TRUNCATE TABLE stock_history');
        $conn->executeStatement('TRUNCATE TABLE etf_history');
        $conn->executeStatement('TRUNCATE TABLE stock_events');
        $conn->executeStatement('TRUNCATE TABLE etf_events');
        $conn->executeStatement('TRUNCATE TABLE user_stocks');
        $conn->executeStatement('TRUNCATE TABLE user_etfs');
        $conn->executeStatement('TRUNCATE TABLE user_bonds');
        $conn->executeStatement('TRUNCATE TABLE bond_history');
        $conn->executeStatement('TRUNCATE TABLE coupon_payment');
        $conn->executeStatement('TRUNCATE TABLE user_options');

        // The chain goes with the ladder, and for the same reason: a contract's expiry is a point in
        // simulation time, so every one of them is either already expired or decades out the moment the
        // clock is reset. The desk relists against the new timeline on its first sweep.
        $conn->executeStatement('TRUNCATE TABLE option_contracts');

        // The whole ladder goes, not just its history. A bond's economics are anchored to simulation time,
        // so an issue sold at year 15 of the old timeline becomes a 25-year bond the moment the clock is
        // reset to zero, with a coupon struck off a curve that no longer exists.
        $conn->executeStatement('TRUNCATE TABLE bonds');
        $conn->executeStatement('TRUNCATE TABLE portfolio_history');
        $conn->executeStatement('TRUNCATE TABLE corporate_report');
        $conn->executeStatement('TRUNCATE TABLE macro_report');
        $conn->executeStatement('TRUNCATE TABLE diet_election');

        // The clock is part of the market's state, not of the installation. Leaving it behind would resume a
        // freshly emptied database part-way through a year it has no history for.
        $conn->executeStatement('TRUNCATE TABLE simulation_clock');

        // Delete any procedurally generated stocks
        $initialTickers = array_column(InitialMarket::STOCKS, 'ticker');
        $placeholders = implode(',', array_fill(0, count($initialTickers), '?'));
        $conn->executeStatement(
            "DELETE FROM stocks WHERE ticker NOT IN ($placeholders)",
            $initialTickers
        );

        // Ensure the test user exists
        $testUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test.test@test.se']);
        if (!$testUser) {
            $testUser = new User();
            $testUser->setEmail('test.test@test.se');
            $testUser->setRoles(['ROLE_ADMIN']);
            $testUser->setUsername('Test');
            $testUser->setIsVerified(true);
            $testUser->setCashBalance('10000.00');
            $testUser->setPassword($this->passwordHasher->hashPassword($testUser, 'test'));
            $this->entityManager->persist($testUser);
        }

        $testUser->setUsername('Test');
        $testUser->setIsVerified(true);
        $this->entityManager->flush();

        $conn->executeStatement('UPDATE users SET cash_balance = 10000.00');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $io->text('2. Flushing Redis cache...');
        $this->redis->flushAll();

        $io->text('3. Reopening every firm on its seed row...');

        // The board is priced against the economy the macro engine opens in, so the first tick does not revalue it.
        $openingMacro = new MacroStateDTO();

        // Every listed firm, kept so the anchor spheres can be marked against the finished board below.
        $board = [];
        $spheres = [];

        foreach (InitialMarket::STOCKS as $stockData) {
            // Reopened in place, so the row keeps the id the rest of the schema refers to, and opened exactly as a
            // fresh seed opens it: a reset market is the market the seed would have opened. A firm added to the
            // seed since this market was first opened is listed now rather than left missing.
            $stock = $this->entityManager->getRepository(Stock::class)->findOneBy(['ticker' => $stockData['ticker']]);
            if ($stock === null) {
                $stock = new Stock();
                $stock->setTicker($stockData['ticker']);
            } else {
                $stock->resetToUnseeded();
            }

            $sphere = $this->openingBoard->open($stock, $stockData, $openingMacro);
            if ($sphere !== null) {
                $spheres[] = $sphere;
            }

            $board[$stockData['ticker']] = $stock;
            $this->entityManager->persist($stock);
        }

        try {
            $this->openingBoard->openSpheres($spheres, $board, $openingMacro);
        } catch (\DomainException $misfit) {
            $io->error($misfit->getMessage());

            return Command::FAILURE;
        }

        // The opening price and the other per-tick columns are mapped non-updatable, so the flush below leaves a
        // reopened row carrying the old market's price on the new market's share count. They are written here, as
        // the ticker writes them; a firm listed for the first time is an INSERT, which carries every column.
        StockTickColumns::write($conn, array_values($board));
        $this->entityManager->flush();

        $io->text('4. Resetting ETF Prices...');
        foreach (InitialMarket::ETFS as $etfData) {
            $params = [
                'name' => $etfData['name'],
                'price' => $etfData['price'],
                'description' => \App\Data\StockInfo::DESCRIPTIONS[$etfData['ticker']] ?? null,
                'expense_ratio' => $etfData['expense_ratio'] ?? 0.0,
                'seed_shares' => FinancialConstants::ETF_SEED_SHARES_OUTSTANDING,
                'ticker' => $etfData['ticker']
            ];

            // Created when missing rather than only updated: a fund added to the seed after a market was
            // first seeded would otherwise never exist on that market, and the index it backs would be
            // struck every tick against a row that is not there.
            $exists = $conn->fetchOne('SELECT id FROM etfs WHERE ticker = :ticker', ['ticker' => $etfData['ticker']]);

            if ($exists === false) {
                $conn->executeStatement(
                    'INSERT INTO etfs (ticker, name, price, description, expense_ratio, basket_per_share, accrued_income, cumulative_fees_paid, cumulative_trading_costs, shares_outstanding, nav_premium, arbitrage_band, recent_distributions, last_distribution_at, updated_at)
                     VALUES (:ticker, :name, :price, :description, :expense_ratio, 1, 0, 0, 0, :seed_shares, 0, 0, NULL, NULL, NOW())',
                    $params
                );
                continue;
            }

            // A reset reopens the timeline, so the fund's BOOKS are reopened with it: the basket goes back
            // to a whole index unit per share and the income it was holding for its members is cleared.
            // Carrying a fee drag and an accrual across a reset would leave the fund already behind an
            // index that has not moved yet, and holding cash collected from companies on a timeline that
            // no longer exists.
            $conn->executeStatement(
                'UPDATE etfs
                    SET name = :name,
                        price = :price,
                        description = :description,
                        expense_ratio = :expense_ratio,
                        basket_per_share = 1,
                        accrued_income = 0,
                        cumulative_fees_paid = 0,
                        cumulative_trading_costs = 0,
                        shares_outstanding = :seed_shares,
                        nav_premium = 0,
                        arbitrage_band = 0,
                        recent_distributions = NULL,
                        last_distribution_at = NULL
                  WHERE ticker = :ticker',
                $params
            );
        }

        // Reopen the bond desk on the fresh timeline.
        $this->treasuryAuction->conductAuction($openingMacro->sovereignCurve(), 0.0);
        $this->entityManager->flush();

        $io->success('Market Reset Complete! You can now start the ticker.');
        return Command::SUCCESS;
    }
}
