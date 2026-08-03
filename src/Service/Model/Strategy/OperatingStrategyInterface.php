<?php

declare(strict_types=1);

namespace App\Service\Model\Strategy;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\SectorCoverageProfile;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

interface OperatingStrategyInterface
{
    public function getTargetMetrics(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): array;
    public function computeActualFinancials(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): ActualFinancialsDTO;
    public function getCoverageProfile(): SectorCoverageProfile;
    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array;
    public function getEffectiveTaxRate(float $macroTaxRate): float;
    public function calculateEconomicReturn(Stock $stock, float $nopat, float $investedCapital): float;
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08): float;
    public function getSecularGrowthRate(Stock $stock): float;
    public function getCapexCyclicality(): float;
    public function getSurpriseBlendWeights(): array;
    public function getTrueReturn(Stock $stock): float;
    public function getEvaluationCapital(float $equity, float $investedCapital): float;
    public function getWorkingCapitalIntensity(Stock $stock): float;
    public function getCapExCompletionRate(Stock $stock): float;
    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void;
    public function getMarginReversionSpeed(): float;
}
