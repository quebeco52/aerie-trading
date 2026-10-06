<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\InterestExpenseDTO;
use App\DTO\DebtExpansionAppetiteDTO;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for Commercial Banks.
 * 
 * Financial Physics:
 * - Profits are driven by Net Interest Margin (NIM) and the spread between wholesale/deposit rates and lending rates.
 * - Evaluated strictly on Return on Equity (ROE) rather than ROIC.
 * - Customer deposits act as operating leverage (inventory), requiring an APY Beta to prevent capital flight.
 */
class CommercialBankBusinessModel extends BaseFinancialBusinessModel
{
    // --- Bank Levy ---
    /** A deposit-taking bank pays the bank levy. */
    public const PAYS_BANK_LEVY = true;

    // --- Prudential Regulation ---
    /** A deposit-taking bank holds the CET1 requirement the District's Financial Regulator sets (MacroStateDTO::bankCapitalRequirement). */
    public const PRUDENTIALLY_REGULATED = true;

    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Loan demand and deposit growth track nominal activity. */
    public const OPERATING_CYCLICALITY = 1.00;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Branch and back-office payroll is the largest non-interest expense of a bank. */
    public const FIXED_COST_LABOR_SHARE = 0.60;

    // --- Demand Transmission Lag ---
    /** Years for a move in the output gap to reach the order book. Credit formation lags activity: loan demand builds after the expansion is underway and drawn balances persist into the downturn. */
    public const DEMAND_LAG_YEARS = 0.75;

    // --- Reporting Incentives ---
    /** Propensity to steer reported earnings toward consensus with accruals. The loan loss provision is a judgement call reviewed quarterly, which is why provisioning is the most documented earnings-smoothing lever in banking. */
    public const EARNINGS_MANAGEMENT_PROPENSITY = 0.65;

    // --- Model Thresholds ---
    /** Minimum Interest Coverage Ratio (ICR) required before distress. */
    public const THRESHOLD_MIN_ICR = 1.05;
    /** Tangible capital over assets, in percentage points, below which the lender has failed: 2%, the critically undercapitalized line at which US prompt corrective action puts a bank into receivership (12 CFR 324.403). */
    public const THRESHOLD_BANKRUPT_EQUITY = 2.0;
    /** Tangible capital over assets, in percentage points, below which the lender is in distress: 4%, the leverage ratio under which prompt corrective action rates a bank undercapitalized. */
    public const THRESHOLD_DISTRESS_EQUITY = 4.0;
    /** Tangible capital over assets, in percentage points, below which the lender is on watch: a point above the 5% well-capitalized leverage ratio. */
    public const THRESHOLD_WARNING_EQUITY = 6.0;
    /** Maximum allowed wholesale leverage multiplier. */
    public const THRESHOLD_WHOLESALE_LEVERAGE_LIMIT = 2.0;
    /** ICR level below which dividends are suspended. */
    public const THRESHOLD_DIVIDEND_CRISIS_ICR = 1.05;
    /** Minimum ICR required to execute share buybacks. */
    public const THRESHOLD_BUYBACK_MIN_ICR = 1.15;
    /** Speed at which ROE reverts to the mean. */
    public const THRESHOLD_REVERSION_SPEED = 0.18;
    /** Spread indicating an economic moat. */
    public const THRESHOLD_MOAT_SPREAD = 0.010;
    /** Net Working Capital (NWC) intensity factor. */
    public const THRESHOLD_NWC_INTENSITY = 0.0;
    /** Rate at which planned capital expenditure is completed. */
    public const THRESHOLD_CAPEX_COMPLETION_RATE = 1.0;

        public function getMinIcr(): float { return self::THRESHOLD_MIN_ICR; }
    public function getBankruptEquityThreshold(): float { return self::THRESHOLD_BANKRUPT_EQUITY; }
    public function getDistressEquityThreshold(): float { return self::THRESHOLD_DISTRESS_EQUITY; }
    public function getWarningEquityThreshold(): float { return self::THRESHOLD_WARNING_EQUITY; }
    public function getWholesaleLeverageLimit(): float { return self::THRESHOLD_WHOLESALE_LEVERAGE_LIMIT; }
    public function getDividendCrisisIcr(): float { return self::THRESHOLD_DIVIDEND_CRISIS_ICR; }
    public function getBuybackMinIcr(): float { return self::THRESHOLD_BUYBACK_MIN_ICR; }
    public function getReversionSpeed(): float { return self::THRESHOLD_REVERSION_SPEED; }
    public function getMoatSpread(): float { return self::THRESHOLD_MOAT_SPREAD; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return self::THRESHOLD_NWC_INTENSITY; }
    public function getCapExCompletionRate(Stock $stock): float { return self::THRESHOLD_CAPEX_COMPLETION_RATE; }


    // --- Dual-Stream Banking Architecture ---
    /** Baseline fraction of bank revenue derived from Net Interest Income (NII). */
    public const NII_REVENUE_WEIGHT      = 0.75;
    /** Baseline fraction of bank revenue derived from Non-Interest / Fee Income (Custodial, Wealth, Payments). */
    public const FEE_REVENUE_WEIGHT      = 0.25;

    // --- Revenue & Shock Physics ---
    /** Baseline volatility multiplier for loan origination and fee revenue shocks. */
    public const REVENUE_VARIANCE_SCALAR = 0.15;

    // --- Vasicek ASRF Credit Model (segment PDs and correlations are the macro's: MacroEngine, CreditFiscalSubsystem) ---
    /** Baseline Loss Given Default (LGD) for senior secured / collateralized bank credit facilities. */
    public const LGD_BASELINE                  = 0.45;

    // --- Loan Book Segments (Fed H.8, all commercial banks, Dec 2019: $10.03T of loans) ---
    /** Residential real estate share of loans ($2.30T): households, defaulting at the macro retail rate, secured on homes. */
    public const RESIDENTIAL_MORTGAGE_SHARE    = 0.23;
    /** Consumer loan share ($1.59T: cards, autos, student): households, defaulting at the macro retail rate, recovery not tied to property. */
    public const CONSUMER_LOAN_SHARE           = 0.16;
    /** Commercial real estate share ($2.32T): firms, defaulting at the macro corporate rate, secured on commercial property. The C&I and other remainder (38%) defaults at the corporate rate with no property collateral. */
    public const COMMERCIAL_REAL_ESTATE_SHARE  = 0.23;
    /** Years the collateral reference price averages over: the weighted-average age of a seasoned loan book (~4 years), so LGD reads the price fall since the loans were written, not since a fixed baseline. */
    public const COLLATERAL_ORIGINATION_YEARS  = 4.0;

    // --- Segment Charge-Off Rates (FRED, all commercial banks, 1991-2019 means; each segment's PD is its rate over LGD_BASELINE) ---
    /** Single-family residential mortgages: 0.43% a year (CORSFRMACBS, peak 2.80%). */
    public const RESIDENTIAL_CHARGE_OFF_RATE = 0.0043;
    /** Consumer loans, cards and autos together: 2.61% a year (CORCACBS, peak 6.60%). */
    public const CONSUMER_CHARGE_OFF_RATE = 0.0261;
    /** Commercial real estate excluding farmland: 0.53% a year (CORCREXFACBS, peak 2.85%). */
    public const COMMERCIAL_REAL_ESTATE_CHARGE_OFF_RATE = 0.0053;
    /** Business (C&I) loans: 0.73% a year (CORBLACBS, peak 2.57%). The H.8 mix of the four gives 0.92%, all loans 0.89% (CORALACBS). */
    public const BUSINESS_CHARGE_OFF_RATE = 0.0073;
    /** Persisted origination reference for the residential property index. */
    public const STATE_RESIDENTIAL_ORIGINATION_PRICE = 'state:collateral_origination_residential';
    /** Persisted origination reference for the commercial property index. */
    public const STATE_COMMERCIAL_ORIGINATION_PRICE  = 'state:collateral_origination_commercial';

    // --- Underwriting Risk Appetite ---
    /** Neutral appetite: a bank here loses each segment's US charge-off rate, and its defaults move with the economy's. */
    public const NEUTRAL_CREDIT_RISK_APPETITE  = 0.50;
    /** Appetite floor (0.2x the segment PDs, ~0.2% a year on the H.8 mix): a book this clean is sovereign paper wearing a loan's clothes. */
    public const MIN_CREDIT_RISK_APPETITE      = 0.10;
    /** Appetite ceiling (2x the segment PDs, ~1.8% a year on the H.8 mix): the edge of a viable commercial book before it is subprime lending. */
    public const MAX_CREDIT_RISK_APPETITE      = 1.00;

    // --- CECL Forward Reserve (ASC 326 lifetime allowance conditioned on the macro forecast) ---
    /** Baseline investment-grade corporate credit spread (macro through-the-cycle IG); the lifetime loss estimate is struck at 1.0x here. */
    public const CECL_BASELINE_CREDIT_SPREAD   = MacroEngine::BASE_CREDIT_SPREAD;
    /** Lifetime-loss multiplier per unit of IG spread widening: +300bps lifts the reserve target ~0.36x. */
    public const CECL_RESERVE_SPREAD_SENSITIVITY = 12.0;
    /** Baseline 12-month forward recession probability (~15%); the lifetime loss estimate is struck at 1.0x here. */
    public const CECL_BASELINE_RECESSION_PROB  = 0.15;
    /** Lifetime-loss multiplier per unit of recession probability above baseline: a near-certain recession lifts the target ~0.85x (large-bank allowances went 1.4% -> 3.3% of loans in 2020 with spreads). */
    public const CECL_RESERVE_RECESSION_SENSITIVITY = 1.00;
    /** Floor on the forecast multiplier: a benign outlook releases part of the through-the-cycle reserve, never most of it. */
    public const CECL_RESERVE_MULTIPLIER_FLOOR = 0.75;

    // --- SLOOS Lending Standards & Credit Boom ---
    /** Sensitivity of NII loan origination volume to net percentage of domestic banks tightening standards (SLOOS). */
    public const SLOOS_NII_ORIGINATION_SENSITIVITY = 0.20;
    /** Origination volume per unit of the household credit-to-GDP gap: a credit boom writes its own loans (Borio & Lowe 2002). */
    public const CREDIT_GAP_ORIGINATION_SENSITIVITY = 0.50;

    // --- Housing Mortgage & M2 Money Supply Transmission ---
    /** Sensitivity of residential purchase and construction mortgage origination volume to housing starts. */
    public const HOUSING_MORTGAGE_ORIGINATION_SENSITIVITY = 0.25;
    /** Sensitivity of commercial bank core deposit expansion and lending capacity to M2 broad money growth. */
    public const M2_DEPOSIT_GROWTH_SENSITIVITY = 0.40;

    // --- Macaulay Duration Gap & IRRBB NIM Physics ---
    /** Weighted average Macaulay duration of bank loan and mortgage assets in years. */
    public const ASSET_DURATION_YEARS          = 4.5;
    /** Years of expected loss the CECL allowance covers. At the through-the-cycle loss rate (~0.9% of loans) this puts the reserve near 1.8% of loans, where large US banks have run since CECL adoption. */
    public const CECL_LIFETIME_HORIZON_YEARS   = 2.0;
    /** Weighted average Macaulay duration of customer deposit and wholesale liabilities in years. */
    public const LIABILITY_DURATION_YEARS      = 1.5;
    /** Floating-rate asset/liability natural hedge effectiveness dampening duration mismatch exposure. */
    public const FLOATING_HEDGE_EFFICIENCY     = 0.50;
    /** Break-even NIM floor (~50bps): the neutral 2s10s slope (~70bps) less the interbank spread the funding side pays. Steeper = profit; flat or inverted = squeeze. */
    public const NIM_BASE_SPREAD_BUFFER        = 0.005;
    /** Calibrated baseline sensitivity for NIM duration gap exposure before company-specific ALM adjustments. */
    public const NIM_INVERSION_SENSITIVITY     = 10.0;
    /** Structural minimum efficiency ratio: the lowest cost-to-revenue ratio any bank can reach, even at perfect NIM. */
    public const MIN_EFFICIENCY_RATIO          = 0.55;

    // --- Analyst Visibility & Error ---
    /** Baseline coverage visibility for analyst estimates. */
    public const BASE_COVERAGE_VISIBILITY = 0.65;
    /** Baseline error rate for analyst estimates. */
    public const BASE_COVERAGE_ERROR = 0.06;

    // --- Model Specific Constants ---
    /** Hard ceiling on gross asset yield: prevents hyperinflated loan yields during margin compression. */
    public const MAX_GROSS_ASSET_YIELD  = 0.40;
    /** EBIT floor as a fraction of core liabilities: ensures the bank never shuts down its loan book. */
    public const MIN_CORE_LENDING_YIELD = 0.015;

    // --- Deposit Beta: exp(-decayRate * utilization) model ---
    /** Maximum beta offered at zero utilization — how much of the policy rate the bank passes to depositors. */
    public const DEPOSIT_BETA_AMPLITUDE        = 0.80;
    /** Base exponential decay rate: erodes the beta as the bank approaches its regulatory leverage limit. */
    public const DEPOSIT_BETA_BASE_DECAY       = 0.50;
    /** Accelerating decay when the bank is heavily deposit-funded and competing harder for cheap liabilities. */
    public const DEPOSIT_BETA_RATIO_DECAY_MULT = 2.00;
    /** Upper bound: banks rarely pay more than 70% of the policy rate to retain depositors. */
    public const MAX_DEPOSIT_BETA              = 0.70;
    /** Lower bound: regulatory and reputational floor — banks always pay some yield. */
    public const MIN_DEPOSIT_BETA              = 0.10;
    /** Market-average beta baseline: the level the system deposit beta scales the firm's own beta from. */
    public const DEPOSIT_BETA_NORMALIZATION_BASELINE = MacroEngine::SYSTEM_DEPOSIT_BETA_BASE;
    /** Deposit flow per unit of money-market share moved this quarter: share gained leaves the deposit base and share lost returns to it (Drechsler, Savov & Schnabl 2017). */
    public const MMF_MIGRATION_DEPOSIT_DRAG = 1.2;

    // --- Hoarding & Deposit Flight ---
    /** Fraction of total debt held as idle excess cash before the bank is flagged as a hoarder. */
    public const HOARDING_THRESHOLD_DEBT_RATIO      = 0.12;
    /** Higher idle cash fraction that triggers more aggressive capital return pressure. */
    public const MEGA_HOARDING_THRESHOLD_DEBT_RATIO = 0.18;

    // --- Bank Valuation Weights ---
    /** Weight given to Price-to-Book value when EPS is positive. */
    public const FAIR_VALUE_BOOK_WEIGHT_PROFIT = 0.40;
    /** Weight given to Price-to-Book value when EPS is negative (liquidation value focus). */
    public const FAIR_VALUE_BOOK_WEIGHT_LOSS = 0.80;

    // --- Liquidity & Cash Target Constants ---
    /** Fraction of operating base held as cash. */
    public const TARGET_CASH_OPERATING_MULT = 0.05;
    /** Fraction of current liabilities held as target cash. */
    public const TARGET_CASH_LIABILITY_MULT = 0.10;
    /** Fraction of wholesale debt held as target cash. */
    public const TARGET_CASH_WHOLESALE_MULT = 0.05;
    /** Minimum fraction of operating base held as cash. */
    public const MIN_CASH_OPERATING_MULT = 0.03;
    /** Minimum fraction of current liabilities held as cash. */
    public const MIN_CASH_LIABILITY_MULT = 0.05;
    /** Minimum fraction of wholesale debt held as cash. */
    public const MIN_CASH_WHOLESALE_MULT = 0.03;
    /** Buffer for operating base when calculating interest income. */
    public const INTEREST_INCOME_CASH_BUFFER = 0.05;

    // --- Debt Expansion Constants ---
    /** Base probability for debt expansion in a neutral environment. */
    public const DEBT_EXPANSION_BASE_PROB = 0.40;
    /** Multiplier applied to spread to adjust debt expansion probability. */
    public const DEBT_EXPANSION_PROB_MULT = 0.40;
    /** Base aggressiveness for debt expansion in a neutral environment. */
    public const DEBT_EXPANSION_BASE_AGGR = 0.02;
    /** Multiplier applied to spread to adjust debt expansion aggressiveness. */
    public const DEBT_EXPANSION_AGGR_MULT = 0.20;
    /** Maximum probability boost for urgent wholesale funding. */
    public const WHOLESALE_URGENCY_PROB_BOOST = 0.80;
    /** Maximum aggressiveness boost for urgent wholesale funding. */
    public const WHOLESALE_URGENCY_AGGR_BOOST = 0.45;
    /** Deposit ratio where debt expansion throttle starts (was 0.80). */
    public const DEPOSIT_THROTTLE_UPPER_BOUND = 0.50;
    /** Deposit ratio where debt expansion throttle maximizes (was 0.40). */
    public const DEPOSIT_THROTTLE_LOWER_BOUND = 0.20;
    /** Minimum floor for debt expansion throttle (was 0.0). */
    public const DEPOSIT_THROTTLE_FLOOR = 0.20;

    // --- Capital Return & Expansion ---
    /** Maximum buyback spend ratio of excess cash for mega hoarders. */
    public const BUYBACK_MEGA_HOARDER_LIMIT = 0.30;
    /** Maximum buyback spend ratio of excess cash for hoarders. */
    public const BUYBACK_HOARDER_LIMIT = 0.10;
    /** Minimum ratio of target leverage before the bank is considered under-leveraged and triggers aggressive buybacks to defend ROE. */
    public const UNDER_LEVERAGED_TOLERANCE = 0.35;

    // --- Macro & Shock Thresholds ---
    /** Sensitivity of bank demand to macroeconomic output gap (lower than physical goods). */
    public const MACRO_DEMAND_BETA_SENSITIVITY = 0.50;
    /** Pricing power multiplier for banks, passing structural yields to expectations. */
    public const MACRO_PRICING_POWER_MULT = 1.0;
    /** Persistence (AR1) parameter for Net Interest Income (NII) Z-score drift. */
    public const STREAM_Z_PERSISTENCE_NII = 0.35;
    /** Persistence (AR1) parameter for Fee Income Z-score drift. */
    public const STREAM_Z_PERSISTENCE_FEE = 0.25;
    /** Minimum duration gap multiplier acting as a hedge floor. */
    public const HEDGE_FLOOR_MULTIPLIER = 0.10;
    /** Minimum base expansion probability floor. */
    public const DEBT_EXPANSION_PROB_MIN = 0.05;
    /** Minimum base expansion aggressiveness floor. */
    public const DEBT_EXPANSION_AGGR_MIN = 0.02;
    /** Maximum base expansion probability limit. */
    public const DEBT_EXPANSION_PROB_MAX = 1.0;
    /** Maximum base expansion aggressiveness limit. */
    public const DEBT_EXPANSION_AGGR_MAX = 0.50;

    /** Output gap multiplier for fee revenue. */
    public const SECTOR_SHOCK_FEE_OUTPUT_GAP_MULT = 0.35;
    /** Charge-offs over the through-the-cycle rate above which defaults are elevated: US banks ran 1.08x their 1985-2019 mean in 2001-02, peaking at 1.39x (FRED CORALACBN). */
    public const SECTOR_SHOCK_ELEVATED_LOSS_MULTIPLE = 1.3;
    /** Charge-offs over the through-the-cycle rate above which provisions are massive: 2.85x in 2009-10, peaking at 3.4x. */
    public const SECTOR_SHOCK_MASSIVE_LOSS_MULTIPLE = 2.5;
    /** Charge-offs over the through-the-cycle rate below which reserves are released: 0.55x in 2004-06, when US banks drew their allowances down. */
    public const SECTOR_SHOCK_RESERVE_RELEASE_LOSS_MULTIPLE = 0.6;

    // --- Basel III Capital Adequacy & CCB ---
    /** Risk weight for risk-free cash and central bank treasury reserves under Basel III Standardized Approach. */
    public const BASEL_RISK_WEIGHT_TREASURY = 0.0;
    /** Risk weight on a first-lien residential mortgage under the US standardized approach: 50% (12 CFR 217.32(g)). */
    public const BASEL_RISK_WEIGHT_RESIDENTIAL_MORTGAGE = 0.50;
    /** Risk weight on business, commercial real estate and consumer loans: 100% (12 CFR 217.32(f), (l)). High-volatility construction lending at 150% is not split out. */
    public const BASEL_RISK_WEIGHT_LOANS = 1.00;
    /** Risk weight on the securities sleeve: 20%, the weight on agency MBS and GSE debt, the bulk of a US bank's securities (12 CFR 217.32(c)). On the H.8 loan mix the three weights give 0.73 of earning assets; US insured banks held $14.90T of RWA on $20.69T of non-cash assets at end-2024, 0.72 (FDIC Call Reports). */
    public const BASEL_RISK_WEIGHT_SECURITIES = 0.20;
    /** Basel III Pillar 1 minimum Common Equity Tier 1 (CET1) ratio, below which the bank is undercapitalized and under supervisory restriction (BCBS 2011, para. 50); it fails only at THRESHOLD_BANKRUPT_EQUITY. */
    public const BASEL_MIN_CET1_RATIO = 0.045;
    /** Basel III minimum plus the 2.5% capital conservation buffer: the payout stop for a lender outside the District's bank requirement. */
    public const BASEL_CCB_CET1_RATIO = 0.070;

    // --- Passive Liability Growth ---
    /** Standard deviation of idiosyncratic drift applied to passive liability growth. */
    public const LIABILITY_GROWTH_DRIFT_STD = 0.005;
    /** Sentiment shock magnitude applied during a severe bank run event. */
    public const EVENT_SHOCK_BANK_RUN = -5.0;
    /** Sentiment shock magnitude applied when significant customer deposits flee. */
    public const EVENT_SHOCK_DEPOSIT_FLIGHT = -2.0;
    /** Sentiment shock magnitude applied when new deposits are heavily captured. */
    public const EVENT_SHOCK_DEPOSIT_CAPTURE = 0.5;

    /** Absolute limit on quarterly liability change fraction. */
    public const LIABILITY_MAX_CHANGE_LIMIT = 0.15;
    /** Quarterly shortfall against trend nominal income growth that triggers a deposit flight event. */
    public const LIABILITY_FLIGHT_THRESHOLD = -0.005;
    /** Quarterly excess over trend nominal income growth that triggers a captured new deposits event. */
    public const LIABILITY_CAPTURE_THRESHOLD = 0.005;

