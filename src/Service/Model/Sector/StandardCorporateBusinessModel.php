<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;

use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\AbstractBusinessModel;

/**
 * Earnings strategy for normal, non-financial companies.
 *
 * Financial Physics:
 * - Evaluated on Return on Invested Capital (ROIC).
 * - Subject to supply chain inflation and physical depreciation.
 * - Operating scale is based on physical assets, not financial leverage.
 */
class StandardCorporateBusinessModel extends AbstractBusinessModel
{
    // --- ROIC & Target Metrics ---
    /** Weight given to historical baseline ROIC when blending with TTM ROIC. */
    public const BASELINE_ROIC_WEIGHT = 0.50;
    /** Weight given to TTM ROIC when blending with historical baseline ROIC. */
    public const TTM_ROIC_WEIGHT      = 0.50;

    // --- Pricing Power & Macro Physics ---
    /**
     * Base of the demand's sensitivity to the lagged gap before the firm's pricing power is added: (0.50 + pricing
     * power) x cyclicality, so the median firm (pricing power 0.5) answers the gap one for one.
     */
    public const CYCLE_SENSITIVITY_BASE = 0.50;

    // --- Inflation Pass-Through (Gopinath & Itskhoki 2010) ---
    /**
     * Pass-through elasticity of a pure price taker; the pricing power index adds to it, so the median firm recovers
     * expected inflation exactly and a price setter one and a half times over.
     */
    public const PASS_THROUGH_BASE_ELASTICITY = 0.50;
    /**
     * Characteristic time in years for expected inflation to reach selling prices through menu costs and contract
     * repricing (Nakamura & Steinsson 2008 price durations). Sector models override for annual tariffs or multi-year
     * contracts.
     */
    public const PRICE_PASS_THROUGH_LAG_YEARS = 0.75;
    /**
     * Elasticity of selling prices to expected inflation for a model that prices off a contractual or regulated
     * formula; null derives it from pricing power (the base elasticity plus the pricing power index).
     *
     * @var float|null
     */
    public const PRICING_ELASTICITY = null;

    // --- Revenue & Shock Physics ---
    /** Variance scalar applied to baseline volatility for sales volume shocks. */
    public const REVENUE_VARIANCE_SCALAR = 0.15;

    /**
     * Physical businesses evaluate their true structural scale based on Invested Capital
     * (Total Equity + Debt - Cash), requiring physical assets to turn a profit.
     */
    public function getTargetMetrics(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());

        $ttmRoic = (float) $stock->getRoicTtm();
        if ($ttmRoic !== 0.0) {
            $baselineRoic = ($baselineRoic * self::BASELINE_ROIC_WEIGHT) + ($ttmRoic * self::TTM_ROIC_WEIGHT);
        }

