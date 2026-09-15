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
use App\Service\Math\FinancialConstants;
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
            // The fee is a property of the fund, not of a run, so it is re-applied on every seed. Its books
            // — the basket it still owns and the income it is holding — are NOT touched here: those are
            // accumulated history, and a reseed is not a liquidation.
            $etf->setExpenseRatio((float) ($etfData['expense_ratio'] ?? 0.0));
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
                $stock->setCorporateFlowBacklog(0.0);

                // Not the whole float: most holders do not lend, which is what makes a name hard to borrow
                // long before anything like all of it has been shorted.
                $stock->setLendableSupplyRatio(FinancialConstants::DEFAULT_LENDABLE_SUPPLY_RATIO);
                $stock->setShortInterestShares('0.00');

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
                $stock->setCeoTenureYears(\App\Service\Corporate\ManagementSuccessionEngine::drawSeedTenure($this->mathUtility));
                $stock->setManagementIntensity(\App\Data\ManagementProfile::drawIntensity($this->mathUtility));

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
                        $strategy->getThroughTheCycleCreditLossRate($stock)
                            * $strategy->getCreditLossHorizonYears()
                            * $strategy->getForwardCreditLossMultiplier($stock, $dummyMacro)
                    );
                }

                $impliedPricingRoic = $isFinancial
                    ? max(0.01, (float) $stock->getBaselineRoe())
                    : $impliedRoic;
                $bookValuePerShare = $shares > 0 ? ((float) ($stockData['total_equity'] ?? 0.0)) / $shares : 0.0;

                // The opening price must be struck on the SAME fundamentals StockTracker feeds the engine on
                // tick 1, or the market re-rates the instant it starts. Both of these default inside the DTO
                // (2% growth, zero net debt), so leaving them out priced every firm as a median-growth,
                // debt-free business and handed a low-growth utility the same multiple as a compounder.
                $seedSecularGrowth = $strategy->getSecularGrowthRate($stock);
                $seedNetDebtPerShare = $shares > 0
                    ? max(0.0, ((float) $stock->getTotalDebt() - (float) $stock->getCorporateTreasury()) / $shares)
                    : 0.0;

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
                    netDebtPerShare: $seedNetDebtPerShare,
                    secularGrowth: $seedSecularGrowth,
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
}
