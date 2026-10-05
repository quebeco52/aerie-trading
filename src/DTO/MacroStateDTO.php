<?php

declare(strict_types=1);

namespace App\DTO;

use App\Data\MacroFieldRegistry;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Math\FinancialConstants;

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

    /** Policy rate at the opening: the Taylor rule's own target with output at trend (3.42%), so the first tick does not move it. */
    private const OPENING_POLICY_RATE = 0.034;

    /** Ten-year at the opening: the engine's curve evaluated at the opening state (at-trend median 4.78%). */
    private const OPENING_YIELD_10Y = 0.0479;

    /**
     * Seeds this snapshot adds to the shared set, for openings only a snapshot needs.
     *
     * App\Data\MacroFieldRegistry::seeds() already carries the seeds both readers share — every
     * `*Ema` pair, plus the pairs declared in its SEED_OVERRIDES that the naming convention does not
     * describe. These
     * two are on top of those, and are not seeded when the engine's own state is hydrated: a
     * snapshot is read by the pricing surfaces, which cannot be handed a stale breakeven or structural
     * slope just because the payload predates that field.
     *
     * @var array<string, string>
     */
    private const HYDRATION_SEEDS = [
        'tipsBreakeven' => 'inflation',
        'structuralSlope' => 'nsSlope',
    ];

    // --- Demand Transmission Lags ---

    /** The output gap as it reaches order books 3 to 18 months behind the economy (MacroAggregateSubsystem::demandTransmissionLagField). */
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
        public float $energyPriceShock = 0.0,
        public float $energyBasePrice = 100.0,
        public float $energySupplyEma = MacroEngine::ENERGY_BASELINE,
        public float $energyCostPushLag = 0.0,
        public float $agriCostPushLag = 0.0,
        public float $consumerSentimentIndex = MacroEngine::SENTIMENT_TREND_LEVEL,
        public float $consumerSentimentIndexEma = MacroEngine::SENTIMENT_TREND_LEVEL,
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
        public float $alliedDefenseSpendingIndex = MacroEngine::ALLIED_DEFENSE_BASELINE,
        public float $alliedDefenseSpendingIndexEma = MacroEngine::ALLIED_DEFENSE_BASELINE,
        public float $commercialPropertyIndex = 100.0,
        public float $commercialPropertyIndexEma = 100.0,
        public float $residentialPropertyIndex = 100.0,
        public float $residentialPropertyIndexEma = 100.0,
        public float $residentialWealthTrend = 100.0,
        public float $residentialPriceMomentum = 0.0,
        public float $retailDefaultRate = MacroEngine::RETAIL_DEFAULT_BASELINE,
        public float $retailDefaultRateEma = MacroEngine::RETAIL_DEFAULT_BASELINE,
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
        public float $policyRate = self::OPENING_POLICY_RATE,
        public float $policyRateEma = self::OPENING_POLICY_RATE,
        public float $targetRate = self::OPENING_POLICY_RATE,
        public float $yield2y = 0.0381,
        public float $yield2yEma = 0.0381,
        public float $yield5y = 0.0428,
        public float $yield5yEma = 0.0428,
        public float $yield10y = self::OPENING_YIELD_10Y,
        public float $yield10yEma = self::OPENING_YIELD_10Y,
        public float $yield30y = 0.0534,
        public float $yield30yEma = 0.0534,
        public float $termPremium10y = 0.0132,
        public float $termPremium10yEma = 0.0132,
        public float $riskNeutral10y = 0.0347,
        public float $riskNeutral10yEma = 0.0347,
        public float $termPremiumShock = 0.0,
        public float $expectedPathShock = 0.0,
        public float $termPremiumRegime = MacroEngine::NS_BASE_TERM_PREMIUM,
        public float $perceivedNeutralRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION,
        public float $marketVolatility = 0.14,
        public float $marketVolatilityEma = 0.14,
        public float $marketZ = 0.0,
        public float $marketZLatent = 0.0,
        public float $marketJumpMultiplier = 1.0,
        /** @var array<string, float> Per-macro-sector shock, keyed by Sectors::MACRO_SECTORS. */
        public array $sectorZ = [],
        /** @var array<string, float> Persistent per-macro-sector demand factor shared by every firm's earnings physics in the sector. */
        public array $sectorDemandZ = [],
        public float $corporateTaxRate = MacroEngine::BASE_CORPORATE_TAX_RATE,
        public float $sovereignDebtToGdp = MacroEngine::INITIAL_DEBT_TO_GDP,
        public float $sovereignDebtToGdpEma = MacroEngine::INITIAL_DEBT_TO_GDP,
        public float $sovereignNetDebtToGdp = MacroEngine::INITIAL_DEBT_TO_GDP,
        public float $sovereignNetDebtToGdpEma = MacroEngine::INITIAL_DEBT_TO_GDP,
        public float $equityRiskPremium = MacroEngine::BASE_EQUITY_RISK_PREMIUM,
        public float $macroCreditSpread = MacroEngine::BASE_CREDIT_SPREAD,
        public float $macroCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD,
        public float $interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD,
        public float $interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD,
        public float $excessBondPremium = 0.0,
        public float $totalFactorProductivityIndex = MacroEngine::TFP_BASELINE,
        public float $totalFactorProductivityIndexEma = MacroEngine::TFP_BASELINE,
        public float $tfpShockLevel = 0.0,
        public float $tfpOutputStage1 = 0.0,
        public float $tfpOutputStage2 = 0.0,
        public float $tfpPotentialStage1 = 0.0,
        public float $tfpPotentialAbsorbed = 0.0,
        public float $productivitySupplyGap = 0.0,
        public float $netExportGap = 0.0,
        public float $domesticDemandGapEma = 0.0,
        public float $financeOutputGap = 0.0,
        public float $financeMarketTrend = 0.0,
        public float $financeMarketTrendSlope = 0.0,
        public float $realExchangeRateTradeLag = 0.0,
        public float $alliedDefenseDeliveryLag = 0.0,
        public float $importPriceLevel = 0.0,
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
        public float $nsCurvature2 = 0.0023,
        public float $nsBeta1 = self::OPENING_POLICY_RATE - (MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION),
        public float $nsBaseTermPremium = 0.0125,
        public float $nsLongEndPremium = 0.0109,
        public float $potentialGdpIndex = 1.0,
        public float $nominalGdpIndex = 1.0,
        public float $equityMarketCap = 0.0,
        public float $equityMarketCapEma = 0.0,
        public float $equityWealthRatio = 0.0,
        public float $equityWealthTrend = 0.0,
        public float $gdpDeflator = 1.0,
        public ?string $eventType = null,
        public float $eventCooldownTimer = 0.0,
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
        public float $recessionProbability = 0.15,
        public float $recessionProbabilityEma = 0.15,
        public float $corporateDefaultRate = MacroEngine::CORPORATE_DEFAULT_BASELINE,
        public float $corporateDefaultRateEma = MacroEngine::CORPORATE_DEFAULT_BASELINE,
        public float $sloosTighteningIndex = 0.0,
        public float $sloosTighteningIndexEma = 0.0,
        public float $supplyChainPressureIndex = 0.0,
        public float $supplyChainPressureIndexEma = 0.0,
        public float $refiningCrackSpread = MacroEngine::CRACK_SPREAD_BASELINE,
        public float $refiningCrackSpreadEma = MacroEngine::CRACK_SPREAD_BASELINE,
        public float $dealActivityIndex = MacroEngine::DEAL_ACTIVITY_BASELINE,
        public float $dealActivityIndexEma = MacroEngine::DEAL_ACTIVITY_BASELINE,
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
        public float $householdDebtToIncome = MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE,
        public float $householdDebtToIncomeEma = MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE,
        public float $householdDebtServiceRatio = MacroEngine::HOUSEHOLD_DSR_NEUTRAL,
        public float $householdDebtServiceRatioEma = MacroEngine::HOUSEHOLD_DSR_NEUTRAL,
        public float $householdDebtServiceTrend = 0.0,
        public float $householdDebtServiceGap = 0.0,
        public float $householdNewBorrowing = 0.0,
        public float $creditToGdpTrend = MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE,
        public float $creditToGdpTrendSlope = 0.0,
        public float $creditToGdpGap = 0.0,
        public float $creditToGdpGapEma = 0.0,
        public float $countercyclicalBufferRate = 0.0,
        public float $countercyclicalBufferRateEma = 0.0,
        public float $creditCrisisHazard = 0.0,
        public float $creditCrisisDrag = 0.0,
        public float $lastCreditCrisisAt = -1.0,
        public float $foreignOutputGap = 0.0,
        public float $foreignOutputGapEma = 0.0,
        public float $foreignOutputGapLag = 0.0,
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
        public float $systemDepositBeta = MacroEngine::SYSTEM_DEPOSIT_BETA_BASE,
        public float $systemDepositBetaEma = MacroEngine::SYSTEM_DEPOSIT_BETA_BASE,
        public float $moneyMarketFundShare = MacroEngine::MMF_SHARE_BASE,
        public float $moneyMarketFundShareEma = MacroEngine::MMF_SHARE_BASE,
        public float $sovereignRiskSpread = 0.001,
        public float $sovereignRiskSpreadEma = 0.001,
        public float $primaryDeficitToGdp = -0.0025,
        public float $policyUncertaintyIndex = MacroEngine::EPU_BASELINE,
        public float $policyUncertaintyIndexEma = MacroEngine::EPU_BASELINE,
        /** The election calendar's pull on policy uncertainty, as the government last handed it (App\DTO\GovernmentPolicyDTO). */
        public float $electionPulse = 0.0,
        public float $corporateTaxPolicyShift = 0.0,
        public float $importTariffRate = 0.0,
        public float $tariffTradeLag = 0.0,
        public float $laborForceGrowthRate = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE,
        public float $mergerReviewLeniency = 0.0,
        public float $immigrationPopulationShift = 0.0,
        public float $greenBeltStringency = 0.0,
        public float $carbonPrice = 0.0,
        public float $extractionStringency = 0.0,
        public float $stampDutyRate = FinancialConstants::STAMP_DUTY_RATE,
        public float $bankLevyRate = 0.0,
        /** The rate committee's supermajority, 1 hawkish, -1 dovish, 0 neither (App\Service\Politics\MonetaryAuthority). */
        public float $authorityMajority = 0.0,
        /** 1 while politics hands the macro a rate committee, else 0: the policy rule reads the supermajority only then. */
        public float $authorityCommitteeSeated = 0.0,
        /** 1 while the Monetary Authority gives ground to the cabinet's pressure, else 0 (App\Service\Politics\PoliticalPressure). */
        public float $authorityConcession = 0.0,
        /** The corporate tax shift as the tax rate has taken it in so far: the shift in force through the rate's own adjustment (App\Service\Macro\Subsystem\CreditFiscalSubsystem::calculateDynamicFiscalPolicy()). */
        public float $corporateTaxShiftRealized = 0.0,
        /** The shift as the trailing year's earnings carry it: the realized shift through a first-order lag of the year's mean lag. */
        public float $corporateTaxShiftEmbodied = 0.0,
        /** The bank levy as the trailing year's earnings carry it, the same lag. */
        public float $bankLevyEmbodied = 0.0,
        /** The corporate tax shift and bank levy the sitting government will pass at its next budget round, and when that falls (-1: not yet read). */
        public float $sittingCorporateTaxPolicyShift = 0.0,
        public float $sittingBankLevyRate = 0.0,
        public float $sittingPolicyFrom = -1.0,
        /** The corporate tax shift and bank levy the market expects from the next government's first budget, and when that falls (-1: no forecast yet) (App\Service\Politics\ElectionForecast). */
        public float $expectedCorporateTaxPolicyShift = 0.0,
        public float $expectedBankLevyRate = 0.0,
        public float $expectedPolicyFrom = -1.0,
        /** The same three as the market expected them the tick before, so a revision reprices at once (App\Service\Market\PolicyCapitalization). */
        public float $previousExpectedCorporateTaxPolicyShift = 0.0,
        public float $previousExpectedBankLevyRate = 0.0,
        public float $previousExpectedPolicyFrom = -1.0,
        public float $previousSittingCorporateTaxPolicyShift = 0.0,
        public float $previousSittingBankLevyRate = 0.0,
        public float $previousSittingPolicyFrom = -1.0,
        /** How far the inflation the public expects the Authority to tolerate has drifted above its target, under pressure it gave ground to (App\Service\Macro\Subsystem\MonetaryPolicySubsystem::updateInflationAnchor()). */
        public float $inflationAnchorDrift = 0.0,
        /** The CET1 requirement on the District's banks in force, as a share of risk-weighted assets, countercyclical buffer aside (App\Service\Politics\FinancialRegulator). */
        public float $bankCapitalRequirement = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT,
        /** The capital the banks have built so far against the requirement and the countercyclical buffer together, lagged by the years they take (App\Service\Macro\Subsystem\CreditFiscalSubsystem). */
        public float $bankCapitalBuilt = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT,
        /** The requirement and the buffer together as the last tick required them: a rise from it cuts household lending. */
        public float $bankCapitalRequiredLast = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT,
        public float $electricityCarbonPriceLevel = 0.0,
        public float $moneySupplyGrowth = MacroEngine::M2_BASE_GROWTH,
        public float $moneySupplyGrowthEma = MacroEngine::M2_BASE_GROWTH,
        public float $sovereignFundDomesticEquity = 0.0,
        public float $sovereignFundForeignEquity = 0.0,
        public float $sovereignFundForeignBonds = 0.0,
        public float $sovereignFundTargetWeight = 0.0,
        /** The fund's policy equity share: the board's policy weight plus the foreign sleeves' equity at their policy split, as the fund opened or as its head has since set it (App\Service\Politics\SovereignReserveFund). */
        public float $sovereignFundPolicyEquityShare = 0.0,
        /** The policy equity share the fund's head has handed the fund, 0 while the head sitting at Year 1 keeps the opening mix. */
        public float $sovereignFundMandateEquityShare = 0.0,
        public float $sovereignFundDollarsPerGdp = 0.0,
        public float $sovereignFundAnnualDraw = 0.0,
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
        public float $sovereignFundStabilisationToGdp = 0.0,
        public float $sovereignFundStabilisationYearStart = 0.0,
        public float $sovereignFundStabilisationLastChange = 0.0,
        public float $sovereignFundGapTrend = 0.0,
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
     * Constructs a MacroStateDTO from the snake_case payload Redis carries.
     *
     * The field list comes from App\Data\MacroFieldRegistry rather than being named here a second
     * time, so an observable added to the constructor is read back off the wire immediately. A field
     * this method forgot used to hydrate its opening value on every load with nothing failing — the
     * caller still got a plausible number, just not the recorded one.
     *
     * Three passes, in order: the values the payload carries, the openings computed from those
     * values, then the series that start level with the series they smooth.
     *
     * @param array<string, mixed> $data Decoded payload keyed by wire key.
     */
    public static function fromArray(array $data): self
    {
        $fields = MacroFieldRegistry::wireKeys();
        $defaults = MacroFieldRegistry::defaults();

        // A field the payload omits keeps the opening value the constructor declares for it.
        $args = [];
        foreach ($fields as $field => $key) {
            if (!isset($data[$key])) {
                continue;
            }

            $args[$field] = match ($field) {
                'sectorZ', 'sectorDemandZ' => is_array($data[$key]) ? array_map('floatval', $data[$key]) : [],
                'qeActive', 'qtActive' => (bool) $data[$key],
                'eventType' => (string) $data[$key],
                default => (float) $data[$key],
            };
        }

        // By reference: the passes below read openings the earlier passes have already computed.
        $resolve = static function (string $field) use (&$args, $defaults): mixed {
            return $args[$field] ?? $defaults[$field];
        };

        // The smoothed 5y was recorded under the macro_report column spelling before the wire key
        // existed, and a payload carrying both is a database row, so the column spelling wins.
        if (isset($data['yield5y_ema'])) {
            $args['yield5yEma'] = (float) $data['yield5y_ema'];
        }

        // Balance sheet intensity was recorded as a one-sided qe_intensity before QT existed.
        $args['balanceSheetIntensity'] ??= (float) ($data['qe_intensity'] ?? 0.0);
        $balanceSheetIntensity = $args['balanceSheetIntensity'];

        $args['qeActive'] ??= $balanceSheetIntensity > MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD;
        $args['qeIntensity'] ??= max(0.0, $balanceSheetIntensity);
        $args['qtActive'] ??= $balanceSheetIntensity < -MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD;
        $args['qtIntensity'] ??= max(0.0, -$balanceSheetIntensity);

        // A payload that carries a policy rate but no curve gets the opening curve's term spreads on top of
        // that rate, so the tenors stay consistent with the rate the payload does report.
        if (isset($args['policyRate'])) {
            foreach (['yield2y', 'yield5y', 'yield10y', 'yield30y'] as $tenor) {
                $args[$tenor] ??= $args['policyRate'] + ($defaults[$tenor] - $defaults['policyRate']);
            }
        }

        // Nelson-Siegel beta1 is the short-end spread of the curve, not a free parameter.
        $args['nsBeta1'] ??= $resolve('policyRate') - $resolve('nsLevel');

        // The quoted slope is the 10y-over-policy term spread, read off the curve the same way the engine
        // reads it, so a rehydrated snapshot does not report a flat curve over yields that are not flat.
        $args['nsSlope'] ??= $resolve('yield10y') - $resolve('policyRate');
        $args['potentialGdpIndex'] ??= $resolve('nominalGdpIndex') / (1.0 + $resolve('outputGap'));
        $args['highYieldCreditSpread'] ??= $resolve('macroCreditSpread') * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;

        // A smoothed series with no reading of its own opens level with the series it smooths. Walked
        // in constructor order so a series seeded from one that is itself seeded resolves in one pass.
        $seeds = MacroFieldRegistry::seeds() + self::HYDRATION_SEEDS;
        foreach (array_keys($fields) as $field) {
            if (isset($args[$field]) || !isset($seeds[$field])) {
                continue;
            }

            $args[$field] = $resolve($seeds[$field]);
        }

        // A saved currency that predates the split into fundamental and deviation keeps its level: the deviation is
        // whatever it sits away from its fundamental. As MacroState::fromArray has it.
        if (isset($data[$fields['exchangeRateIndex']]) && !isset($args['exchangeRateDeviation'])) {
            $args['exchangeRateDeviation'] = $resolve('exchangeRateIndex') / AssetMarketSubsystem::exchangeRateFundamentalAt(
                $resolve('policyRate'),
                $resolve('foreignPolicyRate'),
                $resolve('energyPriceIndexEma'),
                $resolve('industrialMetalsIndexEma'),
                $resolve('marketVolatilityEma'),
                $resolve('sovereignRiskSpreadEma')
            );
        }

        // The open-economy levels open where the currency and the mainland already stand, as MacroState::fromArray has them.
        $args['importPriceLevel'] ??= MacroAggregateSubsystem::importPriceLevelTarget($resolve('exchangeRateIndexEma'), $resolve('importTariffRate'));
        $args['realExchangeRateTradeLag'] ??= MacroAggregateSubsystem::realExchangeRateGap($resolve('exchangeRateIndexEma'), $resolve('exchangeRateTrend'));
        $args['alliedDefenseDeliveryLag'] ??= MacroAggregateSubsystem::alliedDefenseGap($resolve('alliedDefenseSpendingIndexEma'));
        $args['netExportGap'] ??= MacroAggregateSubsystem::netExportGapAt($resolve('foreignOutputGapEma'), $resolve('realExchangeRateTradeLag'), $resolve('alliedDefenseDeliveryLag'), $resolve('tariffTradeLag'));
        // The finance trend a state predating it omits opens on the trend it replaced, so the finance level is unmoved.
        if (!isset($args['financeMarketTrend']) && $resolve('equityWealthTrend') > 0.0) {
            $args['financeMarketTrend'] = $resolve('equityWealthTrend');
        }
        $args['financeOutputGap'] ??= MacroAggregateSubsystem::financeOutputGapAt(
            MacroAggregateSubsystem::creditBalanceGap($resolve('creditToGdpGap'), $resolve('creditToGdpTrend')),
            MacroAggregateSubsystem::marketBalanceGap($resolve('equityWealthRatio'), $resolve('financeMarketTrend'))
        );
        $args['domesticDemandGapEma'] ??= MacroAggregateSubsystem::domesticDemandGapAt($resolve('outputGapEma'), $resolve('productivitySupplyGap'), $resolve('netExportGap'), $resolve('financeOutputGap'));
        // The capital built counts the buffer beside the requirement, as MacroState::fromArray has it.
        if (!isset($args['bankCapitalRequiredLast'])) {
            $args['bankCapitalBuilt'] = $resolve('bankCapitalBuilt') + $resolve('countercyclicalBufferRate');
            $args['bankCapitalRequiredLast'] = $resolve('bankCapitalRequirement') + $resolve('countercyclicalBufferRate');
        }

        return new self(...$args);
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
            balanceSheetIntensity: $this->balanceSheetIntensity,
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
     * it is not a macro series any institution reports, so it draws no district conduit. Matches the
     * quarter EarningsEngine derives from the tick counter, both being elapsed time over a quarter.
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
     * Consumer confidence as a fraction, against where the index sits with output at trend.
     *
     * SENTIMENT_BASELINE is the constant the index is BUILT from — one hundred, less a stack of one-sided
     * penalties for misery, momentum, fear, rates and fuel — so it is a ceiling and cannot be a mean. Read
     * as a deviation from it, confidence comes out negative 83% of the time and ten points light on
     * average: a permanent demand haircut every consumer-facing model was charging, and a standing
     * negative driver every earnings report was attributing to a consumer who had not changed their mind.
     *
     * Use this where confidence is the only channel through which a stream sees the cycle.
     */
    public function sentimentDeviation(): float
    {
        return ($this->consumerSentimentIndexEma - MacroEngine::SENTIMENT_TREND_LEVEL) / self::INDEX_SCALE;
    }

    /**
     * The same reading with the output gap that is already inside it taken back out.
     *
     * Confidence is built from the cycle — the gap enters it directly, and again through the unemployment
     * Okun's law hands it — so a reader that prices the gap AND reads confidence prices the cycle twice.
     * Measured on this engine, the index carries SENTIMENT_GAP_LOADING points of gap (r = 0.78), which is
     * what the second helping is worth. Lemmon & Portniaguina (2006) orthogonalise confidence against
     * fundamentals for exactly this reason: what predicts anything beyond the macro data is the residual.
     *
     * Use this where the cycle is priced beside it.
     */
    public function sentimentResidual(): float
    {
        return $this->sentimentDeviation() - (($this->outputGapEma * MacroEngine::SENTIMENT_GAP_LOADING) / self::INDEX_SCALE);
    }

    /**
     * Snapshots the engine's mutable state vector into this immutable DTO.
     *
     * Every field is carried across by name, so the field list comes from
     * App\Data\MacroFieldRegistry rather than being spelled out a second time; MacroState declares
     * an identically named property for each of this constructor's parameters, and
     * App\Tests\DTO\MacroFieldRegistryTest fails if that ever stops being true.
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
