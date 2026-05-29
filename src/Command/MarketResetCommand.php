<?php

namespace App\Command;

use App\Entity\User;
use App\Data\InitialMarket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:market-reset',
    description: 'Soft resets the market timeline but keeps Admin Panel edits safe.',
)]
class MarketResetCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private \Redis $redis,
        private UserPasswordHasherInterface $passwordHasher
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
        $conn->executeStatement('TRUNCATE TABLE portfolio_history'); 
        $conn->executeStatement('TRUNCATE TABLE corporate_report');
        
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

        $io->text('3. Resetting Stock Prices & Absolute Values...');
        foreach (InitialMarket::STOCKS as $stockData) {
            
            $netIncome = $stockData['total_net_income'] ?? 0.00;
            $margin = $stockData['operating_margin'] ?? 0.15;
            $revenue = $margin > 0 ? $netIncome / $margin : 0.00;
            
            $shares = $stockData['shares_outstanding'] ?? 1_000_000_000;
            $annualEps = $shares > 0 ? ($netIncome / $shares) : 0.0;
            $targetPayout = $stockData['target_payout_ratio'] ?? 0.30;
            $startingDividend = ($annualEps / 4.0) * ($targetPayout * 0.50);

            $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$stockData['industry'] ?? 'General']['leverage_type'] ?? 'none';
            $isLeveraged = $leverageType !== 'none';

            $conn->executeStatement(
                'UPDATE stocks SET 
                    price = :price, 
                    shares_outstanding = :shares,
                    volatility = :vol,
                    current_volatility = :current_vol,
                    beta = :beta,
                    jump_intensity = :jump_int,
                    jump_mean = :jump_mean, 
                    systemic_importance = :importance,
                    jump_vol = :jump_vol,
                    baseline_roic = :roic,
                    current_roic = :roic,
                    baseline_roe = :roe,
                    current_roe = :roe,
                    capex_ratio = :capex,
                    target_payout_ratio = :payout,
                    dividend_speed = :div_speed,
                    fixed_cost_ratio = :fixed_cost,
                    depreciation_rate = :depreciation_rate,
                    corporate_treasury = :treasury,
                    floating_debt_ratio = :floating_ratio,
                    wholesale_debt = :wholesale_debt,
                    customer_deposits = :customer_deposits,
                    operating_margin = :margin,
                    public_float_percentage = :float_pct,
                    total_net_income = :net_income,
                    total_equity = :equity,
                    retained_earnings = :retained,
                    total_revenue = :revenue,
                    total_free_cash_flow = NULL,
                    goodwill = 0.00,
                    historical_fixed_rate = :historical_rate,
                    credit_spread = :credit_spread,
                    buyback_authorization = 0.00,
                    last_dividend = :last_dividend,
                    description = :description,
                    sam_ratio = :sam_ratio,
                    industry = :industry
                WHERE ticker = :ticker',
                [
                    'price' => $stockData['price'],
                    'shares' => $stockData['shares_outstanding'],
                    'vol' => $stockData['volatility'],
                    'current_vol' => $stockData['volatility'],
                    'beta' => $stockData['beta'],
                    'jump_int' => $stockData['jump_intensity'],
                    'jump_mean' => $stockData['jump_mean'],
                    'jump_vol' => $stockData['jump_vol'],
                    'importance' => $stockData['systemic_importance'] ?? 'none',
                    'roic' => $isLeveraged ? 0.10 : ($stockData['baseline_roic'] ?? 0.10),
                    'roe' => $isLeveraged ? ($stockData['baseline_roe'] ?? $stockData['baseline_roic'] ?? 0.10) : 0.10,
                    'capex' => $stockData['capex_ratio'] ?? 0.20,
                    'payout' => $stockData['target_payout_ratio'] ?? 0.30,
                    'div_speed' => $stockData['dividendSpeed'] ?? 0.20,
                    'fixed_cost' => $stockData['fixed_cost_ratio'] ?? 0.50,
                    'depreciation_rate' => $stockData['depreciation_rate'] ?? \App\Data\Sectors::INDUSTRY_METRICS[$stockData['industry'] ?? 'General']['depreciation'] ?? 0.05,
                    'treasury' => $stockData['corporate_treasury'] ?? 1000000000.00,
                    'floating_ratio' => $stockData['floating_debt_ratio'] ?? 0.30,
                    'wholesale_debt' => $stockData['wholesale_debt'] ?? 0.00,
                    'customer_deposits' => $stockData['customer_deposits'] ?? 0.00,
                    'margin' => $stockData['operating_margin'] ?? 0.15,
                    'float_pct' => $stockData['public_float'] ?? 0.90,
                    'net_income' => $stockData['total_net_income'] ?? 0.00,
                    'equity' => $stockData['total_equity'] ?? 0.00,
                    'retained' => $stockData['retained_earnings'] ?? 0.00,
                    'revenue' => $revenue,
                    'historical_rate' => $stockData['historical_fixed_rate'] ?? 0.0400,
                    'credit_spread' => $stockData['credit_spread'] ?? 0.0100,
                    'last_dividend' => $startingDividend,
                    'description' => \App\Data\StockInfo::DESCRIPTIONS[$stockData['ticker']] ?? null,
                    'sam_ratio' => $stockData['sam_ratio'] ?? 1.00,
                    'industry' => $stockData['industry'] ?? null,
                    'ticker' => $stockData['ticker']
                ]
            );
        }

        $io->text('4. Resetting ETF Prices...');
        foreach (InitialMarket::ETFS as $etfData) {
            $conn->executeStatement(
                'UPDATE etfs SET price = :price, description = :description WHERE ticker = :ticker',
                [
                    'price' => $etfData['price'],
                    'description' => \App\Data\StockInfo::DESCRIPTIONS[$etfData['ticker']] ?? null,
                    'ticker' => $etfData['ticker']
                ]
            );
        }

        $io->success('Market Reset Complete! You can now start the ticker.');
        return Command::SUCCESS;
    }
}