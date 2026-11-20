<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Company\IndustryBetas;
use App\Data\Macro\InputOutputExposures;
use App\DTO\DebtExpansionAppetiteDTO;
use App\DTO\DebtHealthDTO;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Corporate\Earnings\FixedAssetLedger;
use App\Service\Event\ShockEvent;
use App\Service\Market\Bond\RatingMethodology;
use App\Service\Market\Pricing\SeedRows;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Real Estate Investment Trusts (REITs).
 */
class ReitBusinessModel extends StandardCorporateBusinessModel
{
    // --- Credit Rating ---
    /** Rated on its capital on undepreciated real estate and S&P's real estate debt-to-EBITDA bands. */
    public const RATING_METHODOLOGY = RatingMethodology::RealEstate;

    // --- Opening Books ---
    /**
     * A trust still states its operating margin: its return is historic-cost NOI yield before depreciation, not a
     * DuPont moat spread.
     */
    public const OPENING_CHARACTER_KEYS = ['revenue', 'operating_margin', 'addressable_share'];
    /** What resolveOpeningBooks() builds for a trust; operating_margin remains a character input. */
    public const OPENING_BOOK_KEYS = [
        'baseline_roic', 'total_equity', 'wholesale_debt', 'customer_deposits', 'corporate_treasury', 'retained_earnings',
        'sam_ratio',
    ];

    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Contracted leases lag
     * the cycle.
     */
    public const OPERATING_CYCLICALITY = 0.80;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.30;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.40;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::REIT;
    /**
     * Utility contracts and service agreements reprice annually, so a spot move reaches property opex over about a
     * year.
     */
    public const INPUT_COST_LAG_YEARS = 0.75;
    /** Operating-expense recoveries and CAM reconciliations bill tenants in arrears, a year or more behind the cost. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 1.00;

    // --- Pricing Power ---
    /**
     * In-place leases carry contractual escalators the landlord collects regardless of the market, but re-leasing
     * spreads are set by the submarket, so recovery is high on the stock and weak at the margin.
     */
    public const PRICING_POWER_INDEX = 0.60;

    // --- Balance Sheet Realism ---
    /**
     * Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). The REIT is the
     * lessor, not the lessee.
     */
    public const LEASE_LIABILITY_INTENSITY = 0.00;

    // --- Demand Transmission Lag ---
    /**
     * Years for a move in the output gap to reach the order book. Rent rolls turn over on multi-year leases: a downturn
     * reaches a landlord only as space comes up for renewal.
     */
    public const DEMAND_LAG_YEARS = 1.50;

    // --- District REIT Distribution Rule ---
    /**
     * Share of taxable income a District REIT must distribute each year to stay a pass-through (US: 90% under IRC 857,
     * 85% calendar-year excise floor under IRC 4981).
     */
    public const DISTRIBUTION_REQUIREMENT = 0.85;

    // --- Solvency on Undepreciated Assets (points of equity per 100 of undepreciated assets) ---
    /**
     * Equity at the standard unsecured REIT bond covenant, total debt at most 60% of total undepreciated assets: below
     * it the trust is in the agency's grey zone.
     */
    public const WARNING_EQUITY_THRESHOLD = 40.0;
    /** Equity at debt of 75% of undepreciated assets: distressed, its unencumbered pool gone. */
    public const DISTRESS_EQUITY_THRESHOLD = 25.0;
    /** Equity at debt of 90% of undepreciated assets: insolvent, the property worth less than what is owed on it. */
    public const BANKRUPT_EQUITY_THRESHOLD = 10.0;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into predictable contracted commercial real estate cash flows. */
    public const BASE_COVERAGE_VISIBILITY = 0.70;
    /** Standard deviation of Wall Street analyst error when estimating REIT revenues. */
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Cap Rate & Portfolio Turnover Rails ---
    /** Fraction of property portfolio acquired/divested per quarter adjusting baseline cap rate. */
    public const PORTFOLIO_TURNOVER_RATE    = 0.025;

