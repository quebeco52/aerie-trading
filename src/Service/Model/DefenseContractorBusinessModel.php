<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Heavy Defense Contractors & Weapons Manufacturers.
 * 
 * Financial Physics:
 * - Bureaucratic Capture: Revenue is locked into multi-decade government defense budgets.
 * - Cost-Plus Contracts: Inflation is a blessing. The government guarantees a fixed margin ON TOP of material costs.
 * - Fixed-Price Development: R&D cost overruns trigger catastrophic forward-loss margin penalties.
 * - Foreign Military Sales (FMS): High-margin international sales driven by geopolitical conflict, but vulnerable to export bans.
 */
class DefenseContractorBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.60; // Defense budgets are public, R&D is black-box
    public const BASE_COVERAGE_ERROR = 0.08;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.12, 'moat_spread' => 0.020, 'nwc_intensity' => 0.10, 'capex_completion_rate' => 0.20];
    }
    public function getCapexCyclicality(): float
    {
        return 0.3;
    }
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.70, 'revenue_weight' => 0.30];
    }

    // --- Dual-Stream Defense Architecture ---
    public const DOMESTIC_PROCUREMENT_WEIGHT = 0.75;
    public const FOREIGN_MILITARY_SALES_WEIGHT = 0.25;

    // --- Government Contracting & Cost-Plus Physics ---
    public const DOMESTIC_VARIANCE_SCALAR   = 0.05; // Bureaucratic capture makes revenue incredibly stable
    public const FMS_VARIANCE_SCALAR        = 0.35; // FMS is much more volatile and geopolitically sensitive
    public const COST_PLUS_BONUS_SCALAR     = 1.50;

    // --- Fixed-Price Contract Margin Squeeze ---
    public const FORWARD_LOSS_Z_SCORE       = -1.50;
    public const FORWARD_LOSS_PENALTY       = 0.08; // 8% variable cost spike from R&D overruns

    // --- Geopolitical Contract Lore & Shock Thresholds ---
    public const FLAGSHIP_FAILURE_Z_SCORE  = -2.50;
    public const FLAGSHIP_FAILURE_MULT     = 0.80;
    public const FLAGSHIP_FAILURE_PENALTY  = 0.10;

    public const MEGA_CONTRACT_WIN_Z_SCORE = 2.50;
    public const MEGA_CONTRACT_WIN_MULT    = 1.15;

    // --- Geopolitical Sanctions & Conflict Physics ---
    public const GEOPOLITICAL_CONFLICT_Z = 2.00;
    public const FMS_CONFLICT_BOOST = 1.50; // Foreign sales explode during war
    public const SANCTIONS_EXECUTION_DRAG = 0.035;

    public const CONGRESSIONAL_EXPORT_BAN_Z = -2.00;
    public const EXPORT_BAN_MULT = 0.50; // FMS gets crushed if Congress blocks arms sales

    // --- Program Execution & Classified R&D Tooling Physics ---
    public const PROGRAM_EXECUTION_ELASTICITY = 0.015;
    public const DEFENSE_TOOLING_DECAY_RATE   = 0.018;
    public const CLASSIFIED_PLATFORM_GAIN_RATE = 0.009;
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.06;
    public const MAX_OPERATING_MARGIN_CEILING = 0.22;

    // --- ROIC Annualization & Smoothing ---
    public const ROIC_ANNUALIZATION_MULT   = 4.00;
    public const MIN_ROIC_CLAMP            = -0.50;
    public const MAX_ROIC_CLAMP            = 1.00;
    public const ROIC_TTM_EMA_WEIGHT       = 0.20;
    public const ROIC_TTM_HIST_WEIGHT      = 0.80;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shift. Defense spending is immune to consumer recessions.
        $physics['macro_demand_shift'] = 0.0;

        // Cost-plus contracts perfectly capture inflation dynamically. 
        $physics['pricing_power_multiplier'] = 1.0;

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'domestic_procurement_weight'   => self::DOMESTIC_PROCUREMENT_WEIGHT,
            'foreign_military_sales_weight' => self::FOREIGN_MILITARY_SALES_WEIGHT,
        ]);

        $domesticWeight = $params['domestic_procurement_weight'];
        $fmsWeight      = $params['foreign_military_sales_weight'];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new \App\DTO\StreamContext($momentum, $mathUtility);

        // Independent stream Z-scores
        $domesticZ = $streams->generateZ('domestic_procurement', 0.60); // High persistence (multi-year budgets)
        $fmsZ      = $streams->generateZ('foreign_military_sales', 0.20); // Foreign sales are geopolitical and volatile
        $eventZ    = $streams->generateZ('event', 0.10);

        // --- Macro & Structural Physics ---
        $inflation = $macroState->inflationEma;
        $costPlusBonus = $inflation > MacroEngine::TARGET_INFLATION
            ? ($inflation - MacroEngine::TARGET_INFLATION) * self::COST_PLUS_BONUS_SCALAR
            : 0.0;

        $nominalDefenseGrowth = max(0.0, ($macroState->nominalGdpIndex - 1.0) * 0.50);

        // --- Tail Risk & Event Multipliers ---
        $domesticMultiplier = 1.0;
        $fmsMultiplier      = 1.0;
        $eventType          = null;
        $weaponFailurePenalty = 0.0;
        $forwardLossPenalty   = 0.0;

        // Program Execution Efficiency (Better execution = lower costs)
        $executionEfficiencyShift = -self::PROGRAM_EXECUTION_ELASTICITY * $domesticZ * $domesticWeight;

        // Fixed-Price Development Losses (e.g., KC-46 / Starliner cost overruns)
        if ($domesticZ < self::FORWARD_LOSS_Z_SCORE) {
            $forwardLossPenalty = self::FORWARD_LOSS_PENALTY;
            $eventType = ShockEvent::PROJECT_DELAY ?? 'fixed_price_cost_overrun';
        }

        if ($eventZ < self::FLAGSHIP_FAILURE_Z_SCORE) {
            $domesticMultiplier = self::FLAGSHIP_FAILURE_MULT;
            $weaponFailurePenalty = self::FLAGSHIP_FAILURE_PENALTY;
            $eventType = ShockEvent::DEFENSE_CONTRACT_LOSS ?? 'flagship_weapon_failure';
        } elseif ($eventZ > self::MEGA_CONTRACT_WIN_Z_SCORE) {
            $domesticMultiplier = self::MEGA_CONTRACT_WIN_MULT;
            $eventType = ShockEvent::DEFENSE_CONTRACT_WIN ?? 'mega_procurement_win';
        } elseif ($eventZ < self::CONGRESSIONAL_EXPORT_BAN_Z) {
            $fmsMultiplier = self::EXPORT_BAN_MULT;
            $eventType = ShockEvent::REGULATORY_FINE ?? 'congressional_export_ban';
        }

        // Active war/conflict overrides standard events
        if ($fmsZ > self::GEOPOLITICAL_CONFLICT_Z) {
            $fmsMultiplier = self::FMS_CONFLICT_BOOST;
            $executionEfficiencyShift += self::SANCTIONS_EXECUTION_DRAG; // Material shortages compress margins during war
            $eventType = ShockEvent::GEOPOLITICAL_SANCTIONS ?? 'geopolitical_conflict';
        }

        // --- Clamped Revenue Streams ---
        $domesticRevenue = max(0.0, $expectedRevenue * $domesticWeight * (1.0 + ($domesticZ * $baselineVol * self::DOMESTIC_VARIANCE_SCALAR) + $costPlusBonus + $nominalDefenseGrowth) * $domesticMultiplier);
        $fmsRevenue      = max(0.0, $expectedRevenue * $fmsWeight * (1.0 + ($fmsZ * $baselineVol * self::FMS_VARIANCE_SCALAR)) * $fmsMultiplier);

        $actualRevenue = $domesticRevenue + $fmsRevenue;

        // --- Margin Clamping ---
        // Adding execution shifts and failure penalties directly to the raw margin (increases Variable Cost Ratio).
        $rawMargin = $realizedVariableMargin + $executionEfficiencyShift + $weaponFailurePenalty + $forwardLossPenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        $primaryShockZ = abs($fmsZ) > abs($domesticZ) ? $fmsZ : $domesticZ;
        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }

        // Cost-plus bonus bypasses the baselineVol scalar because it is a direct percentage revenue boost.
        $observableShockZ = ($domesticZ * $domesticWeight * self::DOMESTIC_VARIANCE_SCALAR * 0.60 * $baselineVol) +
            ($fmsZ * $fmsWeight * self::FMS_VARIANCE_SCALAR * 0.30 * $baselineVol) +
            ($costPlusBonus * $domesticWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'domestic_procurement'   => $domesticRevenue,
                'foreign_military_sales' => $fmsRevenue,
            ],
        );
    }

    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?\App\DTO\MacroStateDTO $macroState = null): float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $thresholds = $this->getModelThresholds();
        $kappa = $thresholds['reversion_speed'] ?? 0.12;
        $moatSpread = $thresholds['moat_spread'] ?? 0.02;

        // NOPAT (Net Operating Profit After Tax)
        $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;

        $effectiveCapital = max(1.0, abs($investedCapital));
        $truePostTaxReturn = ($nopatProxy / $effectiveCapital) * self::ROIC_ANNUALIZATION_MULT;

        $stock->setCurrentRoic((string) max(self::MIN_ROIC_CLAMP, min(self::MAX_ROIC_CLAMP, $truePostTaxReturn)));

        // Defense Contractors experience massive, lumpy shocks when winning/losing multi-billion dollar geopolitical contracts.
        // Use a 0.20 smoothing factor to prevent violent P/E whipsaws when a single contract is won or lost.
        $oldTtm = (float) $stock->getRoicTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * self::ROIC_TTM_EMA_WEIGHT) + ($oldTtm * self::ROIC_TTM_HIST_WEIGHT);

        // Scale kappa so the blended target in getTargetMetrics moves at exactly $kappa
        $scaledKappa = $kappa / self::TTM_ROIC_WEIGHT;
        $math = new MathUtility();

        $saturationPenalty = 0.0;
        if ($macroState !== null) {
            $metrics = new \App\Service\Math\CorporateMetrics();
            $saturationPenalty = $metrics->calculateMarketSaturationPenalty($stock, abs($investedCapital), $macroState);
        }

        $newTtm += $math->calculateReversionPull($newTtm, $wacc - $saturationPenalty, $scaledKappa, $moatSpread);
        $stock->setRoicTtm((string) max(self::MIN_ROIC_CLAMP, min(self::MAX_ROIC_CLAMP, $newTtm)));

        return $truePostTaxReturn;
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Classified tooling tech debt toward floor
            $decayRate = self::DEFENSE_TOOLING_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Next-gen defense platform modernization expands margin ceiling
            $modGain = self::CLASSIFIED_PLATFORM_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
