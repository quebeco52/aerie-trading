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
        private \App\Service\Corporate\DebtEngine $debtEngine,
        private \App\Service\Market\TreasuryAuctionService $treasuryAuction
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
            equityRiskPremium: 0.045,

            // The fitted curve factors the bond ladder is struck off. beta1 is the policy rate minus the
            // level, which is what the curve function expects; the yield fields above are outputs of a
            // curve, not inputs to one, and cannot reconstruct it.
            nsLevel: MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION,
            nsBeta1: 0.04 - (MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION),
            nsBaseTermPremium: MacroEngine::NS_BASE_TERM_PREMIUM,
            nsLongEndPremium: MacroEngine::NS_BASE_TERM_PREMIUM
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

            // The reset price must be struck on the SAME fundamentals StockTracker feeds the engine on the
            // next tick, or the market re-rates immediately. Both default inside the DTO (2% growth, zero
            // net debt), which priced every firm as a median-growth, debt-free business.
            $resetSecularGrowth = $strategy->getSecularGrowthRate($tempStock);
            $resetNetDebtPerShare = $shares > 0
                ? max(0.0, (
                    (float) ($stockData['wholesale_debt'] ?? 0.0)
                    + (float) ($stockData['customer_deposits'] ?? 0.0)
                    - (float) ($stockData['corporate_treasury'] ?? 1000000000.00)
                ) / $shares)
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
                netDebtPerShare: $resetNetDebtPerShare,
                secularGrowth: $resetSecularGrowth,
                baselineRoic: $impliedPricingRoic,
                baselineMargin: (float) ($stockData['operating_margin'] ?? 0.20),
                // The reset writes the balance sheet by SQL rather than through the entity, so capital per share
                // is built from the same seed figures with the entity's own formula.
                investedCapitalPerShare: \App\Service\Math\CorporateMetrics::getInstance()->calculateLiveInvestedCapital(
                    (float) ($stockData['total_equity'] ?? 0.0),
                    (float) ($stockData['wholesale_debt'] ?? 0.0) + (float) ($stockData['customer_deposits'] ?? 0.0),
                    (float) ($stockData['corporate_treasury'] ?? 1000000000.00)
                ) / max(1.0, (float) ($stockData['shares_outstanding'] ?? 1000000000))
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
                    previous_revenue = :revenue,
                    total_free_cash_flow = NULL,
                    last_analyst_revenue = :analyst_revenue,
                    roe_ttm = :roe_ttm,
                    roic_ttm = :roic_ttm,
                    goodwill = 0.00,
                    cip_balance = 0.00,
                    historical_fixed_rate = :historical_rate,
                    credit_spread = :credit_spread,
                    -- Opens at the baseline: until the first tick builds a Merton spread and an
                    -- accelerator premium on top of it, the baseline is what the credit costs.
                    dynamic_credit_spread = :credit_spread,
                    buyback_authorization = 0.00,
                    last_dividend = :last_dividend,
                    description = :description,
                    sam_ratio = :sam_ratio,
                    industry = :industry,
                    management_style = :management_style,
                    ceo_tenure_years = :ceo_tenure_years,
                    management_intensity = :management_intensity,
                    earnings_momentum_z = NULL,
                    is_bankrupt = 0,
                    payment_default = 0,
                    accruals_ratio = 0.0,
                    net_operating_loss = 0.0000,
                    credit_rating = :credit_rating,
                    -- Every ledger and learned parameter goes back to its unseeded state. Each of these is
                    -- nullable (or defaulted) precisely so the engine can re-seed it on the first earnings
                    -- report; leaving stale values behind would carry the old market into the new one.
                    receivables = NULL,
                    inventory = NULL,
                    payables = NULL,
                    receivables_allowance = 0.0000,
                    inventory_allowance = 0.0000,
                    gross_ppe = NULL,
                    accumulated_depreciation = 0.0000,
                    ppe_vintage_deflator = NULL,
                    ppe_tax_basis = NULL,
                    deferred_tax_liability = 0.0000,
                    earning_assets = NULL,
                    credit_loss_allowance = 0.0000,
                    asset_turnover = NULL,
                    lifecycle_stage = NULL,
                    inflation_pass_through = NULL,
                    -- The CIR variable-cost process starts at its own long-run mean, which EarningsEngine
                    -- derives with the depreciation carve-out applied. Computing it here from margin and
                    -- fixed-cost ratio alone overstated the cost ratio by a median 2% and up to 16% on
                    -- capital-intensive names, always in the same direction, so every reset opened those
                    -- firms on a cost base they were not reverting toward.
                    structural_variable_margin = NULL,
                    pre_announced_shortfall = 0.0,
                    last_reported_cost_ratio = NULL,
                    last_book_to_bill = NULL,
                    lagged_demand_gap = NULL,
                    managed_accrual_bank = 0.0000,
                    price_momentum_trend = 0.0,
                    turnover_ratio = :turnover_ratio,
                    impact_variance_ema = 0.0,
                    corporate_flow_backlog = 0.0,
                    lendable_supply_ratio = :lendable_supply_ratio,
                    short_interest_shares = 0.00,
                    reported_operating_margin = NULL,
                    quarterly_net_income_history = NULL,
                    earnings_surprise_history = NULL
                WHERE ticker = :ticker',
                [
                    'credit_rating' => 'BBB',
                    'price' => $neutralPrice,
                    'shares' => $stockData['shares_outstanding'],
                    'vol' => $stockData['volatility'],
                    // Structural, derived from the name's own volatility rather than stored per ticker, so
                    // a retuned volatility cannot leave a turnover behind that no longer matches it.
                    'turnover_ratio' => \App\Service\Market\LiquidityEngine::structuralTurnoverRatio((float) $stockData['volatility']),
                    'lendable_supply_ratio' => FinancialConstants::DEFAULT_LENDABLE_SUPPLY_RATIO,
                    'current_vol' => $stockData['volatility'],
                    'beta' => $stockData['beta'],
                    'jump_int' => $stockData['jump_intensity'],
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
                    'management_style' => $stockData['management_style'] ?? null,
                    'ceo_tenure_years' => \App\Service\Corporate\ManagementSuccessionEngine::drawSeedTenure($this->mathUtility),
                    'management_intensity' => \App\Data\ManagementProfile::drawIntensity($this->mathUtility),
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

        // Reopen the bond desk on the fresh timeline.
        $this->treasuryAuction->conductAuction($dummyMacro->sovereignCurve(), 0.0);
        $this->entityManager->flush();

        $io->success('Market Reset Complete! You can now start the ticker.');
        return Command::SUCCESS;
    }
}
