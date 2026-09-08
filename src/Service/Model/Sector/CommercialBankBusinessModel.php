<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
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
    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Branch and back-office payroll is the largest non-interest expense of a bank. */
    public const FIXED_COST_LABOR_SHARE = 0.60;

    // --- Model Thresholds ---
    /** Minimum Interest Coverage Ratio (ICR) required before distress. */
    public const THRESHOLD_MIN_ICR = 1.05;
    /** Equity multiplier where bankruptcy risk becomes critical. */
    public const THRESHOLD_BANKRUPT_EQUITY = 2.0;
    /** Equity multiplier indicating severe financial distress. */
    public const THRESHOLD_DISTRESS_EQUITY = 4.0;
    /** Equity multiplier serving as an early warning indicator. */
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

    // --- ROE & Target Metrics ---
    /** Weight given to historical baseline ROE when blending with TTM ROE. */
    public const BASELINE_ROE_WEIGHT = 0.50;
    /** Weight given to TTM ROE when blending with historical baseline ROE. */
    public const TTM_ROE_WEIGHT      = 0.50;

    // --- Dual-Stream Banking Architecture ---
    /** Baseline fraction of bank revenue derived from Net Interest Income (NII). */
    public const NII_REVENUE_WEIGHT      = 0.75;
    /** Baseline fraction of bank revenue derived from Non-Interest / Fee Income (Custodial, Wealth, Payments). */
    public const FEE_REVENUE_WEIGHT      = 0.25;

    // --- Revenue & Shock Physics ---
    /** Baseline volatility multiplier for loan origination and fee revenue shocks. */
    public const REVENUE_VARIANCE_SCALAR = 0.15;
    /** LGD (Loss-Given-Default) multiplier: collateralized loans suffer lower realized losses than unsecured credit. */
    public const MACRO_DEFAULT_LGD_DRAG  = 0.040;
    /** Maximum quarterly reserve release clamp (4% of revenue — avoids unlimited reversal). */
    public const MAX_PROVISION_REVERSAL  = 0.04;

    // --- Basel III Vasicek ASRF Credit Model ---
    /** Long-run average (through-the-cycle) annual default probability for prime bank loan portfolios (~1.5%). */
    public const LRA_DEFAULT_RATE              = 0.015;
    /** Asset correlation factor under Basel II/III internal ratings-based approach (IRB) for corporate/commercial exposures. */
    public const ASSET_CORRELATION_RHO         = 0.15;
    /** Baseline Loss Given Default (LGD) for senior secured / collateralized bank credit facilities. */
    public const LGD_BASELINE                  = 0.45;

    // --- CECL Forward Provisioning (Credit Spread Channel) ---
    /** Baseline investment-grade corporate credit spread (~200bps). Widening above this triggers proactive reserve builds. */
    public const CECL_BASELINE_CREDIT_SPREAD   = 0.020;
    /** Variable cost add-on per unit of spread widening above baseline. +100bps widening = +8% cost add-on. */
    public const CECL_SPREAD_SENSITIVITY       = 0.80;
    /** Baseline 12-month forward recession probability (~15%). Increases above this trigger CECL lifetime reserve builds. */
    public const CECL_BASELINE_RECESSION_PROB  = 0.15;
    /** Sensitivity of CECL lifetime loss provisioning to 12-month forward recession probability. */
    public const CECL_RECESSION_PROB_SENSITIVITY = 0.12;

    // --- C&I Corporate Default & SLOOS Lending Standards ---
    /** Weight of speculative-grade corporate default rate shift on commercial & industrial loan loss provisions. */
    public const SHOCK_WEIGHT_CORPORATE_DEFAULT   = 0.040;
    /** Sensitivity of NII loan origination volume to net percentage of domestic banks tightening standards (SLOOS). */
    public const SLOOS_NII_ORIGINATION_SENSITIVITY = 0.20;

    // --- Housing Mortgage & M2 Money Supply Transmission ---
    /** Sensitivity of residential purchase and construction mortgage origination volume to housing starts. */
    public const HOUSING_MORTGAGE_ORIGINATION_SENSITIVITY = 0.25;
    /** Sensitivity of commercial bank core deposit expansion and lending capacity to M2 broad money growth. */
    public const M2_DEPOSIT_GROWTH_SENSITIVITY = 0.40;

    // --- Macaulay Duration Gap & IRRBB NIM Physics ---
    /** Weighted average Macaulay duration of bank loan and mortgage assets in years. */
    public const ASSET_DURATION_YEARS          = 4.5;
    /** Weighted average Macaulay duration of customer deposit and wholesale liabilities in years. */
    public const LIABILITY_DURATION_YEARS      = 1.5;
    /** Floating-rate asset/liability natural hedge effectiveness dampening duration mismatch exposure. */
    public const FLOATING_HEDGE_EFFICIENCY     = 0.50;
    /** Break-even NIM floor (~50bps). Steep curve = profit; flat or inverted curve = squeeze. */
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
    /** Market-average beta baseline: normalizes competitive advantage to 1.0 at the sector mean. */
    public const DEPOSIT_BETA_NORMALIZATION_BASELINE = 0.20;

    // --- Hoarding & Deposit Flight ---
    /** Fraction of total debt held as idle excess cash before the bank is flagged as a hoarder. */
    public const HOARDING_THRESHOLD_DEBT_RATIO      = 0.12;
    /** Higher idle cash fraction that triggers more aggressive capital return pressure. */
    public const MEGA_HOARDING_THRESHOLD_DEBT_RATIO = 0.18;
    /** Policy rate gap above which depositors flee to money-market funds (calibrated to 2022-23 cycle). */
    public const YIELD_FLIGHT_POLICY_RATE_OFFSET    = 0.01;

    // --- Bank Valuation Weights ---
    /** Weight given to Dividend Discount Model yield support when blending bank fair value. */
    public const FAIR_VALUE_DDM_WEIGHT = 0.15;
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
    /** Structural baseline of wholesale debt banks target. */
    public const WHOLESALE_TARGET_RATIO = 0.10;
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
    /** Minimum fraction of debt issued allocated to organic capex. */
    public const ORGANIC_CAPEX_DEBT_MULT = 0.95;
    /** Minimum ratio of target leverage before the bank is considered under-leveraged and triggers aggressive buybacks to defend ROE. */
    public const UNDER_LEVERAGED_TOLERANCE = 0.35;

    // --- Macro & Shock Thresholds ---
    /** Sensitivity of bank demand to macroeconomic output gap (lower than physical goods). */
    public const MACRO_DEMAND_BETA_SENSITIVITY = 0.50;
    /** Pricing power multiplier for banks, passing structural yields to expectations. */
    public const MACRO_PRICING_POWER_MULT = 1.0;
    /** Persistence (AR1) parameter for Net Interest Income (NII) Z-score drift. */
    public const STREAM_Z_PERSISTENCE_NII = 0.35;
    /** Persistence (AR1) parameter for Fee Income and Default Z-score drift. */
    public const STREAM_Z_PERSISTENCE_FEE = 0.25;
    /** Normalization baseline for indices like sentiment and real estate property. */
    public const INDEX_NORMALIZATION_BASE = 100.0;
    /** Impact multiplier of commercial property declines on bank default drag. */
    public const SHOCK_WEIGHT_CRE_DECLINE = 0.05;
    /** Impact multiplier of residential property declines on bank default drag. */
    public const SHOCK_WEIGHT_RESIDENTIAL_DECLINE = 0.05;
    /** Impact multiplier of retail default rate increases on bank default drag. */
    public const SHOCK_WEIGHT_RETAIL_DEFAULT = 0.05;
    /** Minimum duration gap multiplier acting as a hedge floor. */
    public const HEDGE_FLOOR_MULTIPLIER = 0.10;
    /** Weight for current quarter when calculating TTM ROE. */
    public const TTM_CURRENT_QUARTER_WEIGHT = 0.25;
    /** Weight for historical TTM ROE when updating with current quarter. */
    public const TTM_HISTORICAL_WEIGHT = 0.75;
    /** Annualization multiplier for quarterly returns. */
    public const ANNUALIZATION_FACTOR = 4.0;
    /** Maximum clamped ROE reported to the stock. */
    public const ROE_CLAMP_MAX = 1.0;
    /** Minimum clamped ROE reported to the stock. */
    public const ROE_CLAMP_MIN = -0.50;
    /** Maximum probability cap when issuing debt to close wholesale gap. */
    public const WHOLESALE_GAP_PROB_CAP = 0.90;
    /** Multiplier applied to wholesale gap ratio to boost expansion probability. */
    public const WHOLESALE_GAP_PROB_BOOST_MULT = 0.30;
    /** Maximum aggressiveness cap when issuing debt to close wholesale gap. */
    public const WHOLESALE_GAP_AGGR_CAP = 0.35;
    /** Multiplier applied to wholesale gap ratio to boost expansion aggressiveness. */
    public const WHOLESALE_GAP_AGGR_BOOST_MULT = 0.15;
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
    /** Z-score threshold for elevated defaults. */
    public const SECTOR_SHOCK_ELEVATED_DEFAULT_Z = -1.5;
    /** Z-score threshold for massive defaults. */
    public const SECTOR_SHOCK_MASSIVE_DEFAULT_Z = -2.0;
    /** Z-score threshold for reserve releases. */
    public const SECTOR_SHOCK_RESERVE_RELEASE_Z = 2.0;

    // --- Basel III Capital Adequacy & CCB ---
    /** Risk weight for risk-free cash and central bank treasury reserves under Basel III Standardized Approach. */
    public const BASEL_RISK_WEIGHT_TREASURY = 0.0;
    /** Risk weight for standard commercial loans and earning assets under Basel III Standardized Approach. */
    public const BASEL_RISK_WEIGHT_EARNING_ASSETS = 1.0;
    /** Basel III statutory minimum Common Equity Tier 1 (CET1) ratio before insolvency and regulatory seizure. */
    public const BASEL_MIN_CET1_RATIO = 0.040;
    /** Basel III Capital Conservation Buffer (CCB) target CET1 ratio below which dividends and buybacks are prohibited. */
    public const BASEL_CCB_CET1_RATIO = 0.065;

    // --- Passive Liability Growth ---
    /** Minimum sensitivity bound for liability growth relative to stock beta. */
    public const LIABILITY_BETA_SENSITIVITY_MIN = 0.8;
    /** Maximum sensitivity bound for liability growth relative to stock beta. */
    public const LIABILITY_BETA_SENSITIVITY_MAX = 1.2;
    /** Standard deviation of idiosyncratic drift applied to passive liability growth. */
    public const LIABILITY_GROWTH_DRIFT_STD = 0.005;
    /** Sentiment shock magnitude applied during a severe bank run event. */
    public const EVENT_SHOCK_BANK_RUN = -5.0;
    /** Sentiment shock magnitude applied when significant customer deposits flee. */
    public const EVENT_SHOCK_DEPOSIT_FLIGHT = -2.0;
    /** Sentiment shock magnitude applied when new deposits are heavily captured. */
    public const EVENT_SHOCK_DEPOSIT_CAPTURE = 0.5;

    /** Base real GDP growth for liability expansion. */
    public const LIABILITY_BASE_GDP_GROWTH = 0.02;
    /** Positive output gap multiplier for liability growth. */
    public const LIABILITY_GDP_POSITIVE_GAP_MULT = 0.5;
    /** Negative output gap multiplier for liability growth. */
    public const LIABILITY_GDP_NEGATIVE_GAP_MULT = 2.0;
    /** Absolute limit on quarterly liability change fraction. */
    public const LIABILITY_MAX_CHANGE_LIMIT = 0.15;
    /** Change fraction threshold to trigger deposit flight event. */
    public const LIABILITY_FLIGHT_THRESHOLD = -0.005;
    /** Change fraction threshold to trigger captured new deposits event. */
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
        $equity = (float) $stock->getTotalEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();

        $effectiveEquity = max(1.0, $equity);

        // Earning Assets represent the physical capital deployed into loans.
        // It is Equity + Total Debt, minus cash sitting idle in the Treasury.
        $earningAssets = max($effectiveEquity, $effectiveEquity + $totalDebt - $treasury);
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());

        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * self::BASELINE_ROE_WEIGHT) + ($ttmRoe * self::TTM_ROE_WEIGHT);
        }

        $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, $effectiveEquity, $macroState);
        $waccBase = $macroState->policyRate + $macroState->equityRiskPremium;
        $baselineRoe = max($waccBase, $baselineRoe - $saturationPenalty);

        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 15.0;

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
        $depositRate = max(0.001, $policyRate * $depositBeta);

        $blendedWholesaleRate = ($floatingRatio * ($policyRate + $macroState->interbankLiquiditySpreadEma)) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;

        // --- THE CLEAR BALANCE SHEET MATH ---
        // We derive the structural asset yield using the bank's ACTUAL deployed leverage (capped at regulatory limits).
        // This prevents the "Phantom Debt" exploit, where banks operating below max leverage
        // pocket the theoretical interest expense as pure Net Income, causing ROE to hyper-inflate.
        // Crucially, this cap ONLY applies to wholesale debt. Customer deposits are market-driven and unconstrained.

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

        // At optimal leverage, there is no idle cash generating a treasury yield, only fully deployed earning assets
        $optimalEbit = $optimalEbt + $optimalInterestExpense;
        $structuralAssetYield = $optimalEbit / max(1.0, $optimalEarningAssets);

        // Apply the mathematically pure structural yield to the ACTUAL physical loan book
        $targetEbit = $earningAssets * $structuralAssetYield;
        // ------------------------------------

        // Banks and Credit Services will never shrink their core loan book to zero just because cash yields are high.
        // We floor the target EBIT based on their core liabilities to guarantee they maintain baseline lending operations.
        $coreLiabilities = $totalDebt;
        $minLendingEbit = $coreLiabilities * self::MIN_CORE_LENDING_YIELD;

        $targetEbit = max($minLendingEbit, $targetEbit);

        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        // We derive revenue from target EBIT to hit ROE expectations,
        // but we MUST cap the gross yield. If margins compress, uncapped
        // reverse-engineering will cause the bank's loan yields to hyperinflate!
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
        $outputGap = $macroState->outputGapEma;
        $beta = (float) $stock->getBeta();

        return [
            'macro_demand_shift' => $outputGap * $beta * self::MACRO_DEMAND_BETA_SENSITIVITY, // Less demand destruction than physical goods
            'pricing_power_multiplier' => self::MACRO_PRICING_POWER_MULT, // Passes structural yield adjustments to the expectation engine
        ];
    }

    /**
     * Idiosyncratic shock applied directly to loan origination volume and fee revenue.
     * Introduces massive Loss Provision write-offs during economic downturns.
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
        $streams  = $this->createStreamContext($momentum, $mathUtility);

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
        $defaultZ = $streams->generateExogenousZ('default', self::STREAM_Z_PERSISTENCE_FEE); // Idiosyncratic credit default

        $outputGap = $macroState->outputGapEma;

        // Blended dual-stream revenue (NII vs. Non-Interest Fee Income)
        // Fed SLOOS, Housing Starts, and M2 channels:
        // Credit standards tightening (SLOOS > 0) dampens loan origination volume; easing (SLOOS < 0) expands it.
        // Residential housing starts drive mortgage purchase origination, and broad money (M2) growth expands deposit lending capacity.
        $sloosOriginationDrag = $macroState->sloosTighteningIndexEma * self::SLOOS_NII_ORIGINATION_SENSITIVITY;
        $housingMortgageBoost = MathUtility::calculateHousingStartsShift($macroState->housingStartsIndexEma, sensitivity: self::HOUSING_MORTGAGE_ORIGINATION_SENSITIVITY);
        $m2LiquidityBoost = MathUtility::calculateBroadMoneyLiquidityShift($macroState->moneySupplyGrowthEma, sensitivity: self::M2_DEPOSIT_GROWTH_SENSITIVITY);

        $niiRevenue = max(0.0, $expectedRevenue * $niiWeight
            * (1.0 + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) - $sloosOriginationDrag + $housingMortgageBoost + $m2LiquidityBoost));
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
        $equity = (float) $stock->getTotalEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        $effectiveEquity = max(1.0, $equity);
        $earningAssets = max($effectiveEquity, $effectiveEquity + $totalDebt - $treasury);

        // Basel II/III Vasicek ASRF Credit Risk Physics:
        // Expected loss on the loan portfolio under macroeconomic credit shock $defaultZ.
        $baselineEl = $mathUtility->calculateVasicekExpectedLoss(0.0, self::LRA_DEFAULT_RATE, self::ASSET_CORRELATION_RHO, self::LGD_BASELINE);
        $conditionalEl = $mathUtility->calculateVasicekExpectedLoss($defaultZ, self::LRA_DEFAULT_RATE, self::ASSET_CORRELATION_RHO, self::LGD_BASELINE);
        $annualLossDelta = $conditionalEl - $baselineEl;

        // Convert annual loan loss rate delta to quarterly dollar credit provision shock
        $quarterlyDollarLoss = ($annualLossDelta / self::ANNUALIZATION_FACTOR) * $earningAssets;
        $provisionCostAddon = $quarterlyDollarLoss / max(1.0, $actualRevenue);

        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / self::INDEX_NORMALIZATION_BASE;
        $retailDefaultShift = max(0.0, ($macroState->retailDefaultRateEma - MacroEngine::RETAIL_DEFAULT_BASELINE) / MacroEngine::RETAIL_DEFAULT_BASELINE);
        $corporateDefaultShift = max(0.0, ($macroState->corporateDefaultRateEma - MacroEngine::CORPORATE_DEFAULT_BASELINE) / MacroEngine::CORPORATE_DEFAULT_BASELINE);

        $creShift = ($macroState->commercialPropertyIndexEma - self::INDEX_NORMALIZATION_BASE) / self::INDEX_NORMALIZATION_BASE;
        $residentialShift = ($macroState->residentialPropertyIndexEma - self::INDEX_NORMALIZATION_BASE) / self::INDEX_NORMALIZATION_BASE;
        $propertyDrag = ($creShift < 0.0 ? abs($creShift) * self::SHOCK_WEIGHT_CRE_DECLINE : 0.0) + ($residentialShift < 0.0 ? abs($residentialShift) * self::SHOCK_WEIGHT_RESIDENTIAL_DECLINE : 0.0);

        $macroDefaultDrag = ($sentimentShift < 0.0 ? abs($sentimentShift) * self::MACRO_DEFAULT_LGD_DRAG : 0.0)
            + ($retailDefaultShift * self::SHOCK_WEIGHT_RETAIL_DEFAULT)
            + ($corporateDefaultShift * self::SHOCK_WEIGHT_CORPORATE_DEFAULT)
            + $propertyDrag;

        // Clamp reserve release to MAX_PROVISION_REVERSAL to avoid unbounded write-backs
        $lossProvisionShock = max(-self::MAX_PROVISION_REVERSAL, $provisionCostAddon) + $macroDefaultDrag;

        // CECL Forward Provisioning (Credit Spread & Recession Forecast Channels):
        // Under CECL accounting, banks must provision against EXPECTED future lifetime losses.
        // When corporate credit spreads widen or forward recession probability rises, banks build reserves proactively.
        $creditSpread = $macroState->macroCreditSpreadEma;
        $spreadCeclDrag = max(0.0, ($creditSpread - self::CECL_BASELINE_CREDIT_SPREAD) * self::CECL_SPREAD_SENSITIVITY);
        $recessionCeclDrag = max(0.0, ($macroState->recessionProbabilityEma - self::CECL_BASELINE_RECESSION_PROB) * self::CECL_RECESSION_PROB_SENSITIVITY);
        $ceclDrag = $spreadCeclDrag + $recessionCeclDrag;

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
        // Crucially, NIM squeeze and CECL provision charges apply proportionally to the NII revenue share ($niiWeight),
        // leaving Non-Interest custodial / wealth / transaction fee income completely insulated.
        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $niiCostAddon = ($lossProvisionShock + $nimSqueeze + $ceclDrag) * $niiWeight;
        $rawMargin = $realizedVariableMargin + $niiCostAddon;
        $clampedMargin = $this->clampMargin($rawMargin, $minVariableMargin);

        $cet1Ratio = $this->calculateCet1Ratio($stock);

        $eventType = null;
        if ($cet1Ratio < self::BASEL_MIN_CET1_RATIO) {
            $eventType = ShockEvent::BANK_SEIZURE;
        } elseif ($defaultZ < self::SECTOR_SHOCK_MASSIVE_DEFAULT_Z) {
            $eventType = ShockEvent::MASSIVE_CREDIT_PROVISION;
        } elseif ($defaultZ < self::SECTOR_SHOCK_ELEVATED_DEFAULT_Z) {
            $eventType = ShockEvent::ELEVATED_LOAN_DEFAULTS;
        } elseif ($defaultZ > self::SECTOR_SHOCK_RESERVE_RELEASE_Z) {
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
        );
    }



    /**
     * Banks earn standard money-market yields only on excess liquidity that isn't actively deployed.
     */
    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        $operatingBase = $this->getOperatingBase($stock);
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - ($operatingBase * self::INTEREST_INCOME_CASH_BUFFER));

        $policyRate = $macroState->policyRateEma;

        return $excessCash * $this->calculateCashYield($macroState);
    }

    /**
     * Financial companies are evaluated strictly on Return on Equity (ROE), not ROIC.
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?\App\DTO\MacroStateDTO $macroState = null, float $depreciation = 0.0): float
    {
        $kappa = $this->getReversionSpeed();
        $moatSpread = $this->getMoatSpread();

        $equity = (float) $stock->getTotalEquity();
        $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) * self::ANNUALIZATION_FACTOR : 0.0;

        $stock->setCurrentRoe((string) max(self::ROE_CLAMP_MIN, min(self::ROE_CLAMP_MAX, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoeTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * self::TTM_CURRENT_QUARTER_WEIGHT) + ($oldTtm * self::TTM_HISTORICAL_WEIGHT);
        // Scale kappa so the blended target in getTargetMetrics moves at exactly $kappa
        $scaledKappa = $kappa / self::TTM_ROE_WEIGHT;

        $saturationPenalty = 0.0;
        if ($macroState !== null) {
            $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, max(1.0, $equity), $macroState);
        }

        $effectiveMoat = max(0.0, $moatSpread - $saturationPenalty);
        $newTtm += MathUtility::getInstance()->calculateReversionPull($newTtm, $costOfEquity, $scaledKappa, $effectiveMoat);
        $stock->setRoeTtm((string) max(self::ROE_CLAMP_MIN, min(self::ROE_CLAMP_MAX, $newTtm)));

        return max(self::ROE_CLAMP_MIN, min(self::ROE_CLAMP_MAX, $truePostTaxReturn));
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

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $customerDeposits = (float) $stock->getCustomerDeposits();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();

        // Wholesale debt is expensive and relies on fixed/floating market rates
        $wholesaleInterest = ($wholesaleDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($wholesaleDebt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $wholesaleDebt > 0 ? ($wholesaleInterest / $wholesaleDebt) : $currentMarketFixedRate;

        // Deposits are cheap, but the bank must pay an APY to prevent capital flight.
        $depositBeta = $this->calculateDepositBeta($debt, $totalEquity, $equityLimit, $customerDeposits);
        $depositRate = max(0.001, $policyRate * $depositBeta);
        $depositInterest = $customerDeposits * $depositRate;

        return ['interest_expense' => $wholesaleInterest + $depositInterest, 'wholesale_rate' => $wholesaleRate];
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

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        return $peFairValue;
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        // Balance Sheet Heavy: Banks trade heavily on their Book Value (Equity).
        // If earnings collapse, investors focus almost entirely (80% weight) on the liquidation value of the loan book.
        $bookWeight = $normalizedEps > 0 ? self::FAIR_VALUE_BOOK_WEIGHT_PROFIT : self::FAIR_VALUE_BOOK_WEIGHT_LOSS;
        $earningsWeight = 1.0 - $bookWeight;
        $baseConsensus = ($earningsValue * $earningsWeight) + ($pbFairValue * $bookWeight);
        return $dividendSupportValue > 0.0
            ? ($baseConsensus * (1.0 - self::FAIR_VALUE_DDM_WEIGHT)) + ($dividendSupportValue * self::FAIR_VALUE_DDM_WEIGHT)
            : $baseConsensus;
    }

    public function processPassiveLiabilityGrowth(Stock $stock, MacroStateDTO $macroState, array &$state, MathUtility $mathUtility): void
    {
        $currentLiabilities = $state['customerDeposits'];
        if ($currentLiabilities <= 0) return;

        $inflation = $macroState->inflationEma;
        $outputGap = $macroState->outputGapEma;
        $policyRate = $macroState->policyRateEma;

        $equity = (float) $stock->getTotalEquity();
        $totalDebt = $state['wholesaleDebt'] + $currentLiabilities;
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 15.0;

        $realGdpGrowth = self::LIABILITY_BASE_GDP_GROWTH + ($outputGap > 0.0 ? $outputGap * self::LIABILITY_GDP_POSITIVE_GAP_MULT : $outputGap * self::LIABILITY_GDP_NEGATIVE_GAP_MULT);
        $depositApyBeta = $this->calculateDepositBeta($totalDebt, $equity, $equityLimit, $currentLiabilities);
        $state['bank_apy'] = max(0.001, $policyRate * $depositApyBeta);

        // Yield Flight Penalty: If Money Market funds yield much higher than the bank's APY, depositors flee.
        $yieldFlightPenalty = max(0.0, max(0.0, $policyRate - self::YIELD_FLIGHT_POLICY_RATE_OFFSET) - $state['bank_apy']) * 1.0;
        $systemicGrowthQuarterly = ($inflation + $realGdpGrowth - $yieldFlightPenalty) / self::ANNUALIZATION_FACTOR;

        $betaSensitivity = max(self::LIABILITY_BETA_SENSITIVITY_MIN, min(self::LIABILITY_BETA_SENSITIVITY_MAX, abs((float) $stock->getBeta())));

        // Competitive advantage relative to market-average deposit beta.
        // A bank paying above the normalization baseline retains and attracts more deposits.
        $competitiveAdvantage = $depositApyBeta / self::DEPOSIT_BETA_NORMALIZATION_BASELINE;

        $baseGrowth = $systemicGrowthQuarterly > 0 ? $systemicGrowthQuarterly * $betaSensitivity * $competitiveAdvantage : $systemicGrowthQuarterly * $betaSensitivity / max(0.1, $competitiveAdvantage);
        $liabilityChange = $currentLiabilities * max(-self::LIABILITY_MAX_CHANGE_LIMIT, min(self::LIABILITY_MAX_CHANGE_LIMIT, $baseGrowth + ($mathUtility->generateStandardNormal() * self::LIABILITY_GROWTH_DRIFT_STD)));

        if (abs($liabilityChange) > 0) {
            $state['treasury'] += $liabilityChange;
            $state['customerDeposits'] += $liabilityChange;

            if ($state['treasury'] < 0.0) {
                $liquidityShortfall = abs($state['treasury']);
                $amtB = number_format($liquidityShortfall / 1_000_000_000, 2);
                $state['events'][] = ['event_type' => ShockEvent::BANK_RUN, 'context' => ['amount' => $amtB], 'shock' => self::EVENT_SHOCK_BANK_RUN];
            }
            $stock->setCustomerDeposits((string) max(0.0, $state['customerDeposits']));
            if (($liabilityChange / $currentLiabilities) < self::LIABILITY_FLIGHT_THRESHOLD) $state['events'][] = ['event_type' => ShockEvent::CUSTOMER_DEPOSIT_FLIGHT, 'context' => ['amount' => number_format(abs($liabilityChange) / 1_000_000_000, 2)], 'shock' => self::EVENT_SHOCK_DEPOSIT_FLIGHT];
            elseif (($liabilityChange / $currentLiabilities) > self::LIABILITY_CAPTURE_THRESHOLD) $state['events'][] = ['event_type' => ShockEvent::CAPTURED_NEW_DEPOSITS, 'context' => ['amount' => number_format($liabilityChange / 1_000_000_000, 2)], 'shock' => self::EVENT_SHOCK_DEPOSIT_CAPTURE];
        }
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): array
    {
        $depositRatio = $totalDebt > 0.0 ? ($customerDeposits / $totalDebt) : 0.0;

        if ($currentTreasury < $targetOperatingCash && $targetOperatingCash > 0.0) {
            // Urgent liquidity backstop during deposit runoff or cash shortfall
            $shortfallRatio = ($targetOperatingCash - $currentTreasury) / $targetOperatingCash;
            $probability = self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT) + (self::WHOLESALE_URGENCY_PROB_BOOST * $shortfallRatio);
            $aggressiveness = self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier) + (self::WHOLESALE_URGENCY_AGGR_BOOST * $shortfallRatio);

            return [
                'probability' => min(self::DEBT_EXPANSION_PROB_MAX, max(self::DEBT_EXPANSION_PROB_MIN, $probability)),
                'aggressiveness' => min(self::DEBT_EXPANSION_AGGR_MAX, max(self::DEBT_EXPANSION_AGGR_MIN, $aggressiveness)),
            ];
        }

        // Deposit Throttle: When liquid treasury is sufficient and the bank is well-funded by customer deposits,
        // wholesale debt borrowing is throttled down to zero to prevent balance sheet inflation.
        if ($depositRatio >= self::DEPOSIT_THROTTLE_UPPER_BOUND) {
            return [
                'probability' => 0.0,
                'aggressiveness' => 0.0,
            ];
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

        return [
            'probability' => min(self::DEBT_EXPANSION_PROB_MAX, max(self::DEBT_EXPANSION_PROB_MIN, $baseProb)),
            'aggressiveness' => min(self::DEBT_EXPANSION_AGGR_MAX, max(self::DEBT_EXPANSION_AGGR_MIN, $baseAggr)),
        ];
    }

    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        return $currentDebtRatio < ($targetDebtTolerance * self::UNDER_LEVERAGED_TOLERANCE);
    }

    /**
     * Calculates Risk-Weighted Assets (RWA) under the Basel III Standardized Approach.
     */
    public function calculateRiskWeightedAssets(Stock $stock, ?float $currentTreasury = null): float
    {
        $treasury = $currentTreasury ?? (float) $stock->getCorporateTreasury();
        $totalEquity = (float) $stock->getTotalEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $earningAssets = max(0.0, $totalEquity + $totalDebt - $treasury);

        return ($earningAssets * self::BASEL_RISK_WEIGHT_EARNING_ASSETS) + ($treasury * self::BASEL_RISK_WEIGHT_TREASURY);
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

        $totalEquity = (float) $stock->getTotalEquity();
        if ($totalEquity <= 0.0) {
            return 0.0;
        }

        return $totalEquity / $rwa;
    }

    /**
     * Implements Basel III Capital Conservation Buffer (CCB) dividend restrictions.
     */
    public function getRegulatoryDividendCap(Stock $stock, float $currentTreasury): ?float
    {
        $cet1Ratio = $this->calculateCet1Ratio($stock, $currentTreasury);

        if ($cet1Ratio < self::BASEL_CCB_CET1_RATIO) {
            return 0.0;
        }

        return 1.0;
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
            'consumer_sentiment_index_ema',
            'corporate_default_rate_ema',
            'housing_starts_index_ema',
            'inflation_ema',
            'interbank_liquidity_spread_ema',
            'macro_credit_spread_ema',
            'money_supply_growth_ema',
            'output_gap_ema',
            'policy_rate_ema',
            'recession_probability_ema',
            'residential_property_index_ema',
            'retail_default_rate_ema',
            'sloos_tightening_index_ema',
            'yield_10y_ema',
            'yield_2y_ema',
        ];
    }
}
