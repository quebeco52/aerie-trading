<?php

declare(strict_types=1);

namespace App\DTO;

use App\Data\Macro\MacroFieldRegistry;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FixedIncome;
use App\Service\Math\Valuation;
use App\Service\Model\Sector\UtilityBusinessModel;

/**
 * Immutable Data Transfer Object representing a snapshot of the macroeconomic state.
 * Replaces weakly-typed associative arrays ($macroState) across models and engines.
 */
readonly class MacroStateDTO
{
    // --- Index Normalization ---

    /** Points in one unit of a 100-based macro index, so a deviation off one reads as a fraction. */
    private const INDEX_SCALE = 100.0;

    // --- Openings ---

    /**
     * Policy rate at the opening: the Taylor rule's own target with output at trend (3.42%), so the first tick does not
     * move it.
     */
    public const OPENING_POLICY_RATE = 0.034;

    /** Ten-year at the opening: the engine's curve evaluated at the opening state. */
    public const OPENING_YIELD_10Y = 0.0462;

    // --- Demand Transmission Lags ---

    /**
     * The output gap as it reaches order books 3 to 18 months behind the economy
     * (MacroAggregateSubsystem::demandTransmissionLagField).
     */
    public float $outputGapLag3m;
    public float $outputGapLag6m;
    public float $outputGapLag9m;
    public float $outputGapLag12m;
    public float $outputGapLag15m;
    public float $outputGapLag18m;

    /**
     * The macro vector's fields, and the one place their opening values are declared.
     *
     * The opening is the economy at trend, so a fresh engine, a snapshot read before the ticker has
     * published, the seeded market and a test fixture all start from one state that the first tick does
     * not jolt. The slow states that carry the cycle -- the policy rate, sovereign debt and its premium --
     * open where the engine holds them with output at trend (median over quarters with |gap| and its EMA
     * under 0.6%, off the lower bound, no credit crisis, inflation within 0.6pp of target; 32 seeds x
     * 100y), and the curve is the engine's own curve at that state. Cyclical gaps and shocks open at zero.
     * Everything firms read as a deviation from a named reference -- unemployment, spreads, default rates,
     * money growth, the real wage gap, prices and indices -- opens at that reference, so the opening reads
     * neutral to every model. App\Service\Macro\MacroState takes its openings from here.
     */
    public function __construct(
        public float $totalTime = 0.0,
        public float $outputGap = 0.0,
        public float $outputGapEma = 0.0,
        ?float $outputGapLag3m = null,
        ?float $outputGapLag6m = null,
        ?float $outputGapLag9m = null,
        ?float $outputGapLag12m = null,
        ?float $outputGapLag15m = null,
        ?float $outputGapLag18m = null,
        public float $capitalStockOverhang = 0.0,
        public float $capitalStockOverhangEma = 0.0,
        public float $unemploymentRate = MacroEngine::NATURAL_UNEMPLOYMENT,
        public float $unemploymentRateEma = MacroEngine::NATURAL_UNEMPLOYMENT,
        /**
         * @var list<float> The unemployment rate at each of the last month ends, oldest first, as many as the Sahm rule
         *                  reads.
         */
        public array $unemploymentMonthly = [],
        /**
         * The Sahm rule's reading: the three-month average unemployment rate above its lowest of the twelve months
         * before (App\Service\Macro\Subsystem\LaborMarketSubsystem::recordSahmIndicator()).
         */
        /**
         * Real GDP summed over the quarter so far, and the years it covers: the statistics office's quarterly average
         * (MacroAggregateSubsystem::recordQuarterlyRealGdp()).
         */
        public float $realGdpQuarterAccrued = 0.0,
        public float $realGdpQuarterElapsed = 0.0,
        /** The last full quarter's average real GDP; zero before the first. */
        public float $realGdpLastQuarter = 0.0,
        /** Consecutive quarters of rising (positive) or falling (negative) real GDP. */
        public float $gdpQuarterStreak = 0.0,
        /** The last full quarter's real GDP change over the one before. */
        public float $realGdpQuarterlyGrowth = 0.0,
        /**
         * Whether the statistics office has a recession declared: two quarters of falling real GDP, ended by two of
         * growth.
         */
        public bool $recessionDeclared = false,
        public float $jobVacanciesRate = MacroEngine::NATURAL_UNEMPLOYMENT * MacroEngine::NATURAL_LABOR_TIGHTNESS,
        public float $jobVacanciesRateEma = MacroEngine::NATURAL_UNEMPLOYMENT * MacroEngine::NATURAL_LABOR_TIGHTNESS,
        public float $laborTightness = MacroEngine::NATURAL_LABOR_TIGHTNESS,
        public float $laborTightnessEma = MacroEngine::NATURAL_LABOR_TIGHTNESS,
        public float $wageGrowth = MacroEngine::TARGET_INFLATION + MacroEngine::TFP_DRIFT,
        public float $wageGrowthEma = MacroEngine::TARGET_INFLATION + MacroEngine::TFP_DRIFT,
        public float $realWageGap = 0.0,
        public float $nairu = MacroEngine::NATURAL_UNEMPLOYMENT,
        public float $nairuEma = MacroEngine::NATURAL_UNEMPLOYMENT,
        public float $naturalRate = MacroEngine::BASE_NATURAL_RATE,
        public float $naturalRateEma = MacroEngine::BASE_NATURAL_RATE,
        public float $energyPriceIndex = 100.0,
        public float $energyPriceIndexEma = 100.0,
        public float $energyPriceIndexTrend = 0.0,
        /** Decade trend of the energy index the supply drag reads (MacroAggregateSubsystem::supplyCostShift()). */
        public float $energyPriceIndexDecadeTrend = MacroEngine::ENERGY_BASELINE,
        /** Decade trend of the freight index the supply drag reads. */
        public float $freightRateIndexDecadeTrend = MacroEngine::FREIGHT_BASELINE,
        public float $energyPriceShock = 0.0,
        public float $energyBasePrice = 100.0,
        public float $energySupplyEma = MacroEngine::ENERGY_BASELINE,
        public float $energyCostPushLag = 0.0,
        public float $agriCostPushLag = 0.0,
        public float $noncorePassThroughEma = 0.0,
        public float $domesticInflation = MacroEngine::TARGET_INFLATION,
        public float $consumerSentimentIndex = MacroEngine::SENTIMENT_TREND_LEVEL,
        public float $consumerSentimentIndexEma = MacroEngine::SENTIMENT_TREND_LEVEL,
        /**
         * The part of confidence its fundamentals do not explain, in index points
         * (AssetMarketSubsystem::calculateConsumerSentiment()).
         */
        public float $consumerSentimentMood = 0.0,
        public float $exchangeRateIndex = 100.0,
        public float $exchangeRateIndexEma = 100.0,
        public float $exchangeRateTrend = 100.0,
        public float $exchangeRateDeviation = 1.0,
        public float $industrialMetalsIndex = 100.0,
        public float $industrialMetalsIndexEma = 100.0,
        public float $industrialMetalsIndexTrend = 0.0,
        public float $metalsChi = 0.0,
        public float $metalsXi = 4.60517,
        public float $governmentSpendingIndex = 100.0,
        public float $governmentSpendingIndexEma = 100.0,
        /**
         * Purchases' decade trend, which a level the Diet holds that long joins
         * (CreditFiscalSubsystem::discretionarySpendingShift()).
         */
        public float $governmentSpendingIndexTrend = 100.0,
        public float $alliedDefenseSpendingIndex = MacroEngine::ALLIED_DEFENSE_BASELINE,
        public float $alliedDefenseSpendingIndexEma = MacroEngine::ALLIED_DEFENSE_BASELINE,
        public float $commercialPropertyIndex = 100.0,
        public float $commercialPropertyIndexEma = 100.0,
        /**
         * Real market rent per occupied square foot of District commercial space, 1 at neutral: the DiPasquale-Wheaton
         * rent level the price index capitalises.
         */
        public float $commercialMarketRent = 1.0,
        public float $residentialPropertyIndex = 100.0,
        public float $residentialPropertyIndexEma = 100.0,
        public float $residentialWealthTrend = 100.0,
        public float $residentialPriceMomentum = 0.0,
        public float $retailDefaultRate = MacroEngine::RETAIL_DEFAULT_BASELINE,
        public float $retailDefaultRateEma = MacroEngine::RETAIL_DEFAULT_BASELINE,
        public float $retailCreditFactor = 0.0,
        public float $agriculturalCommodityIndex = 100.0,
        public float $agriculturalCommodityIndexEma = 100.0,
        public float $agriculturalCommodityIndexTrend = 0.0,
        public float $agriChi = 0.0,
        public float $agriXi = 4.60517,
        public float $freightRateIndex = 100.0,
        public float $freightRateIndexEma = 100.0,
        public float $freightSupplyEma = 100.0,
        public float $inflation = MacroEngine::TARGET_INFLATION,
        public float $inflationEma = MacroEngine::TARGET_INFLATION,
        public float $tipsBreakeven = MacroEngine::TARGET_INFLATION,
        public float $tipsBreakevenEma = MacroEngine::TARGET_INFLATION,
        /**
         * The breakeven the market quotes, net of the TIPS liquidity premium
         * (MacroAggregateSubsystem::quotedTipsBreakeven()); for display.
         */
        public float $tipsBreakevenQuoted = MacroEngine::TARGET_INFLATION,
        public float $tipsBreakevenQuotedEma = MacroEngine::TARGET_INFLATION,
        public float $policyRate = self::OPENING_POLICY_RATE,
        public float $policyRateEma = self::OPENING_POLICY_RATE,
        /**
         * The Authority's view of the right rate, which closes on the rule between meetings; a meeting rounds it to the
         * step (MonetaryPolicySubsystem::updatePolicyRate()).
         */
        public float $policyRateShadow = self::OPENING_POLICY_RATE,
        /** The decision the market expects at the next meeting. */
        public float $policyRateExpected = self::OPENING_POLICY_RATE,
        /** The last meeting's decision less what the market expected of it; zero between meetings. */
        public float $policySurprise = 0.0,
        /**
         * The inflation over target and the output gap the rule answered, which each camp on the committee weighs its
         * own way.
         */
        public float $ruleInflationGap = 0.0,
        public float $ruleOutputGap = 0.0,
        public float $targetRate = self::OPENING_POLICY_RATE,
        public float $yield2y = 0.0375,
        public float $yield2yEma = 0.0375,
        public float $yield5y = 0.0416,
        public float $yield5yEma = 0.0416,
        public float $yield10y = self::OPENING_YIELD_10Y,
        public float $yield10yEma = self::OPENING_YIELD_10Y,
        public float $yield30y = 0.0522,
        public float $yield30yEma = 0.0522,
        public float $termPremium10y = 0.0115,
        public float $termPremium10yEma = 0.0115,
        public float $riskNeutral10y = 0.0347,
        public float $riskNeutral10yEma = 0.0347,
        public float $termPremiumShock = 0.0,
        public float $expectedPathShock = 0.0,
        public float $termPremiumRegime = MacroEngine::NS_BASE_TERM_PREMIUM,
        /**
         * The mainland's ten-year term premium factors, the global factor the District's loads on
         * (App\Service\Macro\Subsystem\MonetaryPolicySubsystem::updateTermPremiumDynamics()).
         */
        public float $foreignTermPremiumShock = 0.0,
        public float $foreignTermPremiumRegime = MacroEngine::NS_BASE_TERM_PREMIUM,
        public float $perceivedNeutralRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION,
        public float $marketVolatility = 0.14,
        public float $marketVolatilityEma = 0.14,
        public float $marketZ = 0.0,
        public float $marketZLatent = 0.0,
        public float $marketJumpMultiplier = 1.0,
        /** @var array<string, float> Per-macro-sector shock, keyed by Sectors::MACRO_SECTORS. */
        public array $sectorZ = [],
        /**
         * @var array<string, float> Persistent per-macro-sector demand factor shared by every firm's earnings physics
         *                           in the sector.
         */
        public array $sectorDemandZ = [],
        public float $corporateTaxRate = MacroEngine::BASE_CORPORATE_TAX_RATE,
        /**
         * The tax rate's measured structural level, a decade's exponential average
         * (App\Service\Macro\Subsystem\CreditFiscalSubsystem::calculateDynamicFiscalPolicy()).
         */
        public float $corporateTaxRateTrend = MacroEngine::BASE_CORPORATE_TAX_RATE,
        /**
         * The budget's take outside the corporate tax, net of transfers, as a share of GDP: what carries the automatic
         * stabilisers and the fiscal stance.
         */
        public float $netTaxRate = CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE,
        /**
         * The discretionary part of net taxes: the founding share plus the structural stance, without the automatic
         * stabilisers' swing.
         */
        public float $netTaxStanceRate = CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE,
        /** Its decade trend, as corporateTaxRateTrend is the corporate rate's. */
        public float $netTaxStanceRateTrend = CreditFiscalSubsystem::FOUNDING_NET_TAX_RATE,
        public float $sovereignDebtToGdp = MacroEngine::INITIAL_DEBT_TO_GDP,
        /**
         * Interest rate the coupon stock of the debt pays, repricing toward the 10-year as it rolls
         * (App\Service\Macro\Subsystem\CreditFiscalSubsystem::calculateSovereignDebt()).
         */
        public float $sovereignCouponRate = self::OPENING_YIELD_10Y,
        public float $sovereignDebtToGdpEma = MacroEngine::INITIAL_DEBT_TO_GDP,
        public float $sovereignNetDebtToGdp = MacroEngine::INITIAL_DEBT_TO_GDP,
        public float $sovereignNetDebtToGdpEma = MacroEngine::INITIAL_DEBT_TO_GDP,
        /**
         * Gross debt less the whole fund and the rescue holdings, minus net financial worth: what the fiscal reaction
         * and the spread read (CreditFiscalSubsystem::calculateSovereignDebt()).
         */
        public float $sovereignNetFinancialLiabilitiesToGdp = MacroEngine::INITIAL_DEBT_TO_GDP,
        public float $sovereignNetFinancialLiabilitiesToGdpEma = MacroEngine::INITIAL_DEBT_TO_GDP,
        /**
         * Average maturity of the debt, in years, and the one the Exchequer's head steers it toward as it rolls over
         * (CreditFiscalSubsystem).
         */
        public float $sovereignDebtAverageMaturity = CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY,
        public float $sovereignDebtMaturityTarget = CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY,
        public float $equityRiskPremium = MacroEngine::BASE_EQUITY_RISK_PREMIUM,
        public float $macroCreditSpread = MacroEngine::BASE_CREDIT_SPREAD,
        public float $macroCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD,
        /**
         * The IG spread's measured through-the-cycle level, a decade's exponential average
         * (App\Service\Macro\Subsystem\CreditFiscalSubsystem::updateCreditSpreadTrend()).
         */
        public float $macroCreditSpreadTrend = MacroEngine::BASE_CREDIT_SPREAD,
        public float $interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD,
        public float $interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD,
        public float $excessBondPremium = 0.0,
        /** Decade trend of the excess bond premium the credit drag reads. */
        public float $excessBondPremiumTrend = 0.0,
        public float $totalFactorProductivityIndex = MacroEngine::TFP_BASELINE,
        public float $totalFactorProductivityIndexEma = MacroEngine::TFP_BASELINE,
        public float $tfpShockLevel = 0.0,
        /** Scar credit crises have booked that potential has yet to take (log level, negative). */
        public float $crisisScarPending = 0.0,
        /** Scar potential took this tick (log level, negative). */
        public float $crisisScarStep = 0.0,
        /**
         * Labour-force growth migration adds on top of the law's as it answers the labour market, a year
         * (LaborMarketSubsystem::calculateCyclicalMigration()).
         */
        public float $cyclicalMigrationRate = 0.0,
        public float $tfpOutputStage1 = 0.0,
        public float $tfpOutputStage2 = 0.0,
        public float $tfpPotentialStage1 = 0.0,
        public float $tfpPotentialAbsorbed = 0.0,
        public float $productivitySupplyGap = 0.0,
        public float $netExportGap = 0.0,
        public float $domesticDemandGapEma = 0.0,
        public float $financeOutputGap = 0.0,
        public float $realExchangeRateTradeLag = 0.0,
        public float $mainlandDemandExportLag = 0.0,
        public float $alliedDefenseDeliveryLag = 0.0,
        public float $importPriceLevel = 0.0,
        public float $coreGoodsImportInflation = 0.0,
        public float $monetaryStanceStage1 = 0.0,
        public float $monetaryStanceTransmitted = 0.0,
        public float $financialConditionsIndex = 0.0,
        public float $financialConditionsIndexEma = 0.0,
        public bool $qeActive = false,
        public float $qeIntensity = 0.0,
        public bool $qtActive = false,
        public float $qtIntensity = 0.0,
        public float $balanceSheetIntensity = 0.0,
        public float $balanceSheetHoldTimer = 0.0,
        public float $lastQeLaunchAt = -1.0,
        public float $inversionDuration = 0.0,
        public float $nsLevel = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION,
        public float $nsSlope = self::OPENING_YIELD_10Y - self::OPENING_POLICY_RATE,
        public float $nsSlopeEma = self::OPENING_YIELD_10Y - self::OPENING_POLICY_RATE,
        public float $structuralSlope = self::OPENING_YIELD_10Y - self::OPENING_POLICY_RATE,
        public float $nsCurvature = 0.0,
        public float $nsCurvature2 = 0.0,
        public float $nsBeta1 = self::OPENING_POLICY_RATE - (MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION),
        public float $nsBaseTermPremium = 0.0115,
        public float $nsLongEndPremium = 0.0109,
        public float $potentialGdpIndex = 1.0,
        public float $nominalGdpIndex = 1.0,
        public float $nominalGdpGrowth = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION,
        public float $equityMarketCap = 0.0,
        public float $equityMarketCapEma = 0.0,
        public float $equityWealthRatio = 0.0,
        public float $equityWealthTrend = 0.0,
        public float $gdpDeflator = 1.0,
        public float $consumerPriceLevel = 1.0,
        /** @var list<float> Month-end consumer price levels, oldest first, a year's worth for the year-on-year rate. */
        public array $consumerPriceMonthly = [],
        public ?string $eventType = null,
        public float $eventCooldownTimer = 0.0,
        /**
         * @var array<string, float> Level alarms reported this episode, ShockEvent => the time each fired; one clears
         *                           once its condition does (MacroEngine::evaluateSystemicEvent()).
         */
        public array $systemicAlarmLatches = [],
        /**
         * Highest three-month average unemployment rate since the recession headline last reported; the episode ends
         * RECESSION_RECOVERY_MARGIN below it (MacroEngine::evaluateSystemicEvent()).
         */
        public float $supercoreInflation = MacroEngine::TARGET_INFLATION,
        public float $supercoreInflationEma = MacroEngine::TARGET_INFLATION,
        public float $coreGoodsInflation = MacroEngine::TARGET_INFLATION,
        public float $coreGoodsInflationEma = MacroEngine::TARGET_INFLATION,
        public float $highYieldCreditSpread = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER,
        public float $highYieldCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER,
        public float $inventoryStockGap = 0.0,
        public float $inventoryStockGapEma = 0.0,
        public float $demandShock = 0.0,
        public float $demandDisasterShock = 0.0,
        public float $demandDisasterCompensation = 0.0,
        public float $energyInventoryIndex = MacroEngine::COMMODITY_INVENTORY_BASELINE,
        public float $energyInventoryIndexEma = MacroEngine::COMMODITY_INVENTORY_BASELINE,
        public float $capacityUtilizationRate = MacroEngine::CU_BASELINE,
        public float $capacityUtilizationRateEma = MacroEngine::CU_BASELINE,
        public float $recessionProbability = MacroEngine::RECESSION_BASE_RATE,
        public float $recessionProbabilityEma = MacroEngine::RECESSION_BASE_RATE,
        public float $corporateDefaultRate = MacroEngine::CORPORATE_DEFAULT_BASELINE,
        public float $corporateDefaultRateEma = MacroEngine::CORPORATE_DEFAULT_BASELINE,
        public float $sloosTighteningIndex = 0.0,
        public float $sloosTighteningIndexEma = 0.0,
        public float $supplyChainPressureIndex = 0.0,
        public float $supplyChainPressureIndexEma = 0.0,
        public float $supplierDeliveryTimes = MacroEngine::SUPPLIER_DELIVERIES_NEUTRAL,
        public float $supplierDeliveryTimesEma = MacroEngine::SUPPLIER_DELIVERIES_NEUTRAL,
        public float $refiningCrackSpread = MacroEngine::CRACK_SPREAD_BASELINE,
        public float $refiningCrackSpreadEma = MacroEngine::CRACK_SPREAD_BASELINE,
        public float $dealActivityIndex = MacroEngine::DEAL_ACTIVITY_BASELINE,
        public float $dealActivityIndexEma = MacroEngine::DEAL_ACTIVITY_BASELINE,
        /**
         * Deal flow's measured through-the-cycle level, a decade's exponential average
         * (App\Service\Macro\Subsystem\AssetMarketSubsystem::calculateCapitalMarketsDealIndex()).
         */
        public float $dealActivityIndexTrend = MacroEngine::DEAL_ACTIVITY_BASELINE,
        /**
         * Realized ten-year yield volatility, annualized, over the last quarter
         * (App\Service\Macro\Subsystem\MonetaryPolicySubsystem::applyYieldCurve()).
         */
        public float $treasuryVolatility = MonetaryPolicySubsystem::OPENING_TREASURY_VOLATILITY,
        /** That volatility's measured through-the-cycle level, a decade's exponential average. */
        public float $treasuryVolatilityTrend = MonetaryPolicySubsystem::OPENING_TREASURY_VOLATILITY,
        public float $manufacturingPmi = MacroEngine::PMI_BASELINE,
        public float $manufacturingPmiEma = MacroEngine::PMI_BASELINE,
        public float $producerPriceInflation = MacroEngine::TARGET_INFLATION,
        public float $producerPriceInflationEma = MacroEngine::TARGET_INFLATION,
        public float $tradeBalanceToGdp = MacroEngine::TRADE_BALANCE_BASELINE,
        public float $tradeBalanceToGdpEma = MacroEngine::TRADE_BALANCE_BASELINE,
        public float $housingStartsIndex = MacroEngine::HOUSING_STARTS_BASELINE,
        public float $housingStartsIndexEma = MacroEngine::HOUSING_STARTS_BASELINE,
        public float $reimbursementRateIndex = 100.0,
        public float $reimbursementRateIndexEma = 100.0,
        public float $reimbursementRateGrowth = MacroEngine::TARGET_INFLATION - MacroEngine::REIMBURSEMENT_PRODUCTIVITY_OFFSET,
        public float $reimbursementAusterityShare = 0.0,
        public float $householdDebtToIncome = MacroEngine::OPENING_HOUSEHOLD_DEBT_TO_INCOME,
        public float $householdDebtToIncomeEma = MacroEngine::OPENING_HOUSEHOLD_DEBT_TO_INCOME,
        public float $householdDebtServiceRatio = MacroEngine::OPENING_HOUSEHOLD_DSR,
        public float $householdDebtServiceRatioEma = MacroEngine::OPENING_HOUSEHOLD_DSR,
        public float $householdDebtServiceTrend = 0.0,
        public float $householdDebtServiceGap = 0.0,
        public float $householdNewBorrowing = 0.0,
        public float $creditToGdpTrend = MacroEngine::OPENING_HOUSEHOLD_DEBT_TO_INCOME,
        public float $creditToGdpTrendSlope = 0.0,
        public float $creditToGdpGap = 0.0,
        public float $creditToGdpGapEma = 0.0,
        public float $countercyclicalBufferRate = 0.0,
        public float $creditCrisisHazard = 0.0,
        public float $creditCrisisDrag = 0.0,
        public float $lastCreditCrisisAt = -1.0,
        /**
         * Losses the Monetary Authority carries, paid down before it remits
         * (CreditFiscalSubsystem::centralBankRemittance()).
         */
        public float $centralBankDeferredAssetToGdp = 0.0,
        public float $foreignOutputGap = 0.0,
        public float $foreignOutputGapEma = 0.0,
        public float $foreignOutputGapLag = 0.0,
        /**
         * Output gap of the world beyond the mainland and the District, the demand that clears most commodities and
         * freight.
         */
        public float $worldActivityGap = 0.0,
        /** The part of the world gap the mainland's cycle does not carry, an AR(1) latent. */
        public float $worldActivityIdiosyncratic = 0.0,
        public float $foreignCoreInflation = MacroEngine::TARGET_INFLATION,
        public float $foreignCoreInflationLag1 = MacroEngine::TARGET_INFLATION,
        public float $foreignCoreInflationLag2 = MacroEngine::TARGET_INFLATION,
        public float $foreignCoreInflationLag3 = MacroEngine::TARGET_INFLATION,
        public float $foreignPolicyRate = MacroEngine::MAINLAND_NEUTRAL_RATE,
        public float $foreignPolicyRateEma = MacroEngine::MAINLAND_NEUTRAL_RATE,
        public float $globalDemandGap = 0.0,
        public float $globalDemandGapEma = 0.0,
        public float $catastropheLossIndex = 1.0,
        public float $catastropheLossIndexEma = 1.0,
        public float $lastCatastropheAt = -1.0,
        public float $lastCatastropheSeverity = 0.0,
        public float $naturalGasPriceIndex = MacroEngine::NATURAL_GAS_BASELINE,
        public float $naturalGasPriceIndexEma = MacroEngine::NATURAL_GAS_BASELINE,
        public float $gasOilRatioLog = 0.0,
        public float $goldPriceIndex = MacroEngine::GOLD_BASELINE,
        public float $goldPriceIndexEma = MacroEngine::GOLD_BASELINE,
        public float $wholesalePowerPriceIndex = MacroEngine::WHOLESALE_POWER_BASELINE,
        public float $wholesalePowerPriceIndexEma = MacroEngine::WHOLESALE_POWER_BASELINE,
        public float $powerHeatRateLog = 0.0,
        public float $moneyMarketFundShare = MacroEngine::MMF_SHARE_BASE,
        public float $moneyMarketFundShareEma = MacroEngine::MMF_SHARE_BASE,
        /**
         * The market's sovereign default premium: none at the opening, where net liabilities sit far below the fiscal
         * limit (CreditRisk::sovereignDefaultPremium()).
         */
        public float $sovereignRiskSpread = 0.0,
        public float $sovereignRiskSpreadEma = 0.0,
        public float $primaryDeficitToGdp = -0.0025,
        public float $policyUncertaintyIndex = MacroEngine::EPU_BASELINE,
        public float $policyUncertaintyIndexEma = MacroEngine::EPU_BASELINE,
        /**
         * The election calendar's pull on policy uncertainty, as the government last handed it
         * (App\DTO\GovernmentPolicyDTO).
         */
        public float $electionPulse = 0.0,
        /** Government purchases as a share of GDP, the level the Diet's last budget has them revert to. */
        public float $publicSpendingShare = MacroEngine::PUBLIC_PURCHASES_TO_GDP,
        public float $corporateTaxPolicyShift = 0.0,
        public float $importTariffRate = 0.0,
        public float $tariffTradeLag = 0.0,
        public float $laborForceGrowthRate = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE,
        /** Tax on a home bought by a buyer from outside the District, a share of the price. */
        public float $foreignBuyerTaxRate = 0.0,
        public float $mergerReviewLeniency = 0.0,
        public float $immigrationPopulationShift = 0.0,
        public float $greenBeltStringency = 0.0,
        public float $carbonPrice = 0.0,
        public float $extractionStringency = 0.0,
        public float $stampDutyRate = FinancialConstants::STAMP_DUTY_RATE,
        public float $bankLevyRate = 0.0,
        /**
         * Share of the reserve fund's expected long-term real return the budget spends, as the Diet passed it with the
         * fund's consent above the ceiling.
         */
        public float $reserveDrawShare = MacroEngine::RESERVE_DRAW_CEILING,
        /** Share of the Bohn reaction's surplus the Diet raises by tax rather than by cutting purchases. */
        public float $consolidationTaxShare = CreditFiscalSubsystem::FOUNDING_CONSOLIDATION_TAX_SHARE,
        /** Share of the reserve fund's equities the Diet has it hold on the board. */
        public float $reserveHomeEquityShare = SovereignFundSubsystem::OPENING_HOME_EQUITY_SHARE,
        /** Share of a utility's rate request the commission grants, as the Diet has it run. */
        public float $utilityRateCaseGrantShare = UtilityBusinessModel::APPOINTED_COMMISSION_GRANTED_SHARE,
        /** Tax on the dividends the District's own shareholders are paid, as the Diet has it set. */
        public float $dividendTaxRate = Valuation::FOUNDING_DIVIDEND_TAX_RATE,
        /** Gross debt to GDP the Diet's budget steers toward, as the Diet has it set. */
        public float $debtTarget = CreditFiscalSubsystem::FOUNDING_DEBT_TARGET,
        /**
         * Points of GDP a year the budget moves debt toward that target, as the Diet has it set
         * (CreditFiscalSubsystem::requiredPrimaryBalance()).
         */
        public float $debtPace = CreditFiscalSubsystem::FOUNDING_DEBT_PACE,
        /**
         * The rate committee's supermajority, 1 hawkish, -1 dovish, 0 neither (App\Service\Politics\MonetaryAuthority).
         */
        public float $authorityMajority = 0.0,
        /**
         * 1 while politics hands the macro a rate committee, else 0: the policy rule reads the supermajority only then.
         */
        public float $authorityCommitteeSeated = 0.0,
        /**
         * The corporate tax shift as the tax rate has taken it in so far: the shift in force through the rate's own
         * adjustment (App\Service\Macro\Subsystem\CreditFiscalSubsystem::calculateDynamicFiscalPolicy()).
         */
        public float $corporateTaxShiftRealized = 0.0,
        /**
         * The shift as the trailing year's earnings carry it: the realized shift through a first-order lag of the
         * year's mean lag.
         */
        public float $corporateTaxShiftEmbodied = 0.0,
        /** The bank levy as the trailing year's earnings carry it, the same lag. */
        public float $bankLevyEmbodied = 0.0,
        /**
         * The extraction rules' cost factor as the trailing year's earnings carry it, the same lag
         * (FirmEconomics::calculateExtractionCostFactor()).
         */
        public float $extractionCostFactorEmbodied = 1.0,
        /**
         * The stamp duty's share turnover factor as the trailing year's earnings carry it, the same lag
         * (MacroTransmission::calculateStampDutyVolumeFactor()).
         */
        public float $stampDutyVolumeFactorEmbodied = 1.0,
        /**
         * The carbon price's uplift on the power price as the trailing year's earnings carry it, the same lag
         * (CommodityLogisticsSubsystem::carbonPowerPriceUplift()).
         */
        public float $carbonPowerUpliftEmbodied = 0.0,
        /**
         * The average tariff shortfall the commission's grant leaves, as the rate cases and the trailing year have
         * brought it (App\Service\Model\Sector\UtilityBusinessModel::steadyTariffShortfall()).
         */
        public float $utilityTariffShortfallEmbodied = UtilityBusinessModel::FOUNDING_TARIFF_SHORTFALL,
        /**
         * Government purchases above the founding level, as a fraction, as the trailing year's earnings carry them, the
         * same lag.
         */
        public float $governmentSpendingShiftEmbodied = 0.0,
        /**
         * @var array<string, float> The levers the sitting government will pass at its next budget round, keyed as
         *                           App\Service\Politics\PoliticsEngine::LEVER_FIELDS; empty before they have been
         *                           read.
         */
        public array $sittingLevers = [],
        /** When that round falls (-1: not yet read). */
        public float $sittingPolicyFrom = -1.0,
        /**
         * @var array<string, float> The levers the market expects from the next government's first budget, keyed the
         *                           same way (App\Service\Politics\ElectionForecast); empty before a forecast.
         */
        public array $expectedLevers = [],
        /** When that budget falls (-1: no forecast yet). */
        public float $expectedPolicyFrom = -1.0,
        /**
         * @var array<string, float> The sitting government's levers as they stood the tick before, so a revision
         *                           reprices at once (App\Service\Market\Pricing\PolicyCapitalization).
         */
        public array $previousSittingLevers = [],
        /** When that round fell, as read the tick before. */
        public float $previousSittingPolicyFrom = -1.0,
        /** @var array<string, float> The forecast levers as they stood the tick before. */
        public array $previousExpectedLevers = [],
        /** When those took effect, as forecast the tick before. */
        public float $previousExpectedPolicyFrom = -1.0,
        /**
         * The long-run inflation the public has learned from realized inflation over that anchor, the Kozicki-Tinsley
         * shifting endpoint the ten-year breakeven prices
         * (App\Service\Macro\Subsystem\MonetaryPolicySubsystem::updateInflationEndpoint()).
         */
        public float $inflationEndpointGap = 0.0,
        /**
         * The CET1 requirement on the District's banks in force, as a share of risk-weighted assets, countercyclical
         * buffer aside (App\Service\Politics\FinancialRegulator).
         */
        public float $bankCapitalRequirement = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT,
        /**
         * The capital the banks have built so far against the requirement and the countercyclical buffer together,
         * lagged by the years they take (App\Service\Macro\Subsystem\CreditFiscalSubsystem).
         */
        public float $bankCapitalBuilt = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT,
        /**
         * The requirement and the buffer together as the last tick required them: a rise from it cuts household
         * lending.
         */
        public float $bankCapitalRequiredLast = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT,
        /**
         * The mortgage loan-to-value cap on the District's lenders in force, as a share of the property's value; null
         * for none (App\Service\Politics\FinancialRegulator).
         */
        public ?float $mortgageLtvCap = null,
        /**
         * The initial margin an investor must put up to buy or short on credit
         * (App\Service\Politics\SecuritiesCommission); maintenance keeps its founding ratio to it.
         */
        public float $initialMarginRequirement = FinancialConstants::INITIAL_MARGIN_REQUIREMENT,
        /**
         * The multiplier the Securities Commission's enforcement puts on each firm's propensity to manage its reported
         * earnings.
         */
        public float $earningsManagementScale = 1.0,
        /**
         * The countercyclical buffer the regulator holds when credit is not running ahead
         * (App\DTO\GovernmentPolicyDTO).
         */
        public float $neutralCountercyclicalBuffer = 0.0,
        /**
         * How far the cap has so far held household debt below where it would stand without one, in logs
         * (App\Service\Macro\Subsystem\CreditFiscalSubsystem::mortgageLtvCreditCut()).
         */
        public float $ltvCutBuilt = 0.0,
        public float $electricityCarbonPriceLevel = 0.0,
        public float $moneySupplyGrowth = MacroEngine::M2_BASE_GROWTH,
        public float $moneySupplyGrowthEma = MacroEngine::M2_BASE_GROWTH,
        /**
         * M2 growth's measured trend, the level its cyclical readers compare it with
         * (App\Service\Macro\Subsystem\MonetaryPolicySubsystem::calculateMoneySupplyGrowth()).
         */
        public float $moneySupplyGrowthTrend = MacroEngine::M2_BASE_GROWTH,
        /**
         * The Authority's bond holdings over GDP, averaged over a year: what turns its purchases into a flow of new
         * money (MonetaryPolicySubsystem::calculateMoneySupplyGrowth()).
         */
        public float $authorityHoldingsToGdpEma = 0.0,
        public float $sovereignFundDomesticEquity = 0.0,
        public float $sovereignFundForeignEquity = 0.0,
        public float $sovereignFundForeignBonds = 0.0,
        public float $sovereignFundTargetWeight = 0.0,
        /**
         * The fund's policy equity share: the board's policy weight plus the foreign sleeves' equity at their policy
         * split, as the fund opened or as its head has since set it (App\Service\Politics\SovereignReserveFund).
         */
        public float $sovereignFundPolicyEquityShare = 0.0,
        /**
         * The policy equity share the fund's head has handed the fund, 0 while the head sitting at Year 1 keeps the
         * opening mix.
         */
        public float $sovereignFundMandateEquityShare = 0.0,
        /** The share of its equities the fund's policy mix keeps on the board, as last adopted from the Diet's law. */
        public float $sovereignFundHomeEquityShare = SovereignFundSubsystem::OPENING_HOME_EQUITY_SHARE,
        public float $sovereignFundDollarsPerGdp = 0.0,
        public float $sovereignFundAnnualDraw = 0.0,
        /**
         * The share of the expected return this budget year's draw was set at; a budget that moves the share rescales
         * the draw from its round.
         */
        public float $sovereignFundDrawShare = MacroEngine::RESERVE_DRAW_CEILING,
        public float $sovereignFundRebalanceBacklog = 0.0,
        public float $sovereignFundRebalanceRate = 0.0,
        public float $sovereignFundRebalanceMonthsLeft = 0.0,
        public float $sovereignFundRebalanceShare = 0.0,
        public float $sovereignFundTrade = 0.0,
        public float $lastSovereignRebalanceAt = -1.0,
        public float $sovereignFundToGdp = 0.0,
        public float $sovereignFundDomesticWeight = 0.0,
        public float $sovereignFundEquityShare = 0.0,
        public float $sovereignFundOwnershipShare = 0.0,
        public float $sovereignFundDrawToGdp = 0.0,
        public float $sovereignFundStampDutyYearToDate = 0.0,
        public float $sovereignFundStampDutyToGdp = 0.0,
        public float $sovereignFundBondsToGdp = 0.0,
        public float $sovereignFundValueAtClose = 0.0,
        public float $sovereignFundReturnIndex = 0.0,
        public float $sovereignFundRealReturnIndex = 0.0,
        public float $sovereignFundExpectedRealReturn = 0.0,
        public float $foreignEquityIndex = MacroEngine::FOREIGN_EQUITY_BASELINE,
        public float $foreignEquityRiskPremium = MacroEngine::BASE_EQUITY_RISK_PREMIUM,
        public float $foreignEquityValuation = 0.0,
        public float $foreignEquityValuationChange = 0.0,
        public float $foreignBondYield = 0.0,
        public float $boardFloatCap = 0.0,
        public float $boardPriceReturn = 0.0,
        public float $boardDividendCash = 0.0,
        public float $boardNetIssuance = 0.0,
        public float $boardStampDuty = 0.0,
        public float $boardBankLevy = 0.0,
        public float $strategicStakeCash = 0.0,
        public float $sovereignFundBudgetInflow = 0.0,
        /**
         * The corporate default rate's measured trend, its decade-long average: where its cyclical readers measure an
         * excess from.
         */
        public float $corporateDefaultRateTrend = MacroEngine::CORPORATE_DEFAULT_BASELINE,
        /** The household default rate's measured trend, its decade-long average. */
        public float $retailDefaultRateTrend = MacroEngine::RETAIL_DEFAULT_BASELINE,
        /**
         * Commercial property's measured trend, the level a developer reads a boom in office and retail values against.
         */
        public float $commercialPropertyIndexTrend = MacroEngine::CRE_BASELINE,
        /**
         * The financial conditions composite's decade-long average before it is centred: financialConditionsIndex is
         * the composite net of it.
         */
        public float $financialConditionsTrend = 0.0,
        /**
         * The coupon the Authority's own bond book earns: the yields its purchases and reinvestments were struck at
         * (MonetaryPolicySubsystem::rollAuthorityBookCoupon()).
         */
        public float $authorityBookCouponRate = self::OPENING_YIELD_10Y,
        /**
         * The recession probability's measured trend, its decade-long average: what a reserving committee reads the
         * outlook against.
         */
        public float $recessionProbabilityTrend = MacroEngine::RECESSION_BASE_RATE,
    ) {
        // A state given its gap but not its lags is one where the gap has stood long enough to reach every order book.
        $this->outputGapLag3m = $outputGapLag3m ?? $outputGapEma;
        $this->outputGapLag6m = $outputGapLag6m ?? $outputGapEma;
        $this->outputGapLag9m = $outputGapLag9m ?? $outputGapEma;
        $this->outputGapLag12m = $outputGapLag12m ?? $outputGapEma;
        $this->outputGapLag15m = $outputGapLag15m ?? $outputGapEma;
        $this->outputGapLag18m = $outputGapLag18m ?? $outputGapEma;
    }

    /**
     * Constructs a MacroStateDTO from the snake_case payload Redis carries, or a macro_report row.
     *
     * Read through MacroState::fromArray(), the one reader that upgrades a payload predating a field, so the engine
     * and every snapshot of the same payload are the same economy.
     *
     * @param array<string, mixed> $data Decoded payload keyed by wire key.
     */
    public static function fromArray(array $data): self
    {
        return self::fromMacroState(MacroState::fromArray($data));
    }
    /**
     * The fitted term structure, ready for the bond desk to discount an arbitrary maturity against.
     *
     * Assembled rather than stored so there is one authority on which slope belongs in the curve function:
     * $nsSlope on this DTO is the 10y-minus-policy reporting metric and would produce a curve that reprices
     * nothing, while $nsBeta1 is the beta1 the macro engine actually fitted with.
     */
    public function sovereignCurve(): SovereignCurveDTO
    {
        return new SovereignCurveDTO(
            level: $this->nsLevel,
            slope: $this->nsBeta1,
            curvature1: $this->nsCurvature,
            curvature2: $this->nsCurvature2,
            baseTermPremium: $this->nsBaseTermPremium,
            longEndPremium: $this->nsLongEndPremium,
            balanceSheetIntensity: CreditFiscalSubsystem::durationExtraction($this->balanceSheetIntensity, $this->sovereignDebtToGdpEma, $this->sovereignDebtAverageMaturity),
        );
    }

    /**
     * The output gap as it has reached an order book the given number of years behind the economy: one of the
     * distributed lags the macro publishes, or the gap itself at zero.
     *
     * @throws \InvalidArgumentException When the lag is not one the macro publishes.
     */
    public function laggedOutputGap(float $lagYears): float
    {
        if ($lagYears <= 0.0) {
            return $this->outputGapEma;
        }

        return $this->{MacroAggregateSubsystem::demandTransmissionLagField($lagYears)};
    }

    /**
     * Calendar quarter index [0..3] implied by elapsed simulation time. Derived rather than published:
     * it is not a macro series any institution reports, so it draws no district conduit. This is the quarter
     * now; an earnings report filed now covers the one before (EarningsEngine::coveredCalendarQuarter).
     */
    public function calendarQuarter(): int
    {
        return ((int) floor($this->totalTime * 4.0) % 4 + 4) % 4;
    }

    // --- Economic Cycle Label ---
    /** Output gap above which the cycle reads as a boom to a player. */
    public const CYCLE_BOOM_GAP = 0.01;
    /** Output gap below which the cycle reads as a bust to a player. */
    public const CYCLE_BUST_GAP = -0.01;

    /**
     * The economy's phase as the interface names it.
     *
     * Defined once because it is read in two places that must agree: the ticker publishes it on every
     * live update, and the stock page renders it on load. The page used to read a Redis key
     * (`economy_state`) that nothing has ever written, so it fell back to a hardcoded "Expansion" — a
     * label the live feed does not even use — until the first WebSocket tick replaced it.
     */
    public function economicCycleLabel(): string
    {
        return match (true) {
            $this->outputGap > self::CYCLE_BOOM_GAP => 'Boom',
            $this->outputGap < self::CYCLE_BUST_GAP => 'Bust',
            default => 'Neutral',
        };
    }

    /**
     * Consumer confidence as a fraction, against where the index sits with unemployment at NAIRU, inflation on
     * target and joblessness flat.
     *
     * Use this where confidence is the only channel through which a stream sees the cycle.
     */
    public function sentimentDeviation(): float
    {
        return ($this->consumerSentimentIndexEma - MacroEngine::SENTIMENT_TREND_LEVEL) / self::INDEX_SCALE;
    }

    /**
     * The currency against its own trend, in logs; positive is an appreciation.
     *
     * EXCHANGE_RATE_BASELINE is the nominal anchor the index is built on, and it settles away from it on either side
     * depending on the fund and the safe-haven bid, so a reader of it against the anchor books a standing
     * competitiveness gain or loss no move produced. The macro's own net exports read this same gap.
     */
    public function exchangeRateGap(): float
    {
        return MacroAggregateSubsystem::realExchangeRateGap($this->exchangeRateIndexEma, $this->exchangeRateTrend);
    }

    /**
     * Landed import prices against the level the currency's trend sets, in logs: the lagged exchange-rate pass-through
     * of the currency's move off trend, plus the tariff. Read against the trend for the same reason as
     * exchangeRateGap().
     */
    public function importPriceDeviation(): float
    {
        return $this->importPriceLevel - MacroAggregateSubsystem::importPriceLevelTarget($this->exchangeRateTrend);
    }

    /**
     * House prices against the level they have measurably settled at, as a fraction: the reading the engine's own
     * housing wealth effect and household credit take. RESIDENTIAL_BASELINE is the nominal anchor the fundamental is
     * built on, and the index settles below it, so a reader of it against a hundred books a standing slump.
     */
    public function housePriceDeviation(): float
    {
        return $this->residentialWealthTrend > 0.0 ? ($this->residentialPropertyIndexEma / $this->residentialWealthTrend) - 1.0 : 0.0;
    }

    /**
     * Realised inflation over what the market expected, both ways: what a CPI escalator or a cost-plus contract passes
     * through beyond the expected inflation a firm's pricing already carries. Read one-sided against the target, an
     * escalator was paid in every quarter inflation ran hot, charged in none that ran cold, and counted the expected
     * part twice.
     */
    public function inflationSurprise(): float
    {
        return $this->inflationEma - $this->tipsBreakevenEma;
    }

    /**
     * Equity wealth against its multi-year trend, as a fraction: the reading the engine's own equity wealth effect
     * takes. Zero before a market has been reported.
     */
    public function equityWealthDeviation(): float
    {
        return $this->equityWealthTrend > 0.0 ? ($this->equityWealthRatio / $this->equityWealthTrend) - 1.0 : 0.0;
    }

    /**
     * Corporate defaults over their measured trend, as a share of it, both ways: +0.5 is half again the usual rate of
     * failures.
     */
    public function corporateDefaultDeviation(): float
    {
        return $this->corporateDefaultRateTrend > 0.0 ? ($this->corporateDefaultRateEma / $this->corporateDefaultRateTrend) - 1.0 : 0.0;
    }

    /** Household defaults over their measured trend, as a share of it, both ways. */
    public function retailDefaultDeviation(): float
    {
        return $this->retailDefaultRateTrend > 0.0 ? ($this->retailDefaultRateEma / $this->retailDefaultRateTrend) - 1.0 : 0.0;
    }

    /** Commercial property values over their measured trend, as a fraction. */
    public function commercialPropertyDeviation(): float
    {
        return $this->commercialPropertyIndexTrend > 0.0 ? ($this->commercialPropertyIndexEma / $this->commercialPropertyIndexTrend) - 1.0 : 0.0;
    }

    /**
     * Unemployment over its natural rate, in points as a fraction.
     *
     * The natural rate scars after a slump and heals over the years that follow (LaborMarketSubsystem), so unemployment
     * read against MacroEngine::NATURAL_UNEMPLOYMENT shows slack long after the labour market has none.
     */
    public function unemploymentGap(): float
    {
        return $this->unemploymentRateEma - $this->nairuEma;
    }

    /** The floating mortgage rate on the smoothed policy rate (FixedIncome::mortgageRate()). */
    public function mortgageRate(): float
    {
        return FixedIncome::mortgageRate($this->policyRateEma);
    }

    /** The yield on cash and money-market paper at the smoothed policy rate (FixedIncome::moneyMarketYield()). */
    public function moneyMarketYield(): float
    {
        return FixedIncome::moneyMarketYield($this->policyRateEma);
    }

    /**
     * The cost of equity at a beta of one: the smoothed ten-year plus the equity risk premium (Sharpe-Lintner CAPM).
     */
    public function marketCostOfEquity(): float
    {
        return $this->yield10yEma + $this->equityRiskPremium;
    }

    /**
     * Deal flow against its own through-the-cycle level, as a fraction.
     *
     * DEAL_ACTIVITY_BASELINE is where the index is BUILT: a hundred, less the stress its premium, spread, volatility
     * and uncertainty legs read against their bases. Those legs settle above their bases, so the index settles
     * near 95, and a reader of it against the baseline books a standing twentieth less advisory, underwriting and
     * exit revenue than any cycle produced.
     */
    public function dealActivityDeviation(): float
    {
        return ($this->dealActivityIndexEma - $this->dealActivityIndexTrend) / $this->dealActivityIndexTrend;
    }

    /**
     * The same reading with the output gap that is already inside it taken back out.
     *
     * Confidence is built from the cycle — unemployment above NAIRU, its rise and inflation — so a reader that
     * prices the gap AND reads confidence prices the cycle twice. The mood the index carries beside its
     * fundamentals is the part that is not the cycle. Lemmon & Portniaguina (2006) orthogonalise confidence against
     * fundamentals for exactly this reason: what predicts anything beyond the macro data is the residual.
     *
     * Use this where the cycle is priced beside it.
     */
    public function sentimentResidual(): float
    {
        return $this->consumerSentimentMood / self::INDEX_SCALE;
    }

    /**
     * Snapshots the engine's mutable state vector into this immutable DTO.
     *
     * Every field is carried across by name, so the field list comes from
     * App\Data\Macro\MacroFieldRegistry rather than being spelled out a second time; MacroState declares
     * an identically named property for each of this constructor's parameters, and
     * App\Tests\Data\Macro\MacroFieldRegistryTest fails if that ever stops being true.
     */
    public static function fromMacroState(MacroState $state): self
    {
        $arguments = [];
        foreach (array_keys(MacroFieldRegistry::wireKeys()) as $field) {
            $arguments[$field] = $state->$field;
        }

        return new self(...$arguments);
    }

    /**
     * Converts the DTO to the snake_case payload used for Redis persistence and serialization.
     *
     * @return array<string, mixed> Wire key => value, in the order MacroFieldRegistry declares.
     */
    public function toArray(): array
    {
        $payload = [];
        foreach (MacroFieldRegistry::wireKeys() as $field => $key) {
            $payload[$key] = $this->$field;
        }

        return $payload;
    }
}
