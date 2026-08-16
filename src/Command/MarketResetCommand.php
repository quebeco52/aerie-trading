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
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

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
        private MathUtility $mathUtility,
        private \App\Service\Market\MarketEngine $marketEngine,
        private \App\Service\Corporate\DebtEngine $debtEngine
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
        $conn->executeStatement('TRUNCATE TABLE macro_report');

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

        $io->text('3. Resetting Stock Prices & Absolute Values...');

        $dummyMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            inflationEma: 0.02,
            policyRateEma: 0.04,
            yield5yEma: 0.045,
            yield10yEma: 0.05,
            yield30yEma: 0.055,
            yield2yEma: 0.04,
            macroCreditSpreadEma: 0.02,
            marketVolatilityEma: 0.15,
            corporateTaxRate: MacroEngine::BASE_CORPORATE_TAX_RATE,
            equityRiskPremium: 0.045

        );

        foreach (InitialMarket::STOCKS as $stockData) {
            $margin = $stockData['operating_margin'] ?? 0.15;

            $historicalRate = $stockData['historical_fixed_rate'] ?? 0.04;
            $wholesaleDebt = $stockData['wholesale_debt'] ?? 0.0;
            $customerDeposits = $stockData['customer_deposits'] ?? 0.0;
            $treasury = $stockData['corporate_treasury'] ?? 0.0;

            $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$stockData['industry'] ?? 'General']['business_model'] ?? 'none';
            $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
            $isFinancial = \App\Data\Sectors::isFinancial($businessModel);

            // Create a temporary entity to leverage the proper business model physics
            $tempStock = new Stock();
            $tempStock->setTicker($stockData['ticker']);
            $tempStock->setName($stockData['name']);
            $tempStock->setTotalEquity((string) ($stockData['total_equity'] ?? 0.0));
            $tempStock->setWholesaleDebt((string) $wholesaleDebt);
            $tempStock->setCustomerDeposits((string) $customerDeposits);
            $tempStock->setCorporateTreasury((string) $treasury);
            $tempStock->setFloatingDebtRatio((string) ($stockData['floating_debt_ratio'] ?? 0.30));
            $tempStock->setHistoricalFixedRate((string) $historicalRate);
            $tempStock->setOperatingMargin((string) $margin);
            $tempStock->setIndustry($stockData['industry'] ?? 'General');
            $tempStock->setBaselineRoe((string) ($isFinancial ? ($stockData['baseline_roe'] ?? $stockData['baseline_roic'] ?? 0.10) : 0.10));
            $tempStock->setBaselineRoic((string) ($isFinancial ? 0.10 : ($stockData['baseline_roic'] ?? 0.10)));


            // Query the exact structural metrics the engine uses to prevent massive gravity explosions on tick 1
            $targetMetrics = $strategy->getTargetMetrics($tempStock, $dummyMacro, $this->mathUtility);
            $investedCapital = $targetMetrics['invested_capital'];
            $operatingYield = max(0.01, (float) $targetMetrics['baseline_roic']);
            $taxRate = $dummyMacro->corporateTaxRate ?? 0.21;
            $preTaxYield = $operatingYield / (1.0 - $taxRate);
            $revenue = $margin > 0 ? ($investedCapital * ($preTaxYield / $margin)) : 0.0;

            $impliedPricingRoic = $isFinancial 
                ? max(0.01, (float) $tempStock->getBaselineRoe())
                : $operatingYield;

            $shares = $stockData['shares_outstanding'] ?? 1_000_000_000;
            $bookValuePerShare = $shares > 0 ? ((float) ($stockData['total_equity'] ?? 0.0)) / $shares : 0.0;

            // Set required temporary values for debt engine
            $tempStock->setTotalRevenue((string)$revenue);
            $tempStock->setSharesOutstanding((string)$shares);
            $tempStock->setPrice((string)$bookValuePerShare);

            $debtHealth = $this->debtEngine->analyzeDebtHealth($tempStock, $dummyMacro, $revenue, $margin);

            if ($isFinancial) {
                $netIncome = ((float) ($stockData['total_equity'] ?? 0.0)) * (float) $tempStock->getBaselineRoe();
            } else {
                $ebit = $revenue * $margin;
                $interestExpense = $debtHealth->interestExpense ?? 0.0;
                $interestIncome = $strategy->calculateInterestIncome($tempStock, $dummyMacro, $this->mathUtility);
                $ebt = $ebit - $interestExpense + $interestIncome;
                $effectiveTaxRate = $strategy->getEffectiveTaxRate($taxRate);
                $netIncome = max(0.0, $ebt * (1.0 - $effectiveTaxRate));
            }

            $annualEps = $shares > 0 ? ($netIncome / $shares) : 0.0;
            $tempStock->setEarningsPerShare((string)$annualEps);
            $targetPayout = $stockData['target_payout_ratio'] ?? 0.30;
            $startingDividend = ($annualEps / 4.0) * ($targetPayout * 0.50);

            $pricingCtx = new \App\DTO\MarketPricingContext(
                currentPrice: $bookValuePerShare,
                currentVolatility: (float) ($stockData['volatility'] ?? 0.15),
                longTermVolatility: (float) ($stockData['volatility'] ?? 0.15),
                earningsPerShare: $annualEps,
                dt: 0.0,
                lambda: (float) ($stockData['jump_intensity'] ?? 2.0),
                jumpVol: (float) ($stockData['jump_vol'] ?? 0.05),
                beta: (float) ($stockData['beta'] ?? 1.0),
                marketZ: 0.0,
                marketVol: 0.15,
                macroState: $dummyMacro,
                fcfPerShare: null,
                bookValuePerShare: $bookValuePerShare,
                maShock: 0.0,
                currentRoic: $impliedPricingRoic,
                roicTtm: $impliedPricingRoic,
                dividendPerShare: $startingDividend,
                liveWacc: $debtHealth->wacc ?? 0.08,
                baselineIndustryPE: \App\Data\Sectors::INDUSTRY_METRICS[$stockData['industry'] ?? 'General']['pe'] ?? 20.0,
                revenuePerShare: $shares > 0 ? $revenue / $shares : 0.0,
                businessModel: $businessModel,
                liveCostOfEquity: $debtHealth->costOfEquity ?? 0.10
            );

            $marketCalc = $this->marketEngine->calculateNextPrice($pricingCtx);

            // Use the engine's perceived fair value as the neutral Analyst Consensus
            $neutralPrice = $marketCalc['perceived_fair_value'];

            $conn->executeStatement(
                'UPDATE stocks SET 
                    name = :name,
                    sector = :sector,
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
                    structural_variable_margin = :structural_var_margin,
                    public_float_percentage = :float_pct,
                    total_net_income = :net_income,
                    total_equity = :equity,
                    retained_earnings = :retained,
                    total_revenue = :revenue,
                    previous_revenue = :revenue,
                    total_free_cash_flow = NULL,
                    last_analyst_revenue = :analyst_revenue,
                    roe_ttm = :roe_ttm,
                    roic_ttm = :roic_ttm,
                    goodwill = 0.00,
                    cip_balance = 0.00,
                    historical_fixed_rate = :historical_rate,
                    credit_spread = :credit_spread,
                    buyback_authorization = 0.00,
                    last_dividend = :last_dividend,
                    description = :description,
                    sam_ratio = :sam_ratio,
                    industry = :industry
                WHERE ticker = :ticker',
                [
                    'price' => $neutralPrice,
                    'shares' => $stockData['shares_outstanding'],
                    'vol' => $stockData['volatility'],
                    'current_vol' => $stockData['volatility'],
                    'beta' => $stockData['beta'],
                    'jump_int' => $stockData['jump_intensity'],
                    'jump_mean' => $stockData['jump_mean'],
                    'jump_vol' => $stockData['jump_vol'],
                    'importance' => $stockData['systemic_importance'] ?? 'none',
                    'roic' => $isFinancial ? 0.10 : ($stockData['baseline_roic'] ?? 0.10),
                    'roe' => $isFinancial ? ($stockData['baseline_roe'] ?? $stockData['baseline_roic'] ?? 0.10) : 0.10,
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
                    'structural_var_margin' => (1.0 - ($stockData['operating_margin'] ?? 0.15)) * (1.0 - ($stockData['fixed_cost_ratio'] ?? 0.50)),
                    'float_pct' => $stockData['public_float'] ?? 0.90,
                    'net_income' => $netIncome,
                    'equity' => $stockData['total_equity'] ?? 0.00,
                    'retained' => $stockData['retained_earnings'] ?? 0.00,
                    'revenue' => $revenue,
                    'analyst_revenue' => $revenue / 4.0,
                    'roe_ttm' => $isFinancial ? ($stockData['baseline_roe'] ?? $stockData['baseline_roic'] ?? 0.10) : 0.10,
                    'roic_ttm' => $isFinancial ? 0.10 : ($stockData['baseline_roic'] ?? 0.10),
                    'historical_rate' => $stockData['historical_fixed_rate'] ?? 0.0400,
                    'credit_spread' => $stockData['credit_spread'] ?? 0.0100,
                    'last_dividend' => $startingDividend,
                    'name' => $stockData['name'],
                    'sector' => $stockData['sector'],
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
                'UPDATE etfs SET name = :name, price = :price, description = :description WHERE ticker = :ticker',
                [
                    'name' => $etfData['name'],
                    'price' => $etfData['price'],
                    'description' => \App\Data\StockInfo::DESCRIPTIONS[$etfData['ticker']] ?? null,
                    'ticker' => $etfData['ticker']
                ]
            );
        }

        $io->text('5. Generating new $50B procedural corporations for every industry...');

        /*
        $industryList = array_keys(\App\Data\Sectors::INDUSTRY_METRICS);
        $prefixes = ['Apex', 'Horizon', 'Vertex', 'Quantum', 'Aegis', 'Omni', 'Vanguard', 'Pinnacle', 'Meridian', 'Zenith', 'Nova', 'Crest', 'Echo', 'Atlas', 'Helios'];
        $sectorSuffixes = [
            'Information Technology' => ['Technologies', 'Systems', 'Software', 'Networks'],
            'Financials' => ['Capital', 'Financial', 'Partners', 'Holdings'],
            'Health Care' => ['Medical', 'Health', 'Biosciences', 'Pharma'],
            'Consumer Discretionary' => ['Brands', 'Retail', 'Apparel', 'Leisure'],
            'Consumer Staples' => ['Foods', 'Consumer', 'Groceries', 'Beverages'],
            'Industrials' => ['Industries', 'Dynamics', 'Manufacturing', 'Logistics'],
            'Real Estate' => ['Properties', 'Realty', 'Estates', 'Development'],
            'Energy' => ['Energy', 'Resources', 'Petroleum', 'Power'],
            'Materials' => ['Materials', 'Metals', 'Chemicals', 'Mining'],
            'Utilities' => ['Utilities', 'Power', 'Water', 'Energy'],
            'Communication Services' => ['Communications', 'Media', 'Broadcasting', 'Telecom']
        ];

        $existingTickers = array_column(InitialMarket::STOCKS, 'ticker');

        foreach ($industryList as $industry) {
            if ($industry === 'General') continue;

            $sector = $this->determineSectorForIndustry($industry);

            $prefix = $prefixes[array_rand($prefixes)];
            $suffixes = $sectorSuffixes[$sector] ?? ['Group', 'Holdings', 'Inc'];
            $suffix = $suffixes[array_rand($suffixes)];
            
            $name = "$prefix $suffix";
            
            do {
                $ticker = strtoupper(substr($prefix, 0, 1) . substr($suffix, 0, 1) . chr(mt_rand(65, 90)) . chr(mt_rand(65, 90)));
            } while (in_array($ticker, $existingTickers));
            
            $existingTickers[] = $ticker;

            $stock = new Stock();
            $stock->setTicker($ticker);
            $stock->setName($name);
            $stock->setSector($sector);
            $stock->setIndustry($industry);
            $stock->setPrice('50.00');
            $stock->setSharesOutstanding('1000000000'); // 1 Billion Shares = $50B Market Cap
            $stock->setVolatility('0.20');
            $stock->setCurrentVolatility('0.20');
            $stock->setBeta('1.00');
            $stock->setJumpIntensity('0.50');
            $stock->setJumpMean('0.00');
            $stock->setJumpVol('0.05');
            $stock->setSystemicImportance('none');
            $stock->setBaselineRoic('0.12');
            $stock->setCurrentRoic('0.12');
            $stock->setRoicTtm('0.12');
            $stock->setBaselineRoe('0.10');
            $stock->setCurrentRoe('0.10');
            $stock->setRoeTtm('0.10');
            $stock->setCapexRatio('0.20');
            $stock->setTargetPayoutRatio('0.25');
            $stock->setDividendSpeed('0.20');
            $stock->setFixedCostRatio(0.35);
            $stock->setDepreciationRate('0.05');
            $stock->setCorporateTreasury('2500000000.00');
            $stock->setFloatingDebtRatio('0.30');
            $stock->setWholesaleDebt('10000000000.00');
            $stock->setCustomerDeposits('0.00');
            $stock->setOperatingMargin('0.15');
            $stock->setStructuralVariableMargin((1.0 - 0.15) * (1.0 - 0.35));
            $stock->setPublicFloatPercentage('0.85');
            $stock->setTotalNetIncome('5000000000.00');
            $stock->setTotalEquity('20000000000.00');
            $stock->setRetainedEarnings('5000000000.00');
            
            $netIncomeGen = 5000000000.00;
            $ebtGen = $netIncomeGen / 0.79;
            $interestExpGen = 10000000000.00 * 0.05;
            $interestIncGen = 2500000000.00 * 0.0375;
            $ebitGen = $ebtGen + $interestExpGen - $interestIncGen;
            $stock->setTotalRevenue((string) ($ebitGen / 0.15));
            $stock->setHistoricalFixedRate('0.05');
            $stock->setCreditSpread('0.015');
            $stock->setLastDividend('0.15625');
            $stock->setSamRatio('0.25');
            $stock->setEarningsPerShare('5.00');
            $stock->setDescription("A procedurally generated mega-corporation operating in the $industry space.");

            $this->entityManager->persist($stock);
        }
        
        $this->entityManager->flush();
        */

        $io->success('Market Reset Complete! You can now start the ticker.');
        return Command::SUCCESS;
    }

    private function determineSectorForIndustry(string $industry): string
    {
        $industryLower = strtolower($industry);
        if (str_contains($industryLower, 'bank') || str_contains($industryLower, 'insurance') || str_contains($industryLower, 'capital') || str_contains($industryLower, 'financial') || str_contains($industryLower, 'credit') || str_contains($industryLower, 'asset') || str_contains($industryLower, 'mortgage')) return 'Financials';
        if (str_contains($industryLower, 'software') || str_contains($industryLower, 'computer') || str_contains($industryLower, 'semiconductor') || str_contains($industryLower, 'electronic') || str_contains($industryLower, 'information') || str_contains($industryLower, 'communication equipment')) return 'Information Technology';
        if (str_contains($industryLower, 'medical') || str_contains($industryLower, 'health') || str_contains($industryLower, 'drug') || str_contains($industryLower, 'biotechnology') || str_contains($industryLower, 'diagnostics')) return 'Health Care';
        if (str_contains($industryLower, 'apparel') || str_contains($industryLower, 'auto') || str_contains($industryLower, 'entertainment') || str_contains($industryLower, 'leisure') || str_contains($industryLower, 'luxury') || str_contains($industryLower, 'restaurant') || str_contains($industryLower, 'retail') || str_contains($industryLower, 'gambling') || str_contains($industryLower, 'travel') || str_contains($industryLower, 'footwear') || str_contains($industryLower, 'discount stores') || str_contains($industryLower, 'furnishings') || str_contains($industryLower, 'recreational') || str_contains($industryLower, 'lodging')) return 'Consumer Discretionary';
        if (str_contains($industryLower, 'beverage') || str_contains($industryLower, 'food') || str_contains($industryLower, 'grocery') || str_contains($industryLower, 'tobacco') || str_contains($industryLower, 'household') || str_contains($industryLower, 'personal') || str_contains($industryLower, 'farm')) return 'Consumer Staples';
        if (str_contains($industryLower, 'reit') || str_contains($industryLower, 'real estate')) return 'Real Estate';
        if (str_contains($industryLower, 'oil') || str_contains($industryLower, 'gas') || str_contains($industryLower, 'energy') || str_contains($industryLower, 'solar')) return 'Energy';
        if (str_contains($industryLower, 'aluminum') || str_contains($industryLower, 'chemical') || str_contains($industryLower, 'copper') || str_contains($industryLower, 'gold') || str_contains($industryLower, 'material') || str_contains($industryLower, 'steel') || str_contains($industryLower, 'agricultural')) return 'Materials';
        if (str_contains($industryLower, 'utilit')) return 'Utilities';
        if (str_contains($industryLower, 'communication') || str_contains($industryLower, 'advertising') || str_contains($industryLower, 'publishing') || str_contains($industryLower, 'telecom') || str_contains($industryLower, 'media')) return 'Communication Services';
        return 'Industrials';
    }
}
