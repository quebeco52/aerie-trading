<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Schema declaration for the macro_report table of quarterly macroeconomic snapshots.
 *
 * Nothing loads this entity through the ORM. It exists so Doctrine can diff the table into a
 * migration, and so App\Data\MacroFieldRegistry can read the persisted column list off it — the
 * registry matches each column to the App\DTO\MacroStateDTO field of the same property name, and
 * App\Service\Macro\Recorder\MacroSnapshotRecorder builds its INSERT from that mapping.
 *
 * The table is written by that recorder and read by App\Controller\MacroReportController, both in
 * raw SQL, because a snapshot is an append-only row of 106 scalars rather than an object graph. So
 * this class carries mapped properties and no accessors: a getter here would have no caller, and a
 * column here with no matching DTO field fails App\Tests\DTO\MacroFieldRegistryTest rather than
 * quietly recording NULL on every snapshot for the life of the table. The few columns the recorder
 * fills from outside the vector — the surrogate key, the timestamp, the gap decomposition — are named
 * in that test's RECORDER_OWNED_COLUMNS, so the exception is declared rather than assumed.
 *
 * Do not delete it to tidy away an unused class — Doctrine would diff the table out of existence.
 */
#[ORM\Entity]
class MacroReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $recordedAt = null;

    // Wider than the DECIMAL(10, 4) the macro vector uses, because this is the row's KEY rather than one
    // of its readings: simulated time accumulates 1/3600 of a year per tick, so four decimal places put
    // two quarters 0.0001 apart at year 900 and a join or an episode boundary reads the wrong row.
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 6, nullable: true)]
    private ?string $totalTime = null;

    /**
     * The closed quarter's output gap drift decomposition, as App\Service\Macro\Recorder\OutputGapProbe
     * accumulated it: eighteen channel contributions plus diffusion, clamp and the unexplained residual.
     *
     * JSON rather than eighteen more DECIMAL(10, 4) columns, and not for schema economy: a single channel
     * delivers on the order of 1e-5 of potential output over a quarter, which four decimal places round
     * to zero. The decomposition is only worth recording at full float precision.
     *
     * NULL on a row whose quarter the probe could not close — a ticker started mid-quarter, or one running
     * with the probe off. Absent is the honest reading there; zeros would claim a quiet quarter.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $gapChannels = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $inflation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $inflationEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $outputGap = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $outputGapEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $policyRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $policyRateEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $targetRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $yield10y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $yield10yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $yield2y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $yield2yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $yield5y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $yield5yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $yield30y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $yield30yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $corporateTaxRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $equityRiskPremium = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4)]
    private ?string $nominalGdpIndex = null;

    // The wealth ratio and its trend, NOT the capitalisation they are built from. Capitalisation is a raw
    // nominal quantity that compounds with the economy forever and so outgrows any fixed-width column; the
    // ratio divides nominal GDP back out and is stationary. It lives on the wire and in Redis, where it
    // costs nothing, and only these two reach the history table.
    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 2, nullable: true)]
    private ?string $equityWealthRatio = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 2, nullable: true)]
    private ?string $equityWealthTrend = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $marketVolatility = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $macroCreditSpread = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $macroCreditSpreadEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $unemploymentRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $unemploymentRateEma = null;



    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $energyPriceIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $energyPriceIndexEma = null;

    /** The energy price level this economy treats as normal; the PPI leg reads its growth against this. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $energyPriceIndexTrend = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $consumerSentimentIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $consumerSentimentIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $exchangeRateIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $exchangeRateIndexEma = null;

    /** The exchange rate level this economy treats as normal; a consumer wanting "is the currency strong" reads the deviation from it, not from the nominal 100 baseline. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $exchangeRateTrend = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $industrialMetalsIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $industrialMetalsIndexEma = null;

    /** The industrial metals level this economy treats as normal; the PPI leg reads its growth against this. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $industrialMetalsIndexTrend = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $governmentSpendingIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $governmentSpendingIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $commercialPropertyIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $commercialPropertyIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $residentialPropertyIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $residentialPropertyIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $retailDefaultRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $retailDefaultRateEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $agriculturalCommodityIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $agriculturalCommodityIndexEma = null;

    /** The agricultural commodity level this economy treats as normal; the PPI leg reads its growth against this. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $agriculturalCommodityIndexTrend = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $freightRateIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $freightRateIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $capitalStockOverhang = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $capitalStockOverhangEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $interbankLiquiditySpread = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $interbankLiquiditySpreadEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $totalFactorProductivityIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $totalFactorProductivityIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $tipsBreakeven = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $tipsBreakevenEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $nsCurvature2 = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $jobVacanciesRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $jobVacanciesRateEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $laborTightness = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $laborTightnessEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $wageGrowth = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $wageGrowthEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $naturalRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $naturalRateEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $termPremium10y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $termPremium10yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $riskNeutral10y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $riskNeutral10yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $termPremiumShock = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $expectedPathShock = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $termPremiumRegime = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $perceivedNeutralRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $restrictiveDuration = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $balanceSheetIntensity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $nairu = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $nairuEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $sovereignDebtToGdp = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $sovereignDebtToGdpEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $financialConditionsIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $financialConditionsIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $agriCostPushLag = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $supercoreInflationEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $coreGoodsInflationEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $cumulativeInflationGapEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $highYieldCreditSpreadEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $inventoryStockGapEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $energyInventoryIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $capacityUtilizationRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $capacityUtilizationRateEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $recessionProbability = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $recessionProbabilityEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $corporateDefaultRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $corporateDefaultRateEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $sloosTighteningIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $sloosTighteningIndexEma = null;

    /** The level of lending standards this economy treats as normal; a consumer wanting "are standards tight" reads the deviation from it, not from zero. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $sloosTighteningTrend = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $supplyChainPressureIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $supplyChainPressureIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $refiningCrackSpread = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $refiningCrackSpreadEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $dealActivityIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $dealActivityIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $manufacturingPmi = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $manufacturingPmiEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $producerPriceInflation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $producerPriceInflationEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $tradeBalanceToGdp = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $tradeBalanceToGdpEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $housingStartsIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $housingStartsIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $moneySupplyGrowth = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $moneySupplyGrowthEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $naturalGasPriceIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $naturalGasPriceIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $gasOilRatioLog = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $goldPriceIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $goldPriceIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $sovereignRiskSpread = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $sovereignRiskSpreadEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $primaryDeficitToGdp = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $householdDebtToIncome = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $householdDebtToIncomeEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $householdDebtServiceRatio = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $householdDebtServiceRatioEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $creditToGdpGap = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $creditToGdpGapEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $countercyclicalBufferRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $countercyclicalBufferRateEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $creditCrisisHazard = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $creditCrisisDrag = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $foreignOutputGap = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $foreignOutputGapEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $foreignPolicyRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $foreignPolicyRateEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $globalDemandGap = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $globalDemandGapEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $systemDepositBeta = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $systemDepositBetaEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $moneyMarketFundShare = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $moneyMarketFundShareEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $catastropheLossIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $catastropheLossIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $reimbursementRateIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $reimbursementRateIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $reimbursementRateGrowth = null;

    // --- Monetary Regime ---
    // Which regime the central bank was in, not just where the rate sat. A quarter at the effective lower
    // bound with QE running and a quarter at the same rate on the way down are different economies, and the
    // policy_rate column alone cannot tell them apart.

    #[ORM\Column(nullable: true)]
    private ?bool $qeActive = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $qeIntensity = null;

    #[ORM\Column(nullable: true)]
    private ?bool $qtActive = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $qtIntensity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $balanceSheetHoldTimer = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $inversionDuration = null;

    // --- Wealth Stocks ---
    // The levels the wealth channels are read against. Their CONTRIBUTION is in gap_channels; without the
    // stock behind it, a contribution cannot be told from a change in the elasticity that scales it.

    // A whole-board capitalisation runs to eleven figures, so this is the one column the DECIMAL(10, 4) the
    // rest of the vector uses would overflow rather than merely round.
    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: 2, nullable: true)]
    private ?string $equityMarketCap = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 22, scale: 2, nullable: true)]
    private ?string $equityMarketCapEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $residentialWealthTrend = null;

    // --- Gap Drivers ---

    /** The AR(1) demand disturbance's LEVEL; gap_channels carries only what it delivered over the quarter. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $demandShock = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $potentialGdpIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $gdpDeflator = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $inventoryStockGap = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $energyPriceShock = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $policyUncertaintyIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $policyUncertaintyIndexEma = null;

    // --- Inflation Decomposition ---

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $supercoreInflation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $coreGoodsInflation = null;

    /** Drives the price-level-target catch-up, so a Taylor residual cannot be read without it. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $cumulativeInflationGap = null;

    // --- Curve Factors ---
    // The Diebold-Li factors the recorded yields are generated FROM. The yields are already columns, but a
    // curve fault shows in the factor and only its shadow shows in the four tenors.

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $nsLevel = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $nsSlope = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $nsSlopeEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $structuralSlope = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $nsCurvature = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $nsBeta1 = null;

    // --- Credit Stocks ---

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $creditToGdpTrend = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $householdDebtServiceTrend = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $householdDebtServiceGap = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $highYieldCreditSpread = null;

    // --- Episode Markers ---
    // Simulated times, so they carry total_time's width rather than the vector's. They are what lets an
    // episode be cut out of the run by cause instead of by eye.

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 6, nullable: true)]
    private ?string $lastCreditCrisisAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 6, nullable: true)]
    private ?string $lastCatastropheAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $lastCatastropheSeverity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $eventCooldownTimer = null;
}
