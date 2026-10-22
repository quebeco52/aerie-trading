<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Computer Hardware (Supercomputers, PCs, Peripherals).
 *
 * Financial Physics:
 * - Enterprise Model: High-margin B2B hardware sales. Less sensitive to macro output gap.
 * - Consumer Model: Highly cyclical, deeply sensitive to consumer discretionary spending.
 * - Inventory Obsolescence: Physical hardware deprecates quickly, creating higher baseline variance.
 */
class ComputerHardwareBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Refresh cycles are
     * deferred in downturns; devices substitute readily.
     */
    public const OPERATING_CYCLICALITY = 1.30;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 1.00;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::COMPUTER_HARDWARE;
    /**
     * Boxes are specified on a common component bill and bid against near-identical rivals; component cost moves reach
     * street prices, but little else does.
     */
    public const PRICING_POWER_INDEX = 0.45;

    // --- Inventory Cycle ---
    /**
     * Order sensitivity to the economy-wide inventory-to-sales gap (Metzler cycle): overhangs trigger destocking,
     * shortfalls restocking. Channel inventory whipsaws PC and server shipments hardest.
     */
    public const INVENTORY_CYCLE_SENSITIVITY = 1.00;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: back-to-school and holiday device cycles.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.92, 0.97, 1.03, 1.08];
    }

    // --- Balance Sheet Realism ---
    /**
     * Stock-based compensation (ASC 718) as a share of revenue: Computers/Peripherals, 2.47% of revenue, 36 firms
     * (Damodaran, Employee data by industry, US, January 2026).
     */
    public const STOCK_COMPENSATION_INTENSITY = 0.0247;

    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from high-margin enterprise B2B sales. */
    public const ENTERPRISE_WEIGHT = 0.60;
    /** Baseline fraction of revenue derived from cyclical consumer retail hardware. */
    public const CONSUMER_WEIGHT   = 0.40;

    // --- Pricing Power & Macro Physics ---
    /** Base of the demand's sensitivity to the lagged gap before pricing power: higher, for its consumer dependency. */
    public const CYCLE_SENSITIVITY_BASE = 0.80;

    // --- Revenue & Shock Physics ---
    /** Inventory obsolescence increases variance. */
    public const REVENUE_VARIANCE_SCALAR = 0.35;
    /** Enterprise revenue noise as a share of the stream scalar: B2B contracts are sticky. */
    public const ENTERPRISE_VARIANCE_RATIO = 0.50;
    /** Consumer revenue noise as a multiple of the stream scalar: upgrade cycles boom and bust. */
    public const CONSUMER_VARIANCE_RATIO = 1.50;
    /**
     * Enterprise hardware's margin premium over consumer devices, as a share of revenue (Dell FY2024 segments: ISG
     * ~12.7% vs CSG ~6.4% operating margin).
     */
    public const ENTERPRISE_MARGIN_PREMIUM = 0.063;

    // --- Tail Risk & Shock Events ---
    /**
     * Latent supply-stress threshold for a component shortage, in standard deviations: the firm's own draw plus the
     * economy's supply-chain pressure at the comm-equipment loading, so shortages cluster when the whole chain is
     * stretched.
     */
    public const SEMICONDUCTOR_SHORTAGE_Z_SCORE = -2.20;
    /** Enterprise shipments a component shortage lets through: allocation favours the high-margin servers. */
    public const SHORTAGE_ENTERPRISE_MULT = 0.85;
    /** Consumer shipments a component shortage lets through: PCs and peripherals are allocated last. */
    public const SHORTAGE_CONSUMER_MULT = 0.80;
    /**
     * Variable margin cost per standard deviation of supply-chain pressure about normal, both ways (expediting, spot
     * chips, air freight): the comm-equipment figure, as both buy the same parts through the same contract
     * manufacturers.
     */
    public const GSCPI_MARGIN_PENALTY_SCALAR = CommunicationEquipmentBusinessModel::GSCPI_MARGIN_PENALTY_SCALAR;
    /** Positive z-score threshold indicating a massive enterprise/AI hardware super-cycle. */
    public const AI_SUPER_CYCLE_Z_SCORE = 2.40;
    /** Top-line enterprise revenue multiplier for an AI/data center super-cycle. */
    public const AI_SUPER_CYCLE_MULT = 1.20;

    // --- Trade Balance & PPI Transmission ---
    /** Export volume per unit of the foreign bloc's output gap: the customers abroad. */
    public const FOREIGN_DEMAND_SENSITIVITY = 1.00;
    /** Share of the trade-flow shift each book carries: enterprise and consumer systems split it evenly. */
    public const TRADE_FLOW_STREAM_SHARE = 0.50;
    /**
     * Component supply agreements fix bill-of-materials prices for about a quarter before spot moves reach the line.
     */
    public const INPUT_COST_LAG_YEARS = 0.25;

    // --- FX Exposure ---
    /**
     * Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Systems are assembled
     * offshore and bid against imports on a common component bill.
     */
    public const FX_REVENUE_EXPOSURE = 0.10;
    /**
     * Consumer PCs are a landed import competing on retail shelf price, so the consumer book reprices with the currency
     * harder than the enterprise book.
     */
    public const CONSUMER_FX_REVENUE_EXPOSURE = 0.15;

    public function getReversionSpeed(): float
    {
        return 0.25;
    }
    public function getMoatSpread(): float
    {
        return 0.02;
    }

    // --- Secular Demand ---
    /**
     * Spending on personal computers and peripherals plus business investment in computers and peripheral equipment as
     * a share of US nominal GDP in 1997 (BEA NIPA Table 2.4.5, personal consumption expenditures and Table 5.3.5).
     */
    public const SECULAR_SHARE_1997 = 0.0125;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0082;

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Set to 0.0 to prevent double-dipping, as macro volume shocks
        // are handled discretely per-stream in calculateSectorPhysics.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /**
     * The volume the assembler is staffed to: both books' unit shifts at target weights. Every macro term in
     * them is shipments (the cycle, currency competitiveness, trade flows, channel restocking); component
     * prices reach the line through the input cost basket instead.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::EnterpriseWeight->value => self::ENTERPRISE_WEIGHT,
            ModelParam::ConsumerWeight->value   => self::CONSUMER_WEIGHT,
        ]);
        $enterpriseWeight = $params[ModelParam::EnterpriseWeight];
        $consumerWeight = $params[ModelParam::ConsumerWeight];
        $totalWeight = $enterpriseWeight + $consumerWeight;
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        $shifts = $this->resolveStreamVolumeShifts($stock, $macroState);

        return (($enterpriseWeight * $shifts['enterprise_hardware']) + ($consumerWeight * $shifts['consumer_hardware'])) / $totalWeight;
    }

    /**
     * Each book's macro unit shift: enterprise on the output gap, consumer on sentiment, both at the firm's
     * cyclicality, plus the currency, an even share of the trade-flow term and the Metzler inventory cycle.
     *
     * @return array{enterprise_hardware: float, consumer_hardware: float}
     */
    private function resolveStreamVolumeShifts(Stock $stock, MacroStateDTO $macroState): array
    {
        $cycleSensitivity = (self::CYCLE_SENSITIVITY_BASE + $this->resolvePricingPower($stock)) * $this->getOperatingCyclicality($stock);
        $tradeShift = MacroTransmission::calculateForeignDemandShift($macroState->foreignOutputGapEma, sensitivity: self::FOREIGN_DEMAND_SENSITIVITY);
        // Metzler inventory cycle: a channel overhang (positive gap) means distributors destock before reordering.
        $commonShift = ($tradeShift * self::TRADE_FLOW_STREAM_SHARE) - ($macroState->inventoryStockGapEma * self::INVENTORY_CYCLE_SENSITIVITY);

        return [
            'enterprise_hardware' => ($macroState->outputGapEma * $cycleSensitivity) + $this->resolveFxDemandShift($macroState) + $commonShift,
            'consumer_hardware'   => ($macroState->sentimentDeviation() * $cycleSensitivity) + $this->resolveFxDemandShift($macroState, self::CONSUMER_FX_REVENUE_EXPOSURE) + $commonShift,
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::EnterpriseWeight->value => self::ENTERPRISE_WEIGHT,
            ModelParam::ConsumerWeight->value   => self::CONSUMER_WEIGHT,
        ]);

        $enterpriseWeight = $params[ModelParam::EnterpriseWeight];
        $consumerWeight   = $params[ModelParam::ConsumerWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'enterprise_hardware' => $params[ModelParam::EnterpriseWeight],
            'consumer_hardware'   => $params[ModelParam::ConsumerWeight],
        ]);

        $enterpriseWeight = $activeWeights['enterprise_hardware'];
        $consumerWeight   = $activeWeights['consumer_hardware'];

        // Consumer hardware is volatile, enterprise hardware is stickier
        $enterpriseZ = $streams->generateZ('enterprise_hardware', 0.15);
        $consumerZ   = $streams->generateZ('consumer_hardware', 0.05);
        $eventZ      = $streams->generateExogenousZ('event', 0.10);

        $pricingPower = $this->resolvePricingPower($stock);
        $volumeShifts = $this->resolveStreamVolumeShifts($stock, $macroState);
        $enterpriseMacroVolumeShock = $volumeShifts['enterprise_hardware'];
        $consumerMacroVolumeShock = $volumeShifts['consumer_hardware'];

        // Tail Risk Events
        $enterpriseMultiplier = 1.0;
        $consumerMultiplier = 1.0;
        $eventType = null;
        // The expediting cost is the GSCPI margin drag below, so the shortage itself only rations volume.
        $shortagePenalty = ($macroState->supplyChainPressureIndexEma - MacroConstants::GSCPI_BASELINE) * self::GSCPI_MARGIN_PENALTY_SCALAR;

        if ($eventZ < self::SEMICONDUCTOR_SHORTAGE_Z_SCORE + (CommunicationEquipmentBusinessModel::SUPPLY_CHAIN_STRESS_LOADING * $macroState->supplyChainPressureIndexEma)) {
            $eventType = ShockEvent::COMPONENT_SHORTAGE;
            $enterpriseMultiplier = self::SHORTAGE_ENTERPRISE_MULT;
            $consumerMultiplier = self::SHORTAGE_CONSUMER_MULT;
        } elseif ($eventZ > self::AI_SUPER_CYCLE_Z_SCORE) {
            $enterpriseMultiplier = self::AI_SUPER_CYCLE_MULT;
            $eventType = ShockEvent::DATA_CENTRE_ORDER_SURGE;
        }

        $enterpriseShock = $enterpriseZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::ENTERPRISE_VARIANCE_RATIO);
        $consumerShock   = $consumerZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::CONSUMER_VARIANCE_RATIO);
        $enterpriseRevenue = max(0.0, $expectedRevenue * $enterpriseWeight * (1.0 + $enterpriseShock + $enterpriseMacroVolumeShock) * $enterpriseMultiplier);
        $consumerRevenue   = max(0.0, $expectedRevenue * $consumerWeight * (1.0 + $consumerShock + $consumerMacroVolumeShock) * $consumerMultiplier);

        $streamRevenues = [
            'enterprise_hardware' => $enterpriseRevenue,
            'consumer_hardware'   => $consumerRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Structural Margin Blending ---
        // Enterprise B2B hardware runs a lower variable cost ratio than consumer devices by the segment margin
        // premium; both are struck around the engine's ratio so the baseline mix reproduces it exactly.
        $enterpriseVariableCostRatio = max(0.0, $realizedVariableMargin - ($consumerWeight * self::ENTERPRISE_MARGIN_PREMIUM));
        $consumerVariableCostRatio = $consumerWeight > 0.0
            ? ($realizedVariableMargin - ($enterpriseWeight * $enterpriseVariableCostRatio)) / $consumerWeight
            : $realizedVariableMargin;

        // Apply the distinct ratios to actual shocked revenues
        $actualVariableCosts = ($enterpriseRevenue * $enterpriseVariableCostRatio) + ($consumerRevenue * $consumerVariableCostRatio);

        // Input cost basket: components and wholesale goods, metals, freight and assembly payroll, recovered
        // in list prices at the firm's pricing power.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;
        $rawMargin = $effectiveMargin + $shortagePenalty + $inputCostDrag;
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
    // Consumer tech supply chains are heavily scrutinized and scraped.
    /** Share of routine shocks analysts see (35%). */
    public const BASE_COVERAGE_VISIBILITY = 0.35;
    /** Standard deviation of the consensus forecast error (0.05). */
    public const BASE_COVERAGE_ERROR = 0.05;
    /** Floor on routine visibility (10%). */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.10;
    /** Visibility of a quarter with an announced event (90%). */
    public const EVENT_BASE_VISIBILITY = 0.90;
    /** Floor on visibility in an announced-event quarter (50%). */
    public const EVENT_MIN_VISIBILITY = 0.50;

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
            'foreign_output_gap_ema',
            'import_price_level',
            'inventory_stock_gap_ema',
            'output_gap_ema',
            'supply_chain_pressure_index_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
