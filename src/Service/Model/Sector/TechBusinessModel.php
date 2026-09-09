<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Technology & Software companies.
 * 
 * Financial Physics:
 * - Asset-light, highly scalable revenues. Marginal cost of a new user is near zero.
 * - Mostly immune to physical supply chain inflation, but exposed to wage inflation.
 * - Higher inherent top-line volatility (viral growth or sudden user churn).
 * - Vulnerable to "fat left-tail" risks like regulatory anti-trust fines or data breaches.
 */
class TechBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Enterprise budgets and ad spend are cyclical; seats churn slowly. */
    public const OPERATING_CYCLICALITY = 1.20;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.60;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['labor' => 0.60, 'energy' => 0.03, 'ppi' => 0.05];
    /** Switching costs, data gravity and seat-based contracts let a platform reprice an installed base with little churn. */
    public const PRICING_POWER_INDEX = 0.75;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: Q4 enterprise budget flush and holiday advertising.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.95, 0.98, 0.97, 1.10];
    }

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Office campuses and colocation leases. */
    public const LEASE_LIABILITY_INTENSITY = 0.10;
    /** Stock-based compensation as a fraction of revenue (ASC 718): non-cash, added back to FCF, settled in new shares. Engineering talent is paid heavily in equity; FCF runs well above GAAP earnings. */
    public const STOCK_COMPENSATION_INTENSITY = 0.10;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Engineering and go-to-market payroll dominates software overhead; talent inflation bites hardest here. */
    public const FIXED_COST_LABOR_SHARE = 0.75;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    public const BASE_COVERAGE_ERROR = 0.05;
        public function getReversionSpeed(): float { return 0.1; }
    public function getMoatSpread(): float { return 0.025; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return -0.05; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.5; }
    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.06;
    }
    public function getCapexCyclicality(): float
    {
        return 1.0;
    }
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.25, 'revenue_weight' => 0.75];
    }

    // --- Dual-Stream Tech & Software Architecture ---
    /** Baseline fraction of revenue derived from recurring SaaS subscription & cloud infrastructure. */
    public const SUBSCRIPTION_REVENUE_WEIGHT    = 0.60;
    /** Baseline fraction of revenue derived from digital advertising networks & platform usage fees. */
    public const ADVERTISING_REVENUE_WEIGHT     = 0.40;
    /** Sensitivity of digital advertising revenue to macroeconomic output gap cycles. */
    public const ADVERTISING_CYCLICALITY_SCALAR = 0.15;
    /** Fraction of deferred subscription bookings recognized as revenue each quarter (annual contracts, ASC 606 ratable). */
    public const SUBSCRIPTION_RECOGNITION_RATE = 0.25;

    // --- Revenue Volatility ---
    /** Volatility multiplier for top-line revenue shocks reflecting rapid software user scaling and churn. */
    public const REVENUE_VARIANCE_SCALAR   = 0.20;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Fat Tail Event Lore & Shock Thresholds ---
    /** Negative z-score threshold indicating major regulatory anti-trust fines or data breaches. */
    public const REGULATORY_FINE_Z_SCORE   = -2.50;
    /** Variable cost penalty applied during severe regulatory fines and legal compliance mandates. */
    public const REGULATORY_FINE_PENALTY   = 0.15;
    /** Regime key for the court-supervised compliance period that follows an antitrust or privacy settlement. */
    public const REGIME_CONSENT_DECREE     = 'consent_decree';
    /** Quarterly probability the consent decree lapses (~8 quarter expected remediation period). */
    public const CONSENT_DECREE_EXIT_HAZARD = 0.125;
    /** Ongoing quarterly compliance and remediation cost on the ad stack while a consent decree is in force. */
    public const CONSENT_DECREE_COMPLIANCE_PENALTY = 0.03;
    /** Negative z-score threshold indicating severe user churn and platform defection. */
    public const SEVERE_CHURN_Z_SCORE      = -2.00;
    /** Variable cost penalty applied during severe customer churn events. */
    public const SEVERE_CHURN_PENALTY      = 0.05;
    /** Positive z-score threshold indicating breakthrough viral user adoption and network effects. */
    public const VIRAL_GROWTH_Z_SCORE      = 2.50;
    /** Top-line revenue multiplier applied during breakthrough viral user growth. */
    public const VIRAL_GROWTH_REV_MULT     = 1.10;

    // --- Analyst Visibility & Error ---
    // Moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- Innovation Competition Moat & Capital Structure ---
    /** Operating margin mean reversion speed: fast speed reflects rapid technological disruption and competition. */
    public const TECH_REVERSION_SPEED      = 5.0;
    /** Minimum WACC arbitrage spread required before under-leveraged recapitalization is permitted. */
    public const WACC_ARBITRAGE_THRESHOLD  = 0.03;
    /** Minimum interest coverage ratio required to permit recapitalization for intangible asset software models. */
    public const MIN_RECAP_ICR_FLOOR       = 15.0;
    /** Maximum debt tolerance threshold fraction triggering under-leveraged status. */
    public const UNDERLEVERAGED_DEBT_RATIO = 0.50;

    // --- SaaS ARR Operating Leverage & Software Platform Reinvestment Physics ---
    /** Variable margin sensitivity to SaaS ARR expansion (zero marginal cost of software delivery). */
    public const SAAS_OPERATING_LEVERAGE      = 0.022;
    /** Quarterly margin decay rate per unit of underinvestment below software maintenance CapEx. */
    public const TECH_DEBT_DECAY_RATE         = 0.020;
    /** Quarterly margin gain scalar per unit of logarithmic cloud platform modernization. */
    public const PLATFORM_MODERNIZATION_GAIN_RATE = 0.012;
    /** Structural minimum operating margin floor under severe software tech debt and customer churn. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.15;
    /** Structural maximum operating margin ceiling for cloud software monopolies. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.55;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::SubscriptionRevenueWeight->value => self::SUBSCRIPTION_REVENUE_WEIGHT,
            ModelParam::AdvertisingRevenueWeight->value  => self::ADVERTISING_REVENUE_WEIGHT,
            ModelParam::CloudInfrastructureWeight->value => 0.00,
            ModelParam::AdvertisingCyclicality->value     => self::ADVERTISING_CYCLICALITY_SCALAR,
            ModelParam::MonopolyAggression->value         => 0.5,
        ]);

        $rawCloudWeight      = $params[ModelParam::CloudInfrastructureWeight];
        $adCyclicalityScalar = $params[ModelParam::AdvertisingCyclicality];

        $aggression = max(0.0, min(1.0, $params[ModelParam::MonopolyAggression]));
        // Risk vs Reward Trade-off:
        // Reward: Lower variable costs (higher margins) via aggressive pricing and data harvesting
        $marginBonus = $aggression * 0.10; // Up to 1000 bps baseline margin expansion

        // Risk: Massive amplification of regulatory scrutiny
        // At aggression=1.0, Z-score threshold shifts from -2.5 to -1.25 (frequent fines), and penalty severity is 1.5x.
        // At aggression=0.0, Z-score threshold shifts to -3.75 (nearly impossible), and penalty is 0.5x.
        $regulatoryZThreshold = self::REGULATORY_FINE_Z_SCORE * (1.5 - $aggression);
        $regulatorySeverity   = self::REGULATORY_FINE_PENALTY * (0.5 + $aggression);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $targetWeights = [
            'subscription' => $params[ModelParam::SubscriptionRevenueWeight],
            'advertising'  => $params[ModelParam::AdvertisingRevenueWeight],
        ];
        if ($rawCloudWeight > 0.0) {
            $targetWeights['cloud_infrastructure'] = $rawCloudWeight;
        }

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $subWeight   = $activeWeights['subscription'];
        $adWeight    = $activeWeights['advertising'];
        $cloudWeight = $activeWeights['cloud_infrastructure'] ?? 0.0;

        // Independent stream Z-scores with AR(1) persistence
        $subscriptionZ = $streams->generateZ('subscription', 0.45); // Enterprise SaaS ARR & Cloud compute contract volume
        $adZ           = $streams->generateZ('advertising', 0.30); // Digital advertising auction demand & impression volume
        $eventZ        = $streams->generateExogenousZ('event', 0.10); // Fat-tail regulatory antitrust / data breach Z-score

        // Macro advertising cyclicality (marketing budgets expand with positive output gap, collapse in recessions)
        $outputGap = $macroState->outputGapEma;
        $adCyclicality = $outputGap * $adCyclicalityScalar;

        // Fat Tail Risk: Data Breaches, Anti-Trust, and Viral Breakthroughs
        // A settlement is a one-quarter fine followed by a multi-year consent decree: court-supervised
        // compliance spending keeps the ad stack's cost base elevated until the decree lapses.
        $decreeElapsed = $streams->evolveRegime(self::REGIME_CONSENT_DECREE, 0.0, self::CONSENT_DECREE_EXIT_HAZARD);
        $regulatoryShock = 0.0;
        $eventType       = null;

        if ($eventZ < $regulatoryZThreshold && $decreeElapsed === 0) {
            $decreeElapsed = $streams->startRegime(self::REGIME_CONSENT_DECREE);
            $regulatoryShock = $regulatorySeverity; // Massive antitrust / surveillance fine
            $eventType = ShockEvent::REGULATORY_FINE;
        } elseif ($eventZ < self::SEVERE_CHURN_Z_SCORE) {
            $regulatoryShock = self::SEVERE_CHURN_PENALTY;
            $eventType = ShockEvent::SEVERE_CHURN;
        } elseif ($eventZ > self::VIRAL_GROWTH_Z_SCORE) {
            $eventType = ShockEvent::VIRAL_GROWTH;
        }

        // Blended dual-stream revenue (SaaS Subscription vs. Digital Advertising & Platform Usage)
        // Subscription ARR has lower baseline volatility (0.6x scalar), whereas Ads take the full swing plus cyclicality
        // Subscriptions are BOOKED as annual contracts and recognized ratably from deferred revenue, so an
        // ARR shock reaches the income statement over the contract term rather than in one quarter.
        $subscriptionBook = $streams->recognizeBacklog('subscription', $expectedRevenue * $subWeight,
            max(0.0, 1.0 + ($subscriptionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.6))), self::SUBSCRIPTION_RECOGNITION_RATE);
        $subscriptionRevenue = $subscriptionBook['revenue'];

        $viralMultiplier = ($eventType === ShockEvent::VIRAL_GROWTH) ? self::VIRAL_GROWTH_REV_MULT : 1.0;
        $adRevenue = max(0.0, $expectedRevenue * $adWeight
            * (1.0 + ($adZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $adCyclicality)
            * $viralMultiplier);

        $streamRevenues = [
            'subscription' => $subscriptionRevenue,
            'advertising'  => $adRevenue,
        ];

        $cloudRevenue = 0.0;
        $cloudZ = 0.0;
        if ($cloudWeight > 0.0) {
            $cloudZ = $streams->generateZ('cloud_infrastructure', 0.60); // High persistence, sticky enterprise contracts
            $cloudRevenue = max(0.0, $expectedRevenue * $cloudWeight * (1.0 + ($cloudZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.4)));
            $streamRevenues['cloud_infrastructure'] = $cloudRevenue;
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Supply Chain Immunity vs. Continuous Talent Inflation: the basket is almost all variable payroll,
        // so wage growth above trend is the only input price that reaches the cost base.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        // SaaS ARR Operating Leverage:
        // ARR expansion ($subscriptionZ > 0) creates positive operating leverage due to zero marginal cost of software delivery.
        $saasOperatingLeverageShift = -self::SAAS_OPERATING_LEVERAGE * $subscriptionZ * $subWeight;

        // Crucially, antitrust and data privacy regulatory penalties apply proportionally to the Advertising
        // & Platform data-harvesting stream ($adWeight), insulating enterprise subscription margins.
        $complianceDrag = $decreeElapsed > 1 ? self::CONSENT_DECREE_COMPLIANCE_PENALTY * (0.5 + $aggression) : 0.0;
        $adCostAddon = ($regulatoryShock + $complianceDrag) * $adWeight;
        $rawMargin = $realizedVariableMargin + $inputCostDrag + $saasOperatingLeverageShift + $adCostAddon - $marginBonus;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock Z-score selects the most extreme driver across streams
        $primaryShockZ = $streams->resolveDominantShockZ([$subscriptionZ, $adZ], $eventZ);

        $observableShockZ = (($subscriptionZ * $subWeight) + ($adZ * $adWeight)) * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
                    kpis: ['bookings_to_revenue' => $subscriptionBook['book_to_bill'], 'deferred_revenue_quarters' => $subscriptionBook['backlog_quarters']],
        );
    }

    public function getMarginReversionSpeed(): float
    {
        return self::TECH_REVERSION_SPEED; // Rapid innovation cycles and intense technological competition erode excess margins quickly
    }

    /** Software tech debt & customer churn decay toward floor */
    public function getDepreciationDecayRate(): float
    {
        return self::TECH_DEBT_DECAY_RATE;
    }

    /** Cloud ARR platform modernization expands SaaS margin ceiling */
    public function getModernizationGainRate(): float
    {
        return self::PLATFORM_MODERNIZATION_GAIN_RATE;
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        // Asset-light software & tech equities trade on cash earnings, DCF, and recurring revenue power, not Book Value.
        return $dividendSupportValue > 0.0
            ? ($earningsValue * (1.0 - FinancialConstants::FAIR_VALUE_DDM_WEIGHT)) + ($dividendSupportValue * FinancialConstants::FAIR_VALUE_DDM_WEIGHT)
            : $earningsValue;
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
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
