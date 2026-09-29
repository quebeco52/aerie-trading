<?php

namespace App\Service\Macro;

use App\Data\MacroFieldRegistry;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;

/**
 * The macro engine's mutable working copy of the state vector, advanced in place every tick.
 *
 * Its fields and their openings are declared once, on App\DTO\MacroStateDTO's constructor: this class
 * mirrors the names (App\Tests\DTO\MacroFieldRegistryTest fails on a mismatch) and takes its opening
 * values from there, so a fresh engine and every reader of a snapshot start from the same economy.
 */
class MacroState
{
    public float $totalTime;
    public float $inflation;
    public float $inflationEma;
    public float $outputGap;
    public float $outputGapEma;
    // The output gap as it reaches order books 3 to 18 months behind the economy (MacroAggregateSubsystem::demandTransmissionLagField).
    public float $outputGapLag3m;
    public float $outputGapLag6m;
    public float $outputGapLag9m;
    public float $outputGapLag12m;
    public float $outputGapLag15m;
    public float $outputGapLag18m;
    public float $capitalStockOverhang;
    public float $capitalStockOverhangEma;

    public float $unemploymentRate;
    public float $unemploymentRateEma;
    public float $jobVacanciesRate;
    public float $jobVacanciesRateEma;
    public float $laborTightness;
    public float $laborTightnessEma;
    public float $wageGrowth;
    public float $wageGrowthEma;
    // Log real wage relative to its potential-productivity path: the price of labour firms pay against what they sell.
    public float $realWageGap;
    public float $nairu;
    public float $nairuEma;

    public float $naturalRate;
    public float $naturalRateEma;

    public float $energyPriceIndex;
    public float $energyPriceIndexEma;
    // The index's own long-run average, which the PPI leg measures a growth RATE against. Zero means no
    // observation yet: the trend opens at the first one, so a cold start reports no commodity inflation.
    public float $energyPriceIndexTrend;
    public float $energyPriceShock;
    public float $energyBasePrice;
    // Productive energy capacity, lagging price: the supply leg of the energy cobweb.
    public float $energySupplyEma;
    public float $consumerSentimentIndex;
    public float $consumerSentimentIndexEma;

    public float $exchangeRateIndex;
    public float $exchangeRateIndexEma;
    public float $exchangeRateTrend;

    public float $industrialMetalsIndex;
    public float $industrialMetalsIndexEma;
    public float $industrialMetalsIndexTrend;
    public float $metalsChi;
    public float $metalsXi;

    public float $governmentSpendingIndex;
    public float $governmentSpendingIndexEma;
    // Allied defence spending against its trend burden, the demand the District's arms makers export into, and its EMA.
    public float $alliedDefenseSpendingIndex;
    public float $alliedDefenseSpendingIndexEma;

    public float $commercialPropertyIndex;
    public float $commercialPropertyIndexEma;

    public float $residentialPropertyIndex;
    public float $residentialPropertyIndexEma;
    // The price level households have got used to. Unlike the equity ratio below this index carries a
    // baseline scale, so it opens at that baseline instead of at the first reading.
    public float $residentialWealthTrend;
    // The index's recent real growth, a year's average of its log change a year: the momentum buyers extrapolate.
    public float $residentialPriceMomentum;

    public float $retailDefaultRate;
    public float $retailDefaultRateEma;

    public float $agriculturalCommodityIndex;
    public float $agriculturalCommodityIndexEma;
    public float $agriculturalCommodityIndexTrend;
    public float $agriChi;
    public float $agriXi;

    public float $freightRateIndex;
    public float $freightRateIndexEma;
    public float $freightSupplyEma;

    public float $targetRate;
    public float $policyRate;
    public float $policyRateEma;

    public float $tipsBreakeven;
    public float $tipsBreakevenEma;
    public float $energyCostPushLag;
    public float $agriCostPushLag;

    public float $nsLevel;
    public float $nsSlope;
    public float $nsSlopeEma;
    public float $structuralSlope;
    public float $nsCurvature;
    public float $nsCurvature2;
    public float $nsBeta1;
    public float $nsBaseTermPremium;
    public float $nsLongEndPremium;