    /**
     * Returns a stable structural ROIC proxy to keep top-line loan revenue rock solid.
     * Dynamic NIM (Net Interest Margin) expansion/compression is handled strictly in computeActualFinancials.
     *
     * @param Stock       $stock       The bank stock entity.
     * @param MacroStateDTO $macroState  The macroeconomic state.
     * @param MathUtility $mathUtility Mathematical utility.
     * @return array{invested_capital: float, baseline_roic: float}
     */
    public function getTargetMetrics(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        // Capital is tangible: goodwill absorbs no loss, so it neither sizes the book nor earns the return target.
        $equity = $stock->getTangibleEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();

        $effectiveEquity = max(1.0, $equity);

        // Earning assets are the loan book the yield is struck on: the ledger once it is open, and before
        // that the funding deployed away from idle cash.
        $earningAssets = $this->resolveEarningAssets($stock, $treasury);
        $baselineRoe = $this->resolveStructuralTargetRoe($stock, $macroState);

        $equityLimit = \App\Data\Sectors::equityLimit($stock->getIndustry());

        $policyRate = $macroState->policyRateEma;
        $yield5y = $macroState->yield5yEma;
        $structuralSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        $taxRate = $macroState->corporateTaxRate;

        $customerDeposits = (float) $stock->getCustomerDeposits();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();

        // Calculate what the bank MUST pay depositors to keep them from fleeing.
        $depositRatio = $totalDebt > 0 ? ($customerDeposits / $totalDebt) : 0.0;
        $depositBeta = $this->calculateDepositBeta($totalDebt, $equity, $equityLimit, $customerDeposits);
        $depositRate = max(0.001, $policyRate * $this->resolveEffectiveDepositBeta($depositBeta, $macroState));

        $blendedWholesaleRate = ($floatingRatio * ($policyRate + $macroState->interbankLiquiditySpreadEma)) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;

        // Derive structural asset yield using actual deployed wholesale leverage capped at regulatory limits.
        $actualWholesaleLeverage = $effectiveEquity > 0 ? ($wholesaleDebt / $effectiveEquity) : 0.0;
        $wholesaleLeverageLimit = $this->getWholesaleLeverageLimit();

        $allowedWholesaleLeverage = min($actualWholesaleLeverage, max(0.0, $wholesaleLeverageLimit));
        $optimalWholesaleDebt = $effectiveEquity * $allowedWholesaleLeverage;

        $optimalDeposits = $customerDeposits;
        $optimalDebt = $optimalWholesaleDebt + $optimalDeposits;

        $targetCash = $this->calculateTargetOperatingCash($effectiveEquity, $optimalDeposits, $optimalWholesaleDebt);
        $optimalEarningAssets = $effectiveEquity + $optimalDebt - $targetCash;

        $optimalInterestExpense = ($optimalWholesaleDebt * $blendedWholesaleRate) + ($optimalDeposits * $depositRate);

        $optimalNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalNetIncome / (1.0 - $taxRate);

        // Target is pre-provision operating profit, carrying the through-the-cycle credit charge.
        $optimalCreditProvision = $this->resolveThroughTheCycleCreditProvision($stock, $optimalEarningAssets);
        $optimalEbit = $optimalEbt + $optimalInterestExpense + $optimalCreditProvision;
        $structuralAssetYield = $optimalEbit / max(1.0, $optimalEarningAssets);

        // Apply structural yield to actual physical loan book.
        $targetEbit = $earningAssets * $structuralAssetYield;

        // Floor target EBIT based on core liabilities to maintain baseline lending operations.
        $coreLiabilities = $totalDebt;
        $minLendingEbit = $coreLiabilities * self::MIN_CORE_LENDING_YIELD;

        $targetEbit = max($minLendingEbit, $targetEbit);

        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        // Derive target revenue from EBIT, capping gross yield to prevent margin compression distortion.
        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $targetRevenue = min($unboundedRevenue, $earningAssets * self::MAX_GROSS_ASSET_YIELD);

        $grossYield = $targetRevenue / max(1.0, abs($earningAssets));

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => ($grossYield * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $outputGap = $this->resolveLaggedOutputGap($macroState);
        $beta = $this->getOperatingCyclicality($stock);

        return [
            'macro_demand_shift' => $outputGap * $beta * self::MACRO_DEMAND_BETA_SENSITIVITY, // Less demand destruction than physical goods
            'pricing_power_multiplier' => self::MACRO_PRICING_POWER_MULT, // Passes structural yield adjustments to the expectation engine
        ];
    }

    /**
     * Idiosyncratic shocks to loan origination volume and fee revenue; credit losses on the loan book follow the
     * District's systematic credit cycle (see resolveConditionalCreditLossRate()).
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::NiiRevenueWeight->value          => self::NII_REVENUE_WEIGHT,
            ModelParam::FeeRevenueWeight->value          => self::FEE_REVENUE_WEIGHT,
            ModelParam::ProprietaryDividendWeight->value => 0.00,
            ModelParam::NimInversionSensitivity->value   => self::NIM_INVERSION_SENSITIVITY,
        ]);

        $rawProprietaryWeight = $params[ModelParam::ProprietaryDividendWeight];
        $inversionSensitivity = $params[ModelParam::NimInversionSensitivity];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $targetWeights = [
            'net_interest_income' => $params[ModelParam::NiiRevenueWeight],
            'fee_income'          => $params[ModelParam::FeeRevenueWeight],
        ];
        if ($rawProprietaryWeight > 0.0) {
            $targetWeights['proprietary_dividend'] = $rawProprietaryWeight;
        }

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $niiWeight                 = $activeWeights['net_interest_income'];
        $feeWeight                 = $activeWeights['fee_income'];
        $proprietaryDividendWeight = $activeWeights['proprietary_dividend'] ?? 0.0;

        // Independent stream Z-scores with AR(1) persistence
        $revenueZ = $streams->generateZ('net_interest_income', self::STREAM_Z_PERSISTENCE_NII); // NII loan origination volume
        $feeZ     = $streams->generateZ('fee_income', self::STREAM_Z_PERSISTENCE_FEE); // Non-interest custodial / payment fee volume

        $outputGap = $macroState->outputGapEma;

        // Blended dual-stream revenue (NII vs. Non-Interest Fee Income)
        // Fed SLOOS, Housing Starts, and M2 channels:
        // Credit standards tightening (SLOOS > 0) dampens loan origination volume; easing (SLOOS < 0) expands it.
        // Residential housing starts drive mortgage purchase origination, and broad money (M2) growth expands deposit lending capacity.
        $sloosOriginationDrag = $macroState->sloosTighteningIndexEma * self::SLOOS_NII_ORIGINATION_SENSITIVITY;
        $housingMortgageBoost = MathUtility::calculateHousingStartsShift($macroState->housingStartsIndexEma, sensitivity: self::HOUSING_MORTGAGE_ORIGINATION_SENSITIVITY);
        $m2LiquidityBoost = MathUtility::calculateBroadMoneyLiquidityShift($macroState->moneySupplyGrowthEma, sensitivity: self::M2_DEPOSIT_GROWTH_SENSITIVITY);
        $creditBoomBoost = $macroState->creditToGdpGapEma * self::CREDIT_GAP_ORIGINATION_SENSITIVITY;

        $niiRevenue = max(0.0, $expectedRevenue * $niiWeight
            * (1.0 + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) - $sloosOriginationDrag + $housingMortgageBoost + $m2LiquidityBoost + $creditBoomBoost));
        $feeRevenue = max(0.0, $expectedRevenue * $feeWeight
            * (1.0 + ($feeZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + ($outputGap * self::SECTOR_SHOCK_FEE_OUTPUT_GAP_MULT)));

        $streamRevenues = [
            'net_interest_income' => $niiRevenue,
            'fee_income'          => $feeRevenue,
        ];

        $proprietaryDividendRevenue = 0.0;
        $proprietaryDividendZ = 0.0;
        if ($proprietaryDividendWeight > 0.0) {
            // Proprietary dividends are highly cyclical and tie to corporate expansion
            $proprietaryDividendZ = $streams->generateZ('proprietary_dividend', self::STREAM_Z_PERSISTENCE_FEE);
            $proprietaryDividendRevenue = max(0.0, $expectedRevenue * $proprietaryDividendWeight * (1.0 + ($proprietaryDividendZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 1.5)) + ($outputGap * self::SECTOR_SHOCK_FEE_OUTPUT_GAP_MULT * 2.0)));
            $streamRevenues['proprietary_dividend'] = $proprietaryDividendRevenue;
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Balance sheet loan book (Earning Assets) deployed into credit
        $earningAssets = $this->resolveEarningAssets($stock);

        // Charge-offs are the book's conditional loss this quarter. They reach EBIT once, through the allowance
        // roll-forward, which replaces what they consumed (provision = charge-offs + change in allowance).
        $credit = $this->resolveConditionalCreditLossRate($stock, $macroState, $streams, $mathUtility);
        $defaultZ = $credit['systematic_z'];
        $netChargeOffs = ($credit['loss_rate'] / FinancialConstants::QUARTERS_PER_YEAR) * $earningAssets;
        $lossMultiple = $credit['loss_rate'] / max(1e-9, $this->getThroughTheCycleCreditLossRate($stock));

        // The forward-looking CECL reserve (credit spreads, recession forecast) is NOT a margin term: it moves
        // the allowance TARGET through getForwardCreditLossMultiplier(), and the ledger roll-forward books the
        // build once and releases it when the outlook clears. Charging it here every quarter the outlook
        // stayed bad priced a level as news and cost a lender its reserve build several times over.

        // Macaulay Duration Gap & IRRBB NIM Physics:
        // Bank assets (long-term loans/mortgages) have higher duration than liabilities (short-term deposits/repo).
        // Banks utilize interest rate swaps & natural floating-rate debt to hedge a portion of this duration gap.
        $yield10y = $macroState->yield10yEma;
        $yield2y  = $macroState->yield2yEma;
        $bankSpread = $yield10y - ($yield2y + $macroState->interbankLiquiditySpreadEma);

        $rawDurationGap = max(0.0, self::ASSET_DURATION_YEARS - self::LIABILITY_DURATION_YEARS);
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $hedgeMultiplier = ($inversionSensitivity / self::NIM_INVERSION_SENSITIVITY) * (1.0 - ($floatingRatio * self::FLOATING_HEDGE_EFFICIENCY));
        $effectiveDurationGap = $rawDurationGap * max(self::HEDGE_FLOOR_MULTIPLIER, $hedgeMultiplier);

        $curveDeviation = $bankSpread - self::NIM_BASE_SPREAD_BUFFER;
        $nimSqueeze = - ($curveDeviation * $effectiveDurationGap);

        // Physics-grounded Efficiency Floor: Total Operating Costs (Fixed + Variable) / Revenue >= MIN_EFFICIENCY_RATIO.
        // Crucially, the NIM squeeze applies in proportion to the NII revenue share ($niiWeight),
        // leaving Non-Interest custodial / wealth / transaction fee income completely insulated.
        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $rawMargin = $realizedVariableMargin + ($nimSqueeze * $niiWeight);
        $clampedMargin = $this->clampMargin($rawMargin, $minVariableMargin);
        $netInterestSqueeze = ($clampedMargin - $this->clampMargin($realizedVariableMargin, $minVariableMargin)) * $actualRevenue;

        $cet1Ratio = $this->calculateCet1Ratio($stock);

        $eventType = null;
        if ($cet1Ratio < self::BASEL_MIN_CET1_RATIO) {
            $eventType = ShockEvent::CAPITAL_BELOW_MINIMUM;
        } elseif ($lossMultiple > static::SECTOR_SHOCK_MASSIVE_LOSS_MULTIPLE) {
            $eventType = ShockEvent::MASSIVE_CREDIT_PROVISION;
        } elseif ($lossMultiple > static::SECTOR_SHOCK_ELEVATED_LOSS_MULTIPLE) {
            $eventType = ShockEvent::ELEVATED_LOAN_DEFAULTS;
        } elseif ($lossMultiple < static::SECTOR_SHOCK_RESERVE_RELEASE_LOSS_MULTIPLE) {
            $eventType = ShockEvent::RESERVE_RELEASE;
        }

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $streams->resolveDominantShockZ([$defaultZ, $revenueZ]),
            observableShockZ: $revenueZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR,
            eventType: $eventType,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            creditLossProvision: 0.0,
            netChargeOffs: $netChargeOffs,
            netInterestSqueeze: $netInterestSqueeze,
        );
    }

    /**
     * ASC 326 reasonable-and-supportable forecast: the lifetime loss estimate scales with how much worse than
     * through-the-cycle the outlook is, read off the two forward indicators a reserving committee watches,
     * IG credit spreads and the 12-month recession probability. Widening and rising risk lift the target
     * (a build, booked once by the roll-forward); a benign outlook releases down to the floor.
     */
    public function getForwardCreditLossMultiplier(?Stock $stock, MacroStateDTO $macroState): float
    {
        $spreadGap = $macroState->macroCreditSpreadEma - static::CECL_BASELINE_CREDIT_SPREAD;
        $recessionGap = max(0.0, $macroState->recessionProbabilityEma - static::CECL_BASELINE_RECESSION_PROB);

        $multiplier = 1.0
            + ($spreadGap * static::CECL_RESERVE_SPREAD_SENSITIVITY * $this->resolveSpreadReserveBeta($stock))
            + ($recessionGap * static::CECL_RESERVE_RECESSION_SENSITIVITY);

        return max(static::CECL_RESERVE_MULTIPLIER_FLOOR, $multiplier);
    }

    /** How much more than the sector a given lender's reserve moves with credit spreads (1.0 = the sector). */
    protected function resolveSpreadReserveBeta(?Stock $stock): float
    {
        return 1.0;
    }

    /**
     * How far this bank's underwriting sits from the segment norms. Two banks funded identically do not underwrite
     * identically: a universal lender syndicating investment-grade corporate paper runs a cleaner book than a regional
     * lender competing on speed for contractor and developer credit. CreditRiskAppetite scales every segment PD around
     * a neutral 0.50, which is the only place a bank's stated underwriting posture reaches the loss model.
     */
    protected function resolveCreditRiskScale(?Stock $stock): float
    {
        if (!$stock instanceof Stock) {
            return 1.0;
        }

        $params = $this->resolveModelParameters($stock, [
            ModelParam::CreditRiskAppetite->value => self::NEUTRAL_CREDIT_RISK_APPETITE,
        ]);

        $appetite = max(
            self::MIN_CREDIT_RISK_APPETITE,
            min(self::MAX_CREDIT_RISK_APPETITE, (float) $params[ModelParam::CreditRiskAppetite])
        );

        return $appetite / self::NEUTRAL_CREDIT_RISK_APPETITE;
    }

    /**
     * This quarter's annual loss rate on earning assets, read off the loan book segment by segment. Each segment reads the systematic factor
     * the macro default rate implies (households the retail rate, firms the corporate rate) and applies it at the
     * macro's own correlation to the segment's long-run PD, so every lender in the District takes the same credit
     * cycle through its own book; the low-PD segments (mortgages, CRE) swing furthest, as they did in 2009-10.
     * Property-secured segments lose more as collateral falls below the price the loans were written at (Frye 2000).
     *
     * @return array{loss_rate: float, systematic_z: float}
     */
    protected function resolveConditionalCreditLossRate(Stock $stock, MacroStateDTO $macroState, StreamContext $streams, MathUtility $mathUtility): array
    {
        ['residential' => $residentialShare, 'consumer' => $consumerShare, 'commercial_real_estate' => $creShare, 'business' => $businessShare] = $this->resolveLoanBookMix($stock);

        $householdZ = $mathUtility->calculateVasicekSystematicFactor(
            $macroState->retailDefaultRateEma,
            MacroEngine::RETAIL_DEFAULT_BASELINE,
            CreditFiscalSubsystem::RETAIL_ASRF_RHO
        );
        $corporateZ = $mathUtility->calculateVasicekSystematicFactor(
            $macroState->corporateDefaultRateEma,
            MacroEngine::CORPORATE_DEFAULT_BASELINE,
            CreditFiscalSubsystem::CORPORATE_DEFAULT_RHO
        );

        $quarter = 1.0 / FinancialConstants::QUARTERS_PER_YEAR;
        $residentialPrice = $macroState->residentialPropertyIndexEma;
        $commercialPrice = $macroState->commercialPropertyIndexEma;
        $residentialOrigination = $streams->getPersistedState(self::STATE_RESIDENTIAL_ORIGINATION_PRICE, $residentialPrice);
        $commercialOrigination = $streams->getPersistedState(self::STATE_COMMERCIAL_ORIGINATION_PRICE, $commercialPrice);
        $residentialLgd = MathUtility::calculateCollateralLgd(self::LGD_BASELINE, $residentialPrice, $residentialOrigination);
        $commercialLgd = MathUtility::calculateCollateralLgd(self::LGD_BASELINE, $commercialPrice, $commercialOrigination);
        $streams->registerState(self::STATE_RESIDENTIAL_ORIGINATION_PRICE, $mathUtility->calculateDistributedLag($residentialOrigination, $residentialPrice, $quarter, self::COLLATERAL_ORIGINATION_YEARS));
        $streams->registerState(self::STATE_COMMERCIAL_ORIGINATION_PRICE, $mathUtility->calculateDistributedLag($commercialOrigination, $commercialPrice, $quarter, self::COLLATERAL_ORIGINATION_YEARS));

        $pdScale = $this->resolveCreditRiskScale($stock) / self::LGD_BASELINE;
        $householdRho = CreditFiscalSubsystem::RETAIL_ASRF_RHO;
        $corporateRho = CreditFiscalSubsystem::CORPORATE_DEFAULT_RHO;
        $lossRate = ($residentialShare * $mathUtility->calculateVasicekExpectedLoss($householdZ, static::RESIDENTIAL_CHARGE_OFF_RATE * $pdScale, $householdRho, $residentialLgd))
            + ($consumerShare * $mathUtility->calculateVasicekExpectedLoss($householdZ, static::CONSUMER_CHARGE_OFF_RATE * $pdScale, $householdRho, self::LGD_BASELINE))
            + ($creShare * $mathUtility->calculateVasicekExpectedLoss($corporateZ, static::COMMERCIAL_REAL_ESTATE_CHARGE_OFF_RATE * $pdScale, $corporateRho, $commercialLgd))
            + ($businessShare * $mathUtility->calculateVasicekExpectedLoss($corporateZ, static::BUSINESS_CHARGE_OFF_RATE * $pdScale, $corporateRho, self::LGD_BASELINE));

        $householdWeight = $residentialShare + $consumerShare;

        return [
            'loss_rate' => $lossRate * $this->getLoanShareOfEarningAssets(),
            'systematic_z' => ($householdWeight * $householdZ) + ((1.0 - $householdWeight) * $corporateZ),
        ];
    }

    /**
     * The bank's loan book by segment, as shares of loans: the H.8 system mix unless the firm's lore says otherwise.
     * The C&I and other business remainder takes whatever the three named segments leave.
     *
     * @return array{residential: float, consumer: float, commercial_real_estate: float, business: float}
     */
    protected function resolveLoanBookMix(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::ResidentialMortgageShare->value  => static::RESIDENTIAL_MORTGAGE_SHARE,
            ModelParam::ConsumerLoanShare->value         => static::CONSUMER_LOAN_SHARE,
            ModelParam::CommercialRealEstateShare->value => static::COMMERCIAL_REAL_ESTATE_SHARE,
        ]);

