<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\InterestExpenseDTO;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for Credit Services (Credit Cards, Consumer Finance).
 * 
 * Financial Physics:
 * - Hybrid model relying on interest spreads (like a bank) and transaction volume (like a brokerage).
 * - Revenue directly benefits from inflation because interchange/swipe fees are a percentage of total price.
 * - Highly vulnerable to economic downturns (negative output gap) due to a spike in unsecured loan defaults.
 * - Evaluated on Return on Equity (ROE).
 */
class CreditServicesBusinessModel extends CommercialBankBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Unsecured card balances and swipe volumes are consumer-cyclical. */
    public const OPERATING_CYCLICALITY = 1.20;

        public function getMoatSpread(): float { return 0.005; }
    // --- Dual-Stream Credit Services Architecture ---
    /** Baseline fraction of revenue derived from revolving consumer lending interest. */
    public const LENDING_REVENUE_WEIGHT  = 0.65;
    /** Baseline fraction of revenue derived from payment network interchange / swipe fees. */
    public const NETWORK_REVENUE_WEIGHT  = 0.35;

    // --- ROE & Target Architecture ---
    /** Minimum lending EBIT floor as a fraction of core debt liabilities. */
    public const MIN_LENDING_EBIT_YIELD   = 0.05;

    // --- High-Yield Deposit Funding ---
    /** Maximum allowable deposit beta clamp for high-yield savings accounts. */
    public const MAX_DEPOSIT_BETA_CLAMP   = 0.90;
    /** Minimum allowable deposit beta clamp for high-yield savings accounts. */
    public const MIN_DEPOSIT_BETA_CLAMP   = 0.20;
    /** Supplemental deposit beta spread added to attract high-yield savings funding. */
    public const HIGH_YIELD_BETA_SPREAD   = 0.10;
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

    // --- Hoarding & Deposit Flight ---
    /** Fraction of total debt held as idle excess cash before flagged as a hoarder. */
    public const HOARDING_THRESHOLD_DEBT_RATIO      = 0.12;
    /** Higher idle cash fraction that triggers aggressive capital return. */
    public const MEGA_HOARDING_THRESHOLD_DEBT_RATIO = 0.18;

    // --- APR & Gross Yield Ceiling ---
    /** Absolute minimum APR gross yield ceiling floor. */
    public const MIN_APR_YIELD_FLOOR      = 0.25;
    /** Spread over macro policy rate used to determine floating APR gross yield ceiling. */
    public const POLICY_APR_SPREAD        = 0.35;

    // --- Credit Losses (ASC 326) ---
    /** The book is unsecured consumer credit: no mortgages. */
    public const RESIDENTIAL_MORTGAGE_SHARE = 0.0;
    /** The whole book is consumer: card balances and instalment loans. */
    public const CONSUMER_LOAN_SHARE = 1.0;
    /** No commercial property lending. */
    public const COMMERCIAL_REAL_ESTATE_SHARE = 0.0;
    /** Credit card charge-offs at US commercial banks: 4.69% a year over 1991-2019, peak 10.54% in 2010 (FRED CORCCACBS). */
    public const CONSUMER_CHARGE_OFF_RATE = 0.0469;
    /** Years of expected loss the allowance covers: revolving balances turn over in well under two years. */
    public const CECL_LIFETIME_HORIZON_YEARS = 1.5;
    /** Charge-offs over the through-the-cycle rate above which card defaults are elevated: 1.26x the 1991-2019 mean in 2001-02 (FRED CORCCACBS). */
    public const SECTOR_SHOCK_ELEVATED_LOSS_MULTIPLE = 1.2;
    /** Above which provisions are massive: 2.01x in 2009-10, peaking at 2.25x; card losses swing less than a bank book's. */
    public const SECTOR_SHOCK_MASSIVE_LOSS_MULTIPLE = 1.8;
    /** Below which reserves are released: card losses bottomed at 0.62x in the late 2010s. */
    public const SECTOR_SHOCK_RESERVE_RELEASE_LOSS_MULTIPLE = 0.7;

    // --- Revenue Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in transaction swipe markets. */
    public const REVENUE_VARIANCE_SCALAR   = 0.20;

    public const BASE_COVERAGE_VISIBILITY = 0.50;
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- CECL Forward Reserve (ASC 326 lifetime allowance conditioned on the macro forecast) ---
    /** Baseline investment-grade credit spread (macro through-the-cycle IG); the lifetime loss estimate is struck at 1.0x here. */
    public const CECL_BASELINE_CREDIT_SPREAD = MacroEngine::BASE_CREDIT_SPREAD;
    /** Sector reserve sensitivity to spreads a per-ticker CeclSpreadSensitivity is expressed against (a 2.2 issuer moves 2.2/1.5 = 1.47x the sector). */
    public const CECL_SPREAD_SENSITIVITY     = 1.50;
    /** Lifetime-loss multiplier per unit of IG spread widening: unsecured books reprice faster than collateralized bank loans, +300bps lifts the target ~0.45x. */
    public const CECL_RESERVE_SPREAD_SENSITIVITY = 15.0;
    /** Baseline 12-month forward recession probability; the lifetime loss estimate is struck at 1.0x here. */
    public const CECL_BASELINE_RECESSION_PROB   = 0.15;
    /** Lifetime-loss multiplier per unit of recession probability above baseline: card allowances went ~4.9% -> 7.9% of receivables in 2020 (~0.6x) on a near-certain recession. */
    public const CECL_RESERVE_RECESSION_SENSITIVITY = 0.75;
    /** Sensitivity of revolving credit loan origination volume drag to commercial bank credit tightening (SLOOS). */
    public const SLOOS_LENDING_DRAG_SENSITIVITY = 0.15;
    /** Card balances per unit of the household credit-to-GDP gap: revolving credit rides the same boom. */
    public const CREDIT_GAP_LENDING_SENSITIVITY = 0.50;

    // --- Structural Efficiency Floor ---
    /** Minimum cost-to-revenue ratio: even at perfect NIM, structural fixed costs (personnel, compliance, tech) prevent margin going below 50%. */
    public const MIN_EFFICIENCY_RATIO        = 0.50;

    // --- NIM Squeeze & Yield Curve Inversion ---
    /** Baseline spread buffer before NIM squeeze compression begins. */
    public const NIM_SPREAD_BUFFER          = 0.005;
    /** Linear sensitivity scalar for spread compression when yield curve flattens. */
    public const NIM_LINEAR_SENSITIVITY     = 1.50;
    /** Quadratic coefficient amplifying funding costs during yield curve inversions. */
    public const NIM_QUADRATIC_COEFF        = 0.15;
    /** Sensitivity of unsecured lending funding cost squeeze to interbank liquidity freezes (TED spread). */
    public const TED_SPREAD_NIM_PENALTY     = 1.50;

    /**
     * A card issuer's receivables revolve and reprice at will, so the book carries almost no duration; what
     * it does hold is short-dated liquidity against settlement. Inheriting a deposit bank's four-and-a-half
     * year book would have priced a revolving credit line as though it were a mortgage.
     */
    public function getDefaultSecuritiesDuration(): float
    {
        return 2.0;
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        // Swipe fees perfectly capture nominal inflation dynamically.
        // We strip generic pricing power to prevent double-dipping.
        $physics['pricing_power_multiplier'] = 1.0;
        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        // Resolve company-specific tuned credit services parameters
        $params = $this->resolveModelParameters($stock, [
            ModelParam::LendingRevenueWeight->value  => self::LENDING_REVENUE_WEIGHT,
            ModelParam::NetworkRevenueWeight->value  => self::NETWORK_REVENUE_WEIGHT,
            ModelParam::CeclSpreadSensitivity->value => self::CECL_SPREAD_SENSITIVITY,
        ]);

        $lendingWeight   = $params[ModelParam::LendingRevenueWeight];
        $networkWeight   = $params[ModelParam::NetworkRevenueWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'lending' => $params[ModelParam::LendingRevenueWeight],
            'swipe'   => $params[ModelParam::NetworkRevenueWeight],
        ]);

        $lendingWeight = $activeWeights['lending'];
        $networkWeight = $activeWeights['swipe'];

        // Independent stream Z-scores with AR(1) persistence
        $lendingZ = $streams->generateZ('lending', 0.25); // Revolving credit loan origination volume
        $swipeZ   = $streams->generateZ('swipe', 0.25); // Payment gateway transaction swipe volume

        // Inflation Bonus (Interchange Swipe Fees):
        // Swipe fees (Visa/MC network) are a percentage of transaction value — higher prices = higher revenue.
        $inflation = $macroState->inflationEma;
        $inflationBonus = ($inflation - MacroEngine::TARGET_INFLATION) * $this->getOperatingCyclicality($stock);

        // Blended dual-stream revenue (Lending vs. Payment Network Interchange)
        // Bank credit tightening (SLOOS) gates unsecured credit card line extensions and origination volume
        $sloosLendingDrag = max(0.0, $macroState->sloosTighteningIndexEma) * self::SLOOS_LENDING_DRAG_SENSITIVITY;
        $creditBoomBoost = $macroState->creditToGdpGapEma * self::CREDIT_GAP_LENDING_SENSITIVITY;
        $lendingRevenue = max(0.0, $expectedRevenue * $lendingWeight
            * max(0.0, 1.0 - $sloosLendingDrag + $creditBoomBoost)
            * (1.0 + ($lendingZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR))));
        $networkRevenue = max(0.0, $expectedRevenue * $networkWeight
            * (1.0 + ($swipeZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $inflationBonus));
        // Interchange on a bigger ticket is the same swipe: the inflation bonus is price, not processing volume.
        $priceRevenue = max(0.0, $expectedRevenue * $networkWeight * $inflationBonus);
        
        $streamRevenues = [
            'lending' => $lendingRevenue,
            'swipe'   => $networkRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Charge-offs are the card book's conditional loss at the household default cycle (the bank's segment model
        // with the whole book consumer). They reach EBIT once, through the allowance roll-forward. The forward-looking
        // CECL reserve moves the allowance TARGET through getForwardCreditLossMultiplier(), where the per-ticker
        // CeclSpreadSensitivity sets how far this issuer's reserve travels with spreads.
        $credit = $this->resolveConditionalCreditLossRate($stock, $macroState, $streams, $mathUtility);
        $netChargeOffs = ($credit['loss_rate'] / FinancialConstants::QUARTERS_PER_YEAR) * $this->resolveEarningAssets($stock);
        $lossMultiple = $credit['loss_rate'] / max(1e-9, $this->getThroughTheCycleCreditLossRate($stock));

        // Net Interest Margin (NIM) Squeeze (1.5x more sensitive than banks due to wholesale funding dependency)
        $yield10y = $macroState->yield10yEma;
        $yield2y  = $macroState->yield2yEma;
        $tedSpread = max(0.0, $macroState->interbankLiquiditySpreadEma - MacroEngine::INTERBANK_BASELINE_SPREAD);
        $bankSpread = ($yield10y - $yield2y) - ($tedSpread * self::TED_SPREAD_NIM_PENALTY);

        if ($bankSpread < 0) {
            $nimSqueeze = (self::NIM_SPREAD_BUFFER - $bankSpread)
                + pow(abs($bankSpread) * FinancialConstants::YIELD_CURVE_INVERSION_SENSITIVITY, 2) * self::NIM_QUADRATIC_COEFF;
        } else {
            $nimSqueeze = (self::NIM_SPREAD_BUFFER - $bankSpread) * self::NIM_LINEAR_SENSITIVITY;
        }

        // Structural efficiency floor: Total Operating Costs (Fixed + Variable) / Revenue >= MIN_EFFICIENCY_RATIO.
        // Crucially, the NIM squeeze applies proportionally to the Revolving Lending share ($lendingWeight),
        // leaving Payment Network Swipe Interchange completely insulated.
        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $rawMargin = $realizedVariableMargin + ($nimSqueeze * $lendingWeight);
        $clampedMargin = $this->clampMargin($rawMargin, $minVariableMargin);

        $eventType = null;
        if ($lossMultiple > static::SECTOR_SHOCK_MASSIVE_LOSS_MULTIPLE) {
            $eventType = ShockEvent::MASSIVE_CREDIT_PROVISION;
        } elseif ($lossMultiple > static::SECTOR_SHOCK_ELEVATED_LOSS_MULTIPLE) {
            $eventType = ShockEvent::ELEVATED_LOAN_DEFAULTS;
        } elseif ($lossMultiple < static::SECTOR_SHOCK_RESERVE_RELEASE_LOSS_MULTIPLE) {
            $eventType = ShockEvent::RESERVE_RELEASE;
        }

        $primaryShockZ = $streams->resolveDominantShockZ([$credit['systematic_z'], $lendingZ]);
        // observableShockZ: inflation bonus and swipe volume are visible, lending is partially visible
        $lendingBase = max(1.0, $expectedRevenue * $lendingWeight);
        $lendingShock = ($lendingRevenue - $lendingBase) / $lendingBase;
        $networkBase = max(1.0, $expectedRevenue * $networkWeight);
        $networkShock = ($networkRevenue - $networkBase) / $networkBase;
        $observableShockZ = ($lendingShock * $lendingWeight) + ($networkShock * $networkWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            netChargeOffs: $netChargeOffs,
            priceRevenue: $priceRevenue,
        );
    }



    public function getTargetMetrics(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        // Capital is tangible: goodwill absorbs no loss, so it neither sizes the book nor earns the return target.
        $equity = $stock->getTangibleEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();

        $effectiveEquity = max(1.0, $equity);
        $earningAssets = max($effectiveEquity, $effectiveEquity + $totalDebt - $treasury);
        $baselineRoe = $this->resolveStructuralTargetRoe($stock, $macroState);

        $taxRate = $macroState->corporateTaxRate;
        $policyRate = $macroState->policyRateEma;
        $yield5y = $macroState->yield5yEma;
        $structuralSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        $equityLimit = \App\Data\Sectors::equityLimit($stock->getIndustry());

        $customerDeposits = (float) $stock->getCustomerDeposits();
        $depositRatio = $totalDebt > 0 ? ($customerDeposits / $totalDebt) : 0.0;

        // Credit services require highly competitive APYs on their high-yield savings accounts
        $depositBeta = min(self::MAX_DEPOSIT_BETA_CLAMP, max(self::MIN_DEPOSIT_BETA_CLAMP, $this->calculateDepositBeta($totalDebt, $equity, $equityLimit, $customerDeposits) + self::HIGH_YIELD_BETA_SPREAD));
        $depositRate = max(0.001, $policyRate * $depositBeta);
        $blendedWholesaleRate = ($floatingRatio * $policyRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;

        $actualLeverage = $effectiveEquity > 0 ? ($totalDebt / $effectiveEquity) : 0.0;
        $allowedLeverage = min($actualLeverage, max(1.0, $equityLimit));
        $optimalDebt = $effectiveEquity * $allowedLeverage;

        $optimalWholesaleDebt = $optimalDebt * (1.0 - $depositRatio);
        $optimalDeposits = $optimalDebt * $depositRatio;
        $optimalInterestExpense = ($optimalWholesaleDebt * $blendedWholesaleRate) + ($optimalDeposits * $depositRate);

        $optimalNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalNetIncome / (1.0 - $taxRate);

        $targetCash = $this->calculateTargetOperatingCash($effectiveEquity, $optimalDeposits, $optimalWholesaleDebt);
        $optimalEarningAssets = $effectiveEquity + $optimalDebt - $targetCash;

        // Pre-provision operating profit: unsecured card receivables charge off at CONSUMER_CHARGE_OFF_RATE through
        // the cycle and the allowance roll-forward books that against EBIT every quarter, so the ROE target
        // has to be earned on top of it or the reported return is the target minus the loss rate.
        $optimalCreditProvision = $this->resolveThroughTheCycleCreditProvision($stock, $optimalEarningAssets);
        $optimalEbit = $optimalEbt + $optimalInterestExpense + $optimalCreditProvision;
        $structuralAssetYield = $optimalEbit / max(1.0, $optimalEarningAssets);

        $targetEbit = $earningAssets * $structuralAssetYield;

        $coreLiabilities = $totalDebt;
        $minLendingEbit = $coreLiabilities * self::MIN_LENDING_EBIT_YIELD; // Floor is higher than banks due to high-yield credit card loans

        $targetEbit = max($minLendingEbit, $targetEbit);

        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $blendedCostOfFunds = ($depositRatio * $depositRate) + ((1.0 - $depositRatio) * $blendedWholesaleRate);
        $maxApr = max(self::MIN_APR_YIELD_FLOOR, $blendedCostOfFunds + self::POLICY_APR_SPREAD);
        $targetRevenue = min($unboundedRevenue, $earningAssets * $maxApr); // Floating gross yield ceiling based on blended cost of funds

        $grossYield = $targetRevenue / max(1.0, abs($earningAssets));

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => ($grossYield * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function calculateInterestIncome(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        // Credit services generate their interest income from their unsecured loan book, but that is largely
        // captured in Revenue (Gross Yield). We only return the supplemental interest from excess treasury
        // cash to avoid double-counting.
        $operatingBase = $this->getOperatingBase($stock);
        // Credit services act like banks and use standard cash buffering
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - ($operatingBase * self::TARGET_CASH_OPERATING_MULT));

        return $excessCash * $this->calculateCashYield($macroState);
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt, ?\App\DTO\MacroStateDTO $macroState = null): InterestExpenseDTO
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $customerDeposits = (float) $stock->getCustomerDeposits(); // High yield savings sweeps
        $wholesaleDebt = (float) $stock->getWholesaleDebt();

        $wholesaleInterest = ($wholesaleDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($wholesaleDebt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $wholesaleDebt > 0 ? ($wholesaleInterest / $wholesaleDebt) : $currentMarketFixedRate;

        // Credit services must offer highly competitive APYs on their high-yield savings accounts to attract funding
        $depositBeta = min(self::MAX_DEPOSIT_BETA_CLAMP, max(self::MIN_DEPOSIT_BETA_CLAMP, $this->calculateDepositBeta($debt, $totalEquity, $equityLimit, $customerDeposits) + self::HIGH_YIELD_BETA_SPREAD));
        $depositRate = max(0.001, $policyRate * $depositBeta);
        $depositInterest = $customerDeposits * $depositRate;

        return new InterestExpenseDTO(interestExpense: $wholesaleInterest + $depositInterest, wholesaleRate: $wholesaleRate);
    }

    /**
     * The card book is one unsecured consumer segment: the bank's segment model with nothing secured on property, so
     * the loss rate reads the household default cycle alone.
     *
     * @return array{loss_rate: float, systematic_z: float}
     */
    protected function resolveConditionalCreditLossRate(Stock $stock, \App\DTO\MacroStateDTO $macroState, \App\DTO\StreamContext $streams, MathUtility $mathUtility): array
    {
        $householdZ = $mathUtility->calculateVasicekSystematicFactor(
            $macroState->retailDefaultRateEma,
            MacroEngine::RETAIL_DEFAULT_BASELINE,
            \App\Service\Macro\Subsystem\CreditFiscalSubsystem::RETAIL_ASRF_RHO
        );
        $pd = static::CONSUMER_CHARGE_OFF_RATE * $this->resolveCreditRiskScale($stock) / self::LGD_BASELINE;

        return [
            'loss_rate' => $mathUtility->calculateVasicekExpectedLoss($householdZ, $pd, \App\Service\Macro\Subsystem\CreditFiscalSubsystem::RETAIL_ASRF_RHO, self::LGD_BASELINE)
                * $this->getLoanShareOfEarningAssets(),
            'systematic_z' => $householdZ,
        ];
    }

    /** A subprime originator's reserve travels further with credit spreads than a prime card network's. */
    protected function resolveSpreadReserveBeta(?Stock $stock): float
    {
        if (!$stock instanceof Stock) {
            return 1.0;
        }

        $params = $this->resolveModelParameters($stock, [
            ModelParam::CeclSpreadSensitivity->value => self::CECL_SPREAD_SENSITIVITY,
        ]);

        return $params[ModelParam::CeclSpreadSensitivity] / self::CECL_SPREAD_SENSITIVITY;
    }

    public function getCreditLossHorizonYears(): float
    {
        return self::CECL_LIFETIME_HORIZON_YEARS;
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
            'is_hoarder'      => $excessCash > ($totalDebt * self::HOARDING_THRESHOLD_DEBT_RATIO),
            'is_mega_hoarder' => $excessCash > ($totalDebt * self::MEGA_HOARDING_THRESHOLD_DEBT_RATIO),
        ];
    }

    public function getLeverageTarget(float $targetDebtTolerance): float
    {
        $bankEquityLimit = $targetDebtTolerance > 0.0 ? $targetDebtTolerance : $this->getWholesaleLeverageLimit();
        return $bankEquityLimit * 0.90;
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
            'credit_to_gdp_gap_ema',
            'inflation_ema',
            'interbank_liquidity_spread_ema',
            'macro_credit_spread_ema',
            'money_market_fund_share',
            'money_market_fund_share_ema',
            'output_gap_lag_9m',
            'policy_rate_ema',
            'recession_probability_ema',
            'retail_default_rate_ema',
            'sloos_tightening_index_ema',
            'yield_10y_ema',
            'yield_2y_ema',
        ];
    }
}
