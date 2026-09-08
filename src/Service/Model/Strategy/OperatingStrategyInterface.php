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
    public function getCoverageProfile(Stock $stock): SectorCoverageProfile;
    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array;
    /**
     * MacroStateDTO field names (snake_case, matching MacroStateDTO::toArray()) this model's
     * operating physics reads in calculateSectorPhysics()/getMacroPhysics(). Valuation-only reads
     * feeding WACC alone (equityRiskPremium, corporateTaxRate, policyRate) are excluded, so this
     * stays a genuine operating-coupling declaration rather than everything a model touches.
     *
     * Consumed by App\Service\District\DistrictConduitResolver to derive which district
     * institutions draw a conduit to this model — see App\Data\DistrictMap's class docblock.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array;
    public function getEffectiveTaxRate(float $macroTaxRate): float;
    public function calculateEconomicReturn(Stock $stock, float $nopat, float $investedCapital): float;
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?\App\DTO\MacroStateDTO $macroState = null): float;
    public function getSecularGrowthRate(Stock $stock): float;
    public function getCapexCyclicality(): float;
    public function getSurpriseBlendWeights(): array;
    public function getEffectiveReturn(Stock $stock): float;
    public function getTrueReturn(Stock $stock): float;
    public function getEvaluationCapital(float $equity, float $investedCapital): float;
    public function getWorkingCapitalIntensity(Stock $stock): float;
    public function getCapExCompletionRate(Stock $stock): float;
    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void;
    public function getMarginReversionSpeed(): float;
    public function getReversionSpeed(): float;
    public function getMoatSpread(): float;
    public function getPhysicalCapital(Stock $stock): float;
    public function allowsPhysicalOrganicCapex(): bool;
    public function getReturnBasisIncome(Stock $stock, float $quarterlyNopat, float $actualTotalNetIncome): float;
    /**
     * @return array<int, float> Quarterly revenue seasonality multipliers [Q1, Q2, Q3, Q4] summing to 4.0.
     */
    public function getSeasonalityFactors(): array;
}
