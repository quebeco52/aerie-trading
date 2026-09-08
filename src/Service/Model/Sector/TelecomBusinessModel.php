<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Telecommunications (Wireless, Broadband, Fiber).
 * 
 * Financial Physics:
 * - High Barrier to Entry / Oligopoly: Revenue is dominated by ultra-sticky recurring subscriptions.
 * - The Churn Wars: Because the market is saturated, growth only comes from stealing competitors' customers via margin-crushing promotions.
 * - Subscriber Stock-Flow: service revenue is subscribers x ARPU; the base carries every quarter's net adds
 *   forward, churn wars erode it and promotional gross adds defend it at a subscriber acquisition cost.
 * - Bond Proxies: long-dated network bonds roll slowly, so rate shocks reach interest expense with a lag (DebtEngine).
 * - Generational CapEx: spectrum auctions commit a licence outlay of a quarter of annual revenue, queued as CIP.
 */
class TelecomBusinessModel extends StandardCorporateBusinessModel
{
    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: Q4 holiday device upgrades and equipment sales.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.97, 0.98, 1.00, 1.05];
    }

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Tower, rooftop and retail store leases. */
    public const LEASE_LIABILITY_INTENSITY = 0.30;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Network engineering and retail staff share overhead with towers, spectrum amortization and rents. */
    public const FIXED_COST_LABOR_SHARE = 0.45;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.50; // Subscription metrics are reported quarterly
    public const BASE_COVERAGE_ERROR = 0.05;

        public function getWholesaleLeverageLimit(): float { return 2.0; }
    public function getReversionSpeed(): float { return 0.15; }
    public function getMoatSpread(): float { return 0.015; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.05; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.25; }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.02;
    } // Mature, saturated market
    public function getCapexCyclicality(): float
    {
        return 1.8;
    } // Lumpy spectrum and fiber rollout cycles
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.70, 'revenue_weight' => 0.30];
    }

    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from recurring network subscriptions (Wireless, Broadband). */
    public const SUBSCRIPTION_WEIGHT = 0.85;
    /** Baseline fraction of revenue derived from hardware (Smartphones, Routers) and B2B enterprise setups. */
    public const EQUIPMENT_WEIGHT    = 0.15;

    // --- Physics & Variances ---
    public const EQUIPMENT_VARIANCE_SCALAR    = 0.30; // Highly cyclical (hardware upgrades get delayed in recessions)

    // --- Subscriber Base (stock-flow) ---
    /** Persisted subscriber index; 1.0 is the base implied by expected service revenue. */
    public const STATE_SUBSCRIBER_INDEX = 'state:subscriber_index';
    /** Baseline quarterly postpaid churn (~1% a month); steady-state gross adds replace exactly this. */
    public const BASE_QUARTERLY_CHURN = 0.03;
    /** Volatility multiplier on gross adds relative to the replacement rate (one sigma ~ +-0.9% of the base at 20% vol). */
    public const GROSS_ADDS_VARIANCE_SCALAR = 1.50;
    /** Floor on the subscriber index after sustained churn wars. */
    public const MIN_SUBSCRIBER_INDEX = 0.50;
    /** Ceiling on the subscriber index within a saturated market. */
    public const MAX_SUBSCRIBER_INDEX = 1.50;

    // --- The Churn & Price War Physics ---
    /** Margin cost per unit of gross adds above the replacement rate (device subsidies, marketing, dealer commissions). */
    public const SUBSCRIBER_ACQUISITION_COST_ELASTICITY = 0.025;
    /** Z-score threshold indicating a brutal sector-wide price war to steal market share. */
    public const PRICE_WAR_Z_SCORE   = -2.00;
    /** Fat-tail margin penalty when intense promotional pricing (e.g., "Free iPhones") destroys profitability. */
    public const PRICE_WAR_PENALTY   = 0.08;
    /** Regime key for a sector-wide price war (two-state Markov). */
    public const REGIME_PRICE_WAR = 'price_war';
    /** Quarterly probability the price war ends (~4 quarter expected duration). */
    public const PRICE_WAR_EXIT_HAZARD = 0.25;
    /** Extra quarterly churn while rivals run switching promotions. */
    public const PRICE_WAR_CHURN_UPLIFT = 0.02;
    /** ARPU discount from matching rival plan pricing during a price war. */
    public const PRICE_WAR_ARPU_DISCOUNT = 0.05;
    /** Promotional gross adds response (relative) that carriers mount to defend the base in a price war. */
    public const PRICE_WAR_GROSS_ADDS_RESPONSE = 0.50;

    // --- Generational CapEx / Spectrum Tail Risks ---
    /** Z-score threshold indicating a massive government spectrum auction or generational infrastructure leap. */
    public const SPECTRUM_AUCTION_Z_SCORE = 2.20;
    /** Spectrum licence outlay as a fraction of annual revenue (C-band scale), queued as construction-in-progress. */
    public const SPECTRUM_AUCTION_CAPEX_RATIO = 0.25;

    // --- Bond Proxy Capital Structure ---
    /** Quarterly share of the fixed-rate bond stock that matures and reprices (~8-year average tenor). */
    public const DEBT_MATURITY_ROLLOVER_RATE = 0.03;

    // --- Asset Depreciation & Reinvestment ---
    public const NETWORK_DECAY_RATE           = 0.025; // 3G/4G becomes obsolete without continuous fiber/5G upgrades
    public const NETWORK_MODERNIZATION_RATE   = 0.010;
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.05;
    public const MAX_OPERATING_MARGIN_CEILING = 0.35;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shift. We will apply macro deviations discretely per-stream.
        // People don't cancel their phone plans during a recession, but they do stop buying $1,200 iPhones.
        $physics['macro_demand_shift'] = 0.0;

        // Telecoms generally pass inflation through via annual bill hikes, 
        // but face mild regulatory/consumer pushback.
        $inflation = $macroState->tipsBreakevenEma;
        $physics['pricing_power_multiplier'] = 1.0 + ($inflation * 0.50);

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::SubscriptionWeight->value => self::SUBSCRIPTION_WEIGHT,
            ModelParam::EquipmentWeight->value    => self::EQUIPMENT_WEIGHT,
        ]);

        $subscriptionWeight = $params[ModelParam::SubscriptionWeight];
        $equipmentWeight    = $params[ModelParam::EquipmentWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

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
        // Subscriptions are highly defensive (0.2x multiplier). Equipment is highly cyclical (1.5x multiplier).
        $macroDefensiveShift = $macroState->outputGapEma * 0.2 * $beta;
        $macroCyclicalShift  = $macroState->outputGapEma * 1.5 * $beta;

        // --- Event Tail Risks ---
        // A price war is a regime: rival promotions lift churn and cut ARPU for several quarters. A spectrum
        // auction is a CapEx event: the licence outlay is committed here and queued as construction-in-progress.
        $priceWarElapsed = $streams->evolveRegime(self::REGIME_PRICE_WAR, 0.0, self::PRICE_WAR_EXIT_HAZARD);
        $eventType = null;
        $scheduledCapex = 0.0;

        if ($eventZ < self::PRICE_WAR_Z_SCORE && $priceWarElapsed === 0) {
            $priceWarElapsed = $streams->startRegime(self::REGIME_PRICE_WAR);
            $eventType = ShockEvent::TELECOM_PRICE_WAR;
        } elseif ($eventZ > self::SPECTRUM_AUCTION_Z_SCORE) {
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
        $churnRate = self::BASE_QUARTERLY_CHURN + ($inPriceWar ? self::PRICE_WAR_CHURN_UPLIFT : 0.0);
        $grossAddsRate = max(0.0, self::BASE_QUARTERLY_CHURN
            * (1.0 + ($subscriptionZ * $baselineVol * self::GROSS_ADDS_VARIANCE_SCALAR))
            * ($inPriceWar ? (1.0 + self::PRICE_WAR_GROSS_ADDS_RESPONSE) : 1.0));
        $subscribers = max(self::MIN_SUBSCRIBER_INDEX, min(self::MAX_SUBSCRIBER_INDEX, ($openingSubscribers * (1.0 - $churnRate)) + $grossAddsRate));
        $streams->registerState(self::STATE_SUBSCRIBER_INDEX, $subscribers);

        $arpuMultiplier = (1.0 + $macroDefensiveShift) * ($inPriceWar ? (1.0 - self::PRICE_WAR_ARPU_DISCOUNT) : 1.0);

        // --- Clamped Dual-Stream Revenue Calculation ---
        $subscriptionRevenue = max(0.0, $expectedRevenue * $subscriptionWeight * $subscribers * $arpuMultiplier);
        $equipmentRevenue    = max(0.0, $expectedRevenue * $equipmentWeight * (1.0 + ($equipmentZ * $baselineVol * self::EQUIPMENT_VARIANCE_SCALAR) + $macroCyclicalShift));

        $streamRevenues = [
            'wireless_subscriptions' => $subscriptionRevenue,
            'equipment_sales'        => $equipmentRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Cost & Margin Physics ---

        // 1. Subscriber Acquisition Cost: every gross add costs subsidy, marketing and dealer commission
        //    dollars, so growth and churn replacement alike compress margin relative to the replacement rate.
        $sacDrag = self::SUBSCRIBER_ACQUISITION_COST_ELASTICITY * (($grossAddsRate / self::BASE_QUARTERLY_CHURN) - 1.0) * $subscriptionWeight;
        $priceWarPenalty = $inPriceWar ? self::PRICE_WAR_PENALTY : 0.0;

        // Note: the network debt load reaches earnings through DebtEngine's maturity wall (see
        // getDebtMaturityRolloverRate), never as an operating margin drag. Interest sits below EBIT.
        $rawMargin = $realizedVariableMargin + $priceWarPenalty + $sacDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock is whichever stream deviated the most, overridden by tail events
        $primaryShockZ = $streams->resolveDominantShockZ([$equipmentZ, $subscriptionZ], $eventZ);

        // Net adds are reported every quarter and tracked closely; equipment sales carry standard retail visibility.
        $subscriberMove = ($subscribers / max(0.01, $openingSubscribers)) - 1.0;
        $observableShockZ = ($subscriberMove * $subscriptionWeight * 0.90) +
            ($equipmentZ * $equipmentWeight * self::EQUIPMENT_VARIANCE_SCALAR * 0.40 * $baselineVol);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
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
     * Bond proxy: telecoms fund networks with long-dated fixed-rate bonds, so only a small slice of the
     * book reprices each quarter. Rate shocks therefore reach interest expense slowly and persist for years.
     */
    public function getDebtMaturityRolloverRate(): float
    {
        return self::DEBT_MATURITY_ROLLOVER_RATE;
    }

    /** Network aging (e.g., failing to keep up with 5G/fiber deployment) leads to higher churn and margin decay */
    public function getDepreciationDecayRate(): float
    {
        return self::NETWORK_DECAY_RATE;
    }

    /** Fiber-to-the-home and 5G modernization expands pricing power and capacity ceilings */
    public function getModernizationGainRate(): float
    {
        return self::NETWORK_MODERNIZATION_RATE;
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
            'output_gap_ema',
            'tips_breakeven_ema',
        ];
    }
}
