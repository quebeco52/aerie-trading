<?php

namespace App\DataFixtures;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\User;
use App\Data\InitialMarket;
use App\Data\Sectors;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    public function load(ObjectManager $manager): void
    {
        echo "Preparing a clean slate...\n";

        // WIPE REDIS CLEAN
        try {
            $redisUrl = parse_url($_ENV['REDIS_URL'] ?? 'tcp://127.0.0.1:6379');
            $host = $redisUrl['host'] ?? '127.0.0.1';
            $port = $redisUrl['port'] ?? 6379;

            // Open a raw TCP socket to the Redis container
            $fp = @fsockopen($host, $port, $errno, $errstr, 3);
            if ($fp) {
                fwrite($fp, "FLUSHALL\r\n"); // Send the raw flush command
                fclose($fp);
                echo "✅ Redis cache successfully wiped via TCP socket!\n";
            } else {
                echo "Warning: Could not connect to Redis socket. Is Redis running?\n";
            }
        } catch (\Exception $e) {
            echo "Warning: Could not clear Redis. (" . $e->getMessage() . ")\n";
        }

        echo "Seeding the Lakebird Exchange...\n";

        // Loop through ETFs (Future-proofed for multiple indices!)
        foreach (InitialMarket::ETFS as $etfData) {
            $etf = new Etf();
            $etf->setTicker($etfData['ticker']);
            $etf->setName($etfData['name']);
            $etf->setPrice((string) $etfData['price']);

            $etf->setDescription(\App\Data\StockInfo::DESCRIPTIONS[$etfData['ticker']] ?? null);
            $etf->setExpenseRatio((float) ($etfData['expense_ratio'] ?? 0.0));

            $manager->persist($etf);
        }

        // Loop through Stocks
        foreach (InitialMarket::STOCKS as $stockData) {
            $stock = new Stock();
            $stock->setTicker($stockData['ticker']);
            $stock->setName($stockData['name']);
            $stock->setSector($stockData['sector']);
            $shares = (float) ($stockData['shares_outstanding'] ?? 1_000_000_000);
            $totalEquity = (float) ($stockData['total_equity'] ?? 0.0);
            $bookValuePerShare = $shares > 0 ? $totalEquity / $shares : 100.0;
            $baselineReturn = (float) ($stockData['baseline_roic'] ?? $stockData['baseline_roe'] ?? 0.10);
            $approxPb = max(0.5, $baselineReturn / 0.08);
            $initialPrice = max(1.0, $bookValuePerShare * $approxPb);
            $stock->setPrice((string) round($initialPrice, 2));

            // Calculate Neutral EPS
            $targetPE = Sectors::MACRO_SECTORS[$stockData['sector']] ?? 20.0;
            $neutralEps = $initialPrice / $targetPE;
            $stock->setEarningsPerShare((string) round($neutralEps, 2));

            // Standard Metrics
            $stock->setSharesOutstanding((string) $stockData['shares_outstanding']);
            $stock->setVolatility((string) $stockData['volatility']);
            $stock->setCurrentVolatility((string) $stockData['volatility']);
            $stock->setRealizedVarianceEma((float) $stockData['volatility'] ** 2);
            $stock->setBeta((string) $stockData['beta']);
            $stock->setJumpIntensity((string) $stockData['jump_intensity']);
            $stock->setJumpVol((string) $stockData['jump_vol']);
            $stock->setSystemicImportance($stockData['systemic_importance'] ?? 'none');

            // THE FIX: Adding all the new Fundamental & Macro Metrics
            $stock->setBaselineRoic((string) ($stockData['baseline_roic'] ?? 0.10));
            $stock->setCurrentRoic((string) ($stockData['baseline_roic'] ?? 0.10));
            $stock->setRoicTtm((string) ($stockData['baseline_roic'] ?? 0.10));
            
            $stock->setBaselineRoe((string) ($stockData['baseline_roe'] ?? 0.10));
            $stock->setCurrentRoe((string) ($stockData['baseline_roe'] ?? 0.10));
            $stock->setRoeTtm((string) ($stockData['baseline_roe'] ?? 0.10));

            $stock->setCapexRatio((string) ($stockData['capex_ratio'] ?? 0.20));
            $stock->setTargetPayoutRatio((string) ($stockData['target_payout_ratio'] ?? 0.30));
            $stock->setDividendSpeed((string) ($stockData['dividendSpeed'] ?? 0.20));
            
            // Fixed Cost Ratio (Fallback to 0.35 if missing)
            $stock->setFixedCostRatio((float) ($stockData['fixed_cost_ratio'] ?? 0.35));
            
            // Balance Sheet Data
            $stock->setCorporateTreasury((string) ($stockData['corporate_treasury'] ?? 1000000000.00));
            $stock->setOperatingMargin((string) ($stockData['operating_margin'] ?? 0.15));
            $stock->setPublicFloatPercentage((string) ($stockData['public_float'] ?? 0.90));
            $stock->setTotalEquity((string) ($stockData['total_equity'] ?? 0.00));
            $stock->setWholesaleDebt((string) ($stockData['wholesale_debt'] ?? 0.00));
            $stock->setCustomerDeposits((string) ($stockData['customer_deposits'] ?? 0.00));
            $stock->setRetainedEarnings((string) ($stockData['retained_earnings'] ?? 0.00));
            $stock->setLastDividend('0.00');
            $stock->setCreditSpread((string) ($stockData['credit_spread'] ?? 0.0100));
            $stock->setHistoricalFixedRate((string) ($stockData['historical_fixed_rate'] ?? 0.04));

            $stock->setDescription(\App\Data\StockInfo::DESCRIPTIONS[$stockData['ticker']] ?? null);

            $manager->persist($stock);
        }

        // Create a Test User
        $user = new User();
        $user->setEmail('test.test@test.se');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setCashBalance('10000.00');

        // Hash the password
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'test');
        $user->setPassword($hashedPassword);
        $user->setIsVerified(true);

        $manager->persist($user);

        $manager->flush();

        echo "Database successfully seeded with full fundamental physics!\n";
    }
}