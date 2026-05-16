<?php

namespace App\Command;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\User;
use App\Data\InitialMarket;
use App\Data\Sectors;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:market-seed',
    description: 'Seeds the production database with initial market data.',
)]
class MarketSeedCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding the Lakebird Exchange (Production)');

        // Loop through ETFs
        foreach (InitialMarket::ETFS as $etfData) {
            if (!$this->entityManager->getRepository(Etf::class)->findOneBy(['ticker' => $etfData['ticker']])) {
                $etf = new Etf();
                $etf->setTicker($etfData['ticker']);
                $etf->setName($etfData['name']);
                $etf->setPrice((string) $etfData['price']);
                $etf->setDescription(\App\Data\StockInfo::DESCRIPTIONS[$etfData['ticker']] ?? null);
                $this->entityManager->persist($etf);
            }
        }

        // Loop through Stocks
        foreach (InitialMarket::STOCKS as $stockData) {
            if (!$this->entityManager->getRepository(Stock::class)->findOneBy(['ticker' => $stockData['ticker']])) {
                $stock = new Stock();
                $stock->setTicker($stockData['ticker']);
                $stock->setName($stockData['name']);
                $stock->setSector($stockData['sector']);
                $stock->setIndustry($stockData['industry'] ?? null);
                $stock->setPrice((string) $stockData['price']);

                $targetPE = Sectors::MACRO_SECTORS[$stockData['sector']] ?? 20.0;
                $neutralEps = (float) $stockData['price'] / $targetPE;
                $stock->setEarningsPerShare((string) round($neutralEps, 2));

                $stock->setSharesOutstanding((string) $stockData['shares_outstanding']);
                $stock->setVolatility((string) $stockData['volatility']);
                $stock->setCurrentVolatility((string) $stockData['volatility']);
                $stock->setBeta((string) $stockData['beta']);
                $stock->setJumpIntensity((string) $stockData['jump_intensity']);
                $stock->setJumpMean((string) $stockData['jump_mean']);
                $stock->setJumpVol((string) $stockData['jump_vol']);
                $stock->setSystemicImportance($stockData['systemic_importance'] ?? 'none');

                $stock->setBaselineRoic((string) ($stockData['baseline_roic'] ?? 0.10));
                $stock->setCurrentRoic((string) ($stockData['baseline_roic'] ?? 0.10));
                $stock->setCapexRatio((string) ($stockData['capex_ratio'] ?? 0.20));
                $stock->setTargetPayoutRatio((string) ($stockData['target_payout_ratio'] ?? 0.30));
                $stock->setDividendSpeed((string) ($stockData['dividendSpeed'] ?? 0.20));
                $stock->setFixedCostRatio((float) ($stockData['fixed_cost_ratio'] ?? 0.35));
                
                $stock->setCorporateTreasury((string) ($stockData['corporate_treasury'] ?? 1000000000.00));
                $stock->setOperatingMargin((string) ($stockData['operating_margin'] ?? 0.15));
                $stock->setPublicFloatPercentage((string) ($stockData['public_float'] ?? 0.90));
                $stock->setTotalNetIncome((string) ($stockData['total_net_income'] ?? 0.00));
                $stock->setTotalEquity((string) ($stockData['total_equity'] ?? 0.00));
                $stock->setTotalDebt((string) ($stockData['total_debt'] ?? 0.00));
                $stock->setRetainedEarnings((string) ($stockData['retained_earnings'] ?? 0.00));
                $stock->setSamRatio((string) ($stockData['sam_ratio'] ?? 1.00));
                
                $netIncome = $stockData['total_net_income'] ?? 0.00;
                $shares = $stockData['shares_outstanding'] ?? 1_000_000_000;
                $annualEps = $shares > 0 ? ($netIncome / $shares) : 0.0;
                $targetPayout = $stockData['target_payout_ratio'] ?? 0.30;
                $startingDividend = ($annualEps / 4.0) * ($targetPayout * 0.50);
                $stock->setLastDividend((string) $startingDividend);

                $stock->setCreditSpread((string) ($stockData['credit_spread'] ?? 0.0100));
                $stock->setHistoricalFixedRate((string) ($stockData['historical_fixed_rate'] ?? 0.05));
                $stock->setDescription(\App\Data\StockInfo::DESCRIPTIONS[$stockData['ticker']] ?? null);

                $this->entityManager->persist($stock);
            }
        }

        // Test User
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test.test@test.se']);
        if (!$user) {
            $user = new User();
            $user->setEmail('test.test@test.se');
            $user->setRoles(['ROLE_ADMIN']);
            $user->setCashBalance('10000.00');
            $user->setPassword($this->passwordHasher->hashPassword($user, 'test'));
            $this->entityManager->persist($user);
        }
        
        $user->setUsername('Test');

        $this->entityManager->flush();
        $io->success('Database successfully seeded with full fundamental physics!');

        return Command::SUCCESS;
    }
}