<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Data\ModelParam;
use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\SectorCoverageProfile;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;

trait StandardOperatingPhysicsTrait
{
    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.02; // DEFAULT_SECULAR_GROWTH_RATE
    }

    public function getCapexCyclicality(): float
    {
        return 1.5; // DEFAULT_CAPEX_CYCLICALITY
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.50, 'revenue_weight' => 0.50];
    }

    /**
     * Returns quarterly revenue seasonality multipliers [Q1, Q2, Q3, Q4] summing strictly to 4.0.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [1.0, 1.0, 1.0, 1.0];
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
    }

    public function getCoverageProfile(Stock $stock): SectorCoverageProfile
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::BaseVisibility->value => defined('static::BASE_COVERAGE_VISIBILITY') ? static::BASE_COVERAGE_VISIBILITY : 0.20,
            ModelParam::CoverageError->value  => defined('static::BASE_COVERAGE_ERROR') ? static::BASE_COVERAGE_ERROR : 0.06,
            ModelParam::MinVisibility->value  => defined('static::BASE_COVERAGE_MIN_VISIBILITY') ? static::BASE_COVERAGE_MIN_VISIBILITY : 0.0,
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

        return new SectorCoverageProfile(
            baseVisibility: min(1.0, $visibility),
            errorStdDev: $error,
            minVisibility: min(1.0, $minVis)
        );
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return 0.10;
    }

    /** An operating company's assets are plant and a trade cycle, not credit: nothing to charge off. */
    public function getThroughTheCycleCreditLossRate(): float
    {
        return 0.0;
    }

    public function getCreditLossHorizonYears(): float
    {
        return 1.0;
    }

    // --- Input Cost Basket ---
    /** Share of an input price shock a firm with full pricing power recovers in its own prices; the rest lands on margin (incomplete pass-through, Gopinath & Itskhoki 2010). */
    public const MAX_INPUT_COST_PASS_THROUGH = 0.80;
    /** Persisted state key: lagged relative input cost level of the basket (fraction above baseline). */
    public const STATE_INPUT_COST_LEVEL = 'state:input_cost_level';
    /** Persisted state key: lagged share of the input cost level already recovered in selling prices. */
    public const STATE_INPUT_COST_RECOVERY = 'state:input_cost_recovery';
    /** Bound on the signed cost-ratio drag the basket may produce in one quarter. */
    public const MAX_INPUT_COST_DRAG = 0.50;

    /**
     * Shares of the VARIABLE cost base bought in each tracked input market. Channels: energy, metals, agri,
     * freight, ppi (wholesale intermediate goods) and labor (variable payroll). Shares need not sum to one;
     * the remainder is bought at prices no macro index tracks. Sector models declare INPUT_COST_EXPOSURES.
     *
     * @return array<string, float>
     */
    public function getInputCostExposures(): array
    {
        return defined('static::INPUT_COST_EXPOSURES')
            ? (array) static::INPUT_COST_EXPOSURES
            : FinancialConstants::DEFAULT_INPUT_COST_EXPOSURES;
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
        return defined('static::DEMAND_LAG_YEARS')
            ? (float) static::DEMAND_LAG_YEARS
            : FinancialConstants::DEFAULT_DEMAND_LAG_YEARS;
    }

    /**
     * The output gap as it has actually reached this firm, distributed over its transmission lag.
     *
     * The lag state persists on the stock, so a firm carries its own position in the cycle rather than
     * re-deriving it: mid-downturn a long-lag builder is still working through a boom-era book while a
     * spot seller is already in the slump. At zero lag the helper returns the macro series untouched.
     */
    public function resolveLaggedOutputGap(Stock $stock, MacroStateDTO $macroState): float
    {
        $lagYears = $this->getDemandLagYears();
        if ($lagYears <= 0.0) {
            return $macroState->outputGapEma;
        }

        $lagged = MathUtility::getInstance()->calculateDistributedLag(
            currentLaggedValue: $stock->getLaggedDemandGap() ?? $macroState->outputGapEma,
            targetValue: $macroState->outputGapEma,
            dt: \App\Service\Corporate\EarningsEngine::QUARTERLY_TIME_STEP,
            lagTimeConstant: $lagYears
        );
        $stock->setLaggedDemandGap($lagged);

        return $lagged;
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
        return defined('static::FX_REVENUE_EXPOSURE')
            ? (float) static::FX_REVENUE_EXPOSURE
            : FinancialConstants::DEFAULT_FX_REVENUE_EXPOSURE;
    }

    /**
     * Signed demand shift from the exchange rate. A stronger domestic currency (index above base) prices
     * exports out of foreign markets and cheapens the importer's shelf price at home, so the shift is
     * negative on the way up and positive on the way down, scaled by how much revenue is actually exposed.
     */
    public function resolveFxDemandShift(MacroStateDTO $macroState, ?float $exposure = null): float
    {
        $fxShift = ($macroState->exchangeRateIndexEma - FinancialConstants::FX_INDEX_BASE) / FinancialConstants::FX_INDEX_BASE;

        return -$fxShift * ($exposure ?? $this->getFxRevenueExposure());
    }

    /** Years for spot input moves to reach the cost base (0 = spot buyer; forward hedges and supply contracts lengthen it). */
    public function getInputCostLagYears(): float
    {
        return defined('static::INPUT_COST_LAG_YEARS') ? (float) static::INPUT_COST_LAG_YEARS : 0.0;
    }

    /** Years for the recoverable part of an input move to reach selling prices (menu costs, contract repricing, fuel surcharges). */
    public function getInputPassThroughLagYears(): float
    {
        return defined('static::INPUT_PASS_THROUGH_LAG_YEARS')
            ? (float) static::INPUT_PASS_THROUGH_LAG_YEARS
            : FinancialConstants::DEFAULT_INPUT_PASS_THROUGH_LAG_YEARS;
    }

    /**
     * Signed relative price deviation of each tracked input market from its baseline this quarter.
     *
     * @return array<string, float>
     */
    public function resolveInputPriceDeviations(MacroStateDTO $macroState): array
    {
        return [
            'energy'  => $macroState->energyCostPushLag / \App\Service\Macro\MacroEngine::ENERGY_COST_PUSH_TRANSMISSION,
            'metals'  => ($macroState->industrialMetalsIndexEma - 100.0) / 100.0,
            'agri'    => ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0,
            'freight' => ($macroState->freightRateIndexEma - 100.0) / 100.0,
            'ppi'     => $macroState->producerPriceInflationEma - \App\Service\Macro\MacroEngine::TARGET_INFLATION,
            'labor'   => $macroState->wageGrowthEma - (\App\Service\Macro\MacroEngine::TFP_DRIFT + \App\Service\Macro\MacroEngine::TARGET_INFLATION),
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
        $deviation = $this->resolveInputCostDeviation($macroState);
        $dt = \App\Service\Corporate\EarningsEngine::QUARTERLY_TIME_STEP;
        $math = MathUtility::getInstance();

        // A firm with no cost history starts at baseline prices, so a shock already in the market reaches it
        // through the lags like any other.
        $costLevel = $math->calculateDistributedLag(
            currentLaggedValue: $streams->getPersistedState(self::STATE_INPUT_COST_LEVEL, 0.0),
            targetValue: $deviation,
            dt: $dt,
            lagTimeConstant: $this->getInputCostLagYears()
        );
        $recoveredShare = max(0.0, min(1.0, $pricingPower)) * self::MAX_INPUT_COST_PASS_THROUGH;
        $recovery = $math->calculateDistributedLag(
            currentLaggedValue: $streams->getPersistedState(self::STATE_INPUT_COST_RECOVERY, 0.0),
            targetValue: $costLevel * $recoveredShare,
            dt: $dt,
            lagTimeConstant: $this->getInputPassThroughLagYears()
        );

        $streams->registerState(self::STATE_INPUT_COST_LEVEL, $costLevel);
        $streams->registerState(self::STATE_INPUT_COST_RECOVERY, $recovery);

        $drag = max(0.01, $realizedVariableMargin) * ($costLevel - $recovery);

        return max(-self::MAX_INPUT_COST_DRAG, min(self::MAX_INPUT_COST_DRAG, $drag));
    }

    /**
     * The expected-inflation measure selling prices track, chosen by the model's PRICING_INFLATION_BASIS
     * constant (goods breakevens by default, core services inflation for services models).
     */
    public function resolveExpectedInflationBasis(MacroStateDTO $macroState): float
    {
        $basis = defined('static::PRICING_INFLATION_BASIS') ? (string) static::PRICING_INFLATION_BASIS : 'tips_breakeven_ema';

        return match ($basis) {
            'supercore_inflation_ema' => $macroState->supercoreInflationEma,
            'inflation_ema'           => $macroState->inflationEma,
            default                   => $macroState->tipsBreakevenEma,
        };
    }

    /** Pricing power index for this stock: ticker override, then the sector's PRICING_POWER_INDEX, then the median 0.5. */
    protected function resolvePricingPower(Stock $stock): float
    {
        $default = defined('static::PRICING_POWER_INDEX') ? (float) static::PRICING_POWER_INDEX : 0.5;
        $params = $this->resolveModelParameters($stock, [ModelParam::PricingPowerIndex->value => $default]);

        return max(0.0, min(1.0, (float) $params[ModelParam::PricingPowerIndex]));
    }

    /**
     * Own-price elasticity of demand: the volume lost per unit of REAL price increase (price growth above
     * expected inflation). Applied by the engine to the pricing-power multiplier so a price setter's hikes
     * cost it some volume and a lagging price taker's discounts win some.
     */
    public function getPriceElasticityOfDemand(): float
    {
        return defined('static::PRICE_ELASTICITY_OF_DEMAND')
            ? (float) static::PRICE_ELASTICITY_OF_DEMAND
            : FinancialConstants::DEFAULT_PRICE_ELASTICITY_OF_DEMAND;
    }

    public function getIndustrySubstitutability(): float
    {
        return defined('static::INDUSTRY_SUBSTITUTABILITY')
            ? (float) static::INDUSTRY_SUBSTITUTABILITY
            : FinancialConstants::DEFAULT_INDUSTRY_SUBSTITUTABILITY;
    }

    /**
     * Derives the cycle's day counts from the intensity a sector model already declares, so none of the
     * existing overrides have to change. A positive cycle splits into receivables and inventory with a
     * trade-credit offset; a negative one (subscriptions, marketplaces collecting before they pay
     * suppliers) is a payables float with almost nothing tied up on the asset side.
     *
     * @return array{dso: float, dio: float, dpo: float}
     */
    public function getWorkingCapitalDays(Stock $stock): array
    {
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

    public function getLeaseIntensity(): float
    {
        return defined('static::LEASE_LIABILITY_INTENSITY')
            ? (float) static::LEASE_LIABILITY_INTENSITY
            : FinancialConstants::DEFAULT_LEASE_LIABILITY_INTENSITY;
    }

    public function getStockCompensationIntensity(): float
    {
        return defined('static::STOCK_COMPENSATION_INTENSITY')
            ? (float) static::STOCK_COMPENSATION_INTENSITY
            : FinancialConstants::DEFAULT_STOCK_COMPENSATION_INTENSITY;
    }

    public function getFiscalYearStartQuarter(Stock $stock): int
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::FiscalYearStartQuarter->value => 0.0,
        ]);

        return ((int) round($params[ModelParam::FiscalYearStartQuarter]) % 4 + 4) % 4;
    }

    /**
     * How hard this management team leans on accruals to land the quarter on consensus. Reporting
     * incentives are a management property, so the sector constant is a starting point and the ticker
     * override (ModelParam::EarningsManagementPropensity) is where a specific board's culture lives.
     */
    public function getEarningsManagementPropensity(Stock $stock): float
    {
        $default = defined('static::EARNINGS_MANAGEMENT_PROPENSITY')
            ? (float) static::EARNINGS_MANAGEMENT_PROPENSITY
            : FinancialConstants::DEFAULT_EARNINGS_MANAGEMENT_PROPENSITY;
        $params = $this->resolveModelParameters($stock, [ModelParam::EarningsManagementPropensity->value => $default]);

        return max(0.0, min(1.0, (float) $params[ModelParam::EarningsManagementPropensity]));
    }

    public function getLaborCostShare(): float
    {
        return defined('static::FIXED_COST_LABOR_SHARE')
            ? (float) static::FIXED_COST_LABOR_SHARE
            : FinancialConstants::DEFAULT_FIXED_COST_LABOR_SHARE;
    }

    public function getCapExCompletionRate(Stock $stock): float
    {
        return 0.33;
    }

    /**
     * Asset reinvestment physics shared by every sector model. Under-investment below replacement CapEx
     * (reinvestmentRatio < 1) decays operating margin toward the sector floor; over-investment compounds
     * logarithmically toward the sector ceiling (diminishing returns to modernization). Sector models tune
     * the four hooks below, or simply define the canonical constants DEPRECIATION_DECAY_RATE,
     * MODERNIZATION_GAIN_RATE, MIN_OPERATING_MARGIN_FLOOR and MAX_OPERATING_MARGIN_CEILING. Models with no
     * decay physics at all (financial balance sheets) are a no-op.
     */
    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $decayRate = $this->getDepreciationDecayRate();
        $gainRate  = $this->getModernizationGainRate();
        if ($decayRate <= 0.0 && $gainRate <= 0.0) {
            return;
        }

        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            $decay = $decayRate * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max($this->getMinOperatingMarginFloor(), $currentMargin - ($currentMargin * $decay));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            $ceiling = $this->getMaxOperatingMarginCeiling($stock);
            if ($currentMargin >= $ceiling) {
                return; // Already at or above the structural ceiling: modernization cannot add margin.
            }
            $modGain = $gainRate * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min($ceiling, $currentMargin + (($ceiling - $currentMargin) * $modGain));
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }

    /** Quarterly operating margin decay rate per unit of under-investment below replacement CapEx. */
    public function getDepreciationDecayRate(): float
    {
        return defined('static::DEPRECIATION_DECAY_RATE') ? (float) static::DEPRECIATION_DECAY_RATE : 0.0;
    }

    /** Quarterly margin gain scalar per unit of logarithmic over-investment above replacement CapEx. */
    public function getModernizationGainRate(): float
    {
        return defined('static::MODERNIZATION_GAIN_RATE') ? (float) static::MODERNIZATION_GAIN_RATE : 0.0;
    }

    /** Structural operating margin floor reached under sustained under-investment. */
    public function getMinOperatingMarginFloor(): float
    {
        return defined('static::MIN_OPERATING_MARGIN_FLOOR') ? (float) static::MIN_OPERATING_MARGIN_FLOOR : 0.01;
    }

    /** Structural operating margin ceiling that modernization converges toward. */
    public function getMaxOperatingMarginCeiling(Stock $stock): float
    {
        return defined('static::MAX_OPERATING_MARGIN_CEILING') ? (float) static::MAX_OPERATING_MARGIN_CEILING : 1.0;
    }

    public function getMarginReversionSpeed(): float
    {
        return 4.0; // DEFAULT_MARGIN_REVERSION_SPEED
    }

    public function clampMargin(float $rawMargin, float $minMargin = 0.01, float $maxMargin = 1.50): float
    {
        return min($maxMargin, max($minMargin, $rawMargin));
    }

    public function computeActualFinancials(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): ActualFinancialsDTO
    {
        $physics = $this->calculateSectorPhysics($stock, $expectedRevenue, $realizedVariableMargin, $fixedCosts, $baselineVol, $macroState, $mathUtility);

        $clampedMargin = $this->clampMargin($physics->rawVariableMargin);
        // Price is not produced: a rent escalator or a spot-rate spike on a fixed fleet adds revenue without
        // adding a unit of cost, so the variable cost ratio applies to the volume part of revenue only.
        $priceRevenue = max(0.0, min($physics->actualRevenue, $physics->priceRevenue));
        $actualVariableCosts = ($physics->actualRevenue - $priceRevenue) * $clampedMargin;
        $ebit = $physics->actualRevenue - $fixedCosts - $actualVariableCosts;

        return new ActualFinancialsDTO(
            actualRevenue: $physics->actualRevenue,
            actualVariableCosts: $actualVariableCosts,
            clampedMargin: $clampedMargin,
            ebit: $ebit,
            primaryShockZ: $physics->primaryShockZ,
            observableShockZ: $physics->observableShockZ,
            eventType: $physics->eventType,
            eventContext: $physics->eventContext,
            isPublicEvent: $physics->isPublicEvent,
            streamZ: $physics->streamZ,
            streamRevenue: $physics->streamRevenue,
            scheduledCapex: $physics->scheduledCapex,
            kpis: $physics->kpis,
            creditLossProvision: $physics->creditLossProvision,
            netChargeOffs: $physics->netChargeOffs,
            priceRevenue: $priceRevenue,
        );
    }

    abstract protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult;

    public function calculateEconomicReturn(Stock $stock, float $quarterlyNopatOrIncome, float $investedCapital): float
    {
        return $investedCapital > 0 ? ($quarterlyNopatOrIncome / $investedCapital) * 4.0 : 0.0;
    }

    public function getEffectiveReturn(Stock $stock): float
    {
        return (float) ($stock->getCurrentRoic() ?: $stock->getBaselineRoic());
    }

    public function getTrueReturn(Stock $stock): float
    {
        return (float) $stock->getRoicTtm();
    }

    public function getEvaluationCapital(float $equity, float $investedCapital): float
    {
        return $investedCapital;
    }

    public function getReversionSpeed(): float
    {
        return 0.20;
    }

    public function getMoatSpread(): float
    {
        return 0.000;
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

    public function allowsPhysicalOrganicCapex(): bool
    {
        return true;
    }

    public function getReturnBasisIncome(Stock $stock, float $quarterlyNopat, float $actualTotalNetIncome): float
    {
        return $quarterlyNopat;
    }
}