    // --- Lease Ladder ---
    /**
     * Weighted average lease term of the rent roll in years. Only the slice expiring each quarter reprices; the rest is
     * contractually fixed.
     */
    public const LEASE_WALT_YEARS = 6.0;
    /**
     * Persisted state key: in-place rents against the neutral market rent, carried across quarters as the roll turns
     * over. Renamed when the ladder moved from property values to market rents, so a running game starts it at market.
     */
    public const STATE_IN_PLACE_RENT = 'state:in_place_market_rent';
    /**
     * Bound on the mark-to-market gap between in-place and market rents, past which tenants renegotiate or hand back
     * space.
     */
    public const MAX_RELEASING_SPREAD = 0.50;
    /** Absolute maximum cap rate clamp floor to prevent unrealistically high property yields. */
    public const MAX_CAP_RATE_CLAMP         = 0.15;
    /** Maximum spread buffer above 10-year Treasury yield allowed for market cap rates. */
    public const CAP_RATE_CEILING_SPREAD    = 0.12;

    // --- EBIT Yield & ROIC Blending ---
    /** Weight of target net operating income yield when blending with historical ROIC. */
    public const TARGET_EBIT_WEIGHT         = 0.50;
    /** Weight of trailing twelve month ROIC when blending with target EBIT yield. */
    public const TTM_ROIC_WEIGHT            = 0.50;

    // --- Macro Demand Sensitivity ---
    /** Sensitivity of contractual lease demand and hospitality utilization to real GDP output gap. */
    public const MACRO_DEMAND_SCALAR            = 0.25;

    // --- Revenue & Vacancy Shock Physics ---
    /** Base volatility scalar applied to sticky commercial lease revenues. */
    public const REVENUE_VARIANCE_SCALAR        = 0.05;
    /** Volatility multiplier for daily-rate hospitality and hotel revenues. */
    public const HOSPITALITY_VARIANCE_SCALAR    = 1.50;
    /** Volatility multiplier for securitized mortgage and debt packaging income. */
    public const SECURITIZATION_VARIANCE_SCALAR = 2.00;
    /** Volatility multiplier for longevity-linked bond yields and pension assets. */
    public const LONGEVITY_VARIANCE_SCALAR      = 0.25;
    /**
     * Room rates move more than leased rents: US ADR fell 8.8% in 2009 (STR) against a DiPasquale-Wheaton rent fall of
     * ~3% at that year's -5.9% CBO gap.
     */
    public const HOSPITALITY_RATE_LEVERAGE      = 3.0;
    /**
     * Market rent given up per unit of the new-supply competition shift, as new buildings bid for the same tenants;
     * carried over from the value-based model, not separately fitted.
     */
    public const NEW_SUPPLY_RENT_DRAG           = 0.03;

    /**
     * Rent roll lost per unit of district catastrophe burden against an average year, both ways: damaged space earns
     * nothing until repaired, and the average year's damage is already in the baseline.
     */
    public const CATASTROPHE_VACANCY_SENSITIVITY = 0.02;
    /** Tenant-default Z-score below which failing tenants hand back space. */
    public const VACANCY_Z_THRESHOLD            = -1.50;
    /** Rent roll lost per unit of tenant-default Z-score past the threshold: 12% at the threshold. */
    public const VACANCY_LOSS_SCALAR            = 0.08;
    /** Tenant-default Z-score above which a strong leasing market fills vacant space. */
    public const BENIGN_LEASING_Z_FLOOR         = 1.00;
    /** Rent roll won per unit of tenant-default Z-score above the floor. */
    public const LEASING_BONUS_SCALE            = 0.015;
    /**
     * Annual economic wear and maintenance capex rate (1.5%) required to keep buildings physically intact, distinct
     * from GAAP/tax book depreciation (~3%). Used for upkeep spend, the AFFO reserve and the property cap rate.
     */
    public const ECONOMIC_UPKEEP_RATE           = 0.015;
    /**
     * Quarterly share of the fixed-rate bond stock that matures and reprices (~7-year ladder of unsecured notes and
     * mortgages), so rate shocks reach FFO with a lag.
     */
    public const DEBT_MATURITY_ROLLOVER_RATE    = 0.035;
    /**
     * Minimum cash operating expense ratio (cash opex / revenue) floor after depreciation is carved out (NNN floor).
     */
    public const MIN_EFFICIENCY_RATIO           = 0.15;