    public float $yield2y;
    public float $yield2yEma;
    public float $yield5y;
    public float $yield5yEma;
    public float $yield10y;
    public float $yield10yEma;
    public float $yield30y;
    public float $yield30yEma;

    public float $termPremium10y;
    public float $termPremium10yEma;
    public float $riskNeutral10y;
    public float $riskNeutral10yEma;
    public float $termPremiumShock;
    public float $expectedPathShock;
    public float $termPremiumRegime;
    public float $perceivedNeutralRate;
    public float $restrictiveDuration;

    public bool $qeActive;
    public float $qeIntensity;
    public bool $qtActive;
    public float $qtIntensity;
    public float $balanceSheetIntensity;
    public float $balanceSheetHoldTimer;
    // When the last asset-purchase programme was launched, for the one headline it earns.
    public float $lastQeLaunchAt;

    public float $inversionDuration;
    public float $corporateTaxRate;
    public float $sovereignDebtToGdp;
    public float $sovereignDebtToGdpEma;
    // Gross debt less the debt instruments the sovereign fund holds (IMF GFSM 2014 net debt): what the market prices
    // default risk on. Equal to the gross ratio in a run with no fund.
    public float $sovereignNetDebtToGdp;
    public float $sovereignNetDebtToGdpEma;
    public ?string $eventType;
    public float $eventCooldownTimer;
    public float $equityRiskPremium;

    public float $potentialGdpIndex;
    public float $nominalGdpIndex;

    // The whole board's capitalisation, in currency, fed back by the ticker one tick behind. NOT an index
    // level: a level is a tradable instrument's scale and is restated whenever that instrument splits, which
    // would re-base household wealth on a share-count cosmetic. Zero means no market has been reported yet.
    public float $equityMarketCap;
    public float $equityMarketCapEma;

    // Capitalisation over nominal GDP. Both legs compound with the economy, so unlike the capitalisation
    // above this is STATIONARY, and it is the one of the two that gets written to macro_report: a raw
    // nominal quantity outgrows any fixed-width decimal column eventually, and at this board's size
    // DECIMAL(24,2) would have bought about sixteen hours of continuous running.
    public float $equityWealthRatio;

    // What the households have got used to: the wealth ratio's own slow trend, which is what the effect is
    // measured against. Anchoring to a fixed level instead would let any lasting gap between how fast the
    // board compounds and how fast the economy does accumulate into a permanent drift in the output gap.
    //
    // Seeded from the first ratio ever seen rather than from a constant, because the ratio is capitalisation
    // over an index and so carries an arbitrary scale. Measuring the gap against this trend divides that
    // scale out, which is the whole reason the raw capitalisation can be used without a baseline to calibrate.
    public float $equityWealthTrend;
    public float $gdpDeflator;

    public float $marketVolatility;
    public float $marketVolatilityEma;
    public float $marketZ;
    public float $marketZLatent;
    public float $marketJumpMultiplier;
    /** @var array<string, float> Per-macro-sector shock, keyed by Sectors::MACRO_SECTORS. */
    public array $sectorZ;
    /** @var array<string, float> Persistent per-macro-sector demand factor (OU process, unit variance), keyed by Sectors::MACRO_SECTORS. */
    public array $sectorDemandZ;
    public float $financialConditionsIndex;
    public float $financialConditionsIndexEma;

    public float $macroCreditSpread;
    public float $macroCreditSpreadEma;

    public float $interbankLiquiditySpread;
    public float $interbankLiquiditySpreadEma;
    public float $excessBondPremium;

