<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\Aggregate\OpenEconomy;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for container terminal operators (Marine Ports & Services).
 *
 * Financial Physics:
 * - Throughput: revenue is boxes lifted times the fee per box. Gateway boxes are the District's own goods trade
 *   (imports follow domestic demand, exports the mainland's, both the currency and any tariff, at the macro's own
 *   trade elasticities); relayed boxes follow world trade and leave for rival hubs when the currency makes the
 *   port dear. Freight rates are the shipping lines' price, not the terminal's.
 * - Concession pricing: the concession indexes gateway fees to CPI once a year; relay and yard fees are
 *   negotiated against rival hubs and recover what the hub's pricing power allows.
 * - Operating leverage: cranes, quays and crews on shift are fixed costs; the royalty and power scale with boxes.
 * - Dwell: congested supply chains keep boxes in the yards past their free days, and the storage they pay is
 *   earned on space already built, so it is price, not volume.
 * - Dock strikes: the cranes stop while fixed costs run. Lines divert their relays to rival hubs for good; the
 *   District's own boxes wait and are cleared once the strike is settled.
 */
class PortTerminalBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes to the macro cycle: 1.0 carries trade at the macro's own trade elasticities, which the
     * streams apply.
     */
    public const OPERATING_CYCLICALITY = 1.00;
    /**
     * Own-price elasticity of demand: a terminal fee is a few per cent of a box's landed cost, so volume barely answers
     * it.
     */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.20;
    /**
     * Share of an idiosyncratic revenue gain taken from same-industry peers: relayed boxes are won from rival hubs
     * abroad, gateway boxes from nobody.
     */
    public const INDUSTRY_SUBSTITUTABILITY = 0.30;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, labour included, measured from the BEA input-output accounts
     * with supply-chain content.
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::PORT_TERMINAL;
    /**
     * Concession tariffs are indexed to consumer prices, so selling prices track CPI inflation rather than breakevens.
     */
    public const PRICING_INFLATION_BASIS = 'inflation_ema';
    /** Tariff schedules reprice once a year. */
    public const PRICE_PASS_THROUGH_LAG_YEARS = 1.00;
    /**
     * Pricing power on negotiated relay and yard fees: shipping alliances can move a service to a rival hub, so the hub
     * recovers three quarters of inflation.
     */
    public const PRICING_POWER_INDEX = 0.25;
    /** Elasticity of gateway fees to CPI under the concession's annual indexation: inflation recovered in full. */
    public const CONCESSION_FEE_INDEXATION = 1.00;

    // --- Balance Sheet Realism ---
    /**
     * Capitalised operating lease liabilities as a fraction of annual revenue: the concession's fixed rent, about half
     * a year's revenue (ICTSI's concession rights payable, 2019-2023).
     */
    public const LEASE_LIABILITY_INTENSITY = 0.50;
    /** Working capital over revenue: the lines settle their terminal bills within about a month. */
    public const WORKING_CAPITAL_INTENSITY = 0.08;
    /**
     * Share of construction in progress commissioned each quarter: a quay and its cranes take about two years to build.
     */
    public const CAPEX_COMPLETION_RATE = 0.125;

    // --- Analyst Visibility & Error ---
    /**
     * The Freight Authority publishes monthly box counts, so most of a throughput surprise is known before the report.
     */
    public const BASE_COVERAGE_VISIBILITY = 0.60;
    /** Standard deviation of analysts' revenue error: box counts leave little to guess beyond the fee mix. */
    public const BASE_COVERAGE_ERROR = 0.05;

    // --- Revenue Mix ---
    /** Baseline share of revenue from handling the District's own imports and exports. */
    public const GATEWAY_HANDLING_WEIGHT = 0.60;
    /** Baseline share of revenue from relaying boxes between ships, at discounted lifts. */
    public const TRANSSHIPMENT_WEIGHT = 0.25;
    /** Baseline share of revenue from storage, dwell charges and yard services. */
    public const STORAGE_SERVICES_WEIGHT = 0.15;

    // --- Throughput ---
    /**
     * World container trade per unit of world output over the cycle (~1.8, Freund 2009's trade-income elasticity): a
     * relay hub's volume swings harder than output.
     */
    public const TRANSSHIPMENT_TRADE_INCOME_ELASTICITY = 1.80;
    /**
     * Volatility multiplier on gateway box counts beyond the trade cycle: cargo the District itself buys and sells,
     * steady.
     */
    public const GATEWAY_VARIANCE_SCALAR = 0.10;
    /**
     * Volatility multiplier on relayed boxes: an alliance moving one service between hubs shifts several per cent of a
     * hub's relays.
     */
    public const TRANSSHIPMENT_VARIANCE_SCALAR = 0.25;
    /** Volatility multiplier on yard and services revenue. */
    public const STORAGE_VARIANCE_SCALAR = 0.15;
    /** Quarterly persistence of the gateway box-count shock: a shipper's sourcing shift lasts a few quarters. */
    public const GATEWAY_PERSISTENCE = 0.40;
    /** Quarterly persistence of the relay shock: an alliance that moves a service keeps it there for a year or so. */
    public const TRANSSHIPMENT_PERSISTENCE = 0.50;
    /** Quarterly persistence of the yard-revenue shock. */
    public const STORAGE_PERSISTENCE = 0.30;
    /** Quarterly persistence of the event draw that sets off strikes. */
    public const EVENT_SHOCK_PERSISTENCE = 0.10;

    // --- Dwell & Storage ---
    /**
     * Log storage per box per standard deviation of supply-chain pressure: container dwell at Los Angeles rose from
     * about three days to eight as the NY Fed GSCPI reached 4.3 in late 2021, ln(8/3) / 4.3.
     */
    public const STORAGE_DWELL_SENSITIVITY = 0.228;
    /**
     * Variance of the supply-chain pressure index, built in standard deviations; the Jensen term at it keeps the dwell
     * factor's mean at one.
     */
    public const SUPPLY_CHAIN_PRESSURE_VARIANCE = 1.0;

    // --- Dock Strikes ---
    /** Exogenous Z below which dock crews walk off (~2.3% a quarter, a strike about every eleven years). */
    public const DOCK_STRIKE_Z_SCORE = -2.00;
    /** Regime key for a dock strike. */
    public const REGIME_DOCK_STRIKE = 'dock_strike';
    /**
     * Quarterly probability the strike is settled (~1.4 quarters expected; the 2014-15 US West Coast slowdown ran most
     * of a year).
     */
    public const DOCK_STRIKE_EXIT_HAZARD = 0.70;
    /**
     * Share of a quarter's boxes the stopped cranes miss while the strike holds: ten days of a 91-day quarter, as in
     * the 2002 US West Coast lockout.
     */
    public const DOCK_STRIKE_THROUGHPUT_LOSS = 0.11;
    /** Persisted state key: the District's boxes a strike has left waiting, in quarters of gateway revenue. */
    public const STATE_DEFERRED_GATEWAY = 'state:deferred_gateway';

    // --- Bond Proxy Capital Structure ---
    /**
     * Quarterly share of the fixed-rate bond stock that matures and reprices (~10-year tenor of infrastructure bonds
     * against concession cash flows), so a rate shock reaches interest expense over a decade.
     */
    public const DEBT_MATURITY_ROLLOVER_RATE = 0.025;

    /**
     * Quays are dredged and cranes bought when the berths run full: the budget follows the boxes the terminal handles.
     */
    public function getCapexCycleSignal(Stock $stock, MacroStateDTO $macroState): float
    {
        return $this->resolveSectorActivityShift($stock, $macroState);
    }

    /**
     * Boxes carry the cycle inside the sector physics, gateway and relay each on their own trade driver, so the root
     * carries no demand shift; the pricing multipliers still step once a quarter here.
     */
    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        return [
            'macro_demand_shift' => 0.0,
            ...$this->resolvePricingMultipliers($stock, $macroState),
        ];
    }

    /**
     * The workload the crews and cranes are staffed for: boxes handled at the target mix. Dwell is yard space, not
     * crew hours, so storage adds none.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $mix = $this->resolveRevenueMix($stock);

        return ($mix['gateway_handling'] * $this->resolveGatewayVolumeShift($stock, $macroState))
            + ($mix['transshipment'] * $this->resolveTransshipmentVolumeShift($stock, $macroState));
    }

    /**
     * Gateway fees follow the concession's CPI indexation; relay and yard fees recover what the hub's pricing power
     * allows, the pricing-power form every other firm uses.
     */
    protected function resolvePricingElasticity(Stock $stock): float
    {
        $gatewayShare = $this->resolveRevenueMix($stock)['gateway_handling'];
        $negotiated = self::PASS_THROUGH_BASE_ELASTICITY + $this->resolvePricingPower($stock);

        return ($gatewayShare * self::CONCESSION_FEE_INDEXATION) + ((1.0 - $gatewayShare) * $negotiated);
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $mix = $this->resolveRevenueMix($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $active = $streams->resolveActiveStreamWeights($mix);
        $gatewayWeight = $active['gateway_handling'];
        $transshipmentWeight = $active['transshipment'];
        $storageWeight = $active['storage_services'];

        $gatewayZ = $streams->generateZ('gateway_handling', self::GATEWAY_PERSISTENCE);
        $transshipmentZ = $streams->generateZ('transshipment', self::TRANSSHIPMENT_PERSISTENCE);
        $storageZ = $streams->generateZ('storage_services', self::STORAGE_PERSISTENCE);
        $eventZ = $streams->generateExogenousZ('event', self::EVENT_SHOCK_PERSISTENCE);

        // --- Dock Strike ---
        $strikeElapsed = $streams->evolveRegime(self::REGIME_DOCK_STRIKE, 0.0, self::DOCK_STRIKE_EXIT_HAZARD);
        $eventType = null;
        if ($eventZ < self::DOCK_STRIKE_Z_SCORE && $strikeElapsed === 0) {
            $strikeElapsed = $streams->startRegime(self::REGIME_DOCK_STRIKE);
            $eventType = ShockEvent::DOCK_STRIKE;
        }
        $struck = $strikeElapsed > 0;
        $strikeFactor = $struck ? 1.0 - self::DOCK_STRIKE_THROUGHPUT_LOSS : 1.0;

        // --- Handling ---
        // Boxes lifted times the fee: the trade drivers move the box count, the fee rides the engine's pricing
        // multiplier already inside expected revenue. A strike stops the cranes on both kinds of box; the relays go
        // to rival hubs, while the District's own boxes wait and are lifted the quarter after it is settled.
        $gatewayBase = $expectedRevenue * $gatewayWeight;
        $transshipmentBase = $expectedRevenue * $transshipmentWeight;
        $gatewayDemand = max(0.0, $gatewayBase
            * (1.0 + ($gatewayZ * $baselineVol * self::GATEWAY_VARIANCE_SCALAR) + $this->resolveGatewayVolumeShift($stock, $macroState)));
        $waiting = $streams->getPersistedState(self::STATE_DEFERRED_GATEWAY, 0.0);
        if ($struck) {
            $gatewayRevenue = $gatewayDemand * $strikeFactor;
            $waiting += $gatewayBase > 0.0 ? ($gatewayDemand - $gatewayRevenue) / $gatewayBase : 0.0;
        } else {
            $gatewayRevenue = $gatewayDemand + ($waiting * $gatewayBase);
            $waiting = 0.0;
        }
        $streams->registerState(self::STATE_DEFERRED_GATEWAY, $waiting);
        $transshipmentRevenue = max(0.0, $transshipmentBase
            * (1.0 + ($transshipmentZ * $baselineVol * self::TRANSSHIPMENT_VARIANCE_SCALAR) + $this->resolveTransshipmentVolumeShift($stock, $macroState))
            * $strikeFactor);

        // --- Yard & Storage ---
        // Yard services scale with the boxes handled; dwell past the norm is storage earned on space already paid
        // for, so the dwell uplift is price revenue and carries no variable cost.
        $handlingBase = $gatewayBase + $transshipmentBase;
        $handlingVolume = $handlingBase > 0.0 ? ($gatewayRevenue + $transshipmentRevenue) / $handlingBase : 1.0;
        $dwellFactor = $this->resolveDwellFactor($macroState);
        $storageBase = $expectedRevenue * $storageWeight;
        $storageVolumeRevenue = max(0.0, $storageBase * $handlingVolume * (1.0 + ($storageZ * $baselineVol * self::STORAGE_VARIANCE_SCALAR)));
        $storageRevenue = $storageVolumeRevenue * $dwellFactor;

        $streamRevenues = [
            'gateway_handling' => $gatewayRevenue,
            'transshipment'    => $transshipmentRevenue,
            'storage_services' => $storageRevenue,
        ];
        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Dock labour, crane power and the yard fleet's fuel reach the cost base at spot; the annual tariff reset
        // recovers them with the repricing lag.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        // The engine costs revenue less price revenue. Short dwell forgoes storage that never carried a cost, so the
        // costed volume is revenue plus what slack dwell left unearned, struck as a ratio on the revenue earned.
        $unearnedStorage = max(0.0, $storageVolumeRevenue - $storageRevenue);
        $volumeCostScale = $actualRevenue > 0.0 ? ($actualRevenue + $unearnedStorage) / $actualRevenue : 1.0;
        $clampedMargin = $this->clampMargin(($realizedVariableMargin + $inputCostDrag) * $volumeCostScale);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: [
                'throughput_index' => $handlingVolume,
                'dwell_index'      => $dwellFactor,
            ],
            priceRevenue: max(0.0, $storageRevenue - $storageVolumeRevenue),
        );
    }

    /**
     * Gateway boxes against their trend: the District's goods exports and imports at the macro's own volume
     * equations, weighted by their share of goods trade.
     */
    private function resolveGatewayVolumeShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $exportShare = OpenEconomy::goodsExportShareOfTrade();

        $exportVolume = OpenEconomy::goodsExportVolumeGap($macroState->mainlandDemandExportLag, $macroState->realExchangeRateTradeLag, $macroState->tariffTradeLag);
        $importVolume = OpenEconomy::goodsImportVolumeGap($macroState->domesticDemandGapEma, $macroState->realExchangeRateTradeLag, $macroState->tariffTradeLag);

        return (($exportShare * $exportVolume) + ((1.0 - $exportShare) * $importVolume)) * $this->getOperatingCyclicality($stock);
    }

    /**
     * Relayed boxes against their trend: trade between the mainland and the rest of the world at the trade-income
     * elasticity, less the relays a dear currency sends to rival hubs, priced as any exported service is. Bonded
     * cargo pays no District duty, so a tariff does not reach it.
     */
    private function resolveTransshipmentVolumeShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $tradeGap = ($macroState->foreignOutputGapEma + $macroState->worldActivityGap) / 2.0;
        $hubPriceLoss = OpenEconomy::exportPriceVolumeLoss($macroState->realExchangeRateTradeLag);

        return ((self::TRANSSHIPMENT_TRADE_INCOME_ELASTICITY * $tradeGap) - $hubPriceLoss) * $this->getOperatingCyclicality($stock);
    }

    /**
     * Storage per box against its normal take: log dwell moves with supply-chain pressure, Jensen-compensated at the
     * index's variance so the factor averages one over the cycle.
     */
    private function resolveDwellFactor(MacroStateDTO $macroState): float
    {
        $pressure = $macroState->supplyChainPressureIndexEma - MacroConstants::GSCPI_BASELINE;
        $jensen = 0.5 * self::STORAGE_DWELL_SENSITIVITY * self::STORAGE_DWELL_SENSITIVITY * self::SUPPLY_CHAIN_PRESSURE_VARIANCE;

        return exp((self::STORAGE_DWELL_SENSITIVITY * $pressure) - $jensen);
    }

    /**
     * The firm's target revenue mix, ticker override first, normalised to one.
     *
     * @return array{gateway_handling: float, transshipment: float, storage_services: float}
     */
    private function resolveRevenueMix(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::GatewayHandlingWeight->value => self::GATEWAY_HANDLING_WEIGHT,
            ModelParam::TransshipmentWeight->value   => self::TRANSSHIPMENT_WEIGHT,
            ModelParam::StorageServicesWeight->value => self::STORAGE_SERVICES_WEIGHT,
        ]);
        $gateway = max(0.0, $params[ModelParam::GatewayHandlingWeight]);
        $transshipment = max(0.0, $params[ModelParam::TransshipmentWeight]);
        $storage = max(0.0, $params[ModelParam::StorageServicesWeight]);
        $total = $gateway + $transshipment + $storage;
        if ($total <= 0.0) {
            return ['gateway_handling' => 1.0, 'transshipment' => 0.0, 'storage_services' => 0.0];
        }

        return [
            'gateway_handling' => $gateway / $total,
            'transshipment'    => $transshipment / $total,
            'storage_services' => $storage / $total,
        ];
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
            'domestic_demand_gap_ema',
            'energy_cost_push_lag',
            'foreign_output_gap_ema',
            'import_price_level',
            'inflation_ema',
            'mainland_demand_export_lag',
            'real_exchange_rate_trade_lag',
            'real_wage_gap',
            'supply_chain_pressure_index_ema',
            'tariff_trade_lag',
            'tips_breakeven_ema',
            'world_activity_gap',
        ];
    }
}
