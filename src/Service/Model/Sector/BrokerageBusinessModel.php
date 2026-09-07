<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for Brokerages & Capital Markets.
 * 
 * Financial Physics:
 * - Highly leveraged, transaction-driven business model.
 * - Revenue scales off trading volume, investment banking advisory, and margin loans.
 * - Evaluated on Return on Equity (ROE).
 */
class BrokerageBusinessModel extends BaseFinancialBusinessModel
{

    // --- Loss Provisions & Analyst Coverage ---
    /** Loss provision z-factor for margin credit defaults. */
    public const LOSS_PROVISION_Z_FACTOR    = 0.005;
    /** Base coverage visibility for brokerages. */
    public const BASE_COVERAGE_VISIBILITY = 0.30;
    /** Base coverage error for brokerages. */
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Macro Demand Physics ---
    /** Macroeconomic demand shift sensitivity to output gap. */
    public const MACRO_DEMAND_SCALAR = 0.50;
    /** Sensitivity of brokerage capital markets advisory revenue to aggregate deal activity. */
    public const DEAL_ACTIVITY_ADVISORY_SCALAR = 0.25;
    /** Sensitivity of retail brokerage trading activity and margin borrowing to M2 money supply growth. */
    public const M2_RETAIL_TRADING_SENSITIVITY = 0.40;

        public function getWholesaleLeverageLimit(): float { return 8.0; }
    public function getMoatSpread(): float { return 0.005; }
    // --- Dual-Stream Brokerage Architecture ---
    /** Baseline fraction of revenue derived from trading desks, market making, and execution commissions. */
    public const TRADING_REVENUE_WEIGHT  = 0.60;
    /** Baseline fraction of revenue derived from capital markets advisory, placement, and wealth services. */
    public const ADVISORY_REVENUE_WEIGHT = 0.40;

    // --- VIX & Trading Volume Bonus ---
    /** Baseline VIX threshold above which market volatility boosts trading volume and fee revenue. */
    public const VIX_BASELINE_THRESHOLD = 0.20;
    /** Sensitivity scalar translating excess VIX points into direct top-line revenue bonuses. */
    public const VIX_REVENUE_SCALAR     = 0.50;
    /** Extreme VIX threshold triggering record trading volume event lore. */
    public const VIX_EXTREME_THRESHOLD  = 0.30;
    /** Negative z-score threshold indicating severe advisory/deal flow collapse for event lore. */
    public const ADVISORY_CRASH_Z_THRESHOLD = -2.00;

    // --- Client Cash Sweep NII & Efficiency Floor ---
    /** Client uninvested cash sweep deposit balances as a fraction of total wholesale debt / operating assets. */
    public const CLIENT_SWEEP_BASE_RATIO   = 0.50;
    /** Policy rate threshold below which brokerage deposit sweep rates remain near zero (~10bps). */
    public const SWEEP_RATE_BUFFER         = 0.005;
    /** Beta pass-through of short-term policy rate increases to retail cash sweep accounts above the buffer. */
    public const SWEEP_DEPOSIT_BETA        = 0.20;
    /** Structural minimum operating cost-to-revenue ratio reflecting brokerage technology and clearinghouse overhead. */
    public const MIN_EFFICIENCY_RATIO      = 0.40;

    // --- Revenue & Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in transaction-driven markets. */
    public const REVENUE_VARIANCE_SCALAR = 0.20;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- ROE & Target Architecture ---
    /** Weight given to historical baseline ROE when blending with TTM ROE. */
    public const BASELINE_ROE_WEIGHT = 0.50;
    /** Weight given to TTM ROE when blending with historical baseline ROE. */
    public const TTM_ROE_WEIGHT      = 0.50;
    /** Default 5Y Treasury spread over policy rate when yield curve data is absent. */
    public const DEFAULT_5Y_YIELD_PREMIUM = 0.005;
    /** Default maximum financial leverage (Debt/Equity) limit if sector configuration is absent. */
    public const DEFAULT_EQUITY_LIMIT     = 8.00;

    // --- Structural Yield Rails ---
    /** Minimum structural operating EBIT floor as a fraction of earning assets. */
    public const MIN_OPERATING_EBIT_YIELD = 0.015;
    /** Hard ceiling on gross asset yield to prevent reverse-engineered revenue hyperinflation. */
    public const MAX_GROSS_ASSET_YIELD    = 1.50;