    // --- Housing Supply & Tenant Default Transmission ---
    /** Sensitivity of new building supply competition to residential housing starts. */
    public const HOUSING_SUPPLY_COMPETITION_SENSITIVITY = 0.25;
    /** Rent roll lost per unit of the corporate default rate over its measured trend, relative, both ways. */
    public const CORP_DEFAULT_VACANCY_SCALAR = 0.04;
    /** Rent roll lost per unit of the retail default rate over its measured trend, relative, both ways. */
    public const RETAIL_DEFAULT_VACANCY_SCALAR = 0.03;
    /** Rent roll lost per unit of household debt service over its trend, relative to the neutral ratio, both ways. */
    public const DSR_TENANT_DEFAULT_SCALAR = 0.15;

    // --- Event Lore Thresholds ---
    /** Severe tenant default Z-score triggering catastrophic commercial bankruptcy lore. */
    public const LORE_ANCHOR_BANKRUPTCY_Z   = -2.00;
    /** Moderate vacancy Z-score triggering elevated lease vacancy lore. */
    public const LORE_ELEVATED_VACANCY_Z    = -1.50;

    // --- REIT Reversion & Valuation ---
    /** IRC Section 857 corporate tax rate exemption for qualifying pass-through REITs. */
    public const PASS_THROUGH_TAX_RATE      = 0.00;
    /** Fallback positive interest coverage ratio when interest expense is zero. */
    public const INFINITE_ICR_POS_FALLBACK  = 999.0;
    /** Fallback negative interest coverage ratio when operating income and interest expense are zero/negative. */
    public const INFINITE_ICR_NEG_FALLBACK  = -999.0;

    // --- Credit Risk & Recovery ---
    /**
     * Loss given default on debt secured by tangible real estate: the senior secured recovery, 62%
     * (CorporateDefaultService; Moody's ultimate recoveries 1987-2021, 61.5%).
     */
    public const REIT_LOSS_GIVEN_DEFAULT = 1.0 - \App\Service\Market\Bond\CorporateDefaultService::RECOVERY_SENIOR_SECURED;

    // --- Aggressive Property Acquisition Borrowing ---
    /** Base probability of REIT expanding balance sheet debt to fund property acquisitions. */
    public const DEBT_EXPANSION_BASE_PROB   = 0.40;
    /** Sensitivity of debt expansion probability to favorable yield spread multiplier. */
    public const DEBT_EXPANSION_PROB_MULT   = 0.50;
    /** Base fraction of debt capacity utilized during property acquisition cycle. */
    public const DEBT_EXPANSION_BASE_AGGR   = 0.10;
    /** Sensitivity of debt expansion utilization to positive yield spreads. */
    public const DEBT_EXPANSION_AGGR_MULT   = 0.25;

    // --- Valuation & Lease Resistance Moat ---
    /** Half-life speed (quarters) at which long-term lease margins revert toward sector equilibrium. */
    public const MARGIN_REVERSION_SPEED      = 2.0;

    // --- Return Reversion ---
    /**
     * Annual share of a rent-roll return gap that persists a year on (0.80), matching the trust's 6-year WALT lease
     * ladder and 20% annual return reversion speed (Ohlson 1995).
     */
    public const RETURN_PERSISTENCE = 0.80;

    // --- Capital Cycle ---
    /** Share of construction in progress completed into plant each quarter (12.5%). */
    public const CAPEX_COMPLETION_RATE = 0.125;
    /** Net working capital as a share of revenue: none. */
    public const WORKING_CAPITAL_INTENSITY = 0.0;

    // --- Credit Thresholds ---
    /** Minimum interest coverage the firm is held to (1.05x). */
    public const MIN_ICR = 1.05;
    /** Interest coverage below which a firm without a cash buffer is in a dividend crisis (1.05x). */
    public const DIVIDEND_CRISIS_ICR = 1.05;
    /** Interest coverage below which the firm stops repurchasing stock (1.15x). */
    public const BUYBACK_MIN_ICR = 1.15;