    public float $totalFactorProductivityIndex;
    public float $totalFactorProductivityIndexEma;
    // Productivity shock: the random-walk level off the trend path, how far actual output and potential (two Pascal
    // stages each) have absorbed it, and the supply-side part of the output gap that difference opens.
    public float $tfpShockLevel;
    public float $tfpOutputStage1;
    public float $tfpOutputStage2;
    public float $tfpPotentialStage1;
    public float $tfpPotentialAbsorbed;
    public float $productivitySupplyGap;
    // Open economy: net exports away from the structural trade balance (a share of GDP, a level part of the gap), the
    // real exchange rate gap as trade volumes have absorbed it, and the log import price level relative to domestic prices.
    public float $netExportGap;
    // Domestic demand's part of the gap, smoothed like the gap: what the inventory cycle's sales surprise is read against.
    public float $domesticDemandGapEma;
    // The market-driven finance output (a share of GDP, a level part of the gap): loans and managed assets against their trends.
    public float $financeOutputGap;
    // Potential finance output: the one-sided HP(1600) trend of managed market value over GDP, and its log slope a quarter.
    public float $financeMarketTrend;
    public float $financeMarketTrendSlope;
    // The currency index over its fundamental: the purchasing-power deviation that decays while the fundamental jumps.
    public float $exchangeRateDeviation;
    public float $realExchangeRateTradeLag;
    // The log allied defence shift the District's arms exports have delivered, orders reaching exports through the backlog.
    public float $alliedDefenseDeliveryLag;
    public float $importPriceLevel;
    // Monetary transmission: the real-rate stance through two Pascal stages (Solow 1960) on its way to demand.
    public float $monetaryStanceStage1;
    public float $monetaryStanceTransmitted;

    public float $supercoreInflation;
    public float $supercoreInflationEma;
    public float $coreGoodsInflation;
    public float $coreGoodsInflationEma;


    public float $highYieldCreditSpread;
    public float $highYieldCreditSpreadEma;

    public float $inventoryStockGap;
    public float $inventoryStockGapEma;

    public float $demandShock;
    // The parts of demandShock the Kou disasters and their compensator put there, reverting with it; demandShock stays the total.
    public float $demandDisasterShock;
    public float $demandDisasterCompensation;

    public float $energyInventoryIndex;
    public float $energyInventoryIndexEma;

    public float $capacityUtilizationRate;
    public float $capacityUtilizationRateEma;

    public float $recessionProbability;
    public float $recessionProbabilityEma;

    public float $corporateDefaultRate;
    public float $corporateDefaultRateEma;

    public float $sloosTighteningIndex;
    public float $sloosTighteningIndexEma;

    public float $supplyChainPressureIndex;
    public float $supplyChainPressureIndexEma;

    public float $refiningCrackSpread;
    public float $refiningCrackSpreadEma;

    public float $dealActivityIndex;
    public float $dealActivityIndexEma;

    public float $manufacturingPmi;
    public float $manufacturingPmiEma;

    public float $producerPriceInflation;
    public float $producerPriceInflationEma;

    public float $tradeBalanceToGdp;
    public float $tradeBalanceToGdpEma;

    public float $housingStartsIndex;
    public float $housingStartsIndexEma;

    public float $moneySupplyGrowth;
    public float $moneySupplyGrowthEma;

    // Administered healthcare prices: an annual step, not a diffusion.
    public float $reimbursementRateIndex;
    public float $reimbursementRateIndexEma;
    public float $reimbursementRateGrowth;

    // Household balance sheet: leverage, the service it demands, the credit gap and the buffer regulators set on it.
    public float $householdDebtToIncome;
    public float $householdDebtToIncomeEma;
    public float $householdDebtServiceRatio;
    public float $householdDebtServiceRatioEma;
    // The ratio's own long-run average (Drehmann & Juselius 2012), which every consumer measures it against.
    // Zero means no service has been computed yet: the trend starts at the first observation so a cold start is silent.
    public float $householdDebtServiceTrend;
    public float $householdDebtServiceGap;
    // New borrowing beyond what holds leverage level: a year's average of the change in debt to income a year.
    public float $householdNewBorrowing;
    public float $creditToGdpTrend;
    public float $creditToGdpTrendSlope;
    public float $creditToGdpGap;
    public float $creditToGdpGapEma;
    public float $countercyclicalBufferRate;
    public float $countercyclicalBufferRateEma;
    // The credit cycle's crisis channel: this year's hazard, the deleveraging drag a crisis leaves on demand, and when the last one struck.
    public float $creditCrisisHazard;
    public float $creditCrisisDrag;
    public float $lastCreditCrisisAt;

    // The mainland: its output gap and the gap a quarter back, annualized core inflation and its three quarterly lags,
    // the funds rate the Fed sets on them, and the global demand composite.
    public float $foreignOutputGap;
    public float $foreignOutputGapEma;
    public float $foreignOutputGapLag;
    public float $foreignCoreInflation;
    public float $foreignCoreInflationLag1;
    public float $foreignCoreInflationLag2;
    public float $foreignCoreInflationLag3;
    public float $foreignPolicyRate;
    public float $foreignPolicyRateEma;
    public float $globalDemandGap;
    public float $globalDemandGapEma;

    // Physical catastrophes: recent insured loss burden (1.0 = an average year) and the last headline storm.
    public float $catastropheLossIndex;
    public float $catastropheLossIndexEma;
    public float $lastCatastropheAt;
    public float $lastCatastropheSeverity;

    // Natural gas: the oil index times a mean-reverting gas-to-oil ratio, with a winter premium.
    public float $naturalGasPriceIndex;
    public float $naturalGasPriceIndexEma;
    public float $gasOilRatioLog;
    public float $goldPriceIndex;
    public float $goldPriceIndexEma;

    // Wholesale power: gas passed through at the market heat rate, times a seasonal mean-reverting heat-rate factor.
    public float $wholesalePowerPriceIndex;
    public float $wholesalePowerPriceIndexEma;
    public float $powerHeatRateLog;

    // Deposits channel: the system deposit beta and the money-market share it drives.
    public float $systemDepositBeta;
    public float $systemDepositBetaEma;
    public float $moneyMarketFundShare;
    public float $moneyMarketFundShareEma;

    // Sovereign risk: the fiscal premium the market charges over the risk-free level, and the deficit it reads.
    public float $sovereignRiskSpread;
    public float $sovereignRiskSpreadEma;
    public float $primaryDeficitToGdp;

    // Policy uncertainty (BBD-style index) and the fixed-term election calendar it peaks on.
    public float $policyUncertaintyIndex;
    public float $policyUncertaintyIndexEma;
    public float $lastElectionAt;

    // Sovereign reserve fund (App\Service\Macro\Subsystem\SovereignFundSubsystem). The three sleeves: a slice of the
    // board in currency, and foreign equities and foreign paper in FOREIGN units (home value is units / exchangeRateIndex).
    // Zero until the fund incepts on the first tick that carries a board; a run with no market never has one.
    public float $sovereignFundDomesticEquity;
    public float $sovereignFundForeignEquity;
    public float $sovereignFundForeignBonds;
    // The mandate, fixed at inception: the domestic policy weight and the currency value of one unit of the GDP index.
    public float $sovereignFundTargetWeight;
    public float $sovereignFundDollarsPerGdp;
    // Flows: this budget year's draw (currency per year), the rebalance still to trade, its pace, the months left
    // on it, its size against the board's float, this tick's trade, and when the last programme started.
    public float $sovereignFundAnnualDraw;
    public float $sovereignFundRebalanceBacklog;
    public float $sovereignFundRebalanceRate;
    public float $sovereignFundRebalanceMonthsLeft;
    public float $sovereignFundRebalanceShare;
    public float $sovereignFundTrade;
    public float $lastSovereignRebalanceAt;
    // Stationary readings of the fund, the ones macro_report keeps.
    public float $sovereignFundToGdp;
    public float $sovereignFundDomesticWeight;
    public float $sovereignFundEquityShare;
    public float $sovereignFundOwnershipShare;
    public float $sovereignFundDrawToGdp;
    // Fund-financed stabilisation spending above the rule draw, over GDP (negative: saved into the fund), set at the
    // budget rounds and answered by demand at that rate; and the budget year's opening level and last year's change,
    // which the rule steps from.
    public float $sovereignFundStabilisationToGdp;
    public float $sovereignFundStabilisationYearStart;
    public float $sovereignFundStabilisationLastChange;
    // The budget's long-run average of the output gap, the level its cycle is measured from.
    public float $sovereignFundGapTrend;
    // The District's stamp duty on share trading, paid into the fund: this budget year so far, and last year's over GDP.
    public float $sovereignFundStampDutyYearToDate;
    public float $sovereignFundStampDutyToGdp;
    // The paper sleeve over GDP, the fund's holding of debt instruments that net debt deducts.
    public float $sovereignFundBondsToGdp;
    // Performance: the fund's value at the last close (currency), and its time-weighted return index, nominal and
    // deflated by the District's inflation, both 100 at inception. The expected compound real return the current
    // budget year's draw was set from, for the realized return to be read against.
    public float $sovereignFundValueAtClose;
    public float $sovereignFundReturnIndex;
    public float $sovereignFundRealReturnIndex;
    public float $sovereignFundExpectedRealReturn;
    // The foreign equity market the reserve portfolio holds, in its own currency, and the habit premium its investors
    // demand on their own cycle, with the log re-rating it implies and that re-rating's change this tick.
    public float $foreignEquityIndex;
    public float $foreignEquityRiskPremium;
    public float $foreignEquityValuation;
    public float $foreignEquityValuationChange;
    // Yield on the foreign sovereign index the paper sleeve holds, at its duration, at the last close. Zero until priced.
    public float $foreignBondYield;

