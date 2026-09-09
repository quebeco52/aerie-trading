<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Data\ModelParam;
use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\SectorCoverageProfile;
use App\DTO\SectorPhysicsResult;
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
        $actualVariableCosts = $physics->actualRevenue * $clampedMargin;
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
        );
    }

    abstract protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult;

    public function calculateEconomicReturn(Stock $stock, float $quarterlyNopatOrIncome, float $investedCapital): float
    {
        return $investedCapital > 0 ? ($quarterlyNopatOrIncome / $investedCapital) * 4.0 : 0.0;
    }

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
        $scaledKappa = $kappa / (defined('static::TTM_ROIC_WEIGHT') ? static::TTM_ROIC_WEIGHT : 0.50);

        $saturationPenalty = 0.0;
        if ($macroState !== null) {
            $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, abs($investedCapital), $macroState);
        }

        $effectiveMoat = max(0.0, $moatSpread - $saturationPenalty);
        $newTtm += MathUtility::getInstance()->calculateReversionPull($newTtm, $wacc, $scaledKappa, $effectiveMoat);
        $stock->setRoicTtm((string) max(-0.50, min(1.0, $newTtm)));

        return $truePostTaxReturn;
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
