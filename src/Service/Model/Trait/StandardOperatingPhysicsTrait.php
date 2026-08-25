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
        $thresholds = $this->getModelThresholds();
        return $thresholds['nwc_intensity'] ?? 0.05;
    }

    public function getCapExCompletionRate(Stock $stock): float
    {
        $thresholds = $this->getModelThresholds();
        return $thresholds['capex_completion_rate'] ?? 0.33;
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void {}

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
        );
    }

    abstract protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult;

    public function calculateEconomicReturn(Stock $stock, float $quarterlyNopatOrIncome, float $investedCapital): float
    {
        return $investedCapital > 0 ? ($quarterlyNopatOrIncome / $investedCapital) * 4.0 : 0.0;
    }

    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?\App\DTO\MacroStateDTO $macroState = null): float
    {
        $thresholds = $this->getModelThresholds();
        $kappa = $thresholds['reversion_speed'] ?? 0.20;
        $moatSpread = $thresholds['moat_spread'] ?? 0.00;

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

    public function getTrueReturn(Stock $stock): float
    {
        return (float) $stock->getRoicTtm();
    }

    public function getEvaluationCapital(float $equity, float $investedCapital): float
    {
        return $investedCapital;
    }
}
