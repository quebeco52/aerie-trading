<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Model\Trait\StandardBaseModelTrait;
use App\Service\Model\Trait\StandardCapitalAllocationTrait;
use App\Service\Model\Trait\StandardDebtPhysicsTrait;
use App\Service\Model\Trait\StandardMaTrait;
use App\Service\Model\Trait\StandardOperatingPhysicsTrait;
use App\Service\Model\Trait\StandardTreasuryTrait;
use App\Service\Model\Trait\StandardValuationTrait;

/**
 * Earnings strategy for normal, non-financial companies.
 * 
 * Financial Physics:
 * - Evaluated on Return on Invested Capital (ROIC).
 * - Subject to supply chain inflation and physical depreciation.
 * - Operating scale is based on physical assets, not financial leverage.
 */
class StandardCorporateBusinessModel implements BusinessModelInterface
{
    use StandardBaseModelTrait;
    use StandardOperatingPhysicsTrait;
    use StandardTreasuryTrait;
    use StandardCapitalAllocationTrait;
    use StandardDebtPhysicsTrait;
    use StandardMaTrait;
    use StandardValuationTrait;

    // --- ROIC & Target Metrics ---
    /** Weight given to historical baseline ROIC when blending with TTM ROIC. */
    public const BASELINE_ROIC_WEIGHT = 0.50;
    /** Weight given to TTM ROIC when blending with historical baseline ROIC. */
    public const TTM_ROIC_WEIGHT      = 0.50;

    // --- Firm-Level Common Factor ---
    /** One-factor loading of each revenue stream on the firm-wide demand innovation (rho^2 = 36% shared variance). */
    public const FIRM_FACTOR_LOADING = 0.60;

    // --- Pricing Power & Macro Physics ---
    /** Minimum beta floor applied when calculating pricing power resistance to inflation (retained for sector models that key pass-through off beta). */
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.50;

    // --- Inflation Pass-Through (Gopinath & Itskhoki 2010) ---
    /** Pass-through elasticity of a pure price taker; the pricing power index adds to it, so the median firm recovers expected inflation exactly and a price setter one and a half times over. */
    public const PASS_THROUGH_BASE_ELASTICITY = 0.50;
    /** Characteristic time in years for expected inflation to reach selling prices through menu costs and contract repricing (Nakamura & Steinsson 2008 price durations). */
    public const PRICE_PASS_THROUGH_LAG_YEARS = 0.75;

    // --- Revenue & Shock Physics ---
    /** Variance scalar applied to baseline volatility for sales volume shocks. */
    public const REVENUE_VARIANCE_SCALAR = 0.15;
    /** Sensitivity scalar for supply chain inflation cost penalties during high CPI/PPI regimes. */
    public const INFLATION_PENALTY_SCALAR = 0.50;
    /** Sensitivity of corporate variable costs to Producer Price Inflation (PPI). */
    public const PPI_COST_SENSITIVITY = 0.25;
    /** Upper clamp for realized variable margin under severe supply chain inflation. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    public const BASE_COVERAGE_ERROR = 0.06;

    // --- DCF & Valuation Rails ---
    /** Assumed perpetual terminal growth rate for DCF fair value estimation. */
    public const DCF_TERMINAL_GROWTH_RATE = 0.02;
    /** Cap on DCF valuation relative to P/E fair value to prevent infinite perpetual expansion. */
    public const MAX_DCF_TO_PE_CAP_MULT   = 1.50;
    /** Valuation discount applied when FCF is negative due to heavy capex or burn. */
    public const NEGATIVE_FCF_VAL_DISCOUNT = 0.75;

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below replacement CapEx. */
    public const DEPRECIATION_DECAY_RATE      = 0.020;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment above replacement CapEx. */
    public const MODERNIZATION_GAIN_RATE      = 0.010;
    /** Structural minimum operating margin floor under severe physical plant aging. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.015;
    /** Structural maximum operating margin ceiling for modernized industrial plants. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.28;

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

        $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, $stock->getInvestedCapital(), $macroState);
        $waccBase = $macroState->policyRate + $macroState->equityRiskPremium;
        $effectiveRoic = max($waccBase, $baselineRoic - $saturationPenalty);

        return [
            'invested_capital' => $stock->getInvestedCapital(),
            'baseline_roic' => $effectiveRoic
        ];
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value => 0.5,
        ]);
        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));
        $macroSensitivityMultiplier = 0.5 + $pricingPower;

        $outputGap = $macroState->outputGapEma;
        $beta = (float) $stock->getBeta();
        $fxShift = ($macroState->exchangeRateIndexEma - 100.0) / 100.0;

        return [
            'macro_demand_shift' => ($outputGap * $macroSensitivityMultiplier * $beta) - ($fxShift * 0.05 * $beta),
            'pricing_power_multiplier' => 1.0 + $this->resolveInflationPassThrough($stock, $macroState, $pricingPower),
        ];
    }

    /**
     * Incomplete and lagged pass-through of expected inflation into selling prices (Gopinath & Itskhoki 2010).
     *
     * The share of inflation a firm recovers in price is its pricing power, not its beta. Beta measures
     * systematic risk and already scales the demand shift above; keying pass-through off it as well made a
     * cyclical commodity producer look like a price setter and a defensive branded staple like a price taker,
     * and double-counted beta inside one quarter's revenue. The elasticity is centred so the median firm
     * (pricing power 0.5) keeps the unit elasticity the old beta term produced on average, mirroring the
     * 0.5 + p form the demand multiplier already uses.
     *
     * Pass-through is then distributed over time rather than landing whole in the quarter expectations move:
     * menu costs and contract repricing mean posted prices reach the new level over several quarters. The
     * lag state is persisted on the stock, so a firm carries its own repricing history.
     */
    protected function resolveInflationPassThrough(Stock $stock, \App\DTO\MacroStateDTO $macroState, float $pricingPower): float
    {
        $targetPassThrough = $macroState->tipsBreakevenEma * (self::PASS_THROUGH_BASE_ELASTICITY + $pricingPower);

        $laggedPassThrough = MathUtility::getInstance()->calculateDistributedLag(
            currentLaggedValue: $stock->getInflationPassThrough() ?? $targetPassThrough,
            targetValue: $targetPassThrough,
            dt: EarningsEngine::QUARTERLY_TIME_STEP,
            lagTimeConstant: self::PRICE_PASS_THROUGH_LAG_YEARS
        );

        $stock->setInflationPassThrough($laggedPassThrough);

        return $laggedPassThrough;
    }

