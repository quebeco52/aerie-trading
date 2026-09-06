<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Global Maritime Shipping and Freight Logistics.
 * 
 * Financial Physics:
 * - Hyper-Cyclical Spot-Rate Operational Leverage: Revenues are violently levered to global GDP and the macro output gap.
 * - During economic expansions (+Output Gap), spot freight rates surge exponentially against fixed fleet overhead, producing massive Free Cash Flow explosions.
 * - During economic contractions (-Output Gap), capacity gluts and falling container rates cause severe operating losses.
 * - Heavy Physical Depreciation: Vessels and containers rust and degrade rapidly, requiring consistent, non-discretionary CapEx.
 */
class ShippingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.75;
    public const BASE_COVERAGE_ERROR = 0.10;
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.50;
        public function getReversionSpeed(): float { return 0.3; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.125; }
    public function getCapexCyclicality(): float
    {
        return 3.0;
    }
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.20, 'revenue_weight' => 0.80];
    }

    // --- Dual-Stream Maritime Charter Architecture ---
    /** Baseline fraction of revenue derived from volatile spot market freight and short-term voyage charters. */
    public const SPOT_CHARTER_WEIGHT     = 0.50;
    /** Baseline fraction of revenue derived from long-term contracted time charters and dedicated logistics. */
    public const CONTRACT_CHARTER_WEIGHT = 0.50;

    // --- Hyper-Cyclical Spot Rate Physics ---
    /** Macroeconomic demand shift sensitivity to global trade output gaps. */
    public const MACRO_DEMAND_SCALAR       = 1.80;
    /** Minimum beta floor applied when calculating inflation pricing power. */
    public const MIN_PRICING_BETA_FLOOR    = 0.50;
    /** Volatility multiplier for top-line revenue shocks driven by maritime freight spot rates. */
    public const REVENUE_VARIANCE_SCALAR   = 0.25;
    /** Positive output gap threshold triggering exponential spot rate boom multipliers. */
    public const SPOT_BOOM_GAP_THRESHOLD   = 0.015;
    /** Output gap multiplier scaling spot freight rate surges during global trade booms. */
    public const SPOT_BOOM_RATE_MULT       = 4.00;
    /** Negative output gap threshold triggering vessel capacity glut penalties. */
    public const SPOT_GLUT_GAP_THRESHOLD   = -0.015;
    /** Output gap multiplier scaling rate collapses during trade slowdowns and capacity gluts. */
    public const SPOT_GLUT_RATE_MULT       = 3.00;
    /** Continuous global trade elasticity scalar scaling spot freight rates smoothly with output gap. */
    public const CONTINUOUS_SPOT_RATE_SCALAR = 3.50;

    // --- Global Trade & Supply Chain Pressure Transmission ---
    /** Sensitivity of maritime container and bulk freight demand to trade balance shifts. */
    public const TRADE_BALANCE_SENSITIVITY = 1.50;
    /** Spot freight rate surge multiplier per unit of NY Fed global supply chain pressure. */
    public const GSCPI_FREIGHT_BOOST_SCALAR = 0.10;

    // --- Fuel & Bunker Inflation Rails ---
    /** Variable cost penalty multiplier scaling bunker fuel inflation with stock beta. */
    public const BUNKER_INFLATION_SCALAR   = 0.80;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Event Lore Thresholds ---
    /** Positive z-score threshold required during trade booms to trigger port congestion lore. */
    public const LORE_CONGESTION_Z_SCORE   = 1.50;
    /** Negative z-score threshold required during trade gluts to trigger operating loss lore. */
    public const LORE_GLUT_Z_SCORE         = -1.50;

    // --- Analyst Visibility & Error ---
    // Moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- Spot Rate Cycle & Output Gap Physics ---
    /** Operating margin mean reversion speed: fast speed reflects rapid shipbuilding order responses. */
    public const SPOT_REVERSION_SPEED      = 4.0;

    // --- Vessel Fleet Aging & Eco-Fleet Reinvestment Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below fleet replacement CapEx. */
    public const VESSEL_AGING_DECAY_RATE      = 0.025;
    /** Quarterly efficiency gain scalar per unit of eco-fleet modernization above replacement CapEx. */
    public const ECO_FLEET_MODERNIZATION_RATE = 0.012;
    /** Structural minimum operating margin floor under severe vessel aging and bunker fuel drag. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.05;
    /** Structural maximum operating margin ceiling for state-of-the-art eco-fuel vessel fleets. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.38;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $outputGap = $macroState->outputGapEma;
        $inflation = $macroState->tipsBreakevenEma;
        $fxShift = ($macroState->exchangeRateIndexEma - 100.0) / 100.0;
        $tradeShift = MathUtility::calculateTradeBalanceShift($macroState->tradeBalanceToGdpEma, sensitivity: self::TRADE_BALANCE_SENSITIVITY);
        $beta = (float) $stock->getBeta();

        // Extreme sensitivity to global economic momentum and trade volume
        return [
            'macro_demand_shift' => ($outputGap * $beta * self::MACRO_DEMAND_SCALAR) - ($fxShift * 0.10) + $tradeShift,
            'pricing_power_multiplier' => 1.0 + ($inflation * max(self::MIN_PRICING_BETA_FLOOR, $beta)),
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::SpotCharterWeight->value     => self::SPOT_CHARTER_WEIGHT,
            ModelParam::ContractCharterWeight->value => self::CONTRACT_CHARTER_WEIGHT,
        ]);

        $spotWeight     = $params[ModelParam::SpotCharterWeight];
        $contractWeight = $params[ModelParam::ContractCharterWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'spot'     => $params[ModelParam::SpotCharterWeight],
            'contract' => $params[ModelParam::ContractCharterWeight],
        ]);

        $spotWeight     = $activeWeights['spot'];
        $contractWeight = $activeWeights['contract'];

        // Independent stream Z-scores with AR(1) persistence
        $spotZ     = $streams->generateZ('spot', 0.35); // Spot ocean freight / Baltic Dry variance
        $contractZ = $streams->generateZ('contract', 0.50); // Multi-year contracted logistics lines

        // Spot Rate Super-Cycle vs. Capacity Glut
        // Crucially, spot rate elasticity applies continuously to spot charter revenue ($spotWeight).
        $outputGap = $macroState->outputGapEma;
        $freightShift = ($macroState->freightRateIndexEma - 100.0) / 100.0;
        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;
        $fxShift = ($macroState->exchangeRateIndexEma - 100.0) / 100.0;
        $tradeShift = MathUtility::calculateTradeBalanceShift($macroState->tradeBalanceToGdpEma, sensitivity: self::TRADE_BALANCE_SENSITIVITY);
        $gscpiShift = max(0.0, $macroState->supplyChainPressureIndexEma - MacroEngine::GSCPI_BASELINE);
        $spotRateMultiplier = ($outputGap * self::CONTINUOUS_SPOT_RATE_SCALAR) + ($freightShift * 0.50) + ($metalsShift * 0.15) + $tradeShift + ($gscpiShift * self::GSCPI_FREIGHT_BOOST_SCALAR);
        $eventType = null;

        if (($outputGap > self::SPOT_BOOM_GAP_THRESHOLD || $gscpiShift > 1.0) && $spotZ > self::LORE_CONGESTION_Z_SCORE) {
            $eventType = ShockEvent::SHIPPING_PORT_CONGESTION;
        } elseif ($outputGap < self::SPOT_GLUT_GAP_THRESHOLD && $spotZ < self::LORE_GLUT_Z_SCORE) {
            $eventType = ShockEvent::SHIPPING_CAPACITY_GLUT;
        }

        $spotRevenue     = max(0.0, $expectedRevenue * $spotWeight * (1.0 + ($spotZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $spotRateMultiplier));
        $contractRevenue = max(0.0, $expectedRevenue * $contractWeight * (1.0 + ($contractZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) - ($fxShift * 0.10)));

        $streamRevenues = [
            'spot'     => $spotRevenue,
            'contract' => $contractRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Fuel and Bunker Cost Inflation / Deflation:
        // Shipping is directly exposed to crude oil and commodity inflation, capturing savings during deflationary/falling fuel regimes.
        $inflation = $macroState->inflationEma;
        $energyShift = $macroState->energyCostPushLag / MacroEngine::ENERGY_COST_PUSH_TRANSMISSION;

        $bunkerInflationAdjustment = max(
            -0.05,
            min(0.15, (($inflation - MacroEngine::TARGET_INFLATION) + ($energyShift * 0.20)) * abs((float) $stock->getBeta()) * self::BUNKER_INFLATION_SCALAR)
        );

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $bunkerInflationAdjustment);

        $primaryShockZ = $streams->resolveDominantShockZ([$spotZ, $contractZ]);
        // observableShockZ: Baltic Dry Index and Harpex are public daily data (~75% visibility via getCoverageProfile)
        $spotBase = max(1.0, $expectedRevenue * $spotWeight);
        $spotShock = ($spotRevenue - $spotBase) / $spotBase;
        $contractBase = max(1.0, $expectedRevenue * $contractWeight);
        $contractShock = ($contractRevenue - $contractBase) / $contractBase;
        $observableShockZ = ($spotShock * $spotWeight) + ($contractShock * $contractWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    public function getMarginReversionSpeed(): float
    {
        // Spot rate booms attract new shipbuilding orders, causing margins to mean-revert aggressively once new vessels launch
        return self::SPOT_REVERSION_SPEED;
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Vessel aging & bunker fuel drag toward floor
            $decayRate = self::VESSEL_AGING_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Eco-fleet modernization expands margin ceiling
            $modGain = self::ECO_FLEET_MODERNIZATION_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        // Maritime shipping is deeply asset-heavy. During cyclical freight troughs (negative normalized EPS),
        // valuation shifts heavily toward Tangible Net Asset Value (P/B book replacement value) rather than discounted trough earnings.
        $bookWeight = $normalizedEps <= 0.0 ? 0.65 : 0.30;
        $earningsWeight = 1.0 - $bookWeight;

        $baseConsensus = ($earningsValue * $earningsWeight) + ($pbFairValue * $bookWeight);
        return $dividendSupportValue > 0.0
            ? ($baseConsensus * (1.0 - FinancialConstants::FAIR_VALUE_DDM_WEIGHT)) + ($dividendSupportValue * FinancialConstants::FAIR_VALUE_DDM_WEIGHT)
            : $baseConsensus;
    }
}