    // The board as the ticker measured it on the PREVIOUS tick, like equityMarketCap: its float-adjusted
    // capitalisation, the float-weighted price return of that tick, the dividend cash the float was paid, and the
    // float the companies' own issuance added (buybacks negative), at that tick's prices.
    public float $boardFloatCap;
    public float $boardPriceReturn;
    public float $boardDividendCash;
    public float $boardNetIssuance;
    public float $boardStampDuty;
    // Cash the District's strategic stakes (App\Data\StrategicHoldings) paid it on the previous tick, a flow like the
    // board's: dividends, plus its share of buybacks less its share of issues. Held outside the fund, paid into it.
    public float $strategicStakeCash;
    // Currency the budget paid into the fund on the last tick: the surplus below the sovereign debt floor, with no debt
    // left to retire. Set by the fiscal accounts after the fund's update, so the fund takes it in on the next tick.
    public float $sovereignFundBudgetInflow;

    public function __construct()
    {
        foreach (MacroFieldRegistry::defaults() as $field => $opening) {
            $this->$field = $opening;
        }
    }

    /**
     * Initializes the MacroState from a decoded JSON array payload.
     *
     * The field list comes from App\Data\MacroFieldRegistry, so a newly declared observable is read
     * back off the wire the moment it exists. It used to be named here a second time, and a field
     * left out of this method was reset to its opening value on every load without anything failing
     * — the caller still received a plausible number.
     *
     * Three passes, in order: the values the payload carries, the openings that are computed from
     * those values, then the series that start level with the series they smooth.
     *
     * @param array<string, mixed> $data Decoded Redis payload keyed by wire key.
     */
    public static function fromArray(array $data): self
    {
        $state = new self();
        $fields = MacroFieldRegistry::wireKeys();

        // A field the payload omits keeps its opening value.
        $carried = [];
        foreach ($fields as $field => $key) {
            if (!isset($data[$key])) {
                continue;
            }

            $carried[$field] = true;
            $state->$field = match ($field) {
                'sectorZ', 'sectorDemandZ' => is_array($data[$key]) ? array_map('floatval', $data[$key]) : [],
                'qeActive', 'qtActive' => (bool) $data[$key],
                'eventType' => (string) $data[$key],
                default => (float) $data[$key],
            };
        }

        // Openings derived from the values above, for the fields a payload predating them omits.
        if (!isset($carried['balanceSheetIntensity'])) {
            // Balance sheet intensity was recorded as a one-sided qe_intensity before QT existed.
            $state->balanceSheetIntensity = (float) ($data['qe_intensity'] ?? 0.0);
        }
        if (!isset($carried['qeActive'])) {
            $state->qeActive = $state->balanceSheetIntensity > MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD;
        }
        if (!isset($carried['qeIntensity'])) {
            $state->qeIntensity = max(0.0, $state->balanceSheetIntensity);
        }
        if (!isset($carried['qtActive'])) {
            $state->qtActive = $state->balanceSheetIntensity < -MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD;
        }
        if (!isset($carried['qtIntensity'])) {
            $state->qtIntensity = max(0.0, -$state->balanceSheetIntensity);
        }
        if (!isset($carried['nsBeta1'])) {
            // Nelson-Siegel beta1 is the short-end spread of the curve, not a free parameter.
            $state->nsBeta1 = $state->policyRate - $state->nsLevel;
        }
        if (!isset($carried['potentialGdpIndex'])) {
            $state->potentialGdpIndex = $state->nominalGdpIndex / (1.0 + $state->outputGap);
        }
        if (!isset($carried['highYieldCreditSpread'])) {
            $state->highYieldCreditSpread = $state->macroCreditSpread * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;
        }

        // A smoothed series with no reading of its own opens level with the series it smooths.
        foreach (MacroFieldRegistry::seeds() as $field => $source) {
            if (!isset($carried[$field])) {
                $state->$field = $state->$source;
            }
        }

        // A saved currency that predates the split into fundamental and deviation keeps its level: the deviation is
        // whatever it sits away from its fundamental.
        if (isset($carried['exchangeRateIndex']) && !isset($carried['exchangeRateDeviation'])) {
            $state->exchangeRateDeviation = $state->exchangeRateIndex / AssetMarketSubsystem::exchangeRateFundamental($state);
        }

        // The open-economy levels a payload predating them omits open where the currency and the mainland already stand,
        // so their arrival reprices nothing: import prices have absorbed the currency, trade volumes too, and the net
        // export level is already part of the gap the payload carries.
        if (!isset($carried['importPriceLevel'])) {
            $state->importPriceLevel = MacroAggregateSubsystem::importPriceLevelTarget($state->exchangeRateIndexEma);
        }
        if (!isset($carried['realExchangeRateTradeLag'])) {
            $state->realExchangeRateTradeLag = MacroAggregateSubsystem::realExchangeRateGap($state->exchangeRateIndexEma, $state->exchangeRateTrend);
        }
        if (!isset($carried['alliedDefenseDeliveryLag'])) {
            $state->alliedDefenseDeliveryLag = MacroAggregateSubsystem::alliedDefenseGap($state->alliedDefenseSpendingIndexEma);
        }
        if (!isset($carried['netExportGap'])) {
            $state->netExportGap = MacroAggregateSubsystem::netExportGapAt($state->foreignOutputGapEma, $state->realExchangeRateTradeLag, $state->alliedDefenseDeliveryLag);
        }
        // The finance trend a state predating it omits opens on the trend it replaced, so the finance level is unmoved.
        if (!isset($carried['financeMarketTrend']) && $state->equityWealthTrend > 0.0) {
            $state->financeMarketTrend = $state->equityWealthTrend;
        }
        if (!isset($carried['financeOutputGap'])) {
            $state->financeOutputGap = MacroAggregateSubsystem::financeOutputGapAt(
                MacroAggregateSubsystem::creditBalanceGap($state->creditToGdpGap, $state->creditToGdpTrend),
                MacroAggregateSubsystem::marketBalanceGap($state->equityWealthRatio, $state->financeMarketTrend)
            );
        }
        // The inventory cycle's reference opens where domestic demand's smoothed part of the gap stands, so the upgrade
        // sets off no sales surprise.
        if (!isset($carried['domesticDemandGapEma'])) {
            $state->domesticDemandGapEma = MacroAggregateSubsystem::domesticDemandGapAt($state->outputGapEma, $state->productivitySupplyGap, $state->netExportGap, $state->financeOutputGap);
        }

        return $state;
    }

    /**
     * Converts the MacroState to the snake_case payload persisted to Redis.
     *
     * The key list comes from App\Data\MacroFieldRegistry, which reads it off
     * App\DTO\MacroStateDTO's constructor, so the writer on this end of the wire and the
     * MacroStateDTO::fromArray() reader on the other end cannot drift apart over a key name.
     *
     * @return array<string, mixed> Wire key => value.
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