    /**
     * Idiosyncratic variance is applied directly to sales volume.
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value => 0.5,
        ]);
        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        // Risk vs Reward:
        // High pricing power (1.0) = 0x inflation penalty, but 1.5x macro volume sensitivity (highly elastic luxury/premium goods)
        // Low pricing power (0.0)  = 2.0x inflation penalty, but 0.5x macro volume sensitivity (inelastic discount goods)
        $inflationMultiplier = 2.0 - ($pricingPower * 2.0);
        $macroSensitivityMultiplier = 0.5 + $pricingPower;

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $revenueZ = $mathUtility->generatePersistentZ($momentum['revenue'] ?? 0.0, 0.25);

        $revenueShock = ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR));
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);

        // Supply Chain Inflation Penalty
        $inflation = $macroState->inflationEma;
        $baseInflationPenalty = $inflation > \App\Service\Macro\MacroEngine::TARGET_INFLATION ? ($inflation - \App\Service\Macro\MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta()) * self::INFLATION_PENALTY_SCALAR : 0.0;

        $inflationPenalty = $baseInflationPenalty * $inflationMultiplier;

        // Producer Price Inflation: Wholesale input and intermediate goods cost drag
        $ppiCostDrag = MathUtility::calculatePpiCostDrag(
            $macroState->producerPriceInflationEma,
            \App\Service\Macro\MacroEngine::TARGET_INFLATION,
            $pricingPower,
            self::PPI_COST_SENSITIVITY
        );

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inflationPenalty + $ppiCostDrag);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $revenueZ,
            observableShockZ: $revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR),
            eventType: null,
            streamZ: [
                'revenue' => $revenueZ,
            ],
            streamRevenue: [
                'core_business' => $actualRevenue,
            ],
        );
    }

    /**
     * Normal physical companies are evaluated on NOPAT / Invested Capital (ROIC).
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?\App\DTO\MacroStateDTO $macroState = null, float $depreciation = 0.0): float
    {
        $kappa = $this->getReversionSpeed();
        $moatSpread = $this->getMoatSpread();

        $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;

        $effectiveCapital = max(1.0, abs($investedCapital));
        $truePostTaxReturn = ($nopatProxy / $effectiveCapital) * 4.0;

        $stock->setCurrentRoic((string) max(-0.50, min(1.0, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoicTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT) + ($oldTtm * FinancialConstants::TTM_SMOOTHING_OLD_WEIGHT);
        // Scale kappa so the blended target in getTargetMetrics moves at exactly $kappa
        $scaledKappa = $kappa / self::TTM_ROIC_WEIGHT;

        $saturationPenalty = 0.0;
        if ($macroState !== null) {
            $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, abs($investedCapital), $macroState);
        }

        $effectiveMoat = max(0.0, $moatSpread - $saturationPenalty);
        $newTtm += MathUtility::getInstance()->calculateReversionPull($newTtm, $wacc, $scaledKappa, $effectiveMoat);
        $stock->setRoicTtm((string) max(-0.50, min(1.0, $newTtm)));

        return $truePostTaxReturn;
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        // Standard corporates pay market interest rates on ALL of their debt. 
        // They do not get the benefit of cheap customer deposits like banks do.
        $interestExpense = ($debt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($debt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $debt > 0 ? ($interestExpense / $debt) : $currentMarketFixedRate;

        return ['interest_expense' => $interestExpense, 'wholesale_rate' => $wholesaleRate];
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
            'inflation_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
        ];
    }
}