    /**
     * Market cap rate (NOI over market property value): property's unlevered required return (CAPM on its asset beta)
     * less expected rent growth (ten-year breakeven inflation) plus annual economic upkeep.
     */
    public static function capRate(float $assetBeta, MacroStateDTO $macroState): float
    {
        $requiredReturn = Valuation::calculateCAPM(
            $macroState->equityRiskFreeRate,
            $assetBeta,
            MacroConstants::BASE_EQUITY_RISK_PREMIUM,
        );

        return Valuation::propertyCapRate($requiredReturn, $macroState->tipsBreakeven, self::ECONOMIC_UPKEEP_RATE);
    }

    /**
     * Historic-cost book NOI yield (NOI over depreciated net book PP&E) implied by the market cap rate on an
     * established portfolio whose books depreciate faster than physical wear while values inflate.
     */
    public static function bookCapRate(float $assetBeta, float $bookDepreciationRate, MacroStateDTO $macroState): float
    {
        return self::capRate($assetBeta, $macroState) * Valuation::historicCostPropertyValueRatio(
            MacroConstants::TREND_REAL_GROWTH,
            MacroConstants::TARGET_INFLATION,
            $bookDepreciationRate,
            self::ECONOMIC_UPKEEP_RATE,
        );
    }

    /**
     * The trust's revenue is anchored on its historic-cost book NOI yield (getTargetMetrics()), so it opens there; its
     * moat accrues through the return's reversion (updateDynamicRoic()).
     *
     * @param array<string, mixed> $seedRow
     */
    protected function openingReturn(array $seedRow, MacroStateDTO $openingMacro): float
    {
        return self::bookCapRate(
            IndustryBetas::seedBetaFor($seedRow),
            (float) $seedRow['depreciation_rate'],
            $openingMacro,
        );
    }

    /**
     * Trusts state their operating margin directly instead of deriving it from IndustryTurnovers.
     *
     * @param array<string, mixed> $seedRow
     */
    protected function assertOpeningCharacter(array $seedRow): void
    {
        SeedRows::assertCharacter($seedRow, self::OPENING_CHARACTER_KEYS, self::OPENING_BOOK_KEYS, [
            'revenue' => (float) ($seedRow['revenue'] ?? 0.0) > 0.0,
            'operating_margin' => (float) ($seedRow['operating_margin'] ?? 0.0) > 0.0 && (float) ($seedRow['operating_margin'] ?? 1.0) < 1.0,
            'addressable_share' => (float) ($seedRow['addressable_share'] ?? 0.0) > 0.0,
            'retained_share' => (float) ($seedRow['retained_share'] ?? 0.0) >= 0.0 && (float) ($seedRow['retained_share'] ?? 0.0) <= 1.0,
        ]);
    }

    /**
     * Trusts open on the operating margin stated in their seed row.
     *
     * @param array<string, mixed> $seedRow
     */
    protected function openingMargin(array $seedRow, float $openingReturn, float $taxRate): float
    {
        return (float) $seedRow['operating_margin'];
    }

    public function getMaintenanceCapexRate(float $bookDepreciationRate): float
    {
        return self::ECONOMIC_UPKEEP_RATE;
    }

    /**
     * A trust's return is NOI before book depreciation, while OperatingCapacityEngine carves book depreciation out of
     * operating costs so EBIT = revenue x margin. Turnover is struck on the after-depreciation operating return
     * (NOI yield less book depreciation), making EBIT + depreciation equal baselineRoic x invested capital.
     */
    public function resolveStructuralAssetTurnover(float $baselineRoic, float $operatingMargin, float $effectiveTaxRate, float $depreciationRate): float
    {
        return FirmEconomics::structuralAssetTurnover(max(0.01, $baselineRoic - $depreciationRate), $operatingMargin, $effectiveTaxRate);
    }

    public function resolveOperatingBusinessValue(Stock $stock, float $valuationReturn, float $assetCostOfCapital, float $expectedNominalGrowth, MacroStateDTO $macroState): float
    {
        return Valuation::propertyValue(
            $stock->getInvestedCapital(),
            $valuationReturn,
            self::ECONOMIC_UPKEEP_RATE,
            $assetCostOfCapital,
            $macroState->tipsBreakeven,
        );
    }

