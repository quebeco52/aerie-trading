<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Event\ShockEvent;
use App\DTO\SectorCoverageProfile;

/**
 * Earnings strategy for Auto Manufacturers.
 * 
 * Financial Physics:
 * - High Operating Leverage: Massive fixed costs mean margins compress violently during recessions.
 * - Pro-Cyclical: Sensitive to the macro output gap.
 * - Interest Rate Sensitive: Consumer auto sales are heavily financed, so high policy rates destroy demand.
 * - Split Streams: Captive finance arms suffer from loan defaults and NIM squeeze.
 */
class AutoManufacturerBusinessModel extends HeavyManufacturingBusinessModel
{
    // --- Stream Weights ---
    /** Baseline fraction of revenue from core auto sales. */
    public const AUTO_SALES_WEIGHT = 0.85;
    /** Baseline fraction of revenue from captive finance arm. */
    public const AUTO_FINANCING_WEIGHT = 0.15;

    // --- Financing Arm Physics (Shadow Bank / Credit Services) ---
    /** Break-even NIM floor (~50bps). */
    public const NIM_SPREAD_BUFFER = 0.005;
    /** Linear sensitivity to yield curve spread. */
    public const NIM_LINEAR_SENSITIVITY = 1.00;
    /** Quadratic penalty for yield curve inversion. */
    public const NIM_QUADRATIC_COEFF = 0.15;
    /** Drag on auto loans during negative output gaps. */
    public const MACRO_DEFAULT_SCALAR = 0.25;
    /** Baseline credit spread above which CECL provisioning accelerates. */
    public const CECL_BASELINE_CREDIT_SPREAD = 0.02;

    // --- Interest Rate Sensitivity ---
    
    /** 
     * Neutral policy rate (~3.0%). Rates above this negatively impact auto sales demand. 
     */
    public const NEUTRAL_POLICY_RATE = 0.03;
    