        // Marginal ROIC on next dollar of capital: structural return minus Penrose scale diseconomy.
        $saturationPenalty = \App\Service\Corporate\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, $stock->getInvestedCapital(), $macroState);
        $effectiveRoic = max(0.0, $baselineRoic - $saturationPenalty);

        return [
            'invested_capital' => $stock->getInvestedCapital(),
            'baseline_roic' => $effectiveRoic,
        ];
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        return [
            'macro_demand_shift' => $this->resolveCycleDemandShift($stock, $macroState),
            ...$this->resolvePricingMultipliers($stock, $macroState),
        ];
    }

    /**
     * The firm's demand shift from the lagged output gap and the exchange rate. Pure: unlike getMacroPhysics() it
     * steps no pass-through state, so a sector model may read it again inside its own physics.
     */
    protected function resolveCycleDemandShift(Stock $stock, \App\DTO\MacroStateDTO $macroState): float
    {
        $macroSensitivityMultiplier = self::CYCLE_SENSITIVITY_BASE + $this->resolvePricingPower($stock);

        $outputGap = $this->resolveLaggedOutputGap($macroState);
        $beta = $this->getOperatingCyclicality($stock);

        return ($outputGap * $macroSensitivityMultiplier * $beta) + $this->resolveFxDemandShift($macroState);
    }

    /**
     * Elasticity of the firm's selling prices to expected inflation. Defaults to the pricing-power form
     * (a price taker recovers half, the median firm all, a price setter one and a half times); sector models
     * that price off a contractual or regulated formula declare PRICING_ELASTICITY directly.
     */
    protected function resolvePricingElasticity(Stock $stock): float
    {
        if (static::PRICING_ELASTICITY !== null) {
            return (float) static::PRICING_ELASTICITY;
        }

        return self::PASS_THROUGH_BASE_ELASTICITY + $this->resolvePricingPower($stock);
    }

    /**
     * The selling-price and input-cost multipliers the engine applies to revenue capacity and the cost base.
     * Both track the same inflation measure with the same repricing lag; only the elasticity differs, so the
     * gap between them is the firm's real pricing. Called exactly once per quarter (the lag state persists on
     * the stock), so sector models compose it rather than recomputing pass-through themselves.
     *
     * @return array{pricing_power_multiplier: float, input_cost_multiplier: float}
     */
    public function resolvePricingMultipliers(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $expectedInflation = $this->resolveExpectedInflationBasis($macroState);
        $costInflation = $this->resolveCostInflationBasis($macroState);
        $elasticity = $this->resolvePricingElasticity($stock);

        $passThrough = $this->resolveInflationPassThrough($stock, $macroState, $elasticity, $expectedInflation, (float) static::PRICE_PASS_THROUGH_LAG_YEARS);

        return [
            'pricing_power_multiplier' => 1.0 + $passThrough,
            // Input prices move with expected inflation at unit elasticity and the same repricing lag, whatever the
            // firm itself manages to charge: the gap between the two multipliers is the firm's real pricing. If the
            // model sets an administered pricing basis distinct from its cost basis, input costs inflate at their own
            // basis.
            'input_cost_multiplier' => 1.0 + ($costInflation !== $expectedInflation ? $costInflation : ($elasticity > 0.0 ? $passThrough / $elasticity : $expectedInflation)),
        ];
    }

    /**
     * Incomplete and lagged pass-through of expected inflation into selling prices (Gopinath & Itskhoki 2010).
     *
     * The share of inflation a firm recovers in price is its pricing power, not its beta. Beta measures
     * systematic risk and already scales the demand shift above. The elasticity is centred so the median firm
     * (pricing power 0.5) has unit elasticity, mirroring the 0.5 + p form the demand multiplier uses.
     *
     * Pass-through is then distributed over time rather than landing whole in the quarter expectations move:
     * menu costs and contract repricing mean posted prices reach the new level over several quarters. The
     * lag state is persisted on the stock, so a firm carries its own repricing history.
     */
    protected function resolveInflationPassThrough(Stock $stock, \App\DTO\MacroStateDTO $macroState, float $elasticity, ?float $expectedInflation = null, ?float $lagYears = null): float
    {
        $targetPassThrough = ($expectedInflation ?? $macroState->tipsBreakevenEma) * $elasticity;

        $laggedPassThrough = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $stock->getInflationPassThrough() ?? $targetPassThrough,
            targetValue: $targetPassThrough,
            dt: FinancialConstants::QUARTER_YEARS,
            lagTimeConstant: $lagYears ?? (float) static::PRICE_PASS_THROUGH_LAG_YEARS,
        );

        $stock->setInflationPassThrough($laggedPassThrough);

        return $laggedPassThrough;
    }

    /**
     * Idiosyncratic variance is applied directly to sales volume.
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $pricingPower = $this->resolvePricingPower($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $revenueZ = $streams->generateZ('revenue', 0.25);

        $revenueShock = ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR));
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);

        // Input cost basket: energy, metals, agricultural, freight, wholesale-goods and wage prices reach the
        // variable cost base with the buying lag and are recovered in selling prices with the repricing lag.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inputCostDrag);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'core_business' => $actualRevenue,
            ],
        );
    }

    /**
     * Normal physical companies are evaluated on NOPAT / Invested Capital (ROIC).
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?\App\DTO\MacroStateDTO $macroState = null, float $depreciation = 0.0, float $afterTaxSpecialCharges = 0.0): float
    {
        $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;

        $effectiveCapital = max(1.0, abs($investedCapital));
        $truePostTaxReturn = ($nopatProxy / $effectiveCapital) * FinancialConstants::QUARTERS_PER_YEAR;

        $stock->setCurrentRoic((string) max(FinancialConstants::MIN_REPORTED_RETURN, min(FinancialConstants::MAX_REPORTED_RETURN, $truePostTaxReturn)));
        $stock->setRoicTtm((string) $this->revertTrailingReturn($stock, (float) $stock->getRoicTtm(), $truePostTaxReturn, $wacc, self::TTM_ROIC_WEIGHT, abs($investedCapital), $macroState));

        return $truePostTaxReturn;
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
            'industrial_metals_index_ema',
            'output_gap_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
