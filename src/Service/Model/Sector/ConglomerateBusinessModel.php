<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Multi-Industry Conglomerates & Industrial Holding Trusts.
 *
 * Financial Physics:
 * - Natural Hedging: Diversified operating subsidiaries across industrial, consumer, and financial verticals
 *   dramatically dampen baseline earnings volatility.
 * - Tri-Stream Architecture:
 *   1. Industrial Manufacturing: Heavy engineering, specialized chemicals, and B2B manufacturing components
 *      (pro-cyclical, exposed to the macroeconomic output gap and industrial CapEx cycles).
 *   2. Defensive Staples & Infrastructure: Inelastic consumer household goods, civic utilities, and infrastructure
 *      tollbooths (stable recurring cash flows with regulated/contractual inflation escalators).
 *   3. Contrarian Float & Financial Investments: Corporate treasury cash float, high-yield catastrophe bonds,
 *      and value investing dry powder. Decomposed into the three effects a real credit book experiences separately:
 *      **mark-to-market repricing** as spreads gap wider (an immediate loss), **carry alpha** from deploying dry
 *      powder at an elevated spread level (a lagged, saturating gain), and **credit losses** as issuers default.
 *      Widening hurts now; the plateau pays later.
 */
class ConglomerateBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for multi-industry conglomerates with complex multi-segment reporting. */
    public const BASE_COVERAGE_VISIBILITY = 0.35;

    /** Base coverage forecasting error given diversification across unlisted subsidiaries. */
    public const BASE_COVERAGE_ERROR = 0.05;

    // --- Tri-Stream Conglomerate Architecture ---
    /** Baseline fraction of revenue derived from cyclical industrial manufacturing subsidiaries. */
    public const INDUSTRIAL_CONGLOMERATE_WEIGHT = 0.40;

    /** Baseline fraction of revenue derived from defensive consumer staples & infrastructure tollbooths. */
    public const DEFENSIVE_STAPLES_WEIGHT = 0.40;

    /** Baseline fraction of revenue derived from financial float, investments, and contrarian dry powder. */
    public const CONTRARIAN_FLOAT_WEIGHT = 0.20;

    /** Baseline pricing power and monopoly leverage across conglomerate product lines. */
    public const PRICING_POWER_INDEX = 0.70;

    // --- Stream Volatility Scalars ---
    /** Volatility multiplier for cyclical industrial manufacturing throughput. */
    public const INDUSTRIAL_VARIANCE_SCALAR = 0.35;

    /** Volatility multiplier for defensive consumer staples and contracted infrastructure assets. */
    public const DEFENSIVE_VARIANCE_SCALAR = 0.08;

    /** Volatility multiplier for financial investment returns and mark-to-market float gains. */
    public const FLOAT_VARIANCE_SCALAR = 0.60;

    // --- Macro Physics ---
    /** Sensitivity of industrial manufacturing to broader GDP output gap expansion. */
    public const INDUSTRIAL_MACRO_SCALAR = 1.20;

    /** Beta floor for cyclical stream sensitivity; a reported beta near zero is a data artifact, not true acyclicality. */
    public const MIN_CYCLICAL_BETA_FLOOR = 0.35;

    /** Residual output gap sensitivity of staples volume and tollbooth throughput (defensive, but not macro-immune). */
    public const DEFENSIVE_MACRO_SCALAR = 0.15;

    /** Pass-through lag scalar for regulated tollbooth escalators and staples list-price resets. */
    public const PRICING_POWER_LAG_SCALAR = 0.70;

    // --- Contrarian Float Deployment & Credit Book Physics ---
    /** Sensitivity multiplier translating economic contraction into contrarian buyout alpha. */
    public const FLOAT_RECESSION_ALPHA_SCALAR = 2.50;

    /** Sensitivity multiplier translating the sustained credit spread level into distressed float carry. */
    public const FLOAT_SPREAD_BLOWOUT_SCALAR = 12.00;

    /** Maximum asymptotic float revenue expansion from contrarian dry powder deployment. */
    public const MAX_CONTRARIAN_FLOAT_EXPANSION = 0.60;

    /** Distress intensity signal at which half the maximum contrarian float expansion is realized. */
    public const CONTRARIAN_HALF_SATURATION_POINT = 0.50;

    /** Spread duration (years) of the float credit book: short corporates, preferreds, and cat bonds. */
    public const FLOAT_SPREAD_DURATION = 4.00;

    /** Realized credit loss drag on float revenue per unit of relative corporate default rate excess. */
    public const FLOAT_CREDIT_LOSS_SCALAR = 0.08;

    // --- Tail Risk & Shock Events ---
    /** Positive Z-score threshold indicating a landmark corporate acquisition or major subsidiary spin-off. */
    public const STRATEGIC_DIVESTITURE_Z_SCORE = 2.30;

    /** Top-line revenue multiplier from strategic acquisitions or restructuring dividends. */
    public const STRATEGIC_DIVESTITURE_MULT = 1.15;

    /** Negative Z-score threshold indicating an operational bottleneck or subsidiary restructuring drag. */
    public const RESTRUCTURING_DRAG_Z_SCORE = -2.30;

    /** Variable cost penalty applied during multi-subsidiary restructuring or supply chain write-offs. */
    public const RESTRUCTURING_DRAG_PENALTY = 0.04;

    // --- Industrial & Deal Activity Sensitivities ---
    /** Sensitivity of industrial conglomerate subsidiary demand to manufacturing PMI. */
    public const PMI_INDUSTRIAL_SENSITIVITY = 0.40;
    /** Sensitivity of conglomerate wholesale production costs to Producer Price Inflation (PPI). */
    public const PPI_COST_SENSITIVITY = 0.25;
    /** Share of industrial input cost exposure carried by staples subsidiaries (packaging, resins, commodities). */
    public const DEFENSIVE_PPI_EXPOSURE_RATIO = 0.50;
    /** Sensitivity of subsidiary divestiture proceeds and advisory alpha to capital markets deal activity. */
    public const DEAL_ACTIVITY_DIVESTITURE_SCALAR = 0.15;

    // --- Analyst Observability ---
    /** Fraction of float mark-to-market noise visible to public consensus; segment reporting obscures the rest. */
    public const FLOAT_OBSERVABLE_DISCOUNT = 0.40;
    /** Fraction of contrarian deployment alpha visible to public consensus ahead of the filing. */
    public const CONTRARIAN_OBSERVABLE_DISCOUNT = 0.50;

    public function getMinIcr(): float { return 2.5; }
    public function getWholesaleLeverageLimit(): float { return 2.0; }
    public function getDividendCrisisIcr(): float { return 1.75; }
    public function getBuybackMinIcr(): float { return 2.5; }
    public function getReversionSpeed(): float { return 0.1; }
    public function getMoatSpread(): float { return 0.015; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.15; }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.02; // Stable mature holding company growth
    }

    public function getCapexCyclicality(): float
    {
        return 0.50; // Blended across heavy industrial CapEx and asset-light investment float
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.55, 'revenue_weight' => 0.45];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value => self::PRICING_POWER_INDEX,
        ]);
        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        // Nullify global demand shifts to handle cyclicality discretely per stream.
        $physics['macro_demand_shift'] = 0.0;

        // Conglomerate pricing power is real but lagged: regulated tollbooth escalators and staples list-price
        // resets pass inflation into the nominal revenue base with a delay. Without this the model would absorb
        // PPI cost inflation on the margin while never repricing its output.
        $physics['pricing_power_multiplier'] = 1.0 + ($macroState->tipsBreakevenEma * $pricingPower * self::PRICING_POWER_LAG_SCALAR);

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
            ModelParam::IndustrialConglomerateWeight->value => self::INDUSTRIAL_CONGLOMERATE_WEIGHT,
            ModelParam::DefensiveStaplesWeight->value       => self::DEFENSIVE_STAPLES_WEIGHT,
            ModelParam::ContrarianFloatWeight->value        => self::CONTRARIAN_FLOAT_WEIGHT,
            ModelParam::PricingPowerIndex->value            => self::PRICING_POWER_INDEX,
        ]);

        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new StreamContext($momentum, $mathUtility);
        $beta = max(self::MIN_CYCLICAL_BETA_FLOOR, abs((float) $stock->getBeta()));

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'industrial_manufacturing' => $params[ModelParam::IndustrialConglomerateWeight],
            'defensive_staples'        => $params[ModelParam::DefensiveStaplesWeight],
            'financial_investments'    => $params[ModelParam::ContrarianFloatWeight],
        ]);

        $industrialWeight = $activeWeights['industrial_manufacturing'];
        $defensiveWeight  = $activeWeights['defensive_staples'];
        $floatWeight      = $activeWeights['financial_investments'];

        // Independent stream Z-scores with persistent AR(1) momentum
        $industrialZ = $streams->generateZ('industrial_manufacturing', 0.25);
        $defensiveZ  = $streams->generateZ('defensive_staples', 0.45);
        $floatZ      = $streams->generateZ('financial_investments', 0.15);
        $eventZ      = $streams->generateZ('event', 0.10);

        // --- Macro Sensitivities ---
        $outputGap = $macroState->outputGapEma;

        // The sustained spread level drives carry; the gap between spot and trend is the widening impulse.
        $spreadLevel   = $macroState->macroCreditSpreadEma;
        $spreadImpulse = $macroState->macroCreditSpread - $macroState->macroCreditSpreadEma;

        // Industrial manufacturing is pro-cyclical with GDP output gap and manufacturing PMI
        $pmiShift = MathUtility::calculatePmiDemandShift(
            $macroState->manufacturingPmiEma,
            MacroEngine::PMI_BASELINE,
            self::PMI_INDUSTRIAL_SENSITIVITY
        );
        $industrialMacroBoost = ($outputGap * self::INDUSTRIAL_MACRO_SCALAR * $beta) + ($pmiShift * $beta);

        // Staples volume and tollbooth throughput are defensive but not macro-immune (industrial load, toll traffic).
        $defensiveMacroBoost = $outputGap * self::DEFENSIVE_MACRO_SCALAR;

        // Capital markets deal activity expands strategic acquisition & divestiture opportunities
        $dealActivityShift = ($macroState->dealActivityIndexEma - MacroEngine::DEAL_ACTIVITY_BASELINE) / MacroEngine::DEAL_ACTIVITY_BASELINE;
        $divestitureAlpha = max(0.0, $dealActivityShift) * self::DEAL_ACTIVITY_DIVESTITURE_SCALAR;

        // --- Contrarian Float: Carry, Credit Losses, and Mark-to-Market ---
        // Deployment alpha: dry powder committed at a distressed spread level earns an elevated risk premium,
        // saturating as the opportunity set outgrows the balance sheet available to fund it.
        $recessionDepth = max(0.0, -$outputGap);
        $excessSpread   = max(0.0, $spreadLevel - MacroEngine::BASE_CREDIT_SPREAD);

        $rawDeploymentSignal = ($excessSpread * self::FLOAT_SPREAD_BLOWOUT_SCALAR)
            + ($recessionDepth * self::FLOAT_RECESSION_ALPHA_SCALAR)
            + $divestitureAlpha;

        $deploymentAlpha = $mathUtility->calculateDiminishingDistressMultiplier(
            $rawDeploymentSignal,
            self::MAX_CONTRARIAN_FLOAT_EXPANSION,
            self::CONTRARIAN_HALF_SATURATION_POINT
        );

        // Credit losses: spreads compensate for defaults, they are not free yield. Net carry = spread - expected loss.
        $corporateDefaultShift = max(0.0, ($macroState->corporateDefaultRateEma - MacroEngine::CORPORATE_DEFAULT_BASELINE) / MacroEngine::CORPORATE_DEFAULT_BASELINE);
        $creditLossDrag = $corporateDefaultShift * self::FLOAT_CREDIT_LOSS_SCALAR;

        // Mark-to-market: spreads gapping wider reprice the held book downward this quarter (dP/P ~ -D_s * ds).
        $floatMarkToMarket = MathUtility::calculateCreditSpreadMarkToMarket($spreadImpulse, self::FLOAT_SPREAD_DURATION);

        $contrarianSurge = $deploymentAlpha - $creditLossDrag + $floatMarkToMarket;

        // --- Tail Risk & Event Physics ---
        $acquisitionMult = 1.0;
        $eventType = null;
        $restructuringPenalty = 0.0;

        if ($eventZ > self::STRATEGIC_DIVESTITURE_Z_SCORE) {
            $acquisitionMult = self::STRATEGIC_DIVESTITURE_MULT;
            $eventType = ShockEvent::CONGLOMERATE_PORTFOLIO_REALIGNMENT;
        } elseif ($eventZ < self::RESTRUCTURING_DRAG_Z_SCORE) {
            $restructuringPenalty = self::RESTRUCTURING_DRAG_PENALTY * (1.0 - ($pricingPower * 0.50));
            $eventType = ShockEvent::CONGLOMERATE_SUBSIDIARY_WRITEDOWN;
        }

        // --- Tri-Stream Revenue Calculation ---
        $industrialShock = $industrialZ * ($baselineVol * self::INDUSTRIAL_VARIANCE_SCALAR);
        $defensiveShock  = $defensiveZ  * ($baselineVol * self::DEFENSIVE_VARIANCE_SCALAR);
        $floatShock      = $floatZ      * ($baselineVol * self::FLOAT_VARIANCE_SCALAR);

        $industrialRevenue = max(0.0, $expectedRevenue * $industrialWeight * (1.0 + $industrialShock + $industrialMacroBoost) * $acquisitionMult);
        $defensiveRevenue  = max(0.0, $expectedRevenue * $defensiveWeight  * (1.0 + $defensiveShock + $defensiveMacroBoost));
        $floatRevenue      = max(0.0, $expectedRevenue * $floatWeight      * (1.0 + $floatShock + $contrarianSurge));

        $streamRevenues = [
            'industrial_manufacturing' => $industrialRevenue,
            'defensive_staples'        => $defensiveRevenue,
            'financial_investments'    => $floatRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Wholesale cost inflation (PPI) across industrial operations and the input-exposed share of staples
        $ppiCostDrag = MathUtility::calculatePpiCostDrag(
            $macroState->producerPriceInflationEma,
            MacroEngine::TARGET_INFLATION,
            $pricingPower,
            self::PPI_COST_SENSITIVITY
        ) * ($industrialWeight + ($defensiveWeight * self::DEFENSIVE_PPI_EXPOSURE_RATIO));

        // Realized variable margin scaling
        $rawMargin = $realizedVariableMargin + $restructuringPenalty + $ppiCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        $primaryShockZ = $streams->resolveDominantShockZ([$industrialZ, $defensiveZ, $floatZ], $eventZ);

        // Blended observable shock
        $observableShockZ = ($industrialShock * $industrialWeight) +
            ($defensiveShock * $defensiveWeight) +
            ($floatShock * $floatWeight * self::FLOAT_OBSERVABLE_DISCOUNT) +
            ($industrialMacroBoost * $industrialWeight) +
            ($defensiveMacroBoost * $defensiveWeight) +
            ($contrarianSurge * $floatWeight * self::CONTRARIAN_OBSERVABLE_DISCOUNT);

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
        return array_unique(array_merge(parent::getOperatingMacroFields(), [
            'corporate_default_rate_ema',
            'deal_activity_index_ema',
            'macro_credit_spread',
            'macro_credit_spread_ema',
            'manufacturing_pmi_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
        ]));
    }
}
