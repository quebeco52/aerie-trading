<?php

namespace App\Command;

use App\Entity\Etf;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
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
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

#[AsCommand(
    name: 'app:market-seed',
    description: 'Seeds the production database with initial market data.',
)]
class MarketSeedCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private MathUtility $mathUtility,
        private \App\Service\Corporate\DebtEngine $debtEngine,
        private \App\Service\Market\MarketEngine $marketEngine,
        private \App\Service\Market\TreasuryAuctionService $treasuryAuction
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding the Lakebird Exchange (Production)');

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
            equityRiskPremium: 0.045,

            // The fitted curve factors the bond ladder is struck off. beta1 is the policy rate minus the
            // level, which is what the curve function expects; the yield fields above are outputs of a
            // curve, not inputs to one, and cannot reconstruct it.
            nsLevel: MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION,
            nsBeta1: 0.04 - (MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION),
            nsBaseTermPremium: MacroEngine::NS_BASE_TERM_PREMIUM,
            nsLongEndPremium: MacroEngine::NS_BASE_TERM_PREMIUM
        );

        // Loop through ETFs
        foreach (InitialMarket::ETFS as $etfData) {
            $etf = $this->entityManager->getRepository(Etf::class)->findOneBy(['ticker' => $etfData['ticker']]);
            if (!$etf) {
                $etf = new Etf();
                $etf->setTicker($etfData['ticker']);
                $etf->setPrice((string) $etfData['price']);
            }
            $etf->setName($etfData['name']);
            $etf->setDescription(\App\Data\StockInfo::DESCRIPTIONS[$etfData['ticker']] ?? null);
            $this->entityManager->persist($etf);
        }

        // Loop through Stocks
        foreach (InitialMarket::STOCKS as $stockData) {
            $stock = $this->entityManager->getRepository(Stock::class)->findOneBy(['ticker' => $stockData['ticker']]);
            if (!$stock) {
                $stock = new Stock();
                $stock->setTicker($stockData['ticker']);

                $stock->setSharesOutstanding((string) $stockData['shares_outstanding']);
                $stock->setVolatility((string) $stockData['volatility']);
                $stock->setCurrentVolatility((string) $stockData['volatility']);
                $stock->setBeta((string) $stockData['beta']);
                $stock->setJumpIntensity((string) $stockData['jump_intensity']);
                $stock->setJumpVol((string) $stockData['jump_vol']);
                $stock->setSystemicImportance($stockData['systemic_importance'] ?? 'none');

                // How much of the float changes hands in a year: the structural input behind this name's
                // depth, its spread, and how far a given order size moves it.
                $stock->setTurnoverRatio(
                    \App\Service\Market\LiquidityEngine::structuralTurnoverRatio((float) $stockData['volatility'])
                );
                $stock->setImpactVarianceEma(0.0);

                $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$stockData['industry'] ?? 'General']['business_model'] ?? 'none';
                $isFinancial = \App\Data\Sectors::isFinancial($businessModel);

                if ($isFinancial) {
                    $roe = $stockData['baseline_roe'] ?? $stockData['baseline_roic'] ?? 0.10;
                    $stock->setBaselineRoe((string) $roe);
                    $stock->setCurrentRoe((string) $roe);
                    $stock->setRoeTtm((string) $roe);
                } else {
                    $roic = $stockData['baseline_roic'] ?? 0.10;
                    $stock->setBaselineRoic((string) $roic);
                    $stock->setCurrentRoic((string) $roic);
                    $stock->setRoicTtm((string) $roic);
                }

                $stock->setCapexRatio((string) ($stockData['capex_ratio'] ?? 0.20));
                $stock->setTargetPayoutRatio((string) ($stockData['target_payout_ratio'] ?? 0.30));
                $stock->setDividendSpeed((string) ($stockData['dividendSpeed'] ?? 0.20));
                $stock->setFixedCostRatio((float) ($stockData['fixed_cost_ratio'] ?? 0.35));
                $stock->setDepreciationRate((string) ($stockData['depreciation_rate'] ?? \App\Data\Sectors::INDUSTRY_METRICS[$stockData['industry'] ?? 'General']['depreciation'] ?? 0.05));

                $stock->setCorporateTreasury((string) ($stockData['corporate_treasury'] ?? 1000000000.00));
                $stock->setFloatingDebtRatio((string) ($stockData['floating_debt_ratio'] ?? 0.30));
                $stock->setOperatingMargin((string) ($stockData['operating_margin'] ?? 0.15));
                $stock->setPublicFloatPercentage((string) ($stockData['public_float'] ?? 0.90));
                $stock->setTotalEquity((string) ($stockData['total_equity'] ?? 0.00));
                $stock->setWholesaleDebt((string) ($stockData['wholesale_debt'] ?? 0.00));
                $stock->setCustomerDeposits((string) ($stockData['customer_deposits'] ?? 0.00));
                $stock->setRetainedEarnings((string) ($stockData['retained_earnings'] ?? 0.00));
                $stock->setSamRatio((string) ($stockData['sam_ratio'] ?? 1.00));
                $stock->setManagementStyle(\App\Data\ManagementStyle::tryFromNullable($stockData['management_style'] ?? null));

                $margin = $stockData['operating_margin'] ?? 0.15;
                $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);

                // Query the exact structural metrics the engine uses to prevent massive gravity explosions on tick 1
                $targetMetrics = $strategy->getTargetMetrics($stock, $dummyMacro, $this->mathUtility);
                $investedCapital = $targetMetrics['invested_capital'];
                $impliedRoic = max(0.01, (float) $targetMetrics['baseline_roic']);
                $taxRate = $dummyMacro->corporateTaxRate;
                $preTaxRoic = $impliedRoic / (1.0 - $taxRate);
                $revenue = $margin > 0 ? ($investedCapital * ($preTaxRoic / $margin)) : 0.0;

                $stock->setTotalRevenue((string) $revenue);

                $debtHealth = $this->debtEngine->analyzeDebtHealth($stock, $dummyMacro, $revenue, $margin);

                if ($isFinancial) {
                    $netIncome = ((float) ($stockData['total_equity'] ?? 0.0)) * (float) $stock->getBaselineRoe();
                } else {
                    $ebit = $revenue * $margin;
                    $interestExpense = $debtHealth->interestExpense ?? 0.0;
                    $interestIncome = $strategy->calculateInterestIncome($stock, $dummyMacro, $this->mathUtility);
                    $ebt = $ebit - $interestExpense + $interestIncome;
                    $effectiveTaxRate = $strategy->getEffectiveTaxRate($taxRate);
                    $netIncome = max(0.0, $ebt * (1.0 - $effectiveTaxRate));
                }

                $shares = $stockData['shares_outstanding'] ?? 1_000_000_000;
                $annualEps = $shares > 0 ? ($netIncome / $shares) : 0.0;
                $stock->setTotalNetIncome((string) $netIncome);
                $stock->setEarningsPerShare((string) round($annualEps, 2));

                $targetPayout = $stockData['target_payout_ratio'] ?? 0.30;
                $startingDividend = ($annualEps / 4.0) * ($targetPayout * 0.50);
                $stock->setLastDividend((string) $startingDividend);

                $stock->setCreditSpread((string) ($stockData['credit_spread'] ?? 0.0100));
                $stock->setHistoricalFixedRate((string) ($stockData['historical_fixed_rate'] ?? 0.04));

                // Open the fixed-asset ledger so the very first earnings report depreciates a real plant
                // rather than falling back to the capital proxy. Financial balance sheets keep no plant.
                if (!$isFinancial) {
                    $metrics = \App\Service\Math\CorporateMetrics::getInstance();
                    $metrics->buildWorkingCapitalBalances(
                        $stock,
                        $strategy->getWorkingCapitalDays($stock),
                        $revenue,
                        $revenue * (1.0 - $margin)
                    );
                    $metrics->seedReceivablesAllowance($stock, $dummyMacro->corporateDefaultRateEma);
                    $openingNwc = (float) $stock->getNetWorkingCapital();
                    $metrics->seedFixedAssetLedger(
                        $stock,
                        $investedCapital,
                        $openingNwc,
                        (float) $stock->getGoodwill(),
                        $stock->getTotalCipAmount(),
                        (float) ($stockData['asset_age_ratio'] ?? \App\Service\Math\FinancialConstants::SEED_ASSET_AGE_RATIO)
                    );
                } else {
                    // A balance-sheet business opens its loan book instead, with the allowance already at
                    // the lifetime loss it expects so the first report books no phantom provision.
                    \App\Service\Math\CorporateMetrics::getInstance()->seedEarningAssetLedger(
                        $stock,
                        $strategy->getThroughTheCycleCreditLossRate() * $strategy->getCreditLossHorizonYears()
                    );
                }

                $impliedPricingRoic = $isFinancial 
                    ? max(0.01, (float) $stock->getBaselineRoe())
                    : $impliedRoic;
                $bookValuePerShare = $shares > 0 ? ((float) ($stockData['total_equity'] ?? 0.0)) / $shares : 0.0;

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
                    liveCostOfEquity: $debtHealth->costOfEquity ?? 0.10,
                    baselineRoic: $impliedPricingRoic,
                    baselineMargin: (float) ($stockData['operating_margin'] ?? 0.20),
                    investedCapitalPerShare: $stock->getInvestedCapital() / max(1.0, (float) $stock->getSharesOutstanding())
                );

                $marketCalc = $this->marketEngine->calculateNextPrice($pricingCtx);
                $stock->setPrice((string) $marketCalc['perceived_fair_value']);
            }
            $stock->setName($stockData['name']);
            $stock->setSector($stockData['sector']);
            $stock->setIndustry($stockData['industry'] ?? null);
            $stock->setDescription(\App\Data\StockInfo::DESCRIPTIONS[$stockData['ticker']] ?? null);

            $this->entityManager->persist($stock);
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
        $user->setIsVerified(true);

        // Seed procedural mega-corps if none exist
        /*
        $currentCount = $this->entityManager->getRepository(Stock::class)->count([]);
        if ($currentCount <= count(InitialMarket::STOCKS)) {
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
                $stock->setJumpVol('0.05');
                $stock->setSystemicImportance('none');
                $stock->setBaselineRoic('0.12');
                $stock->setCurrentRoic('0.12');
                $stock->setBaselineRoe('0.10');
                $stock->setCurrentRoe('0.10');
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
        }
        */

        $this->entityManager->flush();

        // Open the bond desk with one on-the-run at each tenor. Only the benchmarks are sold here; the rest
        // of the ladder fills in as the quarterly refunding runs, which is also how a real curve gets its
        // off-the-run issues rather than having them appear fully formed.
        $existingBonds = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM bonds');
        if ($existingBonds === 0) {
            $issued = $this->treasuryAuction->conductAuction($dummyMacro->sovereignCurve(), 0.0);
            $this->entityManager->flush();
            $io->text(sprintf('Opened the bond desk with %d benchmark issues.', count($issued)));
        }

        $io->success('Database successfully seeded with full fundamental physics!');

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
