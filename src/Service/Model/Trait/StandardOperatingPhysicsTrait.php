<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Data\Macro\InputOutputExposures;
use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\SectorCoverageProfile;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Corporate\Industry\IndustryRegimeView;
use App\Service\Macro\Subsystem\Aggregate\MovingAverages;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\ModelParam;
use App\Service\Model\StreamContext;

trait StandardOperatingPhysicsTrait
{
    /** Calendar quarter [0..3] of the report in progress, as the engine keys its seasonality; null outside a report. */
    private ?int $reportedCalendarQuarter = null;

    /**
     * The calendar quarter the physics call in progress reports, so a model can place a season on one stream
     * while the engine carries the firm-level factor. Set by computeActualFinancials for each call.
     */
    protected function reportedCalendarQuarter(): ?int
    {
        return $this->reportedCalendarQuarter;
    }

    /** The industry-wide regimes of the report in progress; null outside a report or with no ledger. */
    private ?IndustryRegimeView $industryRegimes = null;

    /**
     * Hands the physics the industry-wide regimes for one report, or takes them away (null). The engine sets them
     * before the report's physics, keeps them through the macro attribution's replays and clears them after.
     */
    public function useIndustryRegimes(?IndustryRegimeView $regimes): void
    {
        $this->industryRegimes = $regimes;
    }

    /** The industry-wide regimes the engine handed this report, or null when the firm reads only its own. */
    protected function industryRegimes(): ?IndustryRegimeView
    {
        return $this->industryRegimes;
    }

    /** A sector with no measured demand drift grows with the economy. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH;
    }

    public function getFadedSecularGrowthRate(Stock $stock, float $simYears): float
    {
        return TimeSeries::fadeTowardTrend(
            $this->getSecularGrowthRate($stock),
            MacroConstants::TREND_REAL_GROWTH,
            $simYears,
            FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS,
        );
    }

    public function getStructuralRevenueMultiplier(Stock $stock): float
    {
        return 1.0;
    }

    public function getCapexCyclicality(): float
    {
        return static::CAPEX_CYCLICALITY;
    }

    /** Most firms budget against the domestic business cycle. */
    public function getCapexCycleSignal(Stock $stock, MacroStateDTO $macroState): float
    {
        return $macroState->outputGapEma;
    }

    /**
     * Returns quarterly revenue seasonality multipliers [Q1, Q2, Q3, Q4] summing strictly to 4.0.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return static::SEASONALITY_FACTORS;
    }

    /** @return array<int, float> */
    public function resolveSeasonalityFactors(Stock $stock): array
    {
        return $this->getSeasonalityFactors();
    }

    /**
     * District catastrophe losses incurred since the firm's last report, and what that window averages, in burden
     * units: the flow of the quarter's storms, not the stock of open claims the index carries. Null on a first report,
     * which has no earlier reading; the reading is persisted for the next.
     *
     * @return array{incurred: float, expected: float}|null
     */
    protected function resolveDistrictCatastropheIncurred(StreamContext $streams, MacroStateDTO $macroState): ?array
    {
        $burden = $macroState->catastropheLossIndex;
        $readAt = $streams->getPersistedState(FinancialConstants::STATE_CATASTROPHE_BURDEN_READ_AT, -1.0);
        $burdenThen = $streams->getPersistedState(FinancialConstants::STATE_CATASTROPHE_BURDEN_AT_REPORT, $burden);
        $streams->registerState(FinancialConstants::STATE_CATASTROPHE_BURDEN_AT_REPORT, $burden);
        $streams->registerState(FinancialConstants::STATE_CATASTROPHE_BURDEN_READ_AT, $macroState->totalTime);

        $window = $macroState->totalTime - $readAt;
        if ($readAt < 0.0 || $window <= 0.0) {
            return null;
        }

        return [
            'incurred' => CommodityLogisticsSubsystem::catastropheIncurredSince($burden, $burdenThen, $window),
            'expected' => CommodityLogisticsSubsystem::expectedCatastropheIncurred($macroState->totalTime, $window),
        ];
    }

    public function getSeasonalFixedCostShare(): float
    {
        return FinancialConstants::SEASONAL_FIXED_COST_SHARE;
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
    }

