<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\DebtExpansionAppetiteDTO;

use App\DTO\SectorPhysicsResult;

use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Event\ShockEvent;
use App\Service\Market\Bond\RatingMethodology;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;
use App\Service\Model\ModelParameters;
use App\Service\Model\StreamContext;

/**
 * Earnings strategy for Asset Managers.
 *
 * Financial Physics:
 * - Asset light, high margin business model.
 * - Revenue scales off highly sticky, recurring Assets Under Management (AUM) fees.
 * - Evaluated on Return on Equity (ROE).
 */
class AssetManagementBusinessModel extends BaseFinancialBusinessModel
{
    // --- Credit Rating ---
    /** Rated on business and financial risk alone, without a solvency score. */
    public const RATING_METHODOLOGY = RatingMethodology::AssetManager;

    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). AUM and flows amplify
     * the market cycle.
     */
    public const OPERATING_CYCLICALITY = 1.20;

    // --- Balance Sheet Realism ---
    /**
     * Stock-based compensation (ASC 718) as a share of revenue: Investments & Asset Management, 5.08% of revenue, 283
     * firms (Damodaran, Employee data by industry, US, January 2026).
     */
    public const STOCK_COMPENSATION_INTENSITY = 0.0508;

    // --- Labor Intensity ---
    /**
     * Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Portfolio manager and distribution
     * compensation dominates asset manager overhead.
     */
    public const FIXED_COST_LABOR_SHARE = 0.70;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for asset managers with quarterly public AUM disclosures. */
    public const BASE_COVERAGE_VISIBILITY = 0.45;
    /** Standard forecasting error on asset management performance fees and net inflows. */
    public const BASE_COVERAGE_ERROR = 0.08;
    /** Minimum visibility floor for analyst consensus models. */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.20;

    // --- Capacity ---
    /** Share of its market an untuned fee manager closes at: where its returns to scale start to fall (0.50). */
    public const DEFAULT_CAPACITY_SHARE = FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.12 a year). */
    public const ROIC_REVERSION_SPEED = 0.12;
    /** Excess return over the required return the moat sustains (1.2pp). */
    public const MOAT_SPREAD = 0.012;

    // --- Credit Thresholds ---
    /** Most wholesale debt the model carries per unit of equity (0.5x equity). */
    public const WHOLESALE_LEVERAGE_LIMIT = 0.5;

    // --- Valuation ---
    /**
     * Weight of book value against earnings value in the consensus fair value: none. Asset managers, hedge funds and
     * private equity trade on fee-related earnings and carry, not on book value.
     */
    public const FAIR_VALUE_BOOK_WEIGHT = 0.0;

    /**
     * A fee manager earns on the money its clients give it, which its own book stands in for here, so a dollar it
     * keeps or borrows past its franchise's capacity buys fees no client pays. Every fee manager closes at its capacity
     * (Berk & Green 2004) and hands back the rest.
     */
    public function getCapacityShare(Stock $stock): ?float
    {
        return $this->resolveModelParameters($stock, [ModelParam::CapacityShare->value => self::DEFAULT_CAPACITY_SHARE])[ModelParam::CapacityShare];
    }

    // --- Secular Demand ---
    /**
     * Securities, commodity contracts and investments value added as a share of US nominal GDP in 1997 (BEA GDP by
     * Industry, value added).
     */
    public const SECULAR_SHARE_1997 = 0.0141;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0130;

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    // --- Analyst Coverage ---
    // Flow reversals and fee cuts are disclosed.
    /** Visibility of a quarter with an announced event (80%). */
    public const EVENT_BASE_VISIBILITY = 0.80;
    /** Floor on visibility in an announced-event quarter (40%). */
    public const EVENT_MIN_VISIBILITY = 0.40;

    // --- Struck Operating Yield ---
    /** Floor on the struck operating profit per unit of book (5%): the fees a platform earns whatever its funding. */
    public const MIN_OPERATING_EBIT_YIELD = 0.05;
    /**
     * Ceiling on the struck revenue per unit of book (2.0x a year), where a thin margin would gross it up past reach.
     */
    public const MAX_TURNOVER_CAP         = 2.00;
    /** Persisted fee revenue per unit of book, struck once at the opening macro (resolveOperatingYield()). */
    public const STATE_OPERATING_YIELD    = 'state:operating_yield';

    // --- Macro & Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in sticky fee models. */
    public const REVENUE_VARIANCE_SCALAR  = 0.10;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;

    // --- Dual-Stream Fee Architecture ---
    /** Baseline fraction of revenue derived from sticky recurring AUM management fees. */
    public const BASE_FEE_WEIGHT           = 0.80;
    /** Baseline fraction of revenue derived from volatile performance fees / carried interest. */
    public const PERFORMANCE_FEE_WEIGHT    = 0.20;

    // --- AUM Market Beta & Performance Fees ---
    /**
     * Sensitivity of AUM management fee base to the output gap: the net-flow half of AUM, since clients add money in
     * expansions and redeem in slumps.
     */
    public const AUM_MARKET_BETA_SCALAR    = 0.40;
    /**
     * AUM beta to the equity market's level against its three-year trend: the mark-to-market half. Under one because a
     * manager's book is only part equities (roughly half at the large diversified houses per their AUM-mix
     * disclosures).
     */
    public const AUM_MARKET_LEVEL_BETA     = 0.60;
    /**
     * Cap on the market-level deviation the fee base reacts to, the same clamp the household wealth channel applies in
     * the IS curve.
     */
    public const AUM_MARKET_LEVEL_DEVIATION_CAP = 0.60;
    /** Z-score threshold above which strong fund alpha crystallizes outsized performance fees / carried interest. */
    public const PERFORMANCE_FEE_Z_FLOOR   = 1.50;
    /** Revenue bonus scalar applied per z-unit above the performance fee threshold. */
    public const PERFORMANCE_FEE_SCALAR    = 0.08;
    /** Severe negative z-score threshold indicating net institutional redemptions and fee compression. */
    public const REDEMPTION_SHOCK_Z_FLOOR  = -1.50;
    /** Revenue penalty scalar applied per z-unit below the redemption shock threshold on performance carry. */
    public const REDEMPTION_SHOCK_SCALAR   = 0.06;
    /** Fraction of institutional redemptions that erodes baseline AUM management fee revenue. */
    public const BASE_REDEMPTION_ATTRITION_SCALAR = 0.04;
    /** Fraction of excess performance fee / carry revenue paid out into portfolio manager incentive bonus pools. */
    public const PERF_BONUS_POOL_PAYOUT    = 0.40;

    // --- Structural Efficiency Floor ---
    /**
     * Minimum cost-to-revenue ratio: high operating leverage ensures variable margin does not collapse below structural
     * platform overhead.
     */
    public const MIN_EFFICIENCY_RATIO      = 0.35;

    // --- Broad Money Supply & AUM Inflows ---
    /** Sensitivity of institutional and retail AUM fund inflows to broad money supply (M2) expansion. */
    public const M2_AUM_INFLOW_SENSITIVITY = 0.35;
    /**
     * Base-fee lift per unit of money-market share gained this quarter: the migrating cash lands in the manager's money
     * funds (2022-23: +5 points of share, +27% money-fund assets).
     */
    public const MMF_AUM_INFLOW_SENSITIVITY = 1.5;

    // --- Event Lore Thresholds ---
    /** Z-score threshold triggering performance fee surge event lore. */
    public const LORE_PERFORMANCE_SURGE_Z  = 1.80;
    /** Z-score threshold triggering institutional fund outflows event lore. */
    public const LORE_FUND_OUTFLOWS_Z      = -1.80;

    // --- Liquidity & Cash Reserves ---
    /** Target operating cash reserve ratio applied to corporate operating base. */
    public const TARGET_OPERATING_BUFFER  = 0.10;
    /** Target operating cash reserve ratio applied to outstanding wholesale debt. */
    public const TARGET_DEBT_BUFFER       = 0.05;
    /** Minimum emergency operating cash reserve ratio applied to corporate operating base. */
    public const MIN_OPERATING_BUFFER     = 0.05;
    /** Threshold ratio of excess cash over operating base triggering standard hoarder status. */
    public const HOARDER_THRESHOLD        = 0.30;
    /** Threshold ratio of excess cash over operating base triggering mega-hoarder status. */
    public const MEGA_HOARDER_THRESHOLD   = 0.50;

    // --- Treasury Yield & 60/40 Portfolio ---
    /** Output gap multiplier scaling equity market returns during booms and busts. */
    public const EQUITY_RETURN_GAP_MULT   = 2.00;
    /** A hedge fund's or sponsor's equity sleeve swings with the cycle half again as hard as a manager's own seed. */
    public const LEVERAGED_SLEEVE_GAP_SCALE = 1.5;
    /** Weight allocated to fixed-income bonds in standard asset manager treasury portfolios. */
    public const TREASURY_BOND_WEIGHT     = 0.60;
    /** Weight allocated to equities in standard asset manager treasury portfolios. */
    public const TREASURY_EQUITY_WEIGHT   = 0.40;

    // --- Debt Issuance & Capital Deployment ---
    /** Baseline probability of initiating debt expansion when spreads are neutral. */
    public const DEBT_EXPANSION_BASE_PROB = 0.50;
    /** Multiplier scaling debt expansion probability with spread attractiveness. */
    public const DEBT_EXPANSION_PROB_MULT = 0.30;
    /** Baseline aggressiveness fraction for new debt issuance. */
    public const DEBT_EXPANSION_BASE_AGGR = 0.05;
    /** Multiplier scaling debt issuance aggressiveness with spread attractiveness. */
    public const DEBT_EXPANSION_AGGR_MULT = 0.10;
    /** Minimum fraction of newly issued debt that must be deployed into organic capex or fund seeding. */
    public const DEBT_CAPEX_DEPLOYMENT    = 0.90;

    /**
     * Fees are a yield on the book struck once, at the opening macro (resolveOperatingYield()). Fees are charged on
     * client assets, not on the manager's own borrowing, so a dearer funding market reaches profit through the interest
     * DebtEngine charges, not through a revenue target re-solved every quarter to hold the return.
     *
     * @return array{invested_capital: float, baseline_roic: float}
     */
    public function getTargetMetrics(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        return [
            'invested_capital' => $this->resolveEarningAssets($stock),
            'baseline_roic' => $this->resolveOperatingYield($stock, $mathUtility) * $stableMargin * (1.0 - $macroState->corporateTaxRate),
        ];
    }

    /**
     * Operating revenue per unit of book, as struck, or struck now at the opening macro: the operating profit the
     * structural return requires once the treasury's income (calculateInterestIncome(), the function the books are
     * kept with) and the wholesale interest are netted, grossed up by the margin, between the operating-profit floor
     * and the turnover ceiling. The interest is charged at the coupon the notes would refinance at, so the yield holds
     * once they have rolled; until then a manager whose notes pay under the market earns above its target.
     */
    protected function resolveOperatingYield(Stock $stock, MathUtility $mathUtility): float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        if (isset($momentum[self::STATE_OPERATING_YIELD])) {
            return (float) $momentum[self::STATE_OPERATING_YIELD];
        }

        $opening = new \App\DTO\MacroStateDTO();
        $earningAssets = max(1.0, $this->resolveEarningAssets($stock));
        $targetEbt = max(1.0, $stock->getTangibleEquity()) * $this->resolveStructuralTargetRoe($stock, $opening) / (1.0 - $opening->corporateTaxRate);
        $interestExpense = (float) $stock->getTotalDebt() * DebtEngine::chargedWholesaleRate($stock, $opening, $this->getLossGivenDefault(), true);
        $targetEbit = max($earningAssets * static::MIN_OPERATING_EBIT_YIELD, $targetEbt + $interestExpense - $this->calculateInterestIncome($stock, $opening));

        return min($targetEbit / max(0.01, (float) $stock->getOperatingMargin()), $earningAssets * static::MAX_TURNOVER_CAP) / $earningAssets;
    }

    /**
     * Every fee manager's quarter carries its struck operating yield forward.
     *
     * @param array<string, float> $momentum Previous quarter stream map ($stock->getEarningsMomentumZ()).
     */
    protected function createStreamContext(array $momentum, MathUtility $mathUtility, ?\App\DTO\MacroStateDTO $macroState = null, ?Stock $stock = null): StreamContext
    {
        $streams = parent::createStreamContext($momentum, $mathUtility, $macroState, $stock);
        if ($stock !== null) {
            $streams->registerState(self::STATE_OPERATING_YIELD, $this->resolveOperatingYield($stock, $mathUtility));
        }

        return $streams;
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        // Nullify generic demand shift to handle AUM market beta discretely per stream in calculateSectorPhysics.
        return [
            'macro_demand_shift' => 0.0,
            'pricing_power_multiplier' => 1.0,
        ];
    }

    /**
     * The client activity the distribution and servicing base is staffed to: net flows into the base-fee
     * book (the cycle, M2 and money-fund migration) at its target weight. The equity-market mark moves the
     * fee base with no client doing anything, and performance fees carry no macro term, so neither counts.
     */
    public function resolveSectorActivityShift(Stock $stock, \App\DTO\MacroStateDTO $macroState): float
    {
        $params = $this->resolveFeeParameters($stock);
        $baseWeight = $params[ModelParam::BaseFeeWeight];
        $totalWeight = $baseWeight + $params[ModelParam::PerformanceFeeWeight];
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        return ($baseWeight / $totalWeight) * $this->resolveClientFlowShift($stock, $macroState, $params[ModelParam::AumMarketBetaScalar]);
    }

    private function resolveFeeParameters(Stock $stock): ModelParameters
    {
        return $this->resolveModelParameters($stock, [
            ModelParam::BaseFeeWeight->value         => self::BASE_FEE_WEIGHT,
            ModelParam::PerformanceFeeWeight->value  => self::PERFORMANCE_FEE_WEIGHT,
            ModelParam::AumMarketBetaScalar->value  => self::AUM_MARKET_BETA_SCALAR,
            ModelParam::PerformanceFeeZFloor->value => self::PERFORMANCE_FEE_Z_FLOOR,
            ModelParam::PerformanceFeeScalar->value  => self::PERFORMANCE_FEE_SCALAR,
        ]);
    }

    /**
     * Net client flows into the base-fee book: the cycle's net-flow half of AUM, broad-money liquidity, and
     * cash migrating from bank deposits into the manager's money funds while it migrates.
     */
    private function resolveClientFlowShift(Stock $stock, \App\DTO\MacroStateDTO $macroState, float $aumBetaScalar): float
    {
        $aumMarketBeta = $this->resolveAumCycleFlow($stock, $macroState, $aumBetaScalar);
        $m2InflowBoost = MacroTransmission::calculateBroadMoneyLiquidityShift($macroState->moneySupplyGrowthEma, $macroState->moneySupplyGrowthTrend, sensitivity: self::M2_AUM_INFLOW_SENSITIVITY);
        $mmfInflowBoost = max(0.0, $macroState->moneyMarketFundShare - $macroState->moneyMarketFundShareEma) * self::MMF_AUM_INFLOW_SENSITIVITY;

        return $aumMarketBeta + $m2InflowBoost + $mmfInflowBoost;
    }

    /** The net-flow half of AUM: clients add money in expansions and redeem in slumps. */
    private function resolveAumCycleFlow(Stock $stock, \App\DTO\MacroStateDTO $macroState, float $aumBetaScalar): float
    {
        return $macroState->outputGapEma * $this->getOperatingCyclicality($stock) * $aumBetaScalar;
    }

    /**
     * Idiosyncratic variance incorporates AUM mark-to-market appreciation/depreciation,
     * asymmetric performance fee / carried interest surges during strong fund alpha quarters,
     * institutional redemption shocks during severe market drawdowns, and structural efficiency floors.
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        // Resolve company-specific tuned asset management parameters
        $params = $this->resolveFeeParameters($stock);

        $aumBetaScalar   = $params[ModelParam::AumMarketBetaScalar];
        $perfZFloor      = $params[ModelParam::PerformanceFeeZFloor];
        $perfScalar      = $params[ModelParam::PerformanceFeeScalar];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Revenue Mix ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'base_fee' => $params[ModelParam::BaseFeeWeight],
            'alpha'    => $params[ModelParam::PerformanceFeeWeight],
        ]);

        $baseWeight = $activeWeights['base_fee'];
        $perfWeight = $activeWeights['alpha'];

        // Independent stream Z-scores with AR(1) persistence
        $baseFeeZ = $streams->generateZ('base_fee', 0.45); // Sticky recurring AUM management fees
        $alphaZ   = $streams->generateZ('alpha', 0.15); // Fund alpha / activist execution

        // 1. AUM net client flows & mark-to-market (Base Management Fee Stream).
        $clientFlowShift = $this->resolveClientFlowShift($stock, $macroState, $aumBetaScalar);
        // Mark-to-market: fee revenue is a percentage of assets, so the equity market's level against its own
        // trend moves the base directly. A run with no reported market leaves both fields at zero and the term silent.
        $marketLevelShift = max(-self::AUM_MARKET_LEVEL_DEVIATION_CAP, min(self::AUM_MARKET_LEVEL_DEVIATION_CAP, $macroState->equityWealthDeviation())) * self::AUM_MARKET_LEVEL_BETA;

        // 2. Asymmetric Performance Fees & Institutional Redemptions (Incentive Fee Stream):
        // Strong alpha quarters crystallize outsized performance fees / carried interest.
        // Severe negative alpha triggers institutional client redemptions eroding baseline AUM.
        $alphaFeeBonus = 0.0;
        $baseRedemptionAttrition = 0.0;
        if ($alphaZ > $perfZFloor) {
            $alphaFeeBonus = ($alphaZ - $perfZFloor) * $perfScalar;
        } elseif ($alphaZ < self::REDEMPTION_SHOCK_Z_FLOOR) {
            $redemptionExcess = abs($alphaZ - self::REDEMPTION_SHOCK_Z_FLOOR);
            $alphaFeeBonus = -$redemptionExcess * self::REDEMPTION_SHOCK_SCALAR;
            $baseRedemptionAttrition = $redemptionExcess * self::BASE_REDEMPTION_ATTRITION_SCALAR;
        }

        // Blended dual-stream revenue
        $baseRevenue = max(0.0, $expectedRevenue * $baseWeight
            * (1.0 + ($baseFeeZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $clientFlowShift + $marketLevelShift - $baseRedemptionAttrition));
        $perfRevenue = max(0.0, $expectedRevenue * $perfWeight
            * (1.0 + ($alphaZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $alphaFeeBonus));

        $streamRevenues = [
            'base_fee' => $baseRevenue,
            'alpha'    => $perfRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // 3. Compensation Pool Flex & Structural Efficiency Floor:
        // Performance fee crystallization expands incentive bonus pool expenses.
        $perfRevenueExcess = max(0.0, $perfRevenue - ($expectedRevenue * $perfWeight));
        $bonusPoolExpense = $perfRevenueExcess * self::PERF_BONUS_POOL_PAYOUT;
        $effectiveVariableCosts = ($actualRevenue * $realizedVariableMargin) + $bonusPoolExpense;
        $effectiveVariableMargin = $actualRevenue > 0 ? ($effectiveVariableCosts / $actualRevenue) : $realizedVariableMargin;

        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $clampedMargin = $this->clampMargin($effectiveVariableMargin, $minVariableMargin);

        // 4. Dynamic Event Type:
        $eventType = null;
        if ($alphaZ > self::LORE_PERFORMANCE_SURGE_Z) {
            $eventType = ShockEvent::PERFORMANCE_FEE_SURGE;
        } elseif ($alphaZ < self::LORE_FUND_OUTFLOWS_Z) {
            $eventType = ShockEvent::FUND_OUTFLOWS;
        }

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    /**
     * Asset Managers invest excess corporate treasury in seed capital co-investment portfolios, bonds and equity: the
     * expected return of the mix (calculateCashYield()). The equity sleeve's realised return replaces its expectation
     * in the books (resolveEquitySleeve()).
     */
    public function calculateInterestIncome(Stock $stock, \App\DTO\MacroStateDTO $macroState, ?float $realizedWholesaleRate = null): float
    {
        return $this->resolveInvestedTreasury($stock) * $this->calculateCashYield($macroState);
    }

    /** @return array{value: float, expected_return: float} */
    public function resolveEquitySleeve(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        return [
            'value' => $this->resolveInvestedTreasury($stock) * $this->resolveTreasuryEquityWeight(),
            'expected_return' => $this->resolveEquitySleeveReturn($macroState, $this->resolveTreasuryEquityGapMultiplier()),
        ];
    }

    /** The treasury invested in the co-investment portfolio: all of it above the operating minimum. */
    protected function resolveInvestedTreasury(Stock $stock): float
    {
        $minCash = $this->calculateMinOperatingCash($this->getOperatingBase($stock), 0.0, (float) $stock->getWholesaleDebt());

        return max(0.0, (float) $stock->getCorporateTreasury() - $minCash);
    }

    /** The equity share of the invested treasury. */
    protected function resolveTreasuryEquityWeight(): float
    {
        return self::TREASURY_EQUITY_WEIGHT;
    }

    /** How strongly the equity sleeve's expected return reads the cycle. */
    protected function resolveTreasuryEquityGapMultiplier(): float
    {
        return self::EQUITY_RETURN_GAP_MULT;
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * self::TARGET_OPERATING_BUFFER, $wholesaleDebt * self::TARGET_DEBT_BUFFER);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return $operatingBase * self::MIN_OPERATING_BUFFER;
    }

    public function calculateCashYield(\App\DTO\MacroStateDTO $macroState): float
    {
        $bondReturn = $macroState->yield10yEma;
        $equityReturn = $this->resolveEquitySleeveReturn($macroState, self::EQUITY_RETURN_GAP_MULT);

        // Asset Managers invest heavily in their own funds ("eating their own cooking").
        // We use a classic 60/40 portfolio (60% Bonds / 40% Equities) which gives them higher market correlation.
        return (self::TREASURY_BOND_WEIGHT * $bondReturn) + (self::TREASURY_EQUITY_WEIGHT * $equityReturn);
    }

    /**
     * The equity tranche's expected return: the market cost of equity (the ten-year plus the equity risk premium),
     * so it moves with rates as every other return in the book does, plus the cycle term.
     */
    protected function resolveEquitySleeveReturn(\App\DTO\MacroStateDTO $macroState, float $gapMultiplier): float
    {
        return $macroState->marketCostOfEquity() + ($macroState->outputGapEma * $gapMultiplier);
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): DebtExpansionAppetiteDTO
    {
        return new DebtExpansionAppetiteDTO(probability: self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT), aggressiveness: self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier));
    }

    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        // Asset managers and brokerages use capital to seed new funds, acquire advisory firms, and build trading
        // platforms.
        return max($organicSpend, $debtIssued * self::DEBT_CAPEX_DEPLOYMENT);
    }

    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float
    {
        // Asset managers and brokerages can expand using existing cash hoards before taking on new debt
        return max(0.0, $baseCapacity - $excessCash);
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
            'equity_wealth_ratio',
            'equity_wealth_trend',
            'money_market_fund_share',
            'money_market_fund_share_ema',
            'money_supply_growth_ema',
            'money_supply_growth_trend',
            'output_gap_ema',
            'yield_10y_ema',
        ];
    }
}