    /** 
     * Scalar for how much demand is destroyed per 100bps of policy rate above neutral. 
     * Auto sales are highly sensitive. A 2% rate hike above neutral could destroy ~5% of volume.
     */
    public const RATE_SENSITIVITY_SCALAR = 2.50;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.08, 'moat_spread' => 0.015, 'nwc_intensity' => 0.15, 'capex_completion_rate' => 0.125];
    }

    public function getCoverageProfile(): SectorCoverageProfile
    {
        // Monthly dealer inventory and car sales data provide ~40% base visibility.
        // Massive safety recalls and major UAW labor strikes are highly public events.
        return new SectorCoverageProfile(
            baseVisibility: 0.40,
            errorStdDev: 0.07,
            eventBaseVisibility: 0.90,
            eventMinVisibility: 0.70
        );
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        // Get the base physics from HeavyManufacturing (which already amplifies output gap)
        $physics = parent::getMacroPhysics($stock, $macroState);
        
        $params = $this->resolveModelParameters($stock, ['rate_sensitivity_scalar' => self::RATE_SENSITIVITY_SCALAR]);
        $rateScalar = $params['rate_sensitivity_scalar'];

        $policyRate = $macroState->policyRate;
        $beta = (float) $stock->getBeta();
        
        // Calculate the rate penalty/boost
        $ratePenalty = 0.0;
        if ($policyRate > self::NEUTRAL_POLICY_RATE) {
            // Heavily penalize demand when rates are high
            $ratePenalty = ($policyRate - self::NEUTRAL_POLICY_RATE) * $beta * $rateScalar;
        } else {
            // Slight boost when rates are ultra-low (cheaper financing)
            $ratePenalty = ($policyRate - self::NEUTRAL_POLICY_RATE) * $beta * ($rateScalar * 0.5);
        }
        
        // Subtract the penalty from the demand shift (a positive penalty reduces demand)
        $physics['macro_demand_shift'] -= $ratePenalty; 
        
        // Auto sales are highly sensitive to consumer sentiment
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $physics['macro_demand_shift'] += $sentimentShift * $beta * 1.5; // Amplify sentiment impact
        
        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'pricing_power_index' => 0.5,
            'auto_sales_weight' => self::AUTO_SALES_WEIGHT,
            'auto_financing_weight' => self::AUTO_FINANCING_WEIGHT
        ]);
        
        $salesWeight = $params['auto_sales_weight'];
        $financeWeight = $params['auto_financing_weight'];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $salesZ = $mathUtility->generatePersistentZ($momentum['auto_sales'] ?? 0.0, 0.25);
        $financeZ = $mathUtility->generatePersistentZ($momentum['auto_financing'] ?? 0.0, 0.25);

        // --- Core Auto Sales (Heavy Manufacturing Physics) ---
        // Resolve pricing power for inflation penalty logic
        $pricingPower = max(0.0, min(1.0, $params['pricing_power_index']));
        $inflationMultiplier = 2.0 - ($pricingPower * 2.0); 
        $macroSensitivityMultiplier = 0.5 + $pricingPower;

        // Base macro volume shock from Consumer Sentiment is now handled in getMacroPhysics
        $salesShock = ($salesZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR));
        
        // Supply chain inflation & energy penalty for physical components and manufacturing
        $inflation = $macroState->inflationEma;
        $energyShift = max(0.0, ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0);
        
        $baseInflationPenalty = $inflation > MacroEngine::TARGET_INFLATION 
            ? ($inflation - MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta()) * self::INFLATION_PENALTY_SCALAR 
            : 0.0;
        $inflationPenalty = ($baseInflationPenalty + ($energyShift * 0.05)) * $inflationMultiplier;

        // --- Auto Financing Arm (Shadow Bank / Credit Services Physics) ---
        // Unsecured default drag: negative sentiment severely hits auto loans
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $macroDefaultDrag = $sentimentShift < 0.0 ? abs($sentimentShift) * self::MACRO_DEFAULT_SCALAR : 0.0;

        // CECL Forward Provisioning (Credit Spread Channel)
        $creditSpread = $macroState->macroCreditSpreadEma;
        $ceclDrag = $creditSpread > self::CECL_BASELINE_CREDIT_SPREAD 
            ? ($creditSpread - self::CECL_BASELINE_CREDIT_SPREAD) * 0.50
            : 0.0;

        // Net Interest Margin (NIM) Squeeze: yield curve inversion hurts captive finance arms
        $yield10y = $macroState->yield10yEma;
        $yield2y  = $macroState->yield2yEma;
        $bankSpread = $yield10y - $yield2y;

        if ($bankSpread < 0) {
            $nimSqueeze = (self::NIM_SPREAD_BUFFER - $bankSpread)
                + pow(abs($bankSpread) * FinancialConstants::YIELD_CURVE_INVERSION_SENSITIVITY, 2) * self::NIM_QUADRATIC_COEFF;
        } else {
            $nimSqueeze = (self::NIM_SPREAD_BUFFER - $bankSpread) * self::NIM_LINEAR_SENSITIVITY;
        }

        // --- Combine Streams ---
        $salesRevenue = $expectedRevenue * $salesWeight * (1.0 + $salesShock);
        $financeRevenue = $expectedRevenue * $financeWeight * (1.0 + ($financeZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $actualRevenue = max(0.0, $salesRevenue + $financeRevenue);

        // Blended margins 
        // Note: Positive cost addons compress the margin (e.g., inflation penalty).
        // Negative values (e.g., steep yield curve NIM spread) actually expand the margin.
        $financeCostAddon = ($macroDefaultDrag + $nimSqueeze + $ceclDrag) * $financeWeight;
        $salesCostAddon = $inflationPenalty * $salesWeight;
        
        $rawMargin = $realizedVariableMargin + $financeCostAddon + $salesCostAddon;
        $clampedMargin = $this->clampMargin($rawMargin);

        // --- Event Lore ---
        $eventType = null;
        $outputGap = $macroState->outputGap;
        if ($salesZ < -1.5 && $outputGap < -0.01) {
            $eventType = ShockEvent::AUTO_SUPPLY_CHAIN_DISRUPTION;
        } elseif ($outputGap < -0.02 && $macroDefaultDrag > 0.01) {
            $eventType = ShockEvent::AUTO_SUBPRIME_DEFAULT_SURGE;
        } elseif ($salesZ > 1.5 && $outputGap > 0.01) {
            $eventType = ShockEvent::AUTO_PRICING_POWER_SURGE;
        } elseif ($mathUtility->generateUniform() <= 0.02) {
            // 2% chance per quarter of a massive recall
            $eventType = ShockEvent::PRODUCT_RECALL;
            // Recalls compress margin heavily
            $clampedMargin = $this->clampMargin($clampedMargin + 0.05);
        }

        $primaryShockZ = abs($salesZ) > abs($financeZ) ? $salesZ : $financeZ;
        
        // Observable shock properly blends both streams with their respective assumed visibility
        // Auto sales are highly visible via dealer reporting (60%), captive finance is less transparent (30%)
        $observableShockZ = ($salesZ * $salesWeight * 0.60 + $financeZ * $financeWeight * 0.30) 
            * $baselineVol * self::REVENUE_VARIANCE_SCALAR;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: [
                'auto_sales'     => $salesZ,
                'auto_financing' => $financeZ,
            ],
            streamRevenue: [
                'auto_sales'     => $salesRevenue,
                'auto_financing' => $financeRevenue,
            ],
        );
    }
}