    /**
     * A property trust's operating return reverts toward its current market book cap rate (updateDynamicRoic()), so its
     * long-run valuation return moves with bookCapRate() plus whatever realized excess its historical anchor carries
     * over its portfolio cap rate. Existing leases keep their spread until they roll (RETURN_PERSISTENCE).
     */
    public function resolveLongRunReturn(Stock $stock, ?MacroStateDTO $macroState = null): ?float
    {
        $anchor = parent::resolveLongRunReturn($stock, $macroState);
        if ($macroState === null) {
            return $anchor;
        }
        $baseline = (float) $stock->getBaselineRoic();
        $excess = ($anchor !== null && $baseline > 0.0) ? ($anchor - $baseline) : 0.0;

        return max(
            0.01,
            self::bookCapRate((float) $stock->getBeta(), FixedAssetLedger::depreciationRateFor($stock), $macroState) + $excess,
        );
    }

    public function getTargetMetrics(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $investedCapital = $stock->getInvestedCapital();
        $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());

        $minCapRate = FinancialConstants::MIN_PERPETUAL_GROWTH_SPREAD + self::ECONOMIC_UPKEEP_RATE;
        $targetCapRate = self::bookCapRate((float) $stock->getBeta(), FixedAssetLedger::depreciationRateFor($stock), $macroState);

        $portfolioTurnoverRate = self::PORTFOLIO_TURNOVER_RATE;
        $maxCapRate = max(self::MAX_CAP_RATE_CLAMP, $macroState->yield10yEma + self::CAP_RATE_CEILING_SPREAD);
        $blendedCapRate = min($maxCapRate, max($minCapRate, ($baselineRoic * (1.0 - $portfolioTurnoverRate)) + ($targetCapRate * $portfolioTurnoverRate)));
        $stock->setBaselineRoic((string) max($minCapRate, $blendedCapRate));

        $targetEbitYield = $blendedCapRate;

        $ttmRoic = (float) $stock->getRoicTtm();
        if ($ttmRoic !== 0.0) {
            $targetEbitYield = ($targetEbitYield * self::TARGET_EBIT_WEIGHT) + ($ttmRoic * self::TTM_ROIC_WEIGHT);
        }

