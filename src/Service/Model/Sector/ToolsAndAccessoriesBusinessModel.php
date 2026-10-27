<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Tools & Accessories (Precision Tooling, Hardware).
 *
 * Financial Physics:
 * - Premium B2B: Ultra-high precision industrial tooling. Extremely sticky, low variance, massive margins.
 * - Retail Liquidations: Manufacturing scrap sold to prosumers. Highly cyclical and volatile.
 */
class ToolsAndAccessoriesBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Industrial tooling
     * and prosumer hardware.
     */
    public const OPERATING_CYCLICALITY = 1.00;
    /** Share of sales that are power tools and equipment, bought as households and trades add to their kit. */
    public const DURABLE_DEMAND_SHARE = 0.40;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.60;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::TOOLS_AND_ACCESSORIES;
    /**
     * Professional trade brands hold shelf and channel power on a sticky commercial base; the retail secondary market
     * does not.
     */
    public const PRICING_POWER_INDEX = 0.65;

    // --- Balance Sheet Realism ---
    /**
     * Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Leased plants and
     * distribution warehouses.
     */
    public const LEASE_LIABILITY_INTENSITY = 0.10;

    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from high-margin commercial B2B precision tooling. */
    public const COMMERCIAL_WEIGHT = 0.60;
    /** Baseline fraction of revenue derived from highly cyclical consumer retail scrap. */
    public const CONSUMER_WEIGHT = 0.40;

    // --- Revenue & Shock Physics ---
    /** Very insulated from typical manufacturing boom/bust. */
    public const REVENUE_VARIANCE_SCALAR = 0.20;
    /** Variable cost per unit of revenue of trade-brand tooling at list price, relative to the consumer line's. */
    public const COMMERCIAL_COST_INTENSITY = 0.85;
    /**
     * Variable cost per unit of revenue of the consumer line: the same unit cost sold under discount brands about 30%
     * below list (1/0.7 = 1.2/0.85), so the seconds earn the thinner margin.
     */
    public const CONSUMER_COST_INTENSITY = 1.20;

    // --- Tail Risk & Shock Events ---
    /** Negative z-score threshold indicating severe supply chain or shipping congestion. */
    public const SUPPLY_CHAIN_CONGESTION_Z_SCORE = -2.20;
    /** Variable margin penalty applied during expedited shipping for disrupted supply chains. */
    public const SUPPLY_CHAIN_CONGESTION_PENALTY = 0.05;
    /** Positive z-score threshold indicating a massive industrial/manufacturing super-cycle. */
    public const MANUFACTURING_SUPER_CYCLE_Z_SCORE = 2.40;
    /** Top-line commercial revenue multiplier for an industrial super-cycle. */
    public const MANUFACTURING_SUPER_CYCLE_MULT = 1.15;
    /** Commercial revenue a congestion quarter lets through: a tenth of shipments wait on the late parts. */
    public const SUPPLY_CHAIN_CONGESTION_REVENUE_MULT = 0.90;
    /** Commercial revenue noise as a share of the stream scalar: trade accounts on standing orders barely move. */
    public const COMMERCIAL_VARIANCE_RATIO = 0.10;
    /** Consumer revenue noise as a multiple of the stream scalar: promotional retail volume swings. */
    public const CONSUMER_VARIANCE_RATIO = 2.50;

    // --- Manufacturing PMI, Housing & PPI Transmission ---
    /** Sensitivity of commercial B2B precision tooling demand to manufacturing PMI shifts. */
    public const PMI_COMMERCIAL_SENSITIVITY = 0.40;
    /** Sensitivity of retail and prosumer tool sales to residential housing starts. */
    public const HOUSING_STARTS_SENSITIVITY = 0.35;
    /**
     * Prosumer volume per unit of confidence beyond what the output gap explains, before cyclicality (Lemmon &
     * Portniaguina 2006 residual; Carroll, Fuhrer & Wilcox 1994).
     */
    public const CONSUMER_SENTIMENT_SENSITIVITY = 0.50;
    /** Alloy and carbide supply contracts fix input prices for about a quarter. */
    public const INPUT_COST_LAG_YEARS = 0.25;

    // --- FX Exposure ---
    /**
     * Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Hand and power tools are
     * sourced and sold across borders against import-competing brands.
     */
    public const FX_REVENUE_EXPOSURE = 0.10;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.08 a year). */
    public const ROIC_REVERSION_SPEED = 0.08;
    /** Excess return over the required return the moat sustains (3pp). */
    public const MOAT_SPREAD = 0.03;

    // --- Seasonality ---
    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: holiday and year-end promotional volumes.
     */
    public const SEASONALITY_FACTORS = [0.92, 1.00, 0.98, 1.10];

    /**
     * The cycle reaches each stream once inside the sector physics (commercial on the gap and the PMI, consumer on
     * the gap, confidence and housing), so the root shift carries no demand term.
     */
    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /** The volume the plants are staffed to: the target-mix cycle shift of both streams. */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::CommercialWeight->value => self::COMMERCIAL_WEIGHT,
            ModelParam::ConsumerWeight->value   => self::CONSUMER_WEIGHT,
        ]);
        $commercialWeight = $params[ModelParam::CommercialWeight];
        $consumerWeight = $params[ModelParam::ConsumerWeight];
        $totalWeight = $commercialWeight + $consumerWeight;
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        $shifts = $this->resolveStreamMacroShifts($stock, $macroState);

        return (($commercialWeight * $shifts['commercial']) + ($consumerWeight * $shifts['consumer'])) / $totalWeight;
    }

    /**
     * Each stream's volume shift, the gap at the firm's cyclicality entering once: commercial tooling on the lagged
     * gap and the manufacturing PMI; prosumer sales on the gap, confidence net of the gap, and housing starts.
     *
     * @return array{commercial: float, consumer: float}
     */
    private function resolveStreamMacroShifts(Stock $stock, MacroStateDTO $macroState): array
    {
        $beta = $this->getOperatingCyclicality($stock);
        $fxShift = $this->resolveFxDemandShift($macroState);
        $pmiShift = MacroTransmission::calculatePmiDemandShift($macroState->manufacturingPmiEma, sensitivity: self::PMI_COMMERCIAL_SENSITIVITY);
        $housingShift = MacroTransmission::calculateHousingStartsShift($macroState->housingStartsIndexEma, sensitivity: self::HOUSING_STARTS_SENSITIVITY);

        return [
            'commercial' => ($this->resolveLaggedOutputGap($macroState) * $beta) + $pmiShift + $fxShift,
            'consumer'   => (($macroState->outputGapEma + ($macroState->sentimentResidual() * self::CONSUMER_SENTIMENT_SENSITIVITY)) * $beta) + $housingShift + $fxShift,
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::CommercialWeight->value => self::COMMERCIAL_WEIGHT,
            ModelParam::ConsumerWeight->value   => self::CONSUMER_WEIGHT,
        ]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'commercial' => $params[ModelParam::CommercialWeight],
            'consumer'   => $params[ModelParam::ConsumerWeight],
        ]);

        $commercialWeight = $activeWeights['commercial'];
        $consumerWeight   = $activeWeights['consumer'];

        // Commercial is highly sticky, Consumer is volatile
        $commercialZ = $streams->generateZ('commercial', 0.02);
        $consumerZ   = $streams->generateZ('consumer', 0.30);
        $eventZ      = $streams->generateExogenousZ('event', 0.10);

        $pricingPower = $this->resolvePricingPower($stock);

        $macroShifts = $this->resolveStreamMacroShifts($stock, $macroState);
        $commercialMacroVolumeShock = $macroShifts['commercial'];
        $consumerMacroVolumeShock = $macroShifts['consumer'];

        // Tail Risk Events
        $cycleMultiplier = 1.0;
        $eventType = null;
        $supplyChainPenalty = 0.0;

        if ($eventZ < self::SUPPLY_CHAIN_CONGESTION_Z_SCORE) {
            $supplyChainPenalty = self::SUPPLY_CHAIN_CONGESTION_PENALTY;
            $eventType = ShockEvent::COMPONENT_SHORTAGE;
            $cycleMultiplier = self::SUPPLY_CHAIN_CONGESTION_REVENUE_MULT;
        } elseif ($eventZ > self::MANUFACTURING_SUPER_CYCLE_Z_SCORE) {
            $cycleMultiplier = self::MANUFACTURING_SUPER_CYCLE_MULT;
            $eventType = ShockEvent::INDUSTRIAL_ORDER_SURGE;
        }

        $commercialShock = $commercialZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::COMMERCIAL_VARIANCE_RATIO);
        $consumerShock   = $consumerZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::CONSUMER_VARIANCE_RATIO);
        $commercialRevenue = max(0.0, $expectedRevenue * $commercialWeight * (1.0 + $commercialShock + $commercialMacroVolumeShock) * $cycleMultiplier);
        $consumerRevenue   = max(0.0, $expectedRevenue * $consumerWeight * (1.0 + $consumerShock + $consumerMacroVolumeShock));

        $streamRevenues = [
            'commercial' => $commercialRevenue,
            'consumer'   => $consumerRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- Structural Margin Blending ---
        // Trade tooling at list price costs less per unit of revenue than the seconds sold under discount brands; each
        // line's ratio is its intensity against the mix, so the expected mix runs at the engine's ratio and a shift
        // toward the consumer line raises the blend.
        $streamCostRatios = FirmEconomics::streamCostRatios(
            $realizedVariableMargin,
            ['commercial' => $commercialWeight, 'consumer' => $consumerWeight],
            ['commercial' => self::COMMERCIAL_COST_INTENSITY, 'consumer' => self::CONSUMER_COST_INTENSITY],
        );
        $actualVariableCosts = ($commercialRevenue * $streamCostRatios['commercial']) + ($consumerRevenue * $streamCostRatios['consumer']);

        // Input cost basket: alloys and carbide, wholesale components, energy and shop-floor payroll,
        // recovered in list prices at the firm's pricing power.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        $rawMargin = ($actualVariableCosts / max(1.0, $actualRevenue)) + $supplyChainPenalty + $inputCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    // --- Analyst Coverage ---
    // B2B precision tooling orders are opaque and hard for retail analysts to track.
    /** Share of routine shocks analysts see (15%). */
    public const BASE_COVERAGE_VISIBILITY = 0.15;
    /** Standard deviation of the consensus forecast error (0.05). */
    public const BASE_COVERAGE_ERROR = 0.05;
    /** Floor on routine visibility (0%). */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.00;
    /** Visibility of a quarter with an announced event (50%). */
    public const EVENT_BASE_VISIBILITY = 0.50;
    /** Floor on visibility in an announced-event quarter (20%). */
    public const EVENT_MIN_VISIBILITY = 0.20;

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'consumer_sentiment_index_ema',
            'exchange_rate_index_ema',
            'housing_starts_index_ema',
            'import_price_level',
            'industrial_metals_index_ema',
            'manufacturing_pmi_ema',
            'output_gap_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