    // --- Clearinghouse Liquidity Rules ---
    /** Target operating cash reserve ratio required to support clearinghouse margin and trade settlements. */
    public const TARGET_CASH_BACKING_RATIO = 0.15;
    /** Hard minimum liquidity floor required to prevent clearinghouse margin defaults. */
    public const MIN_CASH_BACKING_RATIO    = 0.10;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $outputGap = $macroState->outputGapEma;
        $m2Shift = MathUtility::calculateBroadMoneyLiquidityShift($macroState->moneySupplyGrowthEma, sensitivity: self::M2_RETAIL_TRADING_SENSITIVITY);
        $beta = (float) $stock->getBeta();

        return [
            'macro_demand_shift' => ($outputGap * $beta * self::MACRO_DEMAND_SCALAR) + $m2Shift,
            'pricing_power_multiplier' => 1.0,
        ];
    }

    /**
     * Idiosyncratic shock applied to retail trading volume and institutional deal flow.
     * Capital Markets have higher top-line variance compared to sticky Asset Managers.
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::TradingRevenueWeight->value  => self::TRADING_REVENUE_WEIGHT,
            ModelParam::AdvisoryRevenueWeight->value => self::ADVISORY_REVENUE_WEIGHT,
        ]);

        $tradingWeight  = $params[ModelParam::TradingRevenueWeight];
        $advisoryWeight = $params[ModelParam::AdvisoryRevenueWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'trading'  => $params[ModelParam::TradingRevenueWeight],
            'advisory' => $params[ModelParam::AdvisoryRevenueWeight],
        ]);

        $tradingWeight  = $activeWeights['trading'];
        $advisoryWeight = $activeWeights['advisory'];

        // Independent stream Z-scores with AR(1) persistence
        $tradingZ  = $streams->generateZ('trading', 0.20); // Trading volume, flow capture, prop desk P&L
        $advisoryZ = $streams->generateZ('advisory', 0.35); // Advisory mandates, prime brokerage balances

        // The Volatility Bonus (Trading Volume) & M2 Broad Money Liquidity:
        // Brokerage trading revenues are hyper-sensitive to the VIX (Systemic Market Volatility) and retail liquidity (M2 growth).
        // High Volatility = Massive trading volume (panic selling or euphoria buying) which generates massive fees.
        // Crucially, this VIX bonus and M2 retail volume apply to the trading revenue stream ($tradingWeight).
        $vixEma = $macroState->marketVolatilityEma;
        $volatilityBonus = max(0.0, ($vixEma - self::VIX_BASELINE_THRESHOLD) * self::VIX_REVENUE_SCALAR);
        $m2Shift = MathUtility::calculateBroadMoneyLiquidityShift($macroState->moneySupplyGrowthEma, sensitivity: self::M2_RETAIL_TRADING_SENSITIVITY);

        $dealActivityShift = ($macroState->dealActivityIndexEma - MacroEngine::DEAL_ACTIVITY_BASELINE) / MacroEngine::DEAL_ACTIVITY_BASELINE;
        $advisoryDealBonus = $dealActivityShift * self::DEAL_ACTIVITY_ADVISORY_SCALAR;

        $tradingRevenue  = max(0.0, $expectedRevenue * $tradingWeight * (1.0 + ($tradingZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $volatilityBonus + $m2Shift));
        $advisoryRevenue = max(0.0, $expectedRevenue * $advisoryWeight * (1.0 + ($advisoryZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $advisoryDealBonus));
        
        $streamRevenues = [
            'trading'  => $tradingRevenue,
            'advisory' => $advisoryRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Structural Efficiency Floor: Total Operating Costs (Fixed + Variable) / Revenue >= MIN_EFFICIENCY_RATIO.
        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $clampedMargin = $this->clampMargin($realizedVariableMargin, $minVariableMargin);

        $eventType = null;
        if ($vixEma > self::VIX_EXTREME_THRESHOLD) {
            $eventType = ShockEvent::VOLATILITY_SURGE;
        } elseif ($advisoryZ < self::ADVISORY_CRASH_Z_THRESHOLD) {
            $eventType = ShockEvent::ADVISORY_CRASH;
        }

        // observableShockZ: the VIX bonus is completely public via daily VIX tracking — analysts can anticipate it fully.
        $primaryShockZ = $streams->resolveDominantShockZ([$tradingZ, $advisoryZ]);
        $observableShockZ = ($volatilityBonus * $tradingWeight) + ($advisoryDealBonus * $advisoryWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    public function getTargetMetrics(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());

        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * self::BASELINE_ROE_WEIGHT) + ($ttmRoe * self::TTM_ROE_WEIGHT);
        }

        $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, max(1.0, $equity), $macroState);
        $waccBase = $macroState->policyRate + $macroState->equityRiskPremium;
        $baselineRoe = max($waccBase, $baselineRoe - $saturationPenalty);

        $policyRate = $macroState->policyRateEma;
        $taxRate = $macroState->corporateTaxRate;

        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $treasury = (float) $stock->getCorporateTreasury();

        $blendedWholesaleRate = $this->calculateBlendedWholesaleRate($stock, $macroState);

        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? self::DEFAULT_EQUITY_LIMIT;

        $effectiveEquity = max(1.0, $equity);

        // Brokerages rely heavily on wholesale debt to fund high-yielding margin loans for their clients.
        $actualLeverage = $effectiveEquity > 0 ? ($wholesaleDebt / $effectiveEquity) : 0.0;
        $allowedLeverage = min($actualLeverage, max(0.0, $equityLimit));
        $optimalDebt = $effectiveEquity * $allowedLeverage;

        $optimalInterestExpense = $optimalDebt * $blendedWholesaleRate;

        // NOTE: deliberately priced over the policy rate, NOT over $blendedWholesaleRate, even though the
        // expense leg two lines up uses the blended rate and the realized rail in calculateInterestIncome
        // now correctly prices over the firm's own funding cost.
        //
        // Aligning this leg too is the consistent thing to do, but it cannot be done at the current
        // calibration: MARGIN_LOAN_SPREAD is 300 bps and brokerages run up to 8x equity, so a full spread
        // earned over funding on the entire wholesale book yields ~19% ROE from net interest alone against a
        // 15% target. Required operating EBIT then goes negative and target revenue collapses to the floor.
        // The 300 bps figure is a retail margin-lending spread and was implicitly calibrated against this
        // formula, which nets the credit and term spreads back out. Fixing it properly means either
        // recalibrating the constant or modelling a margin-lending allocation the way InvestmentBank does
        // with PRIME_BROKERAGE_ALLOCATION, which is a sector rebalance rather than a defect fix.
        $marginLoanYield = $policyRate + FinancialConstants::MARGIN_LOAN_SPREAD;
        $optimalInterestIncome = $optimalDebt * $marginLoanYield;

        $optimalOperatingNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalOperatingNetIncome / (1.0 - $taxRate);

        $optimalEbit = $optimalEbt + $optimalInterestExpense - $optimalInterestIncome;
        $operatingBase = $this->getOperatingBase($stock);
        $targetCash = $this->calculateTargetOperatingCash($operatingBase, 0.0, $optimalDebt);
        $optimalEarningAssets = $effectiveEquity + $optimalDebt - $targetCash;
        $structuralAssetYield = $optimalEbit / max(1.0, $optimalEarningAssets);

        $earningAssets = max($effectiveEquity, $effectiveEquity + $wholesaleDebt - $treasury);

        $targetEbit = $earningAssets * $structuralAssetYield;
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        $minOperatingEbit = $earningAssets * self::MIN_OPERATING_EBIT_YIELD;
        $targetEbit = max($minOperatingEbit, $targetEbit);

        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $targetRevenue = min($unboundedRevenue, $earningAssets * self::MAX_GROSS_ASSET_YIELD); // Hard cap gross yield on total assets

        $grossYield = $targetRevenue / max(1.0, $earningAssets);

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => ($grossYield * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    /**
     * Fallback blended wholesale funding rate: floating/fixed debt mix priced off the policy rate and 5-year
     * yield curve, plus the firm's static structural credit spread. Used only when no realized wholesale rate
     * from DebtEngine is available (e.g. market seed/reset bootstrapping, before any live debt calc has run).
     *
     * IMPORTANT: this deliberately omits the dynamic Merton/BGG spread widening that
     * DebtEngine::calculateInterestExpenseAndWholesaleRate layers on, because that widening depends on the
     * firm's live market cap and distance-to-default. Do not use this as the benchmark once a realized rate
     * exists — pricing client margin assets off this static approximation while the expense side pays the
     * live distressed rate is exactly the negative-carry bug this indirection guards against; see
     * calculateInterestIncome().
     */
    protected function calculateBlendedWholesaleRate(Stock $stock, \App\DTO\MacroStateDTO $macroState): float
    {
        $policyRate = $macroState->policyRateEma;
        $yield5y = $macroState->yield5yEma;
        $structuralSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        $floatingRate = $policyRate + $macroState->interbankLiquiditySpreadEma;

        return ($floatingRatio * $floatingRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;
    }

    public function calculateInterestIncome(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        $policyRate = $macroState->policyRateEma;

        // Client margin loans are funded one-for-one out of the firm's own wholesale book, so the asset yield
        // must be built on the same realized rate DebtEngine charges on the liability side
        // (DebtMetricsDTO::$wholesaleRate), dynamic credit-spread widening included. Pricing the asset leg off
        // the bare policy rate while the liability leg paid the live distressed rate left the brokerage booking
        // a matched-book carry that evaporated -- and then inverted -- exactly when spreads blew out.
        $fundingBenchmark = max(0.0, $realizedWholesaleRate ?? $this->calculateBlendedWholesaleRate($stock, $macroState));

        // 1. Margin Loan Yield
        // Brokerages lend their wholesale debt to clients as margin loans at a spread over their funding cost.
        $marginLoanYield = $fundingBenchmark + FinancialConstants::MARGIN_LOAN_SPREAD;
        $marginLoans = (float) $stock->getWholesaleDebt();
        $marginInterest = $marginLoans * $marginLoanYield;

        // 2. Client Cash Sweep Net Interest Income (NII):
        // Brokerages hold uninvested client deposit sweeps and earn NII spread over pass-through deposit rates.
        $operatingBase = $this->getOperatingBase($stock);
        $sweepBalances = max(0.0, $operatingBase * self::CLIENT_SWEEP_BASE_RATIO);
        $clientDepositRate = $policyRate > self::SWEEP_RATE_BUFFER
            ? ($policyRate - self::SWEEP_RATE_BUFFER) * self::SWEEP_DEPOSIT_BETA
            : 0.001;
        $sweepSpreadYield = max(0.0, $policyRate - $clientDepositRate);
        $sweepInterest = $sweepBalances * $sweepSpreadYield;

        // 3. Excess Corporate Treasury Yield
        $minCash = $this->calculateMinOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - $minCash);
        $cashYield = $this->calculateCashYield($macroState);
        $cashInterest = $excessCash * $cashYield;

        return $marginInterest + $sweepInterest + $cashInterest;
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        // Brokerages fund operations and margin lending purely via wholesale debt markets.
        $interestExpense = ($debt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($debt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $debt > 0 ? ($interestExpense / $debt) : $currentMarketFixedRate;

        return [
            'interest_expense' => $interestExpense,
            'wholesale_rate' => $wholesaleRate
        ];
    }

    /**
     * Brokerages and Investment Banks do not dump their treasury into 60/40 mutual funds.
     * Their excess cash must remain highly liquid to satisfy clearinghouse margin requirements 
     * and strict regulatory capital constraints. They earn standard risk-free money market yields.
     */
    public function calculateCashYield(\App\DTO\MacroStateDTO $macroState): float
    {
        $policyRate = $macroState->policyRateEma;
        return max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
    }

    /**
     * Brokerages require significantly higher liquidity than standard asset managers.
     * They must hold massive cash reserves against their wholesale debt to satisfy 
     * clearinghouse margin requirements and facilitate high-frequency trade settlements.
     */
    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        // Requires 15% cash backing on all outstanding wholesale debt
        return max($operatingBase * self::TARGET_CASH_BACKING_RATIO, $wholesaleDebt * self::TARGET_CASH_BACKING_RATIO);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        // Hard 10% liquidity floor to prevent catastrophic margin calls
        return max($operatingBase * self::MIN_CASH_BACKING_RATIO, $wholesaleDebt * self::MIN_CASH_BACKING_RATIO);
    }

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        return max($revenueFloorValue, $peFairValue);
    }

    /**
     * Brokerages and Investment Banks rely on wholesale repo and debt facilities to fund trading desks and margin loans.
     */
    public function supportsUnderleveragedDebtExpansion(): bool
    {
        return true;
    }
}