        $saturationPenalty = \App\Service\Corporate\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, $investedCapital, $macroState);
        $effectiveRoic = max(0.01, $targetEbitYield - $saturationPenalty);

        return [
            'invested_capital' => $investedCapital,
            'baseline_roic' => $effectiveRoic,
        ];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        $physics['pricing_power_multiplier'] = 1.0;
        // Inflation is carried inside this model's own stream physics: neither price nor cost base inflates at the
        // engine level.
        $physics['input_cost_multiplier'] = 1.0;
        $outputGap = $this->resolveLaggedOutputGap($macroState);
        $beta = $this->getOperatingCyclicality($stock);
        // REITs hold domestic real estate with sticky contracted leases; scale output gap demand shift appropriately
        $physics['macro_demand_shift'] = $outputGap * self::MACRO_DEMAND_SCALAR * $beta;
        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::StickyLeaseWeight->value            => 0.85,
            ModelParam::VariableHospitalityWeight->value    => 0.15,
            ModelParam::SecuritizationIncomeWeight->value   => 0.00,
            ModelParam::LongevityBondYieldWeight->value     => 0.00,
        ]);

        $rawSecuritizationWeight = $params[ModelParam::SecuritizationIncomeWeight];
        $rawLongevityWeight      = $params[ModelParam::LongevityBondYieldWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $targetWeights = [
            'lease'       => $params[ModelParam::StickyLeaseWeight],
            'hospitality' => $params[ModelParam::VariableHospitalityWeight],
        ];
        if ($rawSecuritizationWeight > 0.0) {
            $targetWeights['securitization_income'] = $rawSecuritizationWeight;
        }
        if ($rawLongevityWeight > 0.0) {
            $targetWeights['longevity_bond_yield'] = $rawLongevityWeight;
        }

        // --- Revenue Mix ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $leaseWeight          = $activeWeights['lease'];
        $hospitalityWeight    = $activeWeights['hospitality'];
        $securitizationWeight = $activeWeights['securitization_income'] ?? 0.0;
        $longevityWeight      = $activeWeights['longevity_bond_yield'] ?? 0.0;

        $revenueZ     = $streams->generateDemandZ('lease');
        $hospitalityZ = $streams->generateDemandZ('hospitality');
        $securitizationZ = $securitizationWeight > 0.0 ? $streams->generateZ('securitization_income', 0.40) : 0.0;
        $longevityZ      = $longevityWeight > 0.0 ? $streams->generateZ('longevity_bond_yield', 0.60) : 0.0;
        $tenantDefaultZ  = $streams->generateExogenousZ('tenant_default', 0.20);

        // Core Revenue Shocks
        $leaseShock          = $revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $hospitalityShock    = $hospitalityZ * ($baselineVol * self::HOSPITALITY_VARIANCE_SCALAR);
        $securitizationShock = $securitizationZ * $baselineVol * self::SECURITIZATION_VARIANCE_SCALAR;
        $longevityShock      = $longevityZ * $baselineVol * self::LONGEVITY_VARIANCE_SCALAR;

        // Market rent is the space market (DiPasquale & Wheaton 1996), not the asset price: a lower cap rate lifts what
        // a building is worth, not what a tenant pays. Apartment rents follow jobs and income the same way.
        $marketRent = $macroState->commercialMarketRent - 1.0;
        // New buildings bid for the same tenants, which takes their share off the rent a new lease signs at.
        $housingSupplyShift = MacroTransmission::calculateHousingStartsShift($macroState->housingStartsIndexEma, sensitivity: self::HOUSING_SUPPLY_COMPETITION_SENSITIVITY);
        $leasingMarketRent = $marketRent - ($housingSupplyShift * self::NEW_SUPPLY_RENT_DRAG);
        // Lease ladder: roll in-place rents toward market at the quarterly rollover rate implied by WALT. The roll's
        // level, not its quarterly change, is what the book of leases bills.
        $inPlaceRent = $streams->getPersistedState(self::STATE_IN_PLACE_RENT, $leasingMarketRent);
        [$rolledInPlaceRent, $releasingSpread] = FirmEconomics::rollLeaseLadder($inPlaceRent, $leasingMarketRent, self::LEASE_WALT_YEARS, self::MAX_RELEASING_SPREAD, FinancialConstants::QUARTER_YEARS);
        $streams->registerState(self::STATE_IN_PLACE_RENT, $rolledInPlaceRent);

        // --- Vacancy ---
        // Failing tenants hand back space and a strong leasing market fills it, net of the loss the roll was built
        // at; business and household defaults above their trends empty space across the market, and fewer fill it.
        $dsrShift = $macroState->householdDebtServiceGap / MacroConstants::HOUSEHOLD_DSR_NEUTRAL;
        $macroTenantVacancy = ($macroState->corporateDefaultDeviation() * self::CORP_DEFAULT_VACANCY_SCALAR)
            + ($macroState->retailDefaultDeviation() * self::RETAIL_DEFAULT_VACANCY_SCALAR)
            + ($dsrShift * self::DSR_TENANT_DEFAULT_SCALAR);
        $vacancy = self::tenantVacancy($tenantDefaultZ) - self::expectedTenantVacancy() + $macroTenantVacancy;
        // Storm damage takes space off the rent roll until it is repaired.
        $catastropheVacancy = ($macroState->catastropheLossIndexEma - 1.0) * self::CATASTROPHE_VACANCY_SENSITIVITY;

        // --- Clamped Revenue Streams ---
        $leaseRevenue       = max(0.0, $expectedRevenue * $leaseWeight * (1.0 + $leaseShock + $rolledInPlaceRent - $vacancy - $catastropheVacancy));
        // The roll's rent level and empty space reprice the same square footage: the building's operating cost runs
        // whatever it bills. CPI indexation needs no term here: the engine carries realised inflation on the
        // property's capacity.
        $priceRevenue       = $expectedRevenue * $leaseWeight * ($rolledInPlaceRent - $vacancy);
        $hospitalityRevenue = max(0.0, $expectedRevenue * $hospitalityWeight * (1.0 + $hospitalityShock + ($marketRent * self::HOSPITALITY_RATE_LEVERAGE)));

        $streamRevenues = [
            'lease'       => $leaseRevenue,
            'hospitality' => $hospitalityRevenue,
        ];
        if ($securitizationWeight > 0.0) {
            $streamRevenues['securitization_income'] = max(0.0, $expectedRevenue * $securitizationWeight * (1.0 + $securitizationShock));
        }
        if ($longevityWeight > 0.0) {
            $streamRevenues['longevity_bond_yield'] = max(0.0, $expectedRevenue * $longevityWeight * (1.0 + $longevityShock));
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Mortgage and unsecured note costs reach FFO through DebtEngine's maturity wall
        // (getDebtMaturityRolloverRate), below NOI. They are not a property operating cost.
        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        // Property operating expense: utilities, on-site payroll and repairs, recovered from tenants through
        // CAM and opex reconciliations that bill a year in arrears.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inputCostDrag, $minVariableMargin);

        $eventType = null;
        if ($tenantDefaultZ < self::LORE_ANCHOR_BANKRUPTCY_Z) {
            $eventType = ShockEvent::REIT_TENANT_BANKRUPTCIES;
        } elseif ($tenantDefaultZ < self::LORE_ELEVATED_VACANCY_Z) {
            $eventType = ShockEvent::REIT_ELEVATED_VACANCIES;
        }

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            priceRevenue: $priceRevenue,
            // The two numbers a real estate analyst reads first: how long the roll is contracted for, and
            // what the space coming up is worth against what it currently earns.
            kpis: [
                'walt_years' => self::LEASE_WALT_YEARS,
                'releasing_spread' => $releasingSpread,
                'in_place_rent_index' => $rolledInPlaceRent,
            ],
        );
    }

    /** Rent roll a tenant-default draw takes off (positive) or a strong leasing market adds (negative). */
    private static function tenantVacancy(float $tenantDefaultZ): float
    {
        if ($tenantDefaultZ < self::VACANCY_Z_THRESHOLD) {
            return abs($tenantDefaultZ) * self::VACANCY_LOSS_SCALAR;
        }

        return $tenantDefaultZ > self::BENIGN_LEASING_Z_FLOOR
            ? -($tenantDefaultZ - self::BENIGN_LEASING_Z_FLOOR) * self::LEASING_BONUS_SCALE
            : 0.0;
    }

    /**
     * The vacancy an average quarter's draw takes off the roll (0.9%): the loss tail past the threshold,
     * E[|Z|; Z < t] = phi(t), less the leasing gains above the floor, E[max(0, Z - f)].
     */
    public static function expectedTenantVacancy(): float
    {
        return (self::VACANCY_LOSS_SCALAR * Distributions::calculateNormalPDF(self::VACANCY_Z_THRESHOLD))
            - (self::LEASING_BONUS_SCALE * Distributions::calculateNormalLowerPartialMoment(-self::BENIGN_LEASING_Z_FLOOR));
    }

    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?MacroStateDTO $macroState = null, float $depreciation = 0.0, float $afterTaxSpecialCharges = 0.0): float
    {
        // Net Operating Income is property income before depreciation, and it is the numerator of the cap rate this
        // return reverts toward below. EBIT is struck after depreciation, so the charge is added back here to recover
        // NOI.
        $effectiveCapital = max(1.0, abs($investedCapital));
        $truePostTaxReturn = (($ebit + $depreciation) / $effectiveCapital) * FinancialConstants::QUARTERS_PER_YEAR;

        $stock->setCurrentRoic((string) max(FinancialConstants::MIN_REPORTED_RETURN, min(FinancialConstants::MAX_REPORTED_RETURN, $truePostTaxReturn)));

        // The return a property book is required to earn on historic-cost capital is its book cap rate.
        $targetYield = $macroState !== null
            ? self::bookCapRate((float) $stock->getBeta(), FixedAssetLedger::depreciationRateFor($stock), $macroState)
            : $wacc;

        $stock->setRoicTtm((string) $this->revertTrailingReturn($stock, (float) $stock->getRoicTtm(), $truePostTaxReturn, $targetYield, self::TTM_ROIC_WEIGHT, abs($investedCapital), $macroState));

        return $truePostTaxReturn;
    }

    /** A trust's return is its untaxed net operating income, struck before depreciation, so it is EBITDA already. */
    public function getNormalEbitda(Stock $stock, float $macroTaxRate, float $depreciation): ?float
    {
        $longRunReturn = $stock->getLongRunReturn();

        return $longRunReturn === null ? null : (float) $longRunReturn * $stock->getInvestedCapital();
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return self::PASS_THROUGH_TAX_RATE;
    }

    public function calculateEconomicReturn(Stock $stock, float $quarterlyNopat, float $investedCapital): float
    {
        return $investedCapital > 0 ? ($quarterlyNopat / $investedCapital) * FinancialConstants::QUARTERS_PER_YEAR : 0.0;
    }

    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0, float $interestIncome = 0.0): float
    {
        // Funds From Operations (NAREIT): EBIT sits below the depreciation line,
        // so real estate depreciation is added back here. A building's book depreciation is famously
        // disconnected from its economic wear, which is exactly why the industry covenants on FFO.
        $ffo = $ebit + $depreciation + $interestIncome;
        return $interestExpense > 0 ? ($ffo / $interestExpense) : ($ffo > 0 ? self::INFINITE_ICR_POS_FALLBACK : self::INFINITE_ICR_NEG_FALLBACK);
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): DebtExpansionAppetiteDTO
    {
        return new DebtExpansionAppetiteDTO(probability: self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT), aggressiveness: self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier));
    }

    /**
     * A trust distributes most of what it earns, so growth borrowed past its manager's target leverage would be funded
     * by debt alone; past it, it issues shares, as TreasuryEngine::fundPlannedPlant() already does for pass-throughs.
     */
    public function calculateDebtExpansionCapacity(float $equity, float $totalDebt, float $wholesaleDebt, DebtHealthDTO $health, float $newBorrowingRate, float $ebit, float $depreciation): float
    {
        $evalDebt = $totalDebt;
        $evalTolerance = min($health->debtTolerance, $health->leverageTarget);
        $balanceSheetCapacity = max(0.0, ($equity * $evalTolerance) - $evalDebt);

        $minimumIcr = $this->getBuybackMinIcr() + 0.5;

        $operatingIncome = $ebit;

        $maxTolerableInterest = max(0.0, $operatingIncome / $minimumIcr);
        $currentInterestExpense = $health->rawMetrics->interestExpense ?? 0.0;
        $availableInterestCapacity = max(0.0, $maxTolerableInterest - $currentInterestExpense);
        $incomeStatementCapacity = $newBorrowingRate > 0 ? ($availableInterestCapacity / $newBorrowingRate) : 0.0;

        return min($incomeStatementCapacity, $balanceSheetCapacity);
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        // Commercial Real Estate REITs trade primarily on Distributable Cash Flow / Dividend Yields and Net Asset Value
        // (NAV / Book).
        if ($dividendSupportValue > 0.0) {
            return ($dividendSupportValue * 0.50) + ($pbFairValue * 0.35) + ($earningsValue * 0.15);
        }
        return ($pbFairValue * 0.60) + ($earningsValue * 0.40);
    }

    /**
     * Pass-through status is conditional on distributing taxable income; the trust pays no corporate tax on what it
     * distributes.
     */
    public function getMinimumDistributionRatio(): float
    {
        return self::DISTRIBUTION_REQUIREMENT;
    }

    /**
     * A trust is valued and forecast on funds from operations: its buildings hold their value while the books
     * depreciate them.
     */
    public function reportsFundsFromOperations(): bool
    {
        return true;
    }

    public function getLossGivenDefault(): float
    {
        return self::REIT_LOSS_GIVEN_DEFAULT;
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
            'catastrophe_loss_index_ema',
            'commercial_market_rent',
            'corporate_default_rate_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'household_debt_service_gap',
            'housing_starts_index_ema',
            'import_price_level',
            'output_gap_lag_18m',
            'retail_default_rate_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