    public function getCoverageProfile(Stock $stock): SectorCoverageProfile
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::BaseVisibility->value => static::BASE_COVERAGE_VISIBILITY,
            ModelParam::CoverageError->value  => static::BASE_COVERAGE_ERROR,
            ModelParam::MinVisibility->value  => static::BASE_COVERAGE_MIN_VISIBILITY,
        ]);

        $visibility = $params[ModelParam::BaseVisibility];
        $error      = $params[ModelParam::CoverageError];
        $minVis     = $params[ModelParam::MinVisibility];

        // Systemic importance modifier: titans get more analyst coverage
        $importance = $stock->getSystemicImportance();
        if ($importance === 'titan') {
            $visibility += 0.15;
            $minVis += 0.10;
        } elseif ($importance === 'systemic') {
            $visibility += 0.10;
            $minVis += 0.05;
        }

        // A model whose quarters can carry an announced event (a trial readout, a recall, a missed hurdle) declares how
        // visible such a quarter is; the systemic boost above applies to its routine quarters either way.
        return new SectorCoverageProfile(
            baseVisibility: min(1.0, $visibility),
            errorStdDev: $error,
            minVisibility: min(1.0, $minVis),
            eventBaseVisibility: static::EVENT_BASE_VISIBILITY,
            eventMinVisibility: static::EVENT_MIN_VISIBILITY,
        );
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        $shares = $this->resolveWorkingCapitalShares($stock);

        return $shares === null
            ? (float) static::WORKING_CAPITAL_INTENSITY
            : $shares['receivables'] + $shares['inventory'] - $shares['payables'];
    }

    /**
     * The trade cycle's three balances as shares of revenue, for a model that knows them apart: a firm whose
     * suppliers fund more than its stock and receivables still holds both. Null leaves the balances split from the
     * net intensity alone.
     *
     * @return array{receivables: float, inventory: float, payables: float}|null
     */
    protected function resolveWorkingCapitalShares(Stock $stock): ?array
    {
        return null;
    }

    /** Goods sold as they are made: none of the stock is maturing. */
    public function getMaturingInventoryShare(Stock $stock): float
    {
        return 0.0;
    }

    /** An operating company's cost base follows its revenue: the operating margin's complement. */
    public function getOperatingCostToEarningAssets(Stock $stock): ?float
    {
        return null;
    }

    /** An operating company's assets are plant and a trade cycle, not credit: nothing to charge off. */
    public function getThroughTheCycleCreditLossRate(?Stock $stock = null): float
    {
        return 0.0;
    }

    public function getCreditLossHorizonYears(): float
    {
        return static::CREDIT_LOSS_HORIZON_YEARS;
    }

    /** No credit book, no forward reserve to condition. */
    public function getForwardCreditLossMultiplier(?Stock $stock, MacroStateDTO $macroState): float
    {
        return 1.0;
    }

    /** A model whose demand runs through macro_demand_shift has already shown the cost base its activity. */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        return 0.0;
    }

    /** A firm that is not a bank owes no bank levy. */
    public function calculateAnnualBankLevy(Stock $stock, MacroStateDTO $macroState): float
    {
        return 0.0;
    }

    /** A firm that is not a bank has nothing the levy is charged on. */
    public function annualBankLevyBase(Stock $stock): float
    {
        return 0.0;
    }

    /** A firm no rule costs productivity carries its committed base at the inputs and wages it is priced at. */
    public function getFixedCostFactor(MacroStateDTO $macroState): float
    {
        return 1.0;
    }

    /**
     * The annualized revenue times the share of it the rules on extraction last scaled (underExtractionRules()); zero
     * for a model that never applies them.
     */
    public function annualExtractionCostBase(Stock $stock): float
    {
        return (float) $stock->getTotalRevenue() * (float) (($stock->getEarningsMomentumZ() ?? [])[FinancialConstants::STATE_EXTRACTION_COST_SHARE] ?? 0.0);
    }

    /**
     * The annualized revenue times the share of it, less its variable cost, the model last reported moving with
     * turnover; zero for a model that reports none.
     */
    public function annualStampDutyTurnoverBase(Stock $stock): float
    {
        return (float) $stock->getTotalRevenue() * (float) (($stock->getEarningsMomentumZ() ?? [])[FinancialConstants::STATE_STAMP_DUTY_TURNOVER_SHARE] ?? 0.0);
    }

    /**
     * The annualized revenue times the share of it the model last reported the carbon price's power uplift adding to
     * earnings before tax; zero for a model that reports none.
     */
    public function annualCarbonPowerEarningsBase(Stock $stock): float
    {
        return (float) $stock->getTotalRevenue() * (float) (($stock->getEarningsMomentumZ() ?? [])[FinancialConstants::STATE_CARBON_POWER_EARNINGS_SHARE] ?? 0.0);
    }

    /** No commission rules on the firm's prices. */
    public function annualUtilityTariffBase(Stock $stock): float
    {
        return 0.0;
    }

    /**
     * The annualized revenue times the share of it the model last reported moving with government purchases, less its
     * variable cost; zero for a model that reports none.
     */
    public function annualPublicSpendingEarningsBase(Stock $stock): float
    {
        return (float) $stock->getTotalRevenue() * (float) (($stock->getEarningsMomentumZ() ?? [])[FinancialConstants::STATE_PUBLIC_SPENDING_EARNINGS_SHARE] ?? 0.0);
    }

    /**
     * Keeps what a unit of government purchases above the founding level adds to earnings before tax, as a share of
     * revenue, for the market to price a change in public spending against (annualPublicSpendingEarningsBase()).
     *
     * @param float $revenuePerUnit Revenue a unit of purchases above the founding level adds, at this report's scale.
     * @param float $variableCostRatio The variable cost the revenue carries, as a share of it.
     */
    protected function registerPublicSpendingExposure(StreamContext $streams, float $revenuePerUnit, float $variableCostRatio, float $actualRevenue): void
    {
        $streams->registerState(
            FinancialConstants::STATE_PUBLIC_SPENDING_EARNINGS_SHARE,
            $actualRevenue > 0.0 ? $revenuePerUnit * (1.0 - $variableCostRatio) / $actualRevenue : 0.0,
        );
    }

    /**
     * A per-unit cost ratio under the rules on extraction in force: stricter rules cost productivity, and every unit
     * costs that much more to produce. The committed base carries the same factor (getFixedCostFactor() of a model that
     * extracts). Keeps the share of revenue the rules scale, both parts before them, for the market to price a change
     * in the rules against (annualExtractionCostBase()).
     *
     * @param float $perUnitCostRatio The cost ratio the rules scale, at the prices the cost base was sized for.
     * @param float $priceRelative    Realized price over the prices the cost base was sized for.
     * @param float $fixedCosts       The quarter's committed cost base, the rules' factor in it.
     * @param float $actualRevenue    The quarter's revenue.
     */
    protected function underExtractionRules(StreamContext $streams, MacroStateDTO $macroState, float $perUnitCostRatio, float $priceRelative, float $fixedCosts, float $actualRevenue): float
    {
        $factor = FirmEconomics::calculateExtractionCostFactor($macroState->extractionStringency);
        $streams->registerState(
            FinancialConstants::STATE_EXTRACTION_COST_SHARE,
            FirmEconomics::calculatePerUnitCostRatio($perUnitCostRatio, $priceRelative) + ($actualRevenue > 0.0 ? $fixedCosts / $factor / $actualRevenue : 0.0),
        );

        return $perUnitCostRatio * $factor;
    }

    // --- Public Quarter ---
    /**
     * Antithetic scenario pairs the public quarter averages the physics over (32 runs): linear terms cancel within a
     * pair, so the sampling error left is the non-linear terms', well inside analysts' own coverage error.
     */
    public const PUBLIC_QUARTER_SCENARIO_PAIRS = 16;

    // --- Input Cost Basket ---
    /**
     * Share of an input price shock a firm with full pricing power recovers in its own prices; the rest lands on margin
     * (incomplete pass-through, Gopinath & Itskhoki 2010).
     */
    public const MAX_INPUT_COST_PASS_THROUGH = 0.80;
    /** Bound on the signed cost-ratio drag the basket may produce in one quarter. */
    public const MAX_INPUT_COST_DRAG = 0.50;

    /**
     * Shares of the VARIABLE cost base bought in each tracked input market. Channels: energy (oil-linked),
     * gas, electricity (industrial tariffs off the wholesale power index), metals, agri, freight, ppi (wholesale
     * intermediate goods) and labor (payroll, priced by the real wage's gap to trend productivity). Shares need not
     * sum to one; the remainder is bought at prices no macro index tracks. Sector models declare
     * INPUT_COST_EXPOSURES, measured in App\Data\Macro\InputOutputExposures; a model that declares none buys the
     * all-private-industries basket.
     *
     * @return array<string, float>
     */
    public function getInputCostExposures(): array
    {
        return static::INPUT_COST_EXPOSURES ?? InputOutputExposures::ALL_PRIVATE_INDUSTRIES;
    }

    // --- Demand Transmission Lag ---
    /**
     * Years for a move in the output gap to reach this firm's order book. A restaurant feels a recession
     * the week it starts; a machinery builder is still delivering against orders booked before it began,
     * and only sees the downturn when the next capex budget is set. Zero leaves demand contemporaneous,
     * which is what a spot business genuinely is.
     */
    public function getDemandLagYears(): float
    {
        return (float) static::DEMAND_LAG_YEARS;
    }

    /**
     * The output gap as it has actually reached this firm, distributed over its transmission lag.
     *
     * The lag is the economy's, not the firm's: every firm with the same delay meets the same cycle, so the macro
     * publishes it (MovingAverages::DEMAND_TRANSMISSION_LAGS) and the model only reads it. Mid-downturn a
     * long-lag builder is still working through a boom-era book while a spot seller is already in the slump. At
     * zero lag the helper returns the macro series untouched.
     */
    public function resolveLaggedOutputGap(MacroStateDTO $macroState): float
    {
        return $macroState->laggedOutputGap($this->getDemandLagYears());
    }

    // --- FX Exposure ---
    /**
     * Share of revenue whose competitiveness moves with the trade-weighted exchange rate: export sales
     * translated home, and domestic sales meeting importers who reprice when the currency does. Distinct
     * from OPERATING_CYCLICALITY, which measures exposure to the output gap: a defensive branded exporter
     * is barely cyclical and heavily FX-exposed, so multiplying one by the other double-counts.
     */
    public function getFxRevenueExposure(): float
    {
        return (float) static::FX_REVENUE_EXPOSURE;
    }

    /**
     * Signed demand shift from the exchange rate. A stronger domestic currency (index above its trend) prices
     * exports out of foreign markets and cheapens the importer's shelf price at home, so the shift is
     * negative on the way up and positive on the way down, scaled by how much revenue is actually exposed.
     * The currency settles away from its nominal base by construction, so the move is read against its trend,
     * as the macro's own net exports read it (MacroStateDTO::exchangeRateGap()).
     */
    public function resolveFxDemandShift(MacroStateDTO $macroState, ?float $exposure = null): float
    {
        $fxShift = $macroState->exchangeRateGap();

        return -$fxShift * ($exposure ?? $this->getFxRevenueExposure());
    }

    /**
     * Years for spot input moves to reach the cost base (0 = spot buyer; forward hedges and supply contracts lengthen
     * it).
     */
    public function getInputCostLagYears(): float
    {
        return (float) static::INPUT_COST_LAG_YEARS;
    }

    /**
     * This firm's buying lag: the sector's by default; a model whose firms hedge to their own horizons overrides it.
     */
    protected function resolveInputCostLagYears(Stock $stock): float
    {
        return $this->getInputCostLagYears();
    }

    /**
     * Years for the recoverable part of an input move to reach selling prices (menu costs, contract repricing, fuel
     * surcharges).
     */
    public function getInputPassThroughLagYears(): float
    {
        return (float) static::INPUT_PASS_THROUGH_LAG_YEARS;
    }

    /**
     * Signed relative price deviation of each tracked input market from its baseline this quarter.
     *
     * @return array<string, float>
     */
    public function resolveInputPriceDeviations(MacroStateDTO $macroState): array
    {
        return [
            'energy'  => $macroState->energyCostPushLag / MacroConstants::ENERGY_COST_PUSH_TRANSMISSION,
            'gas'     => ($macroState->naturalGasPriceIndexEma - 100.0) / 100.0,
            'electricity' => ($macroState->wholesalePowerPriceIndexEma - MacroConstants::WHOLESALE_POWER_BASELINE) / MacroConstants::WHOLESALE_POWER_BASELINE,
            'metals'  => ($macroState->industrialMetalsIndexEma - 100.0) / 100.0,
            'agri'    => ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0,
            'freight' => ($macroState->freightRateIndexEma - 100.0) / 100.0,
            // PPI is a rate. A price level's gap to its H-year EMA trend is H times its growth smoothed over H, so the
            // rate over target times the PPI's own one-year window is the wholesale price level against its trend.
            'ppi'     => MacroConstants::COMMODITY_TREND_HORIZON_YEARS
                * ($macroState->producerPriceInflationEma - MacroConstants::TARGET_INFLATION),
            'labor'   => $macroState->realWageGap,
            'imports' => $macroState->importPriceDeviation(),
        ];
    }

    /** Exposure-weighted relative input cost deviation of the basket (signed, fraction of the variable cost base). */
    public function resolveInputCostDeviation(MacroStateDTO $macroState): float
    {
        $deviations = $this->resolveInputPriceDeviations($macroState);
        $weighted = 0.0;
        foreach ($this->getInputCostExposures() as $channel => $share) {
            $weighted += max(0.0, (float) $share) * ($deviations[$channel] ?? 0.0);
        }

        return $weighted;
    }

    /**
     * Signed change in the variable cost RATIO from input price moves, net of the share the firm recovers in
     * its own prices.
     *
     * Costs follow the input markets with the buying lag (spot buyers feel a diesel spike this quarter, a
     * hedged airline next year); recovery follows with the repricing lag (fuel surcharges, menu prices,
     * contract escalators), scaled by pricing power. The steady-state drag on a permanent shift is
     * margin x deviation x (1 - recovered share); in transition costs lead prices, which is where the
     * squeeze on the way up and the windfall on the way down both come from. Both lag states persist in
     * the stream map so the firm carries its own cost history.
     */
    protected function resolveInputCostDrag(Stock $stock, MacroStateDTO $macroState, StreamContext $streams, float $pricingPower, float $realizedVariableMargin): float
    {
        // A firm with no cost history starts at baseline prices, so a shock already in the market reaches it
        // through the lags like any other.
        $recoveredShare = max(0.0, min(1.0, $pricingPower)) * self::MAX_INPUT_COST_PASS_THROUGH;
        $costLagYears = $this->resolveInputCostLagYears($stock);
        [$costLevel, $recovery] = $this->advanceInputCostState(
            $streams->getPersistedState(FinancialConstants::STATE_INPUT_COST_LEVEL, 0.0),
            $streams->getPersistedState(FinancialConstants::STATE_INPUT_COST_RECOVERY, 0.0),
            $this->resolveInputCostDeviation($macroState),
            $recoveredShare,
            $costLagYears,
        );

        $streams->registerState(FinancialConstants::STATE_INPUT_COST_LEVEL, $costLevel);
        $streams->registerState(FinancialConstants::STATE_INPUT_COST_RECOVERY, $recovery);
        $this->advanceInputCostChannels($macroState, $streams, $recoveredShare, $costLagYears);
        // The share this model recovers, which differs by caller (a defence book's cost-plus share, a retailer's
        // pricing power), and the cost base the gap is charged on (a refiner's opex, never its crude), kept so
        // management can project the next quarter on the same terms.
        $dragBase = max(0.01, $realizedVariableMargin);
        $streams->registerState(FinancialConstants::STATE_INPUT_COST_RECOVERED_SHARE, $recoveredShare);
        $streams->registerState(FinancialConstants::STATE_INPUT_COST_DRAG_BASE, $dragBase);

        $drag = $dragBase * ($costLevel - $recovery);

        return max(-self::MAX_INPUT_COST_DRAG, min(self::MAX_INPUT_COST_DRAG, $drag));
    }

    /**
     * Each input market's own part of the two lags: the same recursion run on that input's term of the basket. The
     * lags are linear and every input shares their time constants and the recovered share, so the inputs' parts sum
     * to the basket's level and recovery; what they leave over is state the basket carried before they were tracked.
     * Bookkeeping only: the drag is still struck on the basket.
     */
    private function advanceInputCostChannels(MacroStateDTO $macroState, StreamContext $streams, float $recoveredShare, float $costLagYears): void
    {
        $gaps = $this->resolveInputPriceDeviations($macroState);
        foreach ($this->getInputCostExposures() as $channel => $share) {
            if ((float) $share <= 0.0) {
                continue;
            }
            [$level, $recovery] = $this->advanceInputCostState(
                $streams->getPersistedState(FinancialConstants::STATE_INPUT_COST_LEVEL_PREFIX . $channel, 0.0),
                $streams->getPersistedState(FinancialConstants::STATE_INPUT_COST_RECOVERY_PREFIX . $channel, 0.0),
                (float) $share * ($gaps[$channel] ?? 0.0),
                $recoveredShare,
                $costLagYears,
            );
            $streams->registerState(FinancialConstants::STATE_INPUT_COST_LEVEL_PREFIX . $channel, $level);
            $streams->registerState(FinancialConstants::STATE_INPUT_COST_RECOVERY_PREFIX . $channel, $recovery);
        }
    }

    /**
     * The quarter's input-cost drag on the cost ratio split by input market, from the lag state it closed with: each
     * input's part, and "earlier" for the basket state no input accounts for (the firm's history before the split
     * was kept), which fades at the lag rates. The parts sum to the drag resolveInputCostDrag() booked, its bound
     * included: when the bound binds every part is scaled down alike. Empty for a model that books no drag.
     *
     * @param array<string, float> $state The stream state map the quarter closed with.
     * @return array<string, float>
     */
    public function attributeInputCostDrag(array $state): array
    {
        if (!isset($state[FinancialConstants::STATE_INPUT_COST_LEVEL], $state[FinancialConstants::STATE_INPUT_COST_DRAG_BASE])) {
            return [];
        }
        $base = (float) $state[FinancialConstants::STATE_INPUT_COST_DRAG_BASE];
        $total = $base * ((float) $state[FinancialConstants::STATE_INPUT_COST_LEVEL] - (float) ($state[FinancialConstants::STATE_INPUT_COST_RECOVERY] ?? 0.0));

        $parts = [];
        foreach ($this->getInputCostExposures() as $channel => $share) {
            $level = $state[FinancialConstants::STATE_INPUT_COST_LEVEL_PREFIX . $channel] ?? null;
            if ((float) $share > 0.0 && $level !== null) {
                $parts[$channel] = $base * ((float) $level - (float) ($state[FinancialConstants::STATE_INPUT_COST_RECOVERY_PREFIX . $channel] ?? 0.0));
            }
        }
        $parts['earlier'] = $total - array_sum($parts);

        $booked = max(-self::MAX_INPUT_COST_DRAG, min(self::MAX_INPUT_COST_DRAG, $total));
        if ($total !== 0.0 && $booked !== $total) {
            $scale = $booked / $total;
            $parts = array_map(static fn(float $part): float => $part * $scale, $parts);
        }

        return $parts;
    }

    /**
     * The unrecovered input-cost gap (cost level less recovery) the quarter now closing will book, projected from
     * the lag state the last report persisted and today's input prices: what management knows before the close
     * without drawing anything. Null for a model that books no input-cost drag.
     */
    public function projectInputCostGap(Stock $stock, MacroStateDTO $macroState): ?float
    {
        $state = $stock->getEarningsMomentumZ() ?? [];
        if (!isset($state[FinancialConstants::STATE_INPUT_COST_LEVEL])) {
            return null;
        }

        [$costLevel, $recovery] = $this->advanceInputCostState(
            (float) $state[FinancialConstants::STATE_INPUT_COST_LEVEL],
            (float) ($state[FinancialConstants::STATE_INPUT_COST_RECOVERY] ?? 0.0),
            $this->resolveInputCostDeviation($macroState),
            (float) ($state[FinancialConstants::STATE_INPUT_COST_RECOVERED_SHARE]
                ?? max(0.0, min(1.0, $this->resolvePricingPower($stock))) * self::MAX_INPUT_COST_PASS_THROUGH),
            $this->resolveInputCostLagYears($stock),
        );

        return $costLevel - $recovery;
    }

    /**
     * The change in the variable cost ratio the closing quarter's input costs will add over what the last report
     * booked: the projected gap less the booked one, on the cost base the model charges the gap to. Null for a
     * model that books no input-cost drag, or one whose last report has not yet recorded its cost base.
     */
    public function projectInputCostDragChange(Stock $stock, MacroStateDTO $macroState): ?float
    {
        $state = $stock->getEarningsMomentumZ() ?? [];
        $projectedGap = $this->projectInputCostGap($stock, $macroState);
        if ($projectedGap === null || !isset($state[FinancialConstants::STATE_INPUT_COST_DRAG_BASE])) {
            return null;
        }

        $bookedGap = (float) $state[FinancialConstants::STATE_INPUT_COST_LEVEL] - (float) ($state[FinancialConstants::STATE_INPUT_COST_RECOVERY] ?? 0.0);

        return (float) $state[FinancialConstants::STATE_INPUT_COST_DRAG_BASE] * ($projectedGap - $bookedGap);
    }

    /**
     * One quarter of the two input-cost lags: costs follow the input markets with the buying lag, recovery
     * follows the cost level with the repricing lag.
     *
     * @return array{0: float, 1: float} Cost level, recovery.
     */
    private function advanceInputCostState(float $costLevel, float $recovery, float $deviation, float $recoveredShare, float $costLagYears): array
    {
        $dt = FinancialConstants::QUARTER_YEARS;
        $nextLevel = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $costLevel,
            targetValue: $deviation,
            dt: $dt,
            lagTimeConstant: $costLagYears,
        );
        $nextRecovery = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $recovery,
            targetValue: $nextLevel * $recoveredShare,
            dt: $dt,
            lagTimeConstant: $this->getInputPassThroughLagYears(),
        );

        return [$nextLevel, $nextRecovery];
    }

    /**
     * The expected-inflation measure selling prices track, chosen by the model's PRICING_INFLATION_BASIS
     * constant (goods breakevens by default, core services inflation for services models).
     */
    public function resolveExpectedInflationBasis(MacroStateDTO $macroState): float
    {
        $basis = (string) static::PRICING_INFLATION_BASIS;

        return match ($basis) {
            'supercore_inflation_ema'   => $macroState->supercoreInflationEma,
            'inflation_ema'             => $macroState->inflationEma,
            'reimbursement_rate_growth' => $macroState->reimbursementRateGrowth,
            default                     => $macroState->tipsBreakevenEma,
        };
    }

    /**
     * The expected-inflation measure input costs track, chosen by the model's COST_INFLATION_BASIS
     * constant. Falls back to resolveExpectedInflationBasis() if it is null.
     */
    public function resolveCostInflationBasis(MacroStateDTO $macroState): float
    {
        if (static::COST_INFLATION_BASIS === null) {
            return $this->resolveExpectedInflationBasis($macroState);
        }

        $basis = (string) static::COST_INFLATION_BASIS;

        return match ($basis) {
            'supercore_inflation_ema'   => $macroState->supercoreInflationEma,
            'inflation_ema'             => $macroState->inflationEma,
            'reimbursement_rate_growth' => $macroState->reimbursementRateGrowth,
            default                     => $macroState->tipsBreakevenEma,
        };
    }

    /**
     * Pricing power index for this stock: ticker override, then the sector's PRICING_POWER_INDEX (the median 0.5 unless
     * declared).
     */
    protected function resolvePricingPower(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [ModelParam::PricingPowerIndex->value => (float) static::PRICING_POWER_INDEX]);

        return max(0.0, min(1.0, (float) $params[ModelParam::PricingPowerIndex]));
    }

    /**
     * Own-price elasticity of demand: the volume lost per unit of REAL price increase (price growth above
     * expected inflation). Applied by the engine to the pricing-power multiplier so a price setter's hikes
     * cost it some volume and a lagging price taker's discounts win some.
     */
    public function getPriceElasticityOfDemand(): float
    {
        return (float) static::PRICE_ELASTICITY_OF_DEMAND;
    }

    public function getIndustrySubstitutability(): float
    {
        return (float) static::INDUSTRY_SUBSTITUTABILITY;
    }

    /**
     * The cycle's day counts: from the balances a model declares, or else derived from its net intensity. A
     * positive cycle splits into receivables and inventory with a trade-credit offset; a negative one with no
     * declared balances is a payables float with nothing tied up on the asset side.
     *
     * @return array{dso: float, dio: float, dpo: float}
     */
    public function getWorkingCapitalDays(Stock $stock): array
    {
        $shares = $this->resolveWorkingCapitalShares($stock);
        if ($shares !== null) {
            return FirmEconomics::workingCapitalDays($shares, 1.0 - (float) $stock->getOperatingMargin());
        }

        $intensity = $this->getWorkingCapitalIntensity($stock);
        $cycleDays = $intensity * FinancialConstants::DAYS_PER_YEAR;

        if ($intensity < 0.0) {
            // Negative working capital: the firm is funded by its suppliers and customers.
            return ['dso' => 0.0, 'dio' => 0.0, 'dpo' => abs($cycleDays)];
        }

        // CCC = DSO + DIO - DPO, so the gross cycle has to be grossed up for the payables offset it nets against.
        $grossDays = $cycleDays / max(0.01, 1.0 - FinancialConstants::WORKING_CAPITAL_PAYABLE_SHARE);

        return [
            'dso' => $grossDays * FinancialConstants::WORKING_CAPITAL_RECEIVABLE_SHARE,
            'dio' => $grossDays * (1.0 - FinancialConstants::WORKING_CAPITAL_RECEIVABLE_SHARE),
            'dpo' => $grossDays * FinancialConstants::WORKING_CAPITAL_PAYABLE_SHARE,
        ];
    }

    public function getLeaseIntensity(Stock $stock): float
    {
        return (float) static::LEASE_LIABILITY_INTENSITY;
    }

    public function getStockCompensationIntensity(Stock $stock): float
    {
        return (float) static::STOCK_COMPENSATION_INTENSITY;
    }

    public function getFiscalYearStartQuarter(Stock $stock): int
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::FiscalYearStartQuarter->value => 0.0,
        ]);

        return ((int) round($params[ModelParam::FiscalYearStartQuarter]) % 4 + 4) % 4;
    }

    /**
     * The PROBABILITY this management team reaches for accruals to land a reachable quarter on consensus,
     * not a scale factor on the entry it books — an accrual sized to less than the gap prints the miss
     * anyway and still owes the reversal, which is the one thing no reporting incentive would choose.
     * Reporting incentives are a management property, so the sector constant is a starting point and the
     * ticker override (ModelParam::EarningsManagementPropensity) is where a specific board's culture lives.
     */
    public function getEarningsManagementPropensity(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [ModelParam::EarningsManagementPropensity->value => (float) static::EARNINGS_MANAGEMENT_PROPENSITY]);

        return max(0.0, min(1.0, (float) $params[ModelParam::EarningsManagementPropensity]));
    }

    /**
     * Payroll's share of the cost base the fixed-cost wage factor and severance scale. A model's own
     * FIXED_COST_LABOR_SHARE wins; otherwise the labor share measured into its input basket, so wages reach the
     * fixed and variable halves of the cost base at the one share the BEA accounts give the industry.
     */
    public function getLaborCostShare(): float
    {
        if (static::FIXED_COST_LABOR_SHARE !== null) {
            return (float) static::FIXED_COST_LABOR_SHARE;
        }

        return isset(static::INPUT_COST_EXPOSURES['labor'])
            ? (float) static::INPUT_COST_EXPOSURES['labor']
            : static::UNMEASURED_LABOR_SHARE;
    }

    public function getCapExCompletionRate(Stock $stock): float
    {
        return static::CAPEX_COMPLETION_RATE;
    }

    /**
     * Capex reaches an operating firm's earnings through the plant it buys or fails to replace (capacity is net
     * plant times turnover), so the stable margin does not also move with the reinvestment ratio. A model whose
     * spend buys a separate output, such as a biotech's pipeline, reads the ratio here.
     */
    public function recordReinvestmentRatio(Stock $stock, float $reinvestmentRatio, float $dt): void {}

    public function getMarginReversionSpeed(): float
    {
        return static::MARGIN_REVERSION_SPEED;
    }

    public function getVariableCostNoiseShare(): float
    {
        return static::VARIABLE_COST_NOISE_SHARE;
    }

    public function clampMargin(float $rawMargin, float $minMargin = 0.01, float $maxMargin = 1.50): float
    {
        return min($maxMargin, max($minMargin, $rawMargin));
    }

    /**
     * Re-runs the quarter's stream physics once per counterfactual — one macro input at its neutral reading, with the
     * expected revenue that reading implies — on the draws the quarter was actually run on, and reports how far each
     * stream's revenue moved. Every run works on a copy of the firm and a replay of the draws, so neither the firm nor
     * the simulation's random path is touched. See App\Service\Corporate\MacroDriverAttribution.
     *
     * @param Stock                                            $stock           The firm as the stream physics first read it.
     * @param array{uniforms: list<float>, spare: ?float}      $draws           The draws the quarter's stream physics was run on.
     * @param array<string, array{0: MacroStateDTO, 1: float}> $counterfactuals Field => [macro state with it neutral, expected revenue under it].
     * @param array<string, float>                             $actualStreams   The quarter's stream revenue.
     * @return array<string, array<string, float>> Stream => field => actual over counterfactual revenue, less one.
     */
    public function measureStreamMacroEffects(Stock $stock, array $draws, array $counterfactuals, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $actualStreams): array
    {
        $effects = [];

        foreach ($counterfactuals as $field => [$state, $expectedRevenue]) {
            $physics = $this->calculateSectorPhysics(clone $stock, $expectedRevenue, $realizedVariableMargin, $fixedCosts, $baselineVol, $state, MathUtility::replaying($draws));

            foreach ($actualStreams as $stream => $revenue) {
                $counterfactual = (float) ($physics->streamRevenue[$stream] ?? 0.0);
                if ($counterfactual > 0.0) {
                    $effects[$stream][$field] = $revenue / $counterfactual - 1.0;
                }
            }
        }

        return $effects;
    }

    public function measureRevenueWithoutSectorFactor(Stock $stock, array $draws, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState): ?float
    {
        $sector = (string) $stock->getSector();
        if ((float) ($macroState->sectorDemandZ[$sector] ?? 0.0) === 0.0) {
            return null;
        }

        // Zero rather than absent: every loading stays as it was, so only the sector's term leaves each stream's draw.
        $state = MacroStateDTO::fromArray(array_replace($macroState->toArray(), ['sector_demand_z' => [$sector => 0.0] + $macroState->sectorDemandZ]));
        $physics = $this->calculateSectorPhysics(clone $stock, $expectedRevenue, $realizedVariableMargin, $fixedCosts, $baselineVol, $state, MathUtility::replaying($draws));

        return $physics->actualRevenue;
    }

    public function computeActualFinancials(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility, ?int $calendarQuarter = null): ActualFinancialsDTO
    {
        $this->reportedCalendarQuarter = $calendarQuarter;
        $physics = $this->calculateSectorPhysics($stock, $expectedRevenue, $realizedVariableMargin, $fixedCosts, $baselineVol, $macroState, $mathUtility);

        return $this->assembleActualFinancials($physics, $fixedCosts);
    }

    /**
     * The quarter as the public expects it: the physics re-run from the same opening state over scenarios of the
     * firm's private draws (its streams, events and regime onsets; the sector's latent demand factor out), averaged.
     * What published data and the last report disclose, the macro terms, benchmark prices, the backlog and
     * subscriber books, announced regimes and the input-cost drag, is the same in every scenario. The scenarios come
     * in antithetic pairs drawn from the firm's own seeded stream, so the simulation's draws are untouched and a term
     * linear in the draws averages out exactly.
     */
    public function measurePublicFinancials(Stock $stock, float $expectedRevenue, float $expectedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, ?int $calendarQuarter = null, int $seed = 0): ActualFinancialsDTO
    {
        $sector = (string) $stock->getSector();
        $state = isset($macroState->sectorDemandZ[$sector])
            ? MacroStateDTO::fromArray(array_replace($macroState->toArray(), ['sector_demand_z' => [$sector => 0.0] + $macroState->sectorDemandZ]))
            : $macroState;
        $this->reportedCalendarQuarter = $calendarQuarter;

        $sums = ['revenue' => 0.0, 'price' => 0.0, 'costs' => 0.0, 'provision' => 0.0, 'chargeOffs' => 0.0];
        $runs = 0;
        for ($pair = 0; $pair < self::PUBLIC_QUARTER_SCENARIO_PAIRS; ++$pair) {
            $draws = MathUtility::ownStream($seed + $pair);
            $draws->beginDrawLog();
            foreach ([$draws, null] as $source) {
                $physics = $this->calculateSectorPhysics(clone $stock, $expectedRevenue, $expectedVariableMargin, $fixedCosts, $baselineVol, $state, $source ?? MathUtility::mirroring($draws->endDrawLog(), $seed + self::PUBLIC_QUARTER_SCENARIO_PAIRS + $pair));
                $scenario = $this->assembleActualFinancials($physics, $fixedCosts);
                $sums['revenue'] += $scenario->actualRevenue;
                $sums['price'] += $scenario->priceRevenue;
                $sums['costs'] += $scenario->actualVariableCosts;
                $sums['provision'] += $scenario->creditLossProvision;
                $sums['chargeOffs'] += $scenario->netChargeOffs;
                ++$runs;
            }
        }

        $revenue = $sums['revenue'] / $runs;
        $volumeRevenue = max(1.0, ($sums['revenue'] - $sums['price']) / $runs);

        return new ActualFinancialsDTO(
            actualRevenue: $revenue,
            actualVariableCosts: $sums['costs'] / $runs,
            clampedMargin: ($sums['costs'] / $runs) / $volumeRevenue,
            ebit: $revenue - $fixedCosts - ($sums['costs'] / $runs),
            creditLossProvision: $sums['provision'] / $runs,
            netChargeOffs: $sums['chargeOffs'] / $runs,
            priceRevenue: $sums['price'] / $runs,
        );
    }

    /** The template method's books on one run of the sector physics. */
    private function assembleActualFinancials(SectorPhysicsResult $physics, float $fixedCosts): ActualFinancialsDTO
    {
        $clampedMargin = $this->clampMargin($physics->rawVariableMargin);
        // Price is not produced: a rent escalator or a spot-rate spike on a fixed fleet adds revenue without
        // adding a unit of cost, so the variable cost ratio applies to the volume part of revenue only. A price
        // below the expected level saves none either: the volume still costs what it did.
        $priceRevenue = min($physics->actualRevenue, $physics->priceRevenue);
        $actualVariableCosts = ($physics->actualRevenue - $priceRevenue) * $clampedMargin;
        $ebit = $physics->actualRevenue - $fixedCosts - $actualVariableCosts;

        return new ActualFinancialsDTO(
            actualRevenue: $physics->actualRevenue,
            actualVariableCosts: $actualVariableCosts,
            clampedMargin: $clampedMargin,
            ebit: $ebit,
            eventType: $physics->eventType,
            eventContext: $physics->eventContext,
            isPublicEvent: $physics->isPublicEvent,
            streamZ: $physics->streamZ,
            streamRevenue: $physics->streamRevenue,
            scheduledCapex: $physics->scheduledCapex,
            kpis: $physics->kpis,
            creditLossProvision: $physics->creditLossProvision,
            netChargeOffs: $physics->netChargeOffs,
            netInterestSqueeze: $physics->netInterestSqueeze,
            priceRevenue: $priceRevenue,
            plantImpairment: $physics->plantImpairment,
            specialCharge: max(0.0, $physics->specialCharge),
        );
    }

    abstract protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult;

    public function calculateEconomicReturn(Stock $stock, float $quarterlyNopatOrIncome, float $investedCapital): float
    {
        return $investedCapital > 0 ? ($quarterlyNopatOrIncome / $investedCapital) * 4.0 : 0.0;
    }

    public function getEffectiveReturn(Stock $stock): float
    {
        // Cast before the test: a stored zero reads "0" or "0.0000", and only the first is falsy as a string.
        return (float) $stock->getCurrentRoic() ?: (float) $stock->getBaselineRoic();
    }

    public function getBaselineReturn(Stock $stock): float
    {
        return (float) $stock->getBaselineRoic();
    }

    public function getTrueReturn(Stock $stock): float
    {
        return (float) $stock->getRoicTtm();
    }

    /** The return is NOPAT on invested capital and only a profit is taxed, so only a profit is grossed back up. */
    public function getNormalEbitda(Stock $stock, float $macroTaxRate, float $depreciation): ?float
    {
        $longRunReturn = $stock->getLongRunReturn();
        if ($longRunReturn === null) {
            return null;
        }
        $nopat = (float) $longRunReturn * $stock->getInvestedCapital();

        return ($nopat > 0.0 ? $nopat / (1.0 - $this->getEffectiveTaxRate($macroTaxRate)) : $nopat) + $depreciation;
    }

    public function getEvaluationCapital(float $equity, float $investedCapital): float
    {
        return $investedCapital;
    }

    public function getReversionSpeed(): float
    {
        return static::ROIC_REVERSION_SPEED;
    }

    public function getMoatSpread(): float
    {
        return static::MOAT_SPREAD;
    }

    public function getPhysicalCapital(Stock $stock): float
    {
        return $stock->getInvestedCapital();
    }

    /**
     * Depreciation runs on net PP&E. Before the ledger is seeded the engine falls back to the capital
     * proxy so a firm that has never reported still books a depreciation charge on its first quarter.
     */
    public function getDepreciableBase(Stock $stock): float
    {
        $netPpe = $stock->getNetPpe();

        return $netPpe > 0.0 ? $netPpe : $this->getPhysicalCapital($stock);
    }

    /** A plant wears out on a schedule, so it is depreciated by time whatever the quarter's output. */
    public function depreciatesByUnitsOfProduction(): bool
    {
        return false;
    }

    /** Every segment holds its share of its own industry's market. */
    public function resolveSegmentPath(Stock $stock, string $segment): ?array
    {
        return null;
    }

    /** Every line is made on plant of its own. */
    public function jointProductLines(): array
    {
        return [];
    }

    /** An operating company reports net income as the books strike it. */
    public function reportsFundsFromOperations(): bool
    {
        return false;
    }

    /**
     * Invested capital less any marketable investments, which carry their own asset line and no depreciation. The
     * treasury is already outside it, so the stakes are the only deduction.
     */
    public function getPlantCapital(Stock $stock, float $investedCapital): float
    {
        return max(0.0, $investedCapital - max(0.0, (float) ($stock->getListedStakesCarrying() ?? 0.0)));
    }

    public function getOpeningInvestmentAssets(Stock $stock): ?float
    {
        return 0.0;
    }

    public function allowsPhysicalOrganicCapex(): bool
    {
        return true;
    }
}
