<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorCoverageProfile;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Communication Equipment (radio access networks, core routing, standard-essential patents).
 *
 * Financial Physics:
 * - Carrier Capex Cycle: the volume engine sells multi-year network deployment contracts to telecom operators.
 *   Orders follow carrier capex budgets (lagged output gap, capital-stock overhang) and are recognized through a
 *   multi-quarter backlog (ASC 606 percentage of completion), so the cycle reaches revenue with a delay.
 * - Generational Rollout Waves: a new wireless generation is a Markov regime (Hamilton 1989): rare onset, a
 *   multi-year build, then a digestion phase in which orders fall back to replacement levels.
 * - SEP Licensing Tollbooth: royalties on every device shipped under the standard, near-pure margin and tied
 *   to handset shipments rather than carrier budgets. A major licensee can withhold royalties for several
 *   quarters (Qualcomm/Apple 2017-19); settlement recovers most of the arrears as a catch-up payment.
 * - Consumer Terminals: broadband gateways and IoT modules ride the consumer channel and the inventory cycle.
 * - Component Supply: a fab shortage slips deliveries into the backlog and taxes margin with expedited sourcing.
 */
class CommunicationEquipmentBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Carrier capex is cyclical but backlog- and royalty-cushioned. */
    public const OPERATING_CYCLICALITY = 1.10;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. Carriers locked into proprietary baseband firmware rarely switch vendors on price. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.40;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. A three-vendor oligopoly swaps share on each tender. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). Radios are a bill of semiconductors, RF metals and contract assembly. */
    public const INPUT_COST_EXPOSURES = ['ppi' => 0.35, 'metals' => 0.06, 'freight' => 0.04, 'labor' => 0.20, 'energy' => 0.02];
    /** A concentrated vendor base under multi-year frame agreements recovers most component moves at the annual price review. */
    public const PRICING_POWER_INDEX = 0.65;
    /** Frame agreements reprice once a year. */
    public const PRICE_PASS_THROUGH_LAG_YEARS = 1.00;
    /** Component supply agreements fix bill-of-materials prices for about a quarter before spot moves reach the line. */
    public const INPUT_COST_LAG_YEARS = 0.25;
    /** Recoverable component cost moves reach carrier prices only at the annual frame-agreement review. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 1.00;

    // --- Demand Transmission Lag ---
    /** Years for a move in the output gap to reach the order book. Carrier capex budgets are set annually and rollouts contracted against them. */
    public const DEMAND_LAG_YEARS = 1.00;

    // --- FX Exposure ---
    /** Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Network contracts are tendered globally against foreign vendors. */
    public const FX_REVENUE_EXPOSURE = 0.25;
    /** Consumer gateways are a landed import competing on retail shelf price. */
    public const CONSUMER_FX_REVENUE_EXPOSURE = 0.15;
    /** Sensitivity of network equipment export flows to merchandise trade balance shifts. */
    public const TRADE_BALANCE_SENSITIVITY = 1.00;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Radio and baseband R&D engineering dominates overhead; assembly is contract-manufactured. */
    public const FIXED_COST_LABOR_SHARE = 0.65;

    // --- Balance Sheet Realism ---
    /** Stock-based compensation as a fraction of revenue (ASC 718): non-cash, added back to FCF, settled in new shares. */
    public const STOCK_COMPENSATION_INTENSITY = 0.02;

    // --- Revenue Mix ---
    /** Baseline fraction of revenue from carrier radio access and core network deployment contracts. */
    public const ENTERPRISE_WEIGHT = 0.75;
    /** Baseline fraction of revenue from standard-essential patent royalties. */
    public const PATENT_LICENSING_WEIGHT = 0.10;
    /** Baseline fraction of revenue from consumer broadband terminals and IoT modules. */
    public const CONSUMER_WEIGHT = 0.15;

    // --- Stream Margin Architecture ---
    /** Structural variable cost ratio of patent royalties (collection and pool administration only). */
    public const LICENSING_VARIABLE_COST_RATIO = 0.05;
    /** Structural variable cost ratio of consumer terminal hardware (commodity gateways at retail). */
    public const CONSUMER_VARIABLE_COST_RATIO = 0.70;

    // --- Revenue & Shock Physics ---
    /** Variance scalar applied to baseline volatility for network order shocks (tender wins and losses). */
    public const REVENUE_VARIANCE_SCALAR = 0.30;
    /** Royalty variance relative to network orders: device shipments are far steadier than carrier tenders. */
    public const LICENSING_VARIANCE_RATIO = 0.30;
    /** Consumer terminal variance relative to network orders: retail upgrade cycles boom and bust. */
    public const CONSUMER_VARIANCE_RATIO = 1.50;

    // --- Carrier Capex Cycle & Backlog ---
    /** Sensitivity of carrier network orders to the lagged output gap (accelerator: capex follows output). */
    public const CARRIER_CAPEX_GDP_SENSITIVITY = 1.20;
    /** Sensitivity of network orders to the economy-wide capital-stock overhang: over-built carriers digest before they re-order. */
    public const CAPITAL_OVERHANG_SCALAR = 0.25;
    /** Fraction of the network backlog (opening backlog plus new orders) delivered and recognized each quarter (~2.3 quarters of coverage). */
    public const NETWORK_BACKLOG_BURN_RATE = 0.30;
    /** Regime key for a generational wireless rollout wave (two-state Markov). */
    public const REGIME_ROLLOUT_WAVE = 'rollout_wave';
    /** Quarterly probability a new generational rollout begins while carriers are in digestion (~8-year gap). */
    public const ROLLOUT_WAVE_ONSET_HAZARD = 0.03;
    /** Quarterly probability the rollout wave ends (~3-year expected build). */
    public const ROLLOUT_WAVE_EXIT_HAZARD = 0.08;
    /** Network order intake uplift (relative) while a generational rollout is under way. */
    public const ROLLOUT_WAVE_ORDER_UPLIFT = 0.20;

    // --- Standard-Essential Patent Licensing ---
    /** Sensitivity of royalty-bearing device shipments to consumer sentiment (handset upgrades are deferred, not cancelled). */
    public const DEVICE_SHIPMENT_SENTIMENT_SENSITIVITY = 0.50;
    /** Regime key for a major licensee withholding royalties pending renegotiation (two-state Markov). */
    public const REGIME_ROYALTY_DISPUTE = 'royalty_dispute';
    /** Negative litigation z-score threshold at which a major licensee stops paying and litigates the licence terms. */
    public const ROYALTY_DISPUTE_Z_SCORE = -2.20;
    /** Quarterly probability the dispute settles (~5 quarter expected duration). */
    public const ROYALTY_DISPUTE_EXIT_HAZARD = 0.20;
    /** Share of royalty revenue withheld while the dispute runs (one flagship licensee's share of the pool). */
    public const ROYALTY_DISPUTE_WITHHELD_SHARE = 0.30;
    /** Share of the withheld arrears recovered as a catch-up payment on settlement; the rest is the settlement discount. */
    public const ROYALTY_SETTLEMENT_RECOVERY_SHARE = 0.75;
    /** Persisted arrears withheld by disputing licensees, in quarters of expected royalty revenue. */
    public const STATE_WITHHELD_ROYALTIES = 'state:withheld_royalties';
    /** Ceiling on accumulated arrears, in quarters of expected royalty revenue. */
    public const MAX_WITHHELD_ROYALTY_QUARTERS = 4.0;

    // --- Component Supply ---
    /** Negative z-score threshold indicating a severe semiconductor fab shortage. */
    public const COMPONENT_SHORTAGE_Z_SCORE = -2.20;
    /** Variable margin penalty applied during expedited component sourcing. */
    public const COMPONENT_SHORTAGE_PENALTY = 0.06;
    /** Fraction of the normal backlog burn delivered during a shortage; the rest slips into next quarter's backlog. */
    public const COMPONENT_SHORTAGE_DELIVERY_SLIP = 0.75;
    /** Consumer terminal volume multiplier during a shortage (retail gateways lose the allocation fight). */
    public const COMPONENT_SHORTAGE_CONSUMER_MULT = 0.85;
    /** Variable margin penalty per standard deviation of global supply chain friction (GSCPI). */
    public const GSCPI_MARGIN_PENALTY_SCALAR = 0.012;
    /** Order sensitivity of consumer terminals to the economy-wide inventory-to-sales gap (Metzler cycle): distributors destock before reordering. */
    public const INVENTORY_CYCLE_SENSITIVITY = 0.60;

    // --- Continuous Elasticity ---
    /** Variable margin sensitivity to network deployments pulling in high-margin optimization software and support attach. */
    public const SOFTWARE_ATTACH_ELASTICITY = 0.010;

    // --- Asset Depreciation & Reinvestment ---
    /** Quarterly margin decay rate per unit of underinvestment in radio and baseband R&D: missing a generation is fatal. */
    public const RADIO_RND_DECAY_RATE = 0.025;
    /** Quarterly margin gain scalar per unit of next-generation platform overinvestment. */
    public const GENERATIONAL_PLATFORM_GAIN_RATE = 0.012;
    /** Structural minimum operating margin floor for a vendor a generation behind. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.05;
    /** Structural maximum operating margin ceiling for a patent-backed network incumbent. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.30;

    public function getReversionSpeed(): float { return 0.20; }
    public function getMoatSpread(): float { return 0.025; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.15; }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.03;
    }

    public function getCapexCyclicality(): float
    {
        return 1.20;
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.60, 'revenue_weight' => 0.40];
    }

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: carrier capex budget flush in Q4.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.91, 0.99, 0.98, 1.12];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Zeroed to prevent double-dipping: the cycle reaches each stream discretely in calculateSectorPhysics
        // (carrier orders through the lagged gap and backlog, royalties through device shipments).
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::EnterpriseWeight->value       => self::ENTERPRISE_WEIGHT,
            ModelParam::PatentLicensingWeight->value  => self::PATENT_LICENSING_WEIGHT,
            ModelParam::ConsumerWeight->value         => self::CONSUMER_WEIGHT,
        ]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta = $this->getOperatingCyclicality($stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'carrier_networks'   => $params[ModelParam::EnterpriseWeight],
            'sep_licensing'      => $params[ModelParam::PatentLicensingWeight],
            'consumer_terminals' => $params[ModelParam::ConsumerWeight],
        ]);

        $networkWeight   = $activeWeights['carrier_networks'];
        $licensingWeight = $activeWeights['sep_licensing'];
        $consumerWeight  = $activeWeights['consumer_terminals'];

        // Tender outcomes persist for a few quarters; royalties are a slow-moving device base; retail is noise.
        $networkZ    = $streams->generateZ('carrier_networks', 0.25);
        $licensingZ  = $streams->generateZ('sep_licensing', 0.50);
        $consumerZ   = $streams->generateZ('consumer_terminals', 0.10);
        $eventZ      = $streams->generateExogenousZ('event', 0.10);
        $litigationZ = $streams->generateExogenousZ('litigation', 0.10);

        $pricingPower = $this->resolvePricingPower($stock);
        $macroSensitivityMultiplier = self::MIN_BETA_PRICING_POWER_FLOOR + $pricingPower;

        // --- Carrier Capex Cycle ---
        // Accelerator: carrier budgets follow the output gap with a year's lag; an economy-wide capital overhang
        // means operators digest what they built before ordering more. Tenders are global, so the currency and
        // merchandise trade flows move the order book too.
        $overhangDrag = $macroState->capitalStockOverhangEma * self::CAPITAL_OVERHANG_SCALAR;
        $tradeShift = MathUtility::calculateTradeBalanceShift($macroState->tradeBalanceToGdpEma, sensitivity: self::TRADE_BALANCE_SENSITIVITY);
        $carrierCapexShift = ($this->resolveLaggedOutputGap($stock, $macroState) * self::CARRIER_CAPEX_GDP_SENSITIVITY * $beta)
            - $overhangDrag
            + $this->resolveFxDemandShift($macroState)
            + $tradeShift;

        // --- Generational Rollout Wave (two-state Markov) ---
        $waveElapsed = $streams->evolveRegime(self::REGIME_ROLLOUT_WAVE, self::ROLLOUT_WAVE_ONSET_HAZARD, self::ROLLOUT_WAVE_EXIT_HAZARD);
        $eventType = $waveElapsed === 1 ? ShockEvent::NETWORK_ROLLOUT_WAVE : null;
        $waveUplift = $waveElapsed > 0 ? self::ROLLOUT_WAVE_ORDER_UPLIFT : 0.0;

        // --- SEP Royalty Dispute (two-state Markov, Z-triggered onset) ---
        // A flagship licensee stops paying and litigates; arrears accrue for the duration and most of them come
        // back as a catch-up payment when the licence is re-signed.
        $expectedLicensingRevenue = $expectedRevenue * $licensingWeight;
        $wasInDispute = $streams->getPersistedState(StreamContext::REGIME_STATE_PREFIX . self::REGIME_ROYALTY_DISPUTE, 0.0) > 0.0;
        $disputeElapsed = $streams->evolveRegime(self::REGIME_ROYALTY_DISPUTE, 0.0, self::ROYALTY_DISPUTE_EXIT_HAZARD);
        $withheldQuarters = max(0.0, $streams->getPersistedState(self::STATE_WITHHELD_ROYALTIES, 0.0));
        $settlementCatchUp = 0.0;

        if ($wasInDispute && $disputeElapsed === 0) {
            $settlementCatchUp = $withheldQuarters * self::ROYALTY_SETTLEMENT_RECOVERY_SHARE * $expectedLicensingRevenue;
            $withheldQuarters = 0.0;
            $eventType = ShockEvent::SEP_ROYALTY_SETTLEMENT;
        } elseif ($litigationZ < self::ROYALTY_DISPUTE_Z_SCORE && $disputeElapsed === 0) {
            $disputeElapsed = $streams->startRegime(self::REGIME_ROYALTY_DISPUTE);
            $eventType = ShockEvent::SEP_ROYALTY_DISPUTE;
        }
        $inDispute = $disputeElapsed > 0;

        // --- Component Shortage ---
        $shortagePenalty = 0.0;
        $burnRate = self::NETWORK_BACKLOG_BURN_RATE;
        $consumerMultiplier = 1.0;

        if ($eventZ < self::COMPONENT_SHORTAGE_Z_SCORE) {
            $shortagePenalty = self::COMPONENT_SHORTAGE_PENALTY;
            $burnRate *= self::COMPONENT_SHORTAGE_DELIVERY_SLIP;
            $consumerMultiplier = self::COMPONENT_SHORTAGE_CONSUMER_MULT;
            $eventType = ShockEvent::SEMICONDUCTOR_FAB_SHORTAGE;
        }

        // --- Stream Revenues ---
        // Network orders enter a multi-quarter backlog and are recognized at the burn rate (percentage of completion).
        $networkOrderMultiplier = max(0.0, 1.0 + ($networkZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $carrierCapexShift + $waveUplift);
        $networkBook = $streams->recognizeBacklog('carrier_networks', $expectedRevenue * $networkWeight, $networkOrderMultiplier, $burnRate);
        $networkRevenue = $networkBook['revenue'];

        // Royalties are per device shipped under the standard: handset upgrades follow consumer sentiment.
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $deviceShipmentShift = $sentimentShift * self::DEVICE_SHIPMENT_SENTIMENT_SENSITIVITY;
        $licensingRevenue = max(0.0, $expectedLicensingRevenue * (1.0 + ($licensingZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::LICENSING_VARIANCE_RATIO)) + $deviceShipmentShift));
        if ($inDispute) {
            $withheld = $licensingRevenue * self::ROYALTY_DISPUTE_WITHHELD_SHARE;
            $licensingRevenue -= $withheld;
            $withheldQuarters = min(self::MAX_WITHHELD_ROYALTY_QUARTERS, $withheldQuarters + ($withheld / max(1.0, $expectedLicensingRevenue)));
        }
        $streams->registerState(self::STATE_WITHHELD_ROYALTIES, $withheldQuarters);
        $licensingRevenue += $settlementCatchUp;

        // Consumer terminals ride sentiment, the currency and the distributor inventory cycle.
        $inventoryCycleShift = -$macroState->inventoryStockGapEma * self::INVENTORY_CYCLE_SENSITIVITY;
        $consumerShift = ($sentimentShift * $macroSensitivityMultiplier * $beta)
            + $this->resolveFxDemandShift($macroState, self::CONSUMER_FX_REVENUE_EXPOSURE)
            + $inventoryCycleShift;
        $consumerRevenue = max(0.0, $expectedRevenue * $consumerWeight * (1.0 + ($consumerZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::CONSUMER_VARIANCE_RATIO)) + $consumerShift) * $consumerMultiplier);

        $streamRevenues = [
            'carrier_networks'   => $networkRevenue,
            'sep_licensing'      => $licensingRevenue,
            'consumer_terminals' => $consumerRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Structural Margin Blending ---
        // Royalties and commodity gateways carry fixed structural cost ratios; the network book takes whatever
        // residual reproduces the engine's target cost ratio at baseline, so mix shifts move margin honestly.
        $expectedNetworkRevenue  = $expectedRevenue * $networkWeight;
        $expectedConsumerRevenue = $expectedRevenue * $consumerWeight;

        $targetTotalCosts = $expectedRevenue * $realizedVariableMargin;
        $fixedStreamCosts = ($expectedLicensingRevenue * self::LICENSING_VARIABLE_COST_RATIO) + ($expectedConsumerRevenue * self::CONSUMER_VARIABLE_COST_RATIO);
        $fixedStreamScale = ($fixedStreamCosts > $targetTotalCosts && $fixedStreamCosts > 0.0) ? ($targetTotalCosts / $fixedStreamCosts) : 1.0;
        $licensingVariableCostRatio = self::LICENSING_VARIABLE_COST_RATIO * $fixedStreamScale;
        $consumerVariableCostRatio  = self::CONSUMER_VARIABLE_COST_RATIO * $fixedStreamScale;

        $networkBaselineCosts = max(0.0, $targetTotalCosts - ($fixedStreamCosts * $fixedStreamScale));
        $networkVariableCostRatio = $expectedNetworkRevenue > 0.0 ? ($networkBaselineCosts / $expectedNetworkRevenue) : $realizedVariableMargin;

        $actualVariableCosts = ($networkRevenue * $networkVariableCostRatio)
            + ($licensingRevenue * $licensingVariableCostRatio)
            + ($consumerRevenue * $consumerVariableCostRatio);

        // Semiconductors, RF metals, freight and contract assembly payroll, recovered at the annual frame review.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);
        $gscpiCostDrag = max(0.0, $macroState->supplyChainPressureIndexEma) * self::GSCPI_MARGIN_PENALTY_SCALAR;

        // Network deployments pull high-margin optimization software and support attach with them.
        $attachShift = -self::SOFTWARE_ATTACH_ELASTICITY * $networkZ * $networkWeight;

        $effectiveMargin = $actualRevenue > 0.0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;
        $rawMargin = $effectiveMargin + $shortagePenalty + $inputCostDrag + $gscpiCostDrag + $attachShift;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock is whichever stream deviated the most, overridden by tail events (supply or litigation).
        $primaryShockZ = $streams->resolveDominantShockZ(
            [$networkZ, $licensingZ, $consumerZ],
            abs($litigationZ) > abs($eventZ) ? $litigationZ : $eventZ
        );

        $observableShockZ = $primaryShockZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: [
                'book_to_bill'              => $networkBook['book_to_bill'],
                'backlog_quarters'          => $networkBook['backlog_quarters'],
                'rollout_wave_quarters'     => (float) $waveElapsed,
                'withheld_royalty_quarters' => $withheldQuarters,
            ],
        );
    }

    public function getCoverageProfile(Stock $stock): SectorCoverageProfile
    {
        // Carrier capex guidance is public and vendor tenders are reported; royalty disputes are litigated in the open.
        return new SectorCoverageProfile(
            baseVisibility: 0.30,
            errorStdDev: 0.05,
            minVisibility: 0.10,
            eventBaseVisibility: 0.85,
            eventMinVisibility: 0.50
        );
    }

    /** Falling a wireless generation behind erodes margin toward the sector floor. */
    public function getDepreciationDecayRate(): float
    {
        return self::RADIO_RND_DECAY_RATE;
    }

    /** Leading the next generational platform compounds margin toward the sector ceiling. */
    public function getModernizationGainRate(): float
    {
        return self::GENERATIONAL_PLATFORM_GAIN_RATE;
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
            'capital_stock_overhang_ema',
            'consumer_sentiment_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'freight_rate_index_ema',
            'industrial_metals_index_ema',
            'inventory_stock_gap_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'supply_chain_pressure_index_ema',
            'tips_breakeven_ema',
            'trade_balance_to_gdp_ema',
            'wage_growth_ema',
        ];
    }
}