        return self::completeLoanBookMix(
            (float) $params[ModelParam::ResidentialMortgageShare],
            (float) $params[ModelParam::ConsumerLoanShare],
            (float) $params[ModelParam::CommercialRealEstateShare]
        );
    }

    /**
     * The sector's own mix, for a lender not yet seeded.
     *
     * @return array{residential: float, consumer: float, commercial_real_estate: float, business: float}
     */
    protected function resolveDefaultLoanBookMix(): array
    {
        return self::completeLoanBookMix(static::RESIDENTIAL_MORTGAGE_SHARE, static::CONSUMER_LOAN_SHARE, static::COMMERCIAL_REAL_ESTATE_SHARE);
    }

    /**
     * Three named segment shares, scaled back to a whole book if they overrun it, with business lending as the remainder.
     *
     * @return array{residential: float, consumer: float, commercial_real_estate: float, business: float}
     */
    protected static function completeLoanBookMix(float $residential, float $consumer, float $commercialRealEstate): array
    {
        $residential = max(0.0, $residential);
        $consumer = max(0.0, $consumer);
        $commercialRealEstate = max(0.0, $commercialRealEstate);
        $named = $residential + $consumer + $commercialRealEstate;
        if ($named > 1.0) {
            $residential /= $named;
            $consumer /= $named;
            $commercialRealEstate /= $named;
        }

        return [
            'residential' => $residential,
            'consumer' => $consumer,
            'commercial_real_estate' => $commercialRealEstate,
            'business' => max(0.0, 1.0 - $residential - $consumer - $commercialRealEstate),
        ];
    }

    /**
     * Through-the-cycle loss on this lender's earning assets: each segment's long-run charge-off rate (Basel's PD x
     * LGD), which is the mean of the conditional losses whenever the macro default rates average at their baselines,
     * on the loan share of the book. A neutral H.8 loan book loses 0.92% a year; US banks charged off 0.89% of loans
     * over 1991-2019 (FRED CORALACBS).
     */
    public function getThroughTheCycleCreditLossRate(?Stock $stock = null): float
    {
        $mix = $stock instanceof Stock ? $this->resolveLoanBookMix($stock) : $this->resolveDefaultLoanBookMix();

        return $this->getLoanShareOfEarningAssets() * $this->resolveCreditRiskScale($stock) * (
            ($mix['residential'] * static::RESIDENTIAL_CHARGE_OFF_RATE)
            + ($mix['consumer'] * static::CONSUMER_CHARGE_OFF_RATE)
            + ($mix['commercial_real_estate'] * static::COMMERCIAL_REAL_ESTATE_CHARGE_OFF_RATE)
            + ($mix['business'] * static::BUSINESS_CHARGE_OFF_RATE)
        );
    }

    /**
     * Loans as a share of earning assets: the part of the book that defaults. The securities sleeve is marked through
     * equity (resolveSecuritiesBook()), never charged off, so segment charge-off rates, which are rates on loans, reach
     * the book at this share.
     */
    public function getLoanShareOfEarningAssets(): float
    {
        return 1.0 - FinancialConstants::SECURITIES_SHARE_OF_EARNING_ASSETS;
    }

    public function getCreditLossHorizonYears(): float
    {
        return self::CECL_LIFETIME_HORIZON_YEARS;
    }

    /** Deposits are the raw material: whatever is not needed as reserves is lent. */
    public function deploysFundingIntoEarningAssets(): bool
    {
        return true;
    }

    /**
     * Banks earn standard money-market yields only on excess liquidity that isn't actively deployed.
     */
    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        $operatingBase = $this->getOperatingBase($stock);
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - ($operatingBase * self::INTEREST_INCOME_CASH_BUFFER));

        // Interest EARNED here is treasury income only: the spread on the loan book is already inside
        // net_interest_income on the revenue side, so the realized wholesale funding rate is deliberately
        // not read — reading it would price the same book twice.
        return $excessCash * $this->calculateCashYield($macroState);
    }


    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max(
            $operatingBase * self::TARGET_CASH_OPERATING_MULT,
            $currentLiability * self::TARGET_CASH_LIABILITY_MULT,
            $wholesaleDebt * self::TARGET_CASH_WHOLESALE_MULT
        );
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max(
            $operatingBase * self::MIN_CASH_OPERATING_MULT,
            $currentLiability * self::MIN_CASH_LIABILITY_MULT,
            $wholesaleDebt * self::MIN_CASH_WHOLESALE_MULT
        );
    }

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            // Banks operate on fractional reserves. Holding more than HOARDING_THRESHOLD_DEBT_RATIO of their total debt in purely IDLE excess cash is hoarding.
            'is_hoarder'      => $excessCash > ($totalDebt * self::HOARDING_THRESHOLD_DEBT_RATIO),
            'is_mega_hoarder' => $excessCash > ($totalDebt * self::MEGA_HOARDING_THRESHOLD_DEBT_RATIO),
        ];
    }

    /**
     * How far the system's deposit pass-through sits from the level the firm's beta is normalised to. The
     * firm's beta is its structural franchise; the system's is the rate cycle (Drechsler, Savov & Schnabl
     * 2017), and the deposit rate is the product. Without a macro reading the scale is one.
     */
    private function resolveSystemDepositBetaScale(?\App\DTO\MacroStateDTO $macroState): float
    {
        if ($macroState === null) {
            return 1.0;
        }

        return max(0.25, min(3.0, $macroState->systemDepositBetaEma / self::DEPOSIT_BETA_NORMALIZATION_BASELINE));
    }

    /**
     * Resolves effective deposit beta scaled by system rate pass-through, strictly capped at MAX_DEPOSIT_BETA.
     * Prevents deposit rates or APYs from ever exceeding the central bank policy rate.
     */
    private function resolveEffectiveDepositBeta(float $depositBeta, ?\App\DTO\MacroStateDTO $macroState): float
    {
        return min(self::MAX_DEPOSIT_BETA, $depositBeta * $this->resolveSystemDepositBetaScale($macroState));
    }

    public function calculateDepositBeta(float $totalDebt, float $equity, float $equityLimit, float $customerDeposits): float
    {
        $utilization = $equity > 0.0 ? ($totalDebt / ($equity * $equityLimit)) : 1.0;
        $depositRatio = $totalDebt > 0 ? ($customerDeposits / $totalDebt) : 0.0;

        $decayRate = self::DEPOSIT_BETA_BASE_DECAY + (self::DEPOSIT_BETA_RATIO_DECAY_MULT * $depositRatio);

        return min(self::MAX_DEPOSIT_BETA, max(self::MIN_DEPOSIT_BETA, self::DEPOSIT_BETA_AMPLITUDE * exp(-$decayRate * $utilization)));
    }

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        if ($retainedEarningsThisQuarter <= 0.0) {
            return 0.0;
        }

        $spendCap = $isMegaHoarder
            ? $excessCash * self::BUYBACK_MEGA_HOARDER_LIMIT
            : $excessCash * self::BUYBACK_HOARDER_LIMIT;

        return max(0.0, min($spendCap, $retainedEarningsThisQuarter));
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt, ?\App\DTO\MacroStateDTO $macroState = null): InterestExpenseDTO
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $customerDeposits = (float) $stock->getCustomerDeposits();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();

        // Wholesale debt is expensive and relies on fixed/floating market rates
        $wholesaleInterest = ($wholesaleDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($wholesaleDebt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $wholesaleDebt > 0 ? ($wholesaleInterest / $wholesaleDebt) : $currentMarketFixedRate;

        // Deposits are cheap, but the bank must pay an APY to prevent capital flight, and how much of the
        // policy rate the whole system passes through moves with the level of rates.
        $depositBeta = $this->calculateDepositBeta($debt, $totalEquity, $equityLimit, $customerDeposits);
        $depositRate = max(0.001, $policyRate * $this->resolveEffectiveDepositBeta($depositBeta, $macroState));
        $depositInterest = $customerDeposits * $depositRate;

        return new InterestExpenseDTO(interestExpense: $wholesaleInterest + $depositInterest, wholesaleRate: $wholesaleRate);
    }


    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        // Commercial banks do not deploy wholesale debt into physical property or organic capex.
        // Debt is deployed into Earning Assets (loans) or held in Treasury for liquidity.
        return $organicSpend;
    }

    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float
    {
        return max(0.0, $baseCapacity - $excessCash);
    }

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue): float
    {
        return $peFairValue;
    }

    /** Banks trade heavily on book; when earnings collapse, investors look almost entirely to the loan book's liquidation value. */
    protected function getFairValueBookWeight(float $normalizedEps): float
    {
        return $normalizedEps > 0 ? self::FAIR_VALUE_BOOK_WEIGHT_PROFIT : self::FAIR_VALUE_BOOK_WEIGHT_LOSS;
    }

    public function processPassiveLiabilityGrowth(Stock $stock, MacroStateDTO $macroState, array &$state, MathUtility $mathUtility): void
    {
        $currentLiabilities = $state['customerDeposits'];
        if ($currentLiabilities <= 0) return;

        $policyRate = $macroState->policyRateEma;

        $equity = (float) $stock->getTotalEquity();
        $totalDebt = $state['wholesaleDebt'] + $currentLiabilities;
        $equityLimit = \App\Data\Sectors::equityLimit($stock->getIndustry());

        $depositApyBeta = $this->calculateDepositBeta($totalDebt, $equity, $equityLimit, $currentLiabilities);
        $state['bank_apy'] = max(0.001, $policyRate * $this->resolveEffectiveDepositBeta($depositApyBeta, $macroState));

        // Money demand has unit income elasticity (Lucas 2000), so the deposit base grows with trend nominal
        // income: realized inflation plus potential real growth. The rate channel is the migration into money
        // funds as the deposit spread opens, and back out as it closes (Drechsler, Savov & Schnabl 2017); the
        // smoothed share lags the level by about a quarter's move.
        $trendGrowthQuarterly = ($macroState->inflationEma + MacroEngine::TFP_DRIFT + MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE) / FinancialConstants::QUARTERS_PER_YEAR;
        $mmfMigrationQuarterly = ($macroState->moneyMarketFundShare - $macroState->moneyMarketFundShareEma) * self::MMF_MIGRATION_DEPOSIT_DRAG;
        $growthQuarterly = $trendGrowthQuarterly - $mmfMigrationQuarterly + ($mathUtility->generateStandardNormal() * self::LIABILITY_GROWTH_DRIFT_STD);
        $liabilityChange = $currentLiabilities * max(-self::LIABILITY_MAX_CHANGE_LIMIT, min(self::LIABILITY_MAX_CHANGE_LIMIT, $growthQuarterly));

        if (abs($liabilityChange) > 0) {
            $state['treasury'] += $liabilityChange;
            $state['customerDeposits'] += $liabilityChange;

            if ($state['treasury'] < 0.0) {
                $liquidityShortfall = abs($state['treasury']);
                $amtB = number_format($liquidityShortfall / 1_000_000_000, 2);
                $state['events'][] = ['event_type' => ShockEvent::BANK_RUN, 'context' => ['amount' => $amtB], 'shock' => self::EVENT_SHOCK_BANK_RUN];
            }
            $stock->setCustomerDeposits((string) max(0.0, $state['customerDeposits']));
            // News is the departure from trend: a base growing with the economy is not a capture.
            $departureFromTrend = ($liabilityChange / $currentLiabilities) - $trendGrowthQuarterly;
            if ($departureFromTrend < self::LIABILITY_FLIGHT_THRESHOLD) $state['events'][] = ['event_type' => ShockEvent::CUSTOMER_DEPOSIT_FLIGHT, 'context' => ['amount' => number_format(abs($liabilityChange) / 1_000_000_000, 2)], 'shock' => self::EVENT_SHOCK_DEPOSIT_FLIGHT];
            elseif ($departureFromTrend > self::LIABILITY_CAPTURE_THRESHOLD) $state['events'][] = ['event_type' => ShockEvent::CAPTURED_NEW_DEPOSITS, 'context' => ['amount' => number_format($liabilityChange / 1_000_000_000, 2)], 'shock' => self::EVENT_SHOCK_DEPOSIT_CAPTURE];
        }
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): DebtExpansionAppetiteDTO
    {
        $depositRatio = $totalDebt > 0.0 ? ($customerDeposits / $totalDebt) : 0.0;

        if ($currentTreasury < $targetOperatingCash && $targetOperatingCash > 0.0) {
            // Urgent liquidity backstop during deposit runoff or cash shortfall
            $shortfallRatio = ($targetOperatingCash - $currentTreasury) / $targetOperatingCash;
            $probability = self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT) + (self::WHOLESALE_URGENCY_PROB_BOOST * $shortfallRatio);
            $aggressiveness = self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier) + (self::WHOLESALE_URGENCY_AGGR_BOOST * $shortfallRatio);

            return new DebtExpansionAppetiteDTO(probability: min(self::DEBT_EXPANSION_PROB_MAX, max(self::DEBT_EXPANSION_PROB_MIN, $probability)), aggressiveness: min(self::DEBT_EXPANSION_AGGR_MAX, max(self::DEBT_EXPANSION_AGGR_MIN, $aggressiveness)));
        }

        // Deposit Throttle: When liquid treasury is sufficient and the bank is well-funded by customer deposits,
        // wholesale debt borrowing is throttled down to zero to prevent balance sheet inflation.
        if ($depositRatio >= self::DEPOSIT_THROTTLE_UPPER_BOUND) {
            return new DebtExpansionAppetiteDTO(probability: 0.0, aggressiveness: 0.0);
        }

        // For banks with lower deposit coverage, scale borrowing capacity smoothly between UPPER and LOWER bounds
        $baseProb = self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT);
        $baseAggr = self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier);

        if ($depositRatio > self::DEPOSIT_THROTTLE_LOWER_BOUND) {
            $depositFactor = 1.0 - (($depositRatio - self::DEPOSIT_THROTTLE_LOWER_BOUND) / (self::DEPOSIT_THROTTLE_UPPER_BOUND - self::DEPOSIT_THROTTLE_LOWER_BOUND));
            $throttleMultiplier = max(self::DEPOSIT_THROTTLE_FLOOR, $depositFactor);
            $baseProb *= $throttleMultiplier;
            $baseAggr *= $throttleMultiplier;
        }

        return new DebtExpansionAppetiteDTO(probability: min(self::DEBT_EXPANSION_PROB_MAX, max(self::DEBT_EXPANSION_PROB_MIN, $baseProb)), aggressiveness: min(self::DEBT_EXPANSION_AGGR_MAX, max(self::DEBT_EXPANSION_AGGR_MIN, $baseAggr)));
    }

    /**
     * The investment securities portfolio alone, not the whole earning-asset book.
     *
     * This is the line where the two halves of a bank's rate risk divide, and getting it wrong doubles the
     * charge. ASC 320 remarks SECURITIES through equity; ASC 310 carries LOANS at amortized cost and never
     * marks them, which is why a loan book's rate exposure surfaces as compressed margin rather than as a
     * writedown — and the NIM squeeze above is already charging exactly that. Marking the loans here as
     * well would bill the same duration gap twice, once as a stock and once as a flow.
     *
     * Reserves at the central bank are excluded for the same reason: cash carries no duration, and marking
     * it would price the one asset a bank holds precisely because it does not move.
     */
    public function resolveSecuritiesBook(Stock $stock, ?float $currentTreasury = null): float
    {
        return max(0.0, $this->resolveEarningAssets($stock, $currentTreasury))
            * (1.0 - $this->getLoanShareOfEarningAssets());
    }

    /**
     * The same natural hedge the NIM squeeze credits, read the same way.
     *
     * Floating-rate assets reprice, and the model's existing view is that they offset the gap at
     * FLOATING_HEDGE_EFFICIENCY rather than one-for-one. Marking the book against an unhedged duration
     * while charging the margin against a hedged one would have the two halves of the same gap disagree.
     */
    public function getSecuritiesFloatingShare(Stock $stock): float
    {
        return max(0.0, min(1.0, (float) $stock->getFloatingDebtRatio() * self::FLOATING_HEDGE_EFFICIENCY));
    }

    /**
     * The same asset-side duration the NIM squeeze is struck against, resolved late so a subclass with a
     * longer book (a thirty-year mortgage lender) marks its own. The squeeze itself still reads self:: and
     * so still uses the deposit bank's figure — deliberately, because re-pointing it would move a margin
     * calibration that has nothing to do with this mark.
     */
    public function getDefaultSecuritiesDuration(): float
    {
        return static::ASSET_DURATION_YEARS;
    }

    public function getLeverageTarget(float $targetDebtTolerance): float
    {
        return $targetDebtTolerance * self::UNDER_LEVERAGED_TOLERANCE;
    }

    /**
     * Calculates Risk-Weighted Assets (RWA) under the Basel III Standardized Approach. Goodwill is deducted
     * from CET1 and so carries no risk weight: the funding proxy is struck on tangible equity.
     */
    public function calculateRiskWeightedAssets(Stock $stock, ?float $currentTreasury = null): float
    {
        $treasury = $currentTreasury ?? (float) $stock->getCorporateTreasury();
        $earningAssets = $stock->hasEarningAssetLedger()
            ? $stock->getNetEarningAssets()
            : max(0.0, $stock->getTangibleEquity() + (float) $stock->getTotalDebt() - $treasury);

        return ($earningAssets * $this->calculateRiskWeightDensity($stock)) + ($treasury * self::BASEL_RISK_WEIGHT_TREASURY);
    }

    /**
     * Risk-weighted assets per unit of earning assets, from the lender's own book: mortgages at half weight, other
     * loans at full weight, the securities sleeve at the agency weight. A mortgage lender needs less capital per
     * dollar lent than a business lender, as the standardized approach intends.
     */
    public function calculateRiskWeightDensity(Stock $stock): float
    {
        $mix = $this->resolveLoanBookMix($stock);
        $loanDensity = ($mix['residential'] * self::BASEL_RISK_WEIGHT_RESIDENTIAL_MORTGAGE)
            + (($mix['consumer'] + $mix['commercial_real_estate'] + $mix['business']) * self::BASEL_RISK_WEIGHT_LOANS);
        $loanShare = $this->getLoanShareOfEarningAssets();

        return ($loanShare * $loanDensity) + ((1.0 - $loanShare) * self::BASEL_RISK_WEIGHT_SECURITIES);
    }

    /**
     * Calculates Common Equity Tier 1 (CET1) capital ratio dynamically from balance sheet equity and RWA.
     */
    public function calculateCet1Ratio(Stock $stock, ?float $currentTreasury = null): float
    {
        $rwa = $this->calculateRiskWeightedAssets($stock, $currentTreasury);
        if ($rwa <= 0.0) {
            return 1.0;
        }

        // Regulatory capital, not book equity. A bank that elects the Basel III AOCI filter adds the
        // available-for-sale mark back before computing the ratio, which is how an institution can report
        // an intact CET1 through a selloff that has already taken its economic capital. A bank that does
        // not elect the filter takes the hit in the quarter the curve moves.
        $regulatoryEquity = $stock->getRegulatoryEquity();
        if ($regulatoryEquity <= 0.0) {
            return 0.0;
        }

        return $regulatoryEquity / $rwa;
    }

    /**
     * The payout stop: no dividend or buyback while CET1 sits below the requirement plus the countercyclical buffer, as
     * Basel III's maximum distributable amount bars them below the combined buffer.
     */
    public function getRegulatoryDividendCap(Stock $stock, float $currentTreasury, ?\App\DTO\MacroStateDTO $macroState = null): ?float
    {
        $cet1Ratio = $this->calculateCet1Ratio($stock, $currentTreasury);
        $ccyb = $macroState !== null ? $macroState->countercyclicalBufferRateEma : 0.0;
        $requiredCet1 = $this->capitalRequirement($macroState) + $ccyb;

        if ($cet1Ratio < $requiredCet1) {
            return 0.0;
        }

        return 1.0;
    }

    /**
     * The CET1 requirement this lender holds, the countercyclical buffer aside: the District's in force for a regulated
     * bank, Basel III's minimum and conservation buffer for one outside it.
     */
    public function capitalRequirement(?\App\DTO\MacroStateDTO $macroState): float
    {
        if (!static::PRUDENTIALLY_REGULATED) {
            return self::BASEL_CCB_CET1_RATIO;
        }

        return $macroState->bankCapitalRequirement ?? FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT;
    }

    /**
     * A regulated bank's capital target moves with the requirement point for point on its risk-weighted assets, so it
     * keeps the buffer over the requirement it was built with: after a 1pp rise UK banks' capital ratios had risen 0.41pp
     * a year on and 0.95pp three years on (Bridges et al. 2014, BoE WP 486, Table C), the target's partial adjustment
     * doing the rest.
     */
    public function getTargetCapitalRatio(Stock $stock, ?\App\DTO\MacroStateDTO $macroState = null): ?float
    {
        $target = parent::getTargetCapitalRatio($stock, $macroState);
        $assets = $stock->getTotalAssets();
        if ($target === null || !static::PRUDENTIALLY_REGULATED || $macroState === null || $assets <= 0.0) {
            return $target;
        }

        return $target + (($this->capitalRequirement($macroState) - FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT) * $this->calculateRiskWeightedAssets($stock) / $assets);
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'commercial_property_index_ema',
            'corporate_default_rate_ema',
            'credit_to_gdp_gap_ema',
            'housing_starts_index_ema',
            'inflation_ema',
            'interbank_liquidity_spread_ema',
            'macro_credit_spread_ema',
            'money_market_fund_share',
            'money_market_fund_share_ema',
            'money_supply_growth_ema',
            'output_gap_ema',
            'output_gap_lag_9m',
            'policy_rate_ema',
            'recession_probability_ema',
            'residential_property_index_ema',
            'retail_default_rate_ema',
            'sloos_tightening_index_ema',
            'system_deposit_beta_ema',
            'yield_10y_ema',
            'yield_2y_ema',
        ];
    }
}
