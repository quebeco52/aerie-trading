<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;

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
    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.20, 'moat_spread' => 0.000, 'nwc_intensity' => 0.10, 'capex_completion_rate' => 0.33];
    }
    use Trait\StandardBaseModelTrait;
    use Trait\StandardOperatingPhysicsTrait;
    use Trait\StandardTreasuryTrait;
    use Trait\StandardCapitalAllocationTrait;
    use Trait\StandardDebtPhysicsTrait;
    use Trait\StandardMaTrait;
    use Trait\StandardValuationTrait;

    // --- ROIC & Target Metrics ---
    /** Weight given to historical baseline ROIC when blending with TTM ROIC. */
    public const BASELINE_ROIC_WEIGHT = 0.50;
    /** Weight given to TTM ROIC when blending with historical baseline ROIC. */
    public const TTM_ROIC_WEIGHT      = 0.50;

    // --- Pricing Power & Macro Physics ---
    /** Minimum beta floor applied when calculating pricing power resistance to inflation. */
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.50;

    // --- Revenue & Shock Physics ---
    /** Variance scalar applied to baseline volatility for sales volume shocks. */
    public const REVENUE_VARIANCE_SCALAR = 0.15;
    /** Sensitivity scalar for supply chain inflation cost penalties during high CPI/PPI regimes. */
    public const INFLATION_PENALTY_SCALAR = 0.50;
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

        $metrics = new \App\Service\Math\CorporateMetrics();
        $saturationPenalty = $metrics->calculateMarketSaturationPenalty($stock, $stock->getInvestedCapital(), $macroState);
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
        $inflation = $macroState->inflationEma;
        $beta = (float) $stock->getBeta();
        $fxShift = ($macroState->exchangeRateIndexEma - 100.0) / 100.0;

        return [
            'macro_demand_shift' => ($outputGap * $macroSensitivityMultiplier * $beta) - ($fxShift * 0.05 * $beta),
            'pricing_power_multiplier' => 1.0 + ($inflation * max(self::MIN_BETA_PRICING_POWER_FLOOR, $beta)),
        ];
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

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inflationPenalty);

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
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?\App\DTO\MacroStateDTO $macroState = null): float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $thresholds = $this->getModelThresholds();
        $kappa = $thresholds['reversion_speed'] ?? 0.20;
        $moatSpread = $thresholds['moat_spread'] ?? 0.00;

        $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;

        $effectiveCapital = max(1.0, abs($investedCapital));
        $truePostTaxReturn = ($nopatProxy / $effectiveCapital) * 4.0;

        $stock->setCurrentRoic((string) max(-0.50, min(1.0, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoicTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT) + ($oldTtm * FinancialConstants::TTM_SMOOTHING_OLD_WEIGHT);
        // Scale kappa so the blended target in getTargetMetrics moves at exactly $kappa
        $scaledKappa = $kappa / self::TTM_ROIC_WEIGHT;
        $math = new MathUtility();

        $saturationPenalty = 0.0;
        if ($macroState !== null) {
            $metrics = new \App\Service\Math\CorporateMetrics();
            $saturationPenalty = $metrics->calculateMarketSaturationPenalty($stock, abs($investedCapital), $macroState);
        }

        $effectiveMoat = max(0.0, $moatSpread - $saturationPenalty);
        $newTtm += $math->calculateReversionPull($newTtm, $wacc, $scaledKappa, $effectiveMoat);
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

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        if ($fcfPerShare !== null && $fcfPerShare > 0.0) {
            $multiplier = $mathUtility->calculateDcfMultiplier($liveWacc, self::DCF_TERMINAL_GROWTH_RATE);
            // The FCF passed from EarningsEngine is Quarterly. We MUST annualize it!
            $annualFcf = $fcfPerShare * 4.0;
            // Cap the DCF so a temporary lack of CapEx doesn't cause an infinite perpetual valuation.
            $dcfFairValue = min(max(0.01, $annualFcf * $multiplier), $peFairValue * self::MAX_DCF_TO_PE_CAP_MULT);
            return ($peFairValue + $dcfFairValue) / 2.0;
        }
        return $fcfPerShare !== null ? max($revenueFloorValue, $peFairValue) * self::NEGATIVE_FCF_VAL_DISCOUNT : max($revenueFloorValue, $peFairValue);
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            $decayRate = self::DEPRECIATION_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            $modGain = self::MODERNIZATION_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
