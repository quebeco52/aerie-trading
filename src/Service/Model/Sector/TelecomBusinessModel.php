<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;
use App\Service\Model\StreamContext;

/**
 * Earnings strategy for Telecommunications (Wireless, Broadband, Fiber).
 *
 * Financial Physics:
 * - High Barrier to Entry / Oligopoly: Revenue is dominated by ultra-sticky recurring subscriptions.
 * - The Churn Wars: Because the market is saturated, growth only comes from stealing competitors' customers via
 *   margin-crushing promotions.
 *   A price war is the industry's: when any carrier starts one, every carrier in the industry fights it until it ends.
 * - Subscriber Stock-Flow: service revenue is subscribers x ARPU; the base carries every quarter's net adds
 *   forward, churn wars erode it and promotional gross adds defend it at a subscriber acquisition cost.
 * - Bond Proxies: long-dated network bonds roll slowly, so rate shocks reach interest expense with a lag (DebtEngine).
 * - Generational CapEx: spectrum auctions commit a licence outlay of a quarter of annual revenue, queued as CIP.
 * - Devices at a loss: handsets are sold below cost and carry the holiday upgrade season; plan revenue is flat
 *   through the year, so the Q4 device peak lowers the quarter's margin.
 */
class TelecomBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Subscriptions are
     * defensive; every net add is a rival's loss in a saturated market.
     */
    public const OPERATING_CYCLICALITY = 0.60;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.30;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.85;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::TELECOM;
    /**
     * Annual CPI-linked plan hikes with regulatory and churn pushback: about half of expected inflation reaches ARPU.
     */
    public const PRICING_ELASTICITY = 0.50;
    /** Plans reprice once a year. */
    public const PRICE_PASS_THROUGH_LAG_YEARS = 1.00;
    /**
     * Wireless carriers hold real pricing power on a captive base; network power and handset costs are partly
     * recovered.
     */
    public const PRICING_POWER_INDEX = 0.30;

    // --- Device Upgrade Season ---
    /**
     * Wireless equipment revenue by calendar quarter [Q1, Q2, Q3, Q4], mean 1.0 (Verizon 2022-23 10-Q/10-K): the
     * holiday upgrade peak.
     */
    public const EQUIPMENT_SEASONALITY = [0.94, 0.92, 0.96, 1.18];

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: the device season on the default
     * equipment share; plan revenue does not move with the calendar.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return self::blendDeviceSeason(self::EQUIPMENT_WEIGHT / (self::SUBSCRIPTION_WEIGHT + self::EQUIPMENT_WEIGHT));
    }

    /** @return array<int, float> */
    public function resolveSeasonalityFactors(Stock $stock): array
    {
        return self::blendDeviceSeason($this->resolveEquipmentShare($stock));
    }

    /**
     * The firm-level season of a revenue mix whose equipment share follows EQUIPMENT_SEASONALITY and whose
     * plan revenue is flat.
     *
     * @return array<int, float>
     */
    private static function blendDeviceSeason(float $equipmentShare): array
    {
        return array_map(static fn(float $season): float => 1.0 + ($equipmentShare * ($season - 1.0)), self::EQUIPMENT_SEASONALITY);
    }

    // --- Balance Sheet Realism ---
    /**
     * Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Tower, rooftop and
     * retail store leases.
     */
    public const LEASE_LIABILITY_INTENSITY = 0.30;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.50; // Subscription metrics are reported quarterly
    public const BASE_COVERAGE_ERROR = 0.05;

    public function getReversionSpeed(): float
    {
        return 0.15;
    }
    public function getMoatSpread(): float
    {
        return 0.015;
    }
    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return 0.05;
    }
    public function getCapExCompletionRate(Stock $stock): float
    {
        return 0.25;
    }

    // --- Secular Demand ---
    /**
     * Broadcasting and telecommunications value added as a share of US nominal GDP in 1997 (BEA GDP by Industry, value
     * added).
     */
    public const SECULAR_SHARE_1997 = 0.0264;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0233;

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }
    public function getCapexCyclicality(): float
    {
        return 1.8;
    } // Lumpy spectrum and fiber rollout cycles

    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue from service plans (wireless, fibre broadband): Verizon 2024 10-K, 83%. */
    public const SUBSCRIPTION_WEIGHT = 0.83;
    /** Baseline fraction of revenue from handsets, routers and other devices: Verizon 2024 10-K, 17%. */
    public const EQUIPMENT_WEIGHT    = 0.17;

    // --- Stream Cost Structure ---
    /**
     * Cost of wireless equipment over wireless equipment revenue (Verizon 10-K 2022-24: 1.10-1.12): devices are sold
     * below cost to win the plan.
     */
    public const EQUIPMENT_VARIABLE_COST_RATIO = 1.12;

    // --- Cycle Sensitivities ---
    /** ARPU response to the output gap per unit of cyclicality: plan downgrades on a base that keeps its lines. */
    public const ARPU_CYCLE_SENSITIVITY = 0.20;
    /** Device volume response to the output gap per unit of cyclicality: upgrades are deferred in a downturn. */
    public const EQUIPMENT_CYCLE_SENSITIVITY = 1.50;

    // --- Physics & Variances ---
    public const EQUIPMENT_VARIANCE_SCALAR    = 0.30; // Highly cyclical (hardware upgrades get delayed in recessions)

    // --- Subscriber Base (stock-flow) ---
    /** Persisted subscriber index; 1.0 is the base implied by expected service revenue. */
    public const STATE_SUBSCRIBER_INDEX = 'state:subscriber_index';
    /**
     * Baseline quarterly postpaid churn (~1% a month), the default a carrier's own BaseQuarterlyChurn overrides;
     * steady-state gross adds replace exactly this.
     */
    public const BASE_QUARTERLY_CHURN = 0.03;
    /**
     * Volatility multiplier on gross adds relative to the replacement rate (one sigma ~ +-0.9% of the base at 20% vol).
     */
    public const GROSS_ADDS_VARIANCE_SCALAR = 1.50;
    /**
     * Quarterly churn per unit of unemployment against the natural rate, both ways (0.10 = 10bps per point):
     * involuntary disconnects and non-payment rise with job losses and ease when jobs are plentiful.
     */
    public const UNEMPLOYMENT_CHURN_SENSITIVITY = 0.10;

    // --- The Churn & Price War Physics ---
    /**
     * Margin cost per unit of gross adds above the replacement rate (device subsidies, marketing, dealer commissions).
     */
    public const SUBSCRIBER_ACQUISITION_COST_ELASTICITY = 0.025;
    /**
     * Z-score threshold for a price war to start in the District's market (~2.3% a quarter), shared among the listed
     * carriers that could start it.
     */
    public const PRICE_WAR_Z_SCORE   = -2.00;
    /** Regime key for a price war: industry-wide through the ledger, the carrier's own two-state Markov without one. */
    public const REGIME_PRICE_WAR = 'price_war';
    /** Quarterly probability the price war ends (~4 quarter expected duration). */
    public const PRICE_WAR_EXIT_HAZARD = 0.25;
    /**
     * Extra quarterly churn while rivals run switching promotions. A switcher leaves one carrier for another, so the
     * same rate comes back as promotional gross adds: the war moves lines between carriers and destroys none.
     */
    public const PRICE_WAR_CHURN_UPLIFT = 0.02;
    /** ARPU discount from matching rival plan pricing during a price war. */
    public const PRICE_WAR_ARPU_DISCOUNT = 0.05;

    // --- Generational CapEx / Spectrum Tail Risks ---
    /** Z-score threshold indicating a massive government spectrum auction or generational infrastructure leap. */
    public const SPECTRUM_AUCTION_Z_SCORE = 2.20;
    /** Spectrum licence outlay as a fraction of annual revenue (C-band scale), queued as construction-in-progress. */
    public const SPECTRUM_AUCTION_CAPEX_RATIO = 0.25;

    // --- Bond Proxy Capital Structure ---
    /** Quarterly share of the fixed-rate bond stock that matures and reprices (~8-year average tenor). */
    public const DEBT_MATURITY_ROLLOVER_RATE = 0.03;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shift. We will apply macro deviations discretely per-stream.
        // People don't cancel their phone plans during a recession, but they do stop buying $1,200 iPhones.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /**
     * The volume the network and retail estate are staffed to carry: the target-mix weighted cycle shift of
     * equipment units. The cycle's ARPU leg (plan downgrades on a base that keeps its lines) is revenue per
     * subscriber, a price, and stays out; cyclical churn moves the persisted subscriber stock, not this level.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        return $this->resolveEquipmentShare($stock) * $this->resolveEquipmentVolumeShift($stock, $macroState);
    }

    /** The firm's target equipment share of revenue. */
    private function resolveEquipmentShare(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::SubscriptionWeight->value => self::SUBSCRIPTION_WEIGHT,
            ModelParam::EquipmentWeight->value    => self::EQUIPMENT_WEIGHT,
        ]);
        $totalWeight = $params[ModelParam::SubscriptionWeight] + $params[ModelParam::EquipmentWeight];

        return $totalWeight > 0.0 ? $params[ModelParam::EquipmentWeight] / $totalWeight : 0.0;
    }

    /** Handset and router upgrades are deferred in a downturn. */
    private function resolveEquipmentVolumeShift(Stock $stock, MacroStateDTO $macroState): float
    {
        return $macroState->outputGapEma * self::EQUIPMENT_CYCLE_SENSITIVITY * $this->getOperatingCyclicality($stock);
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::SubscriptionWeight->value => self::SUBSCRIPTION_WEIGHT,
            ModelParam::EquipmentWeight->value    => self::EQUIPMENT_WEIGHT,
            ModelParam::BaseQuarterlyChurn->value => self::BASE_QUARTERLY_CHURN,
        ]);

        $baseChurn          = max(0.0, $params[ModelParam::BaseQuarterlyChurn]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta = $this->getOperatingCyclicality($stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'wireless_subscriptions' => $params[ModelParam::SubscriptionWeight],
            'equipment_sales'        => $params[ModelParam::EquipmentWeight],
        ]);

        $subscriptionWeight = $activeWeights['wireless_subscriptions'];
        $equipmentWeight    = $activeWeights['equipment_sales'];

        // Independent stream Z-scores
        $subscriptionZ = $streams->generateZ('wireless_subscriptions', 0.40); // High persistence
        $equipmentZ    = $streams->generateZ('equipment_sales', 0.15); // Low persistence, driven by hardware cycles
        $eventZ        = $streams->generateExogenousZ('event', 0.10);

        // --- Macro Demand Sensitivities ---
        // Subscriptions are defensive; device purchases are deferred in a downturn.
        $macroDefensiveShift = $macroState->outputGapEma * self::ARPU_CYCLE_SENSITIVITY * $beta;
        $macroCyclicalShift  = $this->resolveEquipmentVolumeShift($stock, $macroState);

        // --- Event Tail Risks ---
        // A price war is a regime: rival promotions lift churn and cut ARPU for several quarters. A spectrum
        // auction is a CapEx event: the licence outlay is committed here and queued as construction-in-progress.
        [$priceWarElapsed, $startedPriceWar] = $this->resolvePriceWar($streams, $eventZ, $mathUtility);
        $eventType = $startedPriceWar ? ShockEvent::TELECOM_PRICE_WAR : null;
        $scheduledCapex = 0.0;

        if ($eventType === null && $eventZ > self::SPECTRUM_AUCTION_Z_SCORE) {
            $eventType = ShockEvent::SPECTRUM_AUCTION;
            $scheduledCapex = $expectedRevenue * 4.0 * self::SPECTRUM_AUCTION_CAPEX_RATIO;
        }
        $inPriceWar = $priceWarElapsed > 0;

        // --- Subscriber Base (stock-flow) ---
        // Service revenue is subscribers x ARPU. The base evolves as s_t = s_{t-1} (1 - churn) + gross adds,
        // where steady-state gross adds exactly replace baseline churn, so the base carries every past
        // quarter's net adds forward. Demand shocks move gross adds; the cycle moves ARPU (plan downgrades,
        // not cancellations); a price war lifts churn, forces promotional gross adds and discounts ARPU.
        $openingSubscribers = $streams->getPersistedState(self::STATE_SUBSCRIBER_INDEX, 1.0);
        // Base churn is the average labour market's, so unemployment is read against its natural rate both ways.
        $churnRate = max(0.0, $baseChurn
            + ($inPriceWar ? self::PRICE_WAR_CHURN_UPLIFT : 0.0)
            + ($macroState->unemploymentGap() * self::UNEMPLOYMENT_CHURN_SENSITIVITY));
        $grossAddsRate = max(0.0, ($baseChurn * (1.0 + ($subscriptionZ * $baselineVol * self::GROSS_ADDS_VARIANCE_SCALAR)))
            + ($inPriceWar ? self::PRICE_WAR_CHURN_UPLIFT : 0.0));
        $subscribers = ($openingSubscribers * (1.0 - $churnRate)) + $grossAddsRate;
        $streams->registerState(self::STATE_SUBSCRIBER_INDEX, $subscribers);

        $arpuMultiplier = (1.0 + $macroDefensiveShift) * ($inPriceWar ? (1.0 - self::PRICE_WAR_ARPU_DISCOUNT) : 1.0);

        // --- Dual-Stream Revenue ---
        // Expected revenue carries the firm-level season the engine applied; plan revenue is flat through the
        // year, so the season is taken back out of it and the whole swing is placed on device sales.
        $quarter = $this->reportedCalendarQuarter();
        $firmSeason = $quarter !== null ? $this->resolveSeasonalityFactors($stock)[$quarter] : 1.0;
        $deviceSeason = $quarter !== null ? self::EQUIPMENT_SEASONALITY[$quarter] : 1.0;
        $subscriptionVolume = $expectedRevenue * $subscriptionWeight * $subscribers / $firmSeason;
        $subscriptionRevenue = max(0.0, $subscriptionVolume * $arpuMultiplier);
        $equipmentRevenue    = max(0.0, $expectedRevenue * $equipmentWeight * $deviceSeason / $firmSeason
            * (1.0 + ($equipmentZ * $baselineVol * self::EQUIPMENT_VARIANCE_SCALAR) + $macroCyclicalShift));

        $streamRevenues = [
            'wireless_subscriptions' => $subscriptionRevenue,
            'equipment_sales'        => $equipmentRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Cost & Margin Physics ---

        // Devices carry their own cost ratio; plans take the residual that reproduces the engine's cost ratio at
        // the target mix, so a device-heavy quarter costs more per dollar of revenue. Plan costs follow lines
        // carried, not the ARPU charged on them.
        $equipmentCosts = $equipmentWeight * self::EQUIPMENT_VARIABLE_COST_RATIO;
        $equipmentCostScale = $equipmentCosts > $realizedVariableMargin && $equipmentCosts > 0.0 ? $realizedVariableMargin / $equipmentCosts : 1.0;
        $equipmentCostRatio = self::EQUIPMENT_VARIABLE_COST_RATIO * $equipmentCostScale;
        $subscriptionCostRatio = $subscriptionWeight > 0.0 ? max(0.0, $realizedVariableMargin - ($equipmentCosts * $equipmentCostScale)) / $subscriptionWeight : 0.0;
        $actualVariableCosts = ($subscriptionVolume * $subscriptionCostRatio) + ($equipmentRevenue * $equipmentCostRatio);
        $effectiveMargin = $actualRevenue > 0.0 ? $actualVariableCosts / $actualRevenue : $realizedVariableMargin;

        // 1. Subscriber Acquisition Cost: every gross add costs subsidy, marketing and dealer commission
        //    dollars, so growth and churn replacement alike compress margin relative to the replacement rate.
        $sacDrag = $baseChurn > 0.0
            ? self::SUBSCRIBER_ACQUISITION_COST_ELASTICITY * (($grossAddsRate / $baseChurn) - 1.0) * $subscriptionWeight
            : 0.0;

        // Note: the network debt load reaches earnings through DebtEngine's maturity wall (see
        // getDebtMaturityRolloverRate), never as an operating margin drag. Interest sits below EBIT. Network power,
        // handset procurement and field payroll reach the cost base at spot; annual plan hikes recover part of it.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        $rawMargin = $effectiveMargin + $sacDrag + $inputCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            scheduledCapex: $scheduledCapex,
            kpis: [
                'subscriber_index' => $subscribers,
                'quarterly_churn'  => $churnRate,
                'net_adds'         => $subscribers - $openingSubscribers,
                'arpu_index'       => $arpuMultiplier,
            ],
        );
    }

    /**
     * The quarter's price war: quarters it has run including this one (0 when none) and whether this carrier started
     * it.
     *
     * With the industry ledger the war is the industry's: a carrier that draws the onset starts it for every carrier
     * in the industry, its length drawn once from the exit hazard, and the rest are in it from their next report.
     * Without one the carrier runs its own two-state regime on the same hazards.
     *
     * @return array{int, bool}
     */
    private function resolvePriceWar(StreamContext $streams, float $eventZ, MathUtility $mathUtility): array
    {
        $industry = $this->industryRegimes();
        if ($industry === null) {
            $elapsed = $streams->evolveRegime(self::REGIME_PRICE_WAR, 0.0, self::PRICE_WAR_EXIT_HAZARD);
            if ($eventZ < self::PRICE_WAR_Z_SCORE && $elapsed === 0) {
                return [$streams->startRegime(self::REGIME_PRICE_WAR), true];
            }

            return [$elapsed, false];
        }

        $elapsed = $industry->elapsed(self::REGIME_PRICE_WAR);
        $started = false;
        if ($elapsed === 0 && $eventZ < self::priceWarOnsetThreshold($industry->members())) {
            $elapsed = $industry->start(self::REGIME_PRICE_WAR, $mathUtility->generateGeometricCount(self::PRICE_WAR_EXIT_HAZARD));
            $started = true;
        }

        return [$streams->setRegimeElapsed(self::REGIME_PRICE_WAR, $elapsed), $started];
    }

    /**
     * The event Z below which one of the industry's listed carriers starts a war. PRICE_WAR_Z_SCORE is the market's
     * onset rate; each of n carriers draws every quarter, so each carries 1/n of it and the market sees a war as
     * often whatever the roster.
     */
    public static function priceWarOnsetThreshold(int $carriers): float
    {
        if ($carriers <= 1) {
            return self::PRICE_WAR_Z_SCORE;
        }

        return Distributions::standardNormalQuantile(Distributions::standardNormalCdf(self::PRICE_WAR_Z_SCORE) / $carriers);
    }

    /**
     * Bond proxy: telecoms fund networks with long-dated fixed-rate bonds, so only a small slice of the
     * book reprices each quarter. Rate shocks therefore reach interest expense slowly and persist for years.
     */
    public function getDebtMaturityRolloverRate(): float
    {
        return self::DEBT_MATURITY_ROLLOVER_RATE;
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
            'exchange_rate_index_ema',
            'import_price_level',
            'nairu_ema',
            'output_gap_ema',
            'tips_breakeven_ema',
            'unemployment_rate_ema',
            'real_wage_gap',
        ];
    }
}
