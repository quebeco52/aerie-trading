<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Elite Law Firms, White-Shoe Practices & Legal Services.
 * 
 * Financial Physics:
 * - Asset Light & Human Capital Driven: Ultra-low CapEx, very high cash flow conversion.
 * - Tri-Stream Legal Architecture:
 *   1. Corporate Retainers & Governance: Sticky recurring retainer fees for corporate governance, regulatory compliance,
 *      and M&A transactions (pro-cyclical with corporate boardroom activity).
 *   2. High-Stakes Litigation & Contingency Fees: Extremely volatile, high-variance corporate warfare, antitrust battles,
 *      and patent infringement contingency windfalls.
 *   3. Restructuring & Bankruptcy Workouts: **Counter-cyclical legal surge**—when credit spreads blow out and corporate
 *      defaults surge during recessions, Chapter 11 and restructuring billing explodes.
 * - Inflation Dynamics: Immune to physical supply chains, but exposed to top-tier associate wage inflation (mitigated by PricingPowerIndex).
 */
class LawFirmBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Retainers are sticky; litigation and restructuring are counter-cyclical. */
    public const OPERATING_CYCLICALITY = 0.80;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.30;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['labor' => 0.85];

    // --- Services Pricing ---
    /** Elasticity of fee and rate pricing to services (supercore) inflation. Billing rates track professional services inflation almost one for one. */
    public const PRICING_ELASTICITY = 0.90;
    /** Services price off core services inflation ex-housing, not goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: Q4 billing and collections push before partner distributions.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.95, 1.00, 0.95, 1.10];
    }

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Partner and associate compensation is nearly the entire overhead of a law firm. */
    public const FIXED_COST_LABOR_SHARE = 0.85;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for elite private partnerships and legal firms. */
    public const BASE_COVERAGE_VISIBILITY = 0.20;

    /** Base coverage forecasting error given the lumpiness of litigation payouts. */
    public const BASE_COVERAGE_ERROR = 0.06;

    // --- Tri-Stream Legal Architecture ---
    /** Baseline fraction of revenue derived from sticky B2B corporate retainers and M&A compliance. */
    public const CORPORATE_RETAINER_WEIGHT = 0.45;

    /** Baseline fraction of revenue derived from high-stakes corporate litigation and contingency fees. */
    public const LITIGATION_CONTINGENCY_WEIGHT = 0.35;

    /** Baseline fraction of revenue derived from counter-cyclical restructuring and bankruptcy workouts. */
    public const RESTRUCTURING_ADVISORY_WEIGHT = 0.20;

    /** Baseline pricing power and rate-card leverage of elite white-shoe legal counsel. */
    public const PRICING_POWER_INDEX = 0.85;

    // --- Stream Volatility Scalars ---
    /** Volatility multiplier for steady corporate retainer and advisory billing. */
    public const RETAINER_VARIANCE_SCALAR = 0.10;

    /** Volatility multiplier for lumpy litigation settlement payouts. */
    public const LITIGATION_VARIANCE_SCALAR = 2.50;

    /** Volatility multiplier for bankruptcy restructuring mandates. */
    public const RESTRUCTURING_VARIANCE_SCALAR = 0.45;

    // --- Macro Physics & Counter-Cyclical Restructuring ---
    /** Sensitivity of corporate M&A and advisory billing to economic output gap. */
    public const RETAINER_MACRO_SCALAR = 0.60;

    /** Sensitivity multiplier translating economic contraction depth into bankruptcy advisory surge. */
    public const RESTRUCTURING_RECESSION_SCALAR = 3.00;

    /** Sensitivity multiplier translating corporate credit spread spikes into restructuring billing surge. */
    public const RESTRUCTURING_SPREAD_SCALAR = 15.00;

    /** Baseline credit spread above which corporate bankruptcy workouts accelerate. */
    public const DEFAULT_CREDIT_SPREAD_BASELINE = 0.015;
    /** Sensitivity of corporate legal retainers and M&A compliance to aggregate deal activity. */
    public const DEAL_ACTIVITY_RETAINER_SCALAR = 0.20;
    /** Sensitivity of bankruptcy restructuring legal billing to macroeconomic corporate default rate surges. */
    public const CORPORATE_DEFAULT_RESTRUCTURING_SCALAR = 0.30;

    // --- Associate Wage Inflation ---
    /** Associate compensation reprices annually with the bonus cycle, faster than menu-cost goods. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.50;

    // --- Tail Risk & Event Physics ---
    /** Positive Z-score threshold indicating a landmark corporate litigation or antitrust victory. */
    public const LITIGATION_WIN_Z_SCORE = 2.00;

    /** Revenue multiplier for a major corporate litigation or contingency settlement victory. */
    public const LITIGATION_WIN_MULT = 1.50;

    /** Negative Z-score threshold indicating a major trial defeat or lost contingency verdict. */
    public const LITIGATION_LOSS_Z_SCORE = -2.00;

    /** Revenue multiplier for a lost major trial or forfeited contingency engagement. */
    public const LITIGATION_LOSS_MULT = 0.75;

        public function getMinIcr(): float { return 3.0; }
    public function getWholesaleLeverageLimit(): float { return 1.5; }
    public function getDividendCrisisIcr(): float { return 2.0; }
    public function getBuybackMinIcr(): float { return 3.0; }
    public function getReversionSpeed(): float { return 0.15; }
    public function getMoatSpread(): float { return 0.015; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.05; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.2; }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.02;
    }

    public function getCapexCyclicality(): float
    {
        return 0.10; // Virtually no physical capex
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.70, 'revenue_weight' => 0.30];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global generic demand shifts; cyclicality is handled per-stream.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility
    ): SectorPhysicsResult {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::CorporateRetainerWeight->value     => self::CORPORATE_RETAINER_WEIGHT,
            ModelParam::LitigationContingencyWeight->value  => self::LITIGATION_CONTINGENCY_WEIGHT,
            ModelParam::RestructuringAdvisoryWeight->value  => self::RESTRUCTURING_ADVISORY_WEIGHT,
            ModelParam::PricingPowerIndex->value            => self::PRICING_POWER_INDEX,
        ]);

        $retainerWeight      = $params[ModelParam::CorporateRetainerWeight];
        $litigationWeight    = $params[ModelParam::LitigationContingencyWeight];
        $restructuringWeight = $params[ModelParam::RestructuringAdvisoryWeight];
        $pricingPower        = $params[ModelParam::PricingPowerIndex];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta = $this->getOperatingCyclicality($stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'corporate_retainers'    => $params[ModelParam::CorporateRetainerWeight],
            'litigation_settlements' => $params[ModelParam::LitigationContingencyWeight],
            'restructuring_advisory' => $params[ModelParam::RestructuringAdvisoryWeight],
        ]);

        $retainerWeight      = $activeWeights['corporate_retainers'];
        $litigationWeight    = $activeWeights['litigation_settlements'];
        $restructuringWeight = $activeWeights['restructuring_advisory'];

        // Independent stream Z-scores with persistent AR(1) momentum
        $retainerZ      = $streams->generateZ('corporate_retainers', 0.40);
        $litigationZ    = $streams->generateZ('litigation_settlements', 0.10);
        $restructuringZ = $streams->generateZ('restructuring_advisory', 0.30);
        $eventZ         = $streams->generateExogenousZ('event', 0.10);

        // --- Macro Sensitivities & Restructuring Surge ---
        $outputGap = $macroState->outputGapEma;
        $creditSpread = ($macroState->macroCreditSpread !== MacroEngine::BASE_CREDIT_SPREAD)
            ? $macroState->macroCreditSpread
            : $macroState->macroCreditSpreadEma;

        // Retainers expand during corporate booms and active M&A deal flow
        $dealActivityShift = ($macroState->dealActivityIndexEma - MacroEngine::DEAL_ACTIVITY_BASELINE) / MacroEngine::DEAL_ACTIVITY_BASELINE;
        $retainerMacroBoost = max(0.0, $outputGap * self::RETAINER_MACRO_SCALAR * $beta)
            + ($dealActivityShift * self::DEAL_ACTIVITY_RETAINER_SCALAR);

        // Restructuring surges counter-cyclically during economic recessions and credit default waves
        $recessionDepth = max(0.0, -$outputGap);
        $excessSpread   = max(0.0, $creditSpread - self::DEFAULT_CREDIT_SPREAD_BASELINE);
        $corporateDefaultShift = max(0.0, ($macroState->corporateDefaultRateEma - MacroEngine::CORPORATE_DEFAULT_BASELINE) / MacroEngine::CORPORATE_DEFAULT_BASELINE);
        $restructuringSurge = ($recessionDepth * self::RESTRUCTURING_RECESSION_SCALAR * $beta)
            + ($excessSpread * self::RESTRUCTURING_SPREAD_SCALAR)
            + ($corporateDefaultShift * self::CORPORATE_DEFAULT_RESTRUCTURING_SCALAR);

        // --- Tail Risk & Settlement Events ---
        $litigationMult = 1.0;
        $eventType = null;

        if ($litigationZ > self::LITIGATION_WIN_Z_SCORE || $eventZ > self::LITIGATION_WIN_Z_SCORE) {
            $litigationMult = self::LITIGATION_WIN_MULT;
            $eventType = ShockEvent::LITIGATION_SETTLEMENT_WIN;
        } elseif ($litigationZ < self::LITIGATION_LOSS_Z_SCORE || $eventZ < self::LITIGATION_LOSS_Z_SCORE) {
            $litigationMult = self::LITIGATION_LOSS_MULT;
            $eventType = ShockEvent::LITIGATION_SETTLEMENT_LOSS;
        }

        // --- Tri-Stream Revenue Calculation ---
        $retainerShock      = $retainerZ      * ($baselineVol * self::RETAINER_VARIANCE_SCALAR);
        $litigationShock    = $litigationZ    * ($baselineVol * self::LITIGATION_VARIANCE_SCALAR);
        $restructuringShock = $restructuringZ * ($baselineVol * self::RESTRUCTURING_VARIANCE_SCALAR);

        $retainerRevenue      = max(0.0, $expectedRevenue * $retainerWeight      * (1.0 + $retainerShock + $retainerMacroBoost));
        $litigationRevenue    = max(0.0, $expectedRevenue * $litigationWeight    * (1.0 + $litigationShock) * $litigationMult);
        $restructuringRevenue = max(0.0, $expectedRevenue * $restructuringWeight * (1.0 + $restructuringShock + $restructuringSurge));

        $streamRevenues = [
            'corporate_retainers'    => $retainerRevenue,
            'litigation_settlements' => $litigationRevenue,
            'restructuring_advisory' => $restructuringRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Associate wage inflation: variable payroll follows wage growth above trend and is recovered through
        // rate-card increases at the firm's pricing power.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        $rawMargin = $realizedVariableMargin + $inputCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        $primaryShockZ = $streams->resolveDominantShockZ([$retainerZ, $litigationZ, $restructuringZ], $eventZ);

        // Blended observable shock
        $observableShockZ = ($retainerShock * $retainerWeight) +
            ($litigationShock * $litigationWeight * 0.50) +
            ($restructuringShock * $restructuringWeight * 0.50) +
            ($restructuringSurge * $restructuringWeight * 0.40);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
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
            'corporate_default_rate_ema',
            'deal_activity_index_ema',
            'exchange_rate_index_ema',
            'macro_credit_spread',
            'macro_credit_spread_ema',
            'output_gap_ema',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
