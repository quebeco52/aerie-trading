<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;

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
 * Earnings strategy for Restaurant Franchisors & Fast Food Giants.
 *
 * Financial Physics:
 * - Tri-Stream Franchisor Architecture:
 *      1. Company-Operated Stores: High variable food/labor/energy costs, low margin, traffic volatility.
 *      2. Franchise Royalties & Ad Pool Fees: Pure royalty (% of franchisee gross sales) with near 100% margin.
 *      3. Franchise Real Estate Leases: Captive real estate rental income with CPI escalation clauses.
 * - Inflation Sensitivity: Food commodities and kitchen labor compress company-operated margins,
 *   while franchise real estate leases provide a reliable inflation hedge.
 */
class RestaurantBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Dining out is cut in
     * downturns; restaurants substitute freely.
     */
    public const OPERATING_CYCLICALITY = 1.00;
    /**
     * Dining demand per unit of the confidence mood at unit cyclicality (0.40): ten points of mood beside the cycle
     * move covers 4%, the sentiment loading the other discretionary consumer models carry.
     */
    public const DINING_MOOD_SENSITIVITY = 0.40;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.80;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.70;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::RESTAURANT;
    /**
     * Menu prices move freely but the diner's next-best meal is one storefront away, so a chain recovers only part of a
     * food or wage move before traffic answers.
     */
    public const PRICING_POWER_INDEX = 0.45;

    // --- Balance Sheet Realism ---
    /**
     * Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Franchisor real
     * estate and store leases are the largest obligation on a restaurant balance sheet.
     */
    public const LEASE_LIABILITY_INTENSITY = 0.60;

    // --- Tri-Stream Architecture ---
    /** Baseline fraction of revenue derived from company-owned store operations. */
    public const CORPORATE_WEIGHT       = 0.50;
    /** Baseline fraction of revenue derived from high-margin franchise royalties. */
    public const FRANCHISE_WEIGHT       = 0.30;
    /** Baseline fraction of revenue derived from franchise real estate rental leases. */
    public const FRANCHISE_LEASE_WEIGHT = 0.20;
    /**
     * Franchise rent's exposure to store traffic against sales': base rent plus a percentage of sales, half the swing.
     */
    public const FRANCHISE_LEASE_CYCLE_LOADING = 0.50;

    // --- Working Capital by Stream (shares of the stream's revenue) ---
    /**
     * Company stores: card takings in ~4 days, ~5 days of sales in food and paper, suppliers and crew owed ~26 days.
     */
    public const COMPANY_STORES_WORKING_CAPITAL = ['receivables' => 0.01, 'inventory' => 0.015, 'payables' => 0.07];
    /** Royalties: billed monthly on franchisees' sales and collected in ~22 days. */
    public const FRANCHISE_ROYALTIES_WORKING_CAPITAL = ['receivables' => 0.06, 'inventory' => 0.0, 'payables' => 0.01];
    /** Franchise rent: billed monthly ahead and collected in ~11 days. */
    public const FRANCHISE_LEASES_WORKING_CAPITAL = ['receivables' => 0.03, 'inventory' => 0.0, 'payables' => 0.01];

    /** The stores carry a few days of food and owe their suppliers; the franchisor is owed its royalties and rent. */
    protected function resolveWorkingCapitalShares(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::CompanyStoresWeight->value      => self::CORPORATE_WEIGHT,
            ModelParam::FranchiseRoyaltiesWeight->value => self::FRANCHISE_WEIGHT,
            ModelParam::FranchiseLeaseWeight->value     => self::FRANCHISE_LEASE_WEIGHT,
        ]);

        return FirmEconomics::blendWorkingCapitalShares(
            [$params[ModelParam::CompanyStoresWeight], $params[ModelParam::FranchiseRoyaltiesWeight], $params[ModelParam::FranchiseLeaseWeight]],
            [self::COMPANY_STORES_WORKING_CAPITAL, self::FRANCHISE_ROYALTIES_WORKING_CAPITAL, self::FRANCHISE_LEASES_WORKING_CAPITAL],
        );
    }

    // --- Pricing Power & Macro Physics ---
    /** Menu boards reprice within a couple of quarters; franchisors pass food and wage inflation to guests quickly. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.50;

    // --- Revenue & Shock Physics ---
    public const REVENUE_VARIANCE_SCALAR = 0.40;

    // --- Stream Cost Allocation ---
    /** Royalty variable cost per dollar, relative to a company-store dollar. */
    public const FRANCHISE_COST_INTENSITY = 0.05;
    /** Franchise rent variable cost per dollar, relative to a company-store dollar. */
    public const LEASE_COST_INTENSITY     = 0.02;
    /**
     * Most a company-store sales dollar can cost: food & paper 31.2% plus crew payroll 29.6% (McDonald's 2023 10-K).
     */
    public const COMPANY_STORE_VARIABLE_COST_CEILING = 0.608;

    // --- Tail Risk & Shock Events ---
    public const FOOD_SAFETY_SCANDAL_Z_SCORE = -2.20;
    /** Onset-quarter variable cost hit from inventory write-offs, closures and crisis response. */
    public const FOOD_SAFETY_SCANDAL_PENALTY = 0.08;
    /** Regime key for the multi-quarter guest-traffic recovery that follows a food-safety scandal. */
    public const REGIME_FOOD_SAFETY_RECOVERY = 'food_safety_recovery';
    /** Quarterly probability the scandal drops out of guest memory (~7 quarter expected recovery). */
    public const FOOD_SAFETY_RECOVERY_EXIT_HAZARD = 0.15;
    /** Share of company-store traffic lost in the scandal quarter. */
    public const FOOD_SAFETY_TRAFFIC_LOSS = 0.15;
    /** Quarterly geometric rate at which lost guests return (half-life ~2 quarters). */
    public const FOOD_SAFETY_TRAFFIC_RECOVERY_RATE = 0.35;
    /** Ongoing quarterly cost of food-safety audits and win-back marketing during the recovery. */
    public const FOOD_SAFETY_REMEDIATION_PENALTY = 0.02;
    public const VIRAL_MENU_ITEM_Z_SCORE     = 2.40;
    public const VIRAL_MENU_ITEM_MULT        = 1.15;

    public function getMoatSpread(): float
    {
        return 0.015;
    }

    public function getSeasonalityFactors(): array
    {
        return [0.85, 1.10, 1.20, 0.85]; // Spring/summer patio dining and vacation demand
    }

    // --- Secular Demand ---
    /**
     * Food services and drinking places value added as a share of US nominal GDP in 1997 (BEA GDP by Industry, value
     * added).
     */
    public const SECULAR_SHARE_1997 = 0.0176;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0228;

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    /**
     * Dining out follows the cycle through the parent's output-gap shift; confidence adds only what the gap does not
     * already explain (Lemmon & Portniaguina 2006).
     */
    protected function resolveCycleDemandShift(Stock $stock, MacroStateDTO $macroState): float
    {
        return parent::resolveCycleDemandShift($stock, $macroState)
            + ($macroState->sentimentResidual() * self::DINING_MOOD_SENSITIVITY * $this->getOperatingCyclicality($stock));
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::CompanyStoresWeight->value     => self::CORPORATE_WEIGHT,
            ModelParam::FranchiseRoyaltiesWeight->value => self::FRANCHISE_WEIGHT,
            ModelParam::FranchiseLeaseWeight->value    => self::FRANCHISE_LEASE_WEIGHT,
        ]);

        $corporateWeight = $params[ModelParam::CompanyStoresWeight];
        $franchiseWeight = $params[ModelParam::FranchiseRoyaltiesWeight];
        $leaseWeight     = $params[ModelParam::FranchiseLeaseWeight];
        $pricingPower    = $this->resolvePricingPower($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'company_operated_stores'      => $params[ModelParam::CompanyStoresWeight],
            'franchise_royalties'          => $params[ModelParam::FranchiseRoyaltiesWeight],
            'franchise_real_estate_leases' => $params[ModelParam::FranchiseLeaseWeight],
        ]);

        $corporateWeight = $activeWeights['company_operated_stores'];
        $franchiseWeight = $activeWeights['franchise_royalties'];
        $leaseWeight     = $activeWeights['franchise_real_estate_leases'];

        // Independent stream Z-scores
        $corporateZ = $streams->generateZ('company_operated_stores', 0.10);
        $franchiseZ = $streams->generateZ('franchise_royalties', 0.20);
        $leaseZ     = $streams->generateZ('franchise_real_estate_leases', 0.50);
        $eventZ     = $streams->generateExogenousZ('event', 0.10);

        // Tail Risk Events
        // A food-safety scandal is a one-quarter write-off followed by a multi-quarter traffic recovery:
        // lost guests return geometrically while audits and win-back marketing keep costs elevated.
        $recoveryElapsed = $streams->evolveRegime(self::REGIME_FOOD_SAFETY_RECOVERY, 0.0, self::FOOD_SAFETY_RECOVERY_EXIT_HAZARD);
        $viralMultiplier = 1.0;
        $eventType = null;
        $foodSafetyPenalty = 0.0;
        $scandalWriteOff = 0.0;

        if ($eventZ < self::FOOD_SAFETY_SCANDAL_Z_SCORE && $recoveryElapsed === 0) {
            $recoveryElapsed = $streams->startRegime(self::REGIME_FOOD_SAFETY_RECOVERY);
            $scandalWriteOff = self::FOOD_SAFETY_SCANDAL_PENALTY;
            $eventType = ShockEvent::PRODUCT_RECALL;
        } elseif ($eventZ > self::VIRAL_MENU_ITEM_Z_SCORE) {
            $viralMultiplier = self::VIRAL_MENU_ITEM_MULT;
            $eventType = ShockEvent::VIRAL_GROWTH;
        }

        if ($recoveryElapsed > 0) {
            $trafficLoss = self::FOOD_SAFETY_TRAFFIC_LOSS * exp(-self::FOOD_SAFETY_TRAFFIC_RECOVERY_RATE * ($recoveryElapsed - 1));
            $viralMultiplier *= (1.0 - $trafficLoss);
            if ($recoveryElapsed > 1) {
                $foodSafetyPenalty += self::FOOD_SAFETY_REMEDIATION_PENALTY;
            }
        }

        // Store traffic carries the cycle into company sales and the royalty on franchisee sales; the franchise rent
        // roll carries FRANCHISE_LEASE_CYCLE_LOADING of it.
        $cycleShares = MacroTransmission::allocateDemandShift(
            ['company_operated_stores' => $corporateWeight, 'franchise_royalties' => $franchiseWeight, 'franchise_real_estate_leases' => $leaseWeight],
            ['company_operated_stores' => 1.0, 'franchise_royalties' => 1.0, 'franchise_real_estate_leases' => self::FRANCHISE_LEASE_CYCLE_LOADING],
            $this->resolveCycleDemandShift($stock, $macroState),
        );

        $corporateRevenue = max(0.0, $expectedRevenue * $corporateWeight * (1.0 + ($corporateZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $cycleShares['company_operated_stores']) * $viralMultiplier);
        $franchiseRevenue = max(0.0, $expectedRevenue * $franchiseWeight * (1.0 + ($franchiseZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.15)) + $cycleShares['franchise_royalties']));
        $leaseRevenue     = max(0.0, $expectedRevenue * $leaseWeight     * (1.0 + ($leaseZ     * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.05)) + $cycleShares['franchise_real_estate_leases']));

        $streamRevenues = [
            'company_operated_stores'      => $corporateRevenue,
            'franchise_royalties'          => $franchiseRevenue,
            'franchise_real_estate_leases' => $leaseRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        $costRatios = self::resolveStreamCostRatios($corporateWeight, $franchiseWeight, $leaseWeight, $realizedVariableMargin);
        $actualVariableCosts = ($corporateRevenue * $costRatios['company']) + ($franchiseRevenue * $costRatios['franchise']) + ($leaseRevenue * $costRatios['lease']);

        // Input cost basket: food commodities, kitchen crew wages, utilities and packaging reach company-operated
        // kitchens at spot and are recovered on the menu board with the repricing lag. Franchise royalties and
        // rents carry almost no variable cost, which the blended cost ratio above already reflects.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        $rawMargin = ($actualVariableCosts / max(1.0, $actualRevenue)) + $foodSafetyPenalty + $inputCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        $observableShockZ = ($corporateZ * $corporateWeight * self::REVENUE_VARIANCE_SCALAR * $baselineVol) ;

        // The one-off charge is booked as a special item: in EBIT, out of the run rate, the Street figure and the cost
        // ratio.
        $specialCharge = $scandalWriteOff * max(0.0, $actualRevenue);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            specialCharge: $specialCharge,
        );
    }

    /**
     * Variable cost ratio of each stream, weighted to the firm's variable cost ratio at plan. A company-store dollar
     * costs at most a company store's food, paper and crew; what a franchisor's cost base holds beyond that is system
     * cost (ad funds, field support) that scales with franchisee sales, so it rides the royalty and rent streams.
     *
     * @return array{company: float, franchise: float, lease: float}
     */
    public static function resolveStreamCostRatios(float $corporateWeight, float $franchiseWeight, float $leaseWeight, float $variableMargin): array
    {
        $franchiseIntensity = (self::FRANCHISE_COST_INTENSITY * $franchiseWeight) + (self::LEASE_COST_INTENSITY * $leaseWeight);
        $divisor = $corporateWeight + $franchiseIntensity;
        if ($divisor <= 0.0) {
            return ['company' => $variableMargin, 'franchise' => $variableMargin, 'lease' => $variableMargin];
        }

        $companyRatio = $variableMargin / $divisor;
        if ($franchiseIntensity > 0.0) {
            $companyRatio = min(self::COMPANY_STORE_VARIABLE_COST_CEILING, $companyRatio);
        }
        $franchiseScale = $franchiseIntensity > 0.0 ? max(0.0, $variableMargin - ($corporateWeight * $companyRatio)) / $franchiseIntensity : 0.0;

        return [
            'company'   => $companyRatio,
            'franchise' => self::FRANCHISE_COST_INTENSITY * $franchiseScale,
            'lease'     => self::LEASE_COST_INTENSITY * $franchiseScale,
        ];
    }

    public function getCoverageProfile(Stock $stock): \App\DTO\SectorCoverageProfile
    {
        return new \App\DTO\SectorCoverageProfile(
            baseVisibility: 0.30,
            errorStdDev: 0.05,
            minVisibility: 0.10,
            eventBaseVisibility: 0.90,
            eventMinVisibility: 0.50,
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
            'agricultural_commodity_index_ema',
            'consumer_sentiment_index_ema',
            'exchange_rate_index_ema',
            'import_price_level',
            'output_gap_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
