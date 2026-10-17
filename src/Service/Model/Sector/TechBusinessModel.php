<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\SectorPhysicsResult;

use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;

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
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Enterprise budgets
     * and ad spend are cyclical; seats churn slowly.
     */
    public const OPERATING_CYCLICALITY = 1.20;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.60;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::TECH;
    /**
     * Switching costs, data gravity and seat-based contracts let a platform reprice an installed base with little
     * churn.
     */
    public const PRICING_POWER_INDEX = 0.75;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: Q4 enterprise budget flush and holiday
     * advertising.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.95, 0.98, 0.97, 1.10];
    }

    // --- Balance Sheet Realism ---
    /**
     * Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Office campuses and
     * colocation leases.
     */
    public const LEASE_LIABILITY_INTENSITY = 0.10;
    /**
     * Stock-based compensation (ASC 718) as a share of revenue: Software (System & Application), 7.83% of revenue, 309
     * firms (Damodaran, Employee data by industry, US, January 2026).
     */
    public const STOCK_COMPENSATION_INTENSITY = 0.0783;

    // --- FX Exposure ---
    /**
     * Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Software and cloud are sold
     * worldwide from a home cost base, so a strong domestic currency translates foreign ARR down.
     */
    public const FX_REVENUE_EXPOSURE = 0.15;

    // --- Demand Transmission Lag ---
    /**
     * Years for a move in the output gap to reach bookings. Enterprise IT budgets are set annually, so a downturn cuts
     * renewals and seats at the next budget round, not this quarter.
     */
    public const DEMAND_LAG_YEARS = 0.50;

    // --- Reporting Incentives ---
    /**
     * Propensity to steer reported earnings toward consensus with accruals. Equity compensation ties management's own
     * wealth to the print, and deferred revenue schedules give them the timing discretion to act on it.
     */
    public const EARNINGS_MANAGEMENT_PROPENSITY = 0.60;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    public const BASE_COVERAGE_ERROR = 0.05;
    public function getReversionSpeed(): float
    {
        return 0.1;
    }
    public function getMoatSpread(): float
    {
        return 0.025;
    }
    public function getCapExCompletionRate(Stock $stock): float
    {
        return 0.5;
    }
    // --- Secular Demand ---
    /**
     * Business and household spending on software as a share of US nominal GDP in 1997 (BEA NIPA Table 5.3.5, private
     * fixed investment and Table 2.4.5).
     */
    public const SECULAR_SHARE_1997 = 0.0118;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0265;

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }
    public function getCapexCyclicality(): float
    {
        return 1.0;
    }

    // --- Dual-Stream Tech & Software Architecture ---
    /** Baseline fraction of revenue derived from recurring SaaS subscription & cloud infrastructure. */
    public const SUBSCRIPTION_REVENUE_WEIGHT    = 0.60;
    /** Baseline fraction of revenue derived from digital advertising networks & platform usage fees. */
    public const ADVERTISING_REVENUE_WEIGHT     = 0.40;
    /**
     * Advertising's exposure to the cycle, the reference stream: marketing budgets are the first cut and the first
     * restored.
     */
    public const ADVERTISING_CYCLE_LOADING = 1.00;
    /** Subscription bookings' exposure to the cycle against advertising's: annual seats renew through a slump, 0.40. */
    public const SUBSCRIPTION_CYCLE_LOADING = 0.40;
    /** Usage-billed cloud's exposure to the cycle against advertising's: compute follows customers' activity, 0.70. */
    public const CLOUD_CYCLE_LOADING = 0.70;
    /**
     * Fraction of deferred subscription bookings recognized as revenue each quarter (annual contracts, ASC 606
     * ratable).
     */
    public const SUBSCRIPTION_RECOGNITION_RATE = 0.25;

    // --- Working Capital by Stream (shares of the stream's revenue) ---
    /**
     * Subscriptions: invoices on ~44-day terms against seats billed ahead, ~91 days of revenue held as deferred revenue
     * and payables.
     */
    public const SUBSCRIPTION_WORKING_CAPITAL = ['receivables' => 0.12, 'inventory' => 0.0, 'payables' => 0.25];
    /** Advertising: advertisers pay in ~55 days; publishers' revenue share and suppliers are owed ~22 days of sales. */
    public const ADVERTISING_WORKING_CAPITAL = ['receivables' => 0.15, 'inventory' => 0.0, 'payables' => 0.06];
    /** Cloud: usage billed monthly in arrears, paid in ~55 days; data-centre suppliers owed ~29 days of sales. */
    public const CLOUD_WORKING_CAPITAL = ['receivables' => 0.15, 'inventory' => 0.0, 'payables' => 0.08];

    /**
     * Software holds no stock: each stream is owed by its customers, and subscriptions are paid for ahead of the
     * service.
     */
    protected function resolveWorkingCapitalShares(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::SubscriptionRevenueWeight->value => self::SUBSCRIPTION_REVENUE_WEIGHT,
            ModelParam::AdvertisingRevenueWeight->value  => self::ADVERTISING_REVENUE_WEIGHT,
            ModelParam::CloudInfrastructureWeight->value => 0.00,
        ]);

        return FirmEconomics::blendWorkingCapitalShares(
            [$params[ModelParam::SubscriptionRevenueWeight], $params[ModelParam::AdvertisingRevenueWeight], $params[ModelParam::CloudInfrastructureWeight]],
            [self::SUBSCRIPTION_WORKING_CAPITAL, self::ADVERTISING_WORKING_CAPITAL, self::CLOUD_WORKING_CAPITAL],
        );
    }

    // --- Revenue Volatility ---
    /** Volatility multiplier for top-line revenue shocks reflecting rapid software user scaling and churn. */
    public const REVENUE_VARIANCE_SCALAR   = 0.20;

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
    /** Minimum interest coverage ratio required to permit recapitalization for intangible asset software models. */
    public const MIN_RECAP_ICR_FLOOR       = 15.0;
    /** Target leverage as a share of the debt tolerance; below it the firm is under-levered. */
    public const UNDERLEVERAGED_DEBT_RATIO = 0.50;

    // --- SaaS ARR Operating Leverage & Software Platform Reinvestment Physics ---
    /** Variable margin sensitivity to SaaS ARR expansion (zero marginal cost of software delivery). */
    public const SAAS_OPERATING_LEVERAGE      = 0.022;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::SubscriptionRevenueWeight->value => self::SUBSCRIPTION_REVENUE_WEIGHT,
            ModelParam::AdvertisingRevenueWeight->value  => self::ADVERTISING_REVENUE_WEIGHT,
            ModelParam::CloudInfrastructureWeight->value => 0.00,
            ModelParam::AdvertisingCyclicality->value     => self::ADVERTISING_CYCLE_LOADING,
            ModelParam::MonopolyAggression->value         => 0.5,
        ]);

        $rawCloudWeight      = $params[ModelParam::CloudInfrastructureWeight];
        $adCycleLoading      = $params[ModelParam::AdvertisingCyclicality];

        $aggression = max(0.0, min(1.0, $params[ModelParam::MonopolyAggression]));
        // The reward of aggressive pricing and data harvesting is already in the seeded margin the engine hands in;
        // what aggression adds here is regulatory scrutiny. At aggression=1.0, Z-score threshold shifts from -2.5 to
        // -1.25 (frequent fines), and penalty severity is 1.5x. At aggression=0.0, Z-score threshold shifts to -3.75
        // (nearly impossible), and penalty is 0.5x.
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
        // Enterprise SaaS ARR & Cloud compute contract volume
        $subscriptionZ = $streams->generateZ('subscription', 0.45);
        // Digital advertising auction demand & impression volume
        $adZ           = $streams->generateZ('advertising', 0.30);
        // Fat-tail regulatory antitrust / data breach Z-score
        $eventZ        = $streams->generateExogenousZ('event', 0.10);

        // The firm-wide cycle lands on all of expected revenue; it is carried by advertising, less by usage-billed
        // cloud and least by annual subscription seats.
        $cycleShares = MacroTransmission::allocateDemandShift(
            $activeWeights,
            ['subscription' => self::SUBSCRIPTION_CYCLE_LOADING, 'advertising' => $adCycleLoading, 'cloud_infrastructure' => self::CLOUD_CYCLE_LOADING],
            $this->resolveCycleDemandShift($stock, $macroState),
        );

        // Fat Tail Risk: Data Breaches, Anti-Trust, and Viral Breakthroughs
        // A settlement is a one-quarter fine followed by a multi-year consent decree: court-supervised
        // compliance spending keeps the ad stack's cost base elevated until the decree lapses.
        $decreeElapsed = $streams->evolveRegime(self::REGIME_CONSENT_DECREE, 0.0, self::CONSENT_DECREE_EXIT_HAZARD);
        $regulatoryShock = 0.0;
        $regulatoryFine  = 0.0;
        $eventType       = null;

        if ($eventZ < $regulatoryZThreshold && $decreeElapsed === 0) {
            $decreeElapsed = $streams->startRegime(self::REGIME_CONSENT_DECREE);
            $regulatoryFine = $regulatorySeverity; // Massive antitrust / surveillance fine
            $eventType = ShockEvent::REGULATORY_FINE;
        } elseif ($eventZ < self::SEVERE_CHURN_Z_SCORE) {
            $regulatoryShock = self::SEVERE_CHURN_PENALTY;
            $eventType = ShockEvent::SEVERE_CHURN;
        } elseif ($eventZ > self::VIRAL_GROWTH_Z_SCORE) {
            $eventType = ShockEvent::VIRAL_GROWTH;
        }

        // Blended dual-stream revenue (SaaS Subscription vs. Digital Advertising & Platform Usage) Subscription ARR has
        // lower baseline volatility (0.6x scalar), whereas Ads take the full swing plus cyclicality Subscriptions are
        // BOOKED as annual contracts and recognized ratably from deferred revenue, so an ARR shock reaches the income
        // statement over the contract term rather than in one quarter.
        $subscriptionBook = $streams->recognizeBacklog(
            'subscription',
            $expectedRevenue * $subWeight,
            max(0.0, 1.0 + ($subscriptionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.6)) + $cycleShares['subscription']),
            self::SUBSCRIPTION_RECOGNITION_RATE,
        );
        $subscriptionRevenue = $subscriptionBook['revenue'];

        $viralMultiplier = ($eventType === ShockEvent::VIRAL_GROWTH) ? self::VIRAL_GROWTH_REV_MULT : 1.0;
        $adRevenue = max(0.0, $expectedRevenue * $adWeight
            * (1.0 + ($adZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $cycleShares['advertising'])
            * $viralMultiplier);

        $streamRevenues = [
            'subscription' => $subscriptionRevenue,
            'advertising'  => $adRevenue,
        ];

        $cloudRevenue = 0.0;
        $cloudZ = 0.0;
        if ($cloudWeight > 0.0) {
            // High persistence, sticky enterprise contracts
            $cloudZ = $streams->generateZ('cloud_infrastructure', 0.60);
            $cloudRevenue = max(0.0, $expectedRevenue * $cloudWeight * (1.0 + ($cloudZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.4) + $cycleShares['cloud_infrastructure']));
            $streamRevenues['cloud_infrastructure'] = $cloudRevenue;
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Supply Chain Immunity vs. Continuous Talent Inflation: the basket is almost all variable payroll,
        // so wage growth above trend is the only input price that reaches the cost base.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        // SaaS ARR Operating Leverage: ARR expansion ($subscriptionZ > 0) creates positive operating leverage due to
        // zero marginal cost of software delivery.
        $saasOperatingLeverageShift = -self::SAAS_OPERATING_LEVERAGE * $subscriptionZ * $subWeight;

        // Crucially, antitrust and data privacy regulatory penalties apply proportionally to the Advertising
        // & Platform data-harvesting stream ($adWeight), insulating enterprise subscription margins.
        $complianceDrag = $decreeElapsed > 1 ? self::CONSENT_DECREE_COMPLIANCE_PENALTY * (0.5 + $aggression) : 0.0;
        $adCostAddon = ($regulatoryShock + $complianceDrag) * $adWeight;
        $rawMargin = $realizedVariableMargin + $inputCostDrag + $saasOperatingLeverageShift + $adCostAddon;
        $clampedMargin = $this->clampMargin($rawMargin);

        $observableShockZ = (($subscriptionZ * $subWeight) + ($adZ * $adWeight)) * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);

        // The one-off charge is booked as a special item: in EBIT, out of the run rate, the Street figure and the cost
        // ratio.
        $specialCharge = $regulatoryFine * $adWeight * $actualRevenue;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: ['bookings_to_revenue' => $subscriptionBook['book_to_bill'], 'deferred_revenue_quarters' => $subscriptionBook['backlog_quarters']],
            specialCharge: $specialCharge,
        );
    }

    public function getMarginReversionSpeed(): float
    {
        // Rapid innovation cycles and intense technological competition erode excess margins quickly
        return self::TECH_REVERSION_SPEED;
    }

    /** Asset-light platforms trade on cash earnings, DCF and recurring revenue power, not on book value. */
    protected function getFairValueBookWeight(float $normalizedEps): float
    {
        return 0.0;
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
            'output_gap_lag_6m',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
