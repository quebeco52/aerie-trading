<?php

namespace App\Service\EarningsStrategy;

use App\Entity\Stock;
use App\Service\MathUtility;

/**
 * Interface that defines the core financial physics required to process 
 * earnings and balance sheet evolutions for specific industries.
 */
interface EarningsStrategyInterface
{
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array;
    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float;
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MathUtility $mathUtility): array;
    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float;
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float;
}