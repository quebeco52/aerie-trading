<?php

namespace App\Command;

use App\Data\InitialMarket;
use App\Data\SectorPE;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:market-reset',
    description: 'Soft resets the market timeline but keeps Admin Panel edits safe.',
)]
class MarketResetCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private \Redis $redis
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $conn = $this->entityManager->getConnection();

        $io->title('Initiating Market Soft Reset');

        // TRUNCATE HISTORY (Disable Foreign Keys safely)
        $io->text('1. Wiping historical charts and events...');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->executeStatement('TRUNCATE TABLE stock_history');
        $conn->executeStatement('TRUNCATE TABLE etf_history');
        $conn->executeStatement('TRUNCATE TABLE stock_events');
        $conn->executeStatement('TRUNCATE TABLE etf_events');
        $conn->executeStatement('TRUNCATE TABLE user_stocks');
        $conn->executeStatement('TRUNCATE TABLE portfolio_history'); 
        
        $conn->executeStatement('UPDATE users SET cash_balance = 10000.00'); 
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        // FLUSH REDIS
        $io->text('2. Flushing Redis cache...');
        $this->redis->flushAll();

        // RESET STOCK NUMBERS
        $io->text('3. Resetting Stock Prices & Math based on InitialMarket...');
        foreach (InitialMarket::STOCKS as $stockData) {
            
            // THE NEW EPS MATH
            $targetPE = SectorPE::MACRO_SECTORS[$stockData['sector']] ?? 20.0;
            $neutralEps = (float) $stockData['price'] / $targetPE;

            $conn->executeStatement(
                'UPDATE stocks SET 
                    price = :price, 
                    earnings_per_share = :eps, 
                    shares_outstanding = :shares,
                    volatility = :vol,
                    current_volatility = :vol,
                    beta = :beta,
                    jump_intensity = :jump_int,
                    jump_mean = :jump_mean, 
                    systemic_importance = :importance,
                    jump_vol = :jump_vol
                WHERE ticker = :ticker',
                [
                    'price' => $stockData['price'],
                    'eps' => round($neutralEps, 2),
                    'shares' => $stockData['shares_outstanding'],
                    'vol' => $stockData['volatility'],
                    'beta' => $stockData['beta'],
                    'jump_int' => $stockData['jump_intensity'],
                    'jump_mean' => $stockData['jump_mean'],
                    'jump_vol' => $stockData['jump_vol'],
                    'importance' => $stockData['systemic_importance'] ?? 'none',
                    'ticker' => $stockData['ticker']
                ]
            );
        }

        // RESET ETF NUMBERS
        $io->text('4. Resetting ETF Prices...');
        foreach (InitialMarket::ETFS as $etfData) {
            $conn->executeStatement(
                'UPDATE etfs SET price = :price WHERE ticker = :ticker',
                [
                    'price' => $etfData['price'],
                    'ticker' => $etfData['ticker']
                ]
            );
        }

        $io->success('Market Reset Complete! You can now start the ticker.');

        return Command::SUCCESS;
    }
}