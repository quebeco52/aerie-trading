<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\InputOutputExposures;
use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for the Chemical Industry (Petrochemicals, Specialty Chemicals, Agrochemicals).
 *
 * Financial Physics:
 * - Feedstock Cost Curve: base chemicals are priced off the marginal (naphtha, oil-linked) cracker while the firm pays
 *   its own gas/oil slate, so a gas-advantaged cracker gains when oil rises against gas.
 * - Tri-Stream Demand Architecture:
 *      1. Base Petrochemicals: Highly cyclical, volume price takers driven geometrically by output gap and industrial metals.
 *      2. Specialty Chemicals: Defensive, high-margin, patent-protected electronic materials and catalysts with asymmetric cost pass-through.
 *      3. Agrochemicals: Uncorrelated to standard macro cycles; driven by agricultural commodity indices and weather jump diffusion.
 * - Feedstock Pass-Through: specialty chemicals pass their own feedstock cost through with pricing power; agrochemicals as far as farm prices move with it. Symmetric in sign.
 * - Capital Intensity & Plant Turnarounds: Continuous chemical corrosion requires strict maintenance CapEx; underinvestment causes compounding margin decay and turnaround downtime.
 */
class ChemicalBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Volume follows industrial production; grades substitute within limits. */
    public const OPERATING_CYCLICALITY = 1.20;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.60;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.50;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain content (labor still the model's own). */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::CHEMICAL;
    /** Share of the firm's own cracker feedstock that is gas-linked (ethane) rather than oil-linked (naphtha); ~0.5 for a US-Gulf mixed slate. */
    public const GAS_FEEDSTOCK_SHARE = 0.50;
    /** Base petrochemicals clear at the marginal cracker's cost and take the price they are given; the specialty and agrochemical books carry the formulation power. */
    public const PRICING_POWER_INDEX = 0.40;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: Q2 planting season for agrochemicals.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [1.00, 1.08, 0.95, 0.97];
    }

    // --- Demand Transmission Lag ---
    /** Years for a move in the output gap to reach the order book. Offtake contracts and plant scheduling hold volumes steady for a couple of quarters after the cycle turns. */
    public const DEMAND_LAG_YEARS = 0.50;

    // --- FX Exposure ---
    /** Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Base chemicals trade on delivered price against foreign crackers; specialties and agrochemicals are largely sold abroad. */
    public const FX_REVENUE_EXPOSURE = 0.15;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for chemical sector analysts tracking feedstock crack spreads. */
    public const BASE_COVERAGE_VISIBILITY = 0.40;
    /** Standard forecasting error on chemical margins and commodity feedstock volatility. */
    public const BASE_COVERAGE_ERROR = 0.08;
    /** Minimum visibility floor for analyst consensus models. */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.20;

    // --- Tri-Stream Architecture Baseline Weights ---
    /** Baseline fraction of revenue from cyclical base olefins, aromatics, and bulk petrochemicals. */
    public const BASE_PETROCHEMICALS_WEIGHT = 0.50;
    /** Baseline fraction of revenue from defensive, high-margin specialty chemicals and catalysts. */
    public const SPECIALTY_CHEMICALS_WEIGHT = 0.30;
    /** Baseline fraction of revenue from non-cyclical fertilizers and crop protection agrochemicals. */
    public const AGROCHEMICALS_WEIGHT = 0.20;

    // --- Stream Variance & Persistence Scalars ---
    /** Volatility multiplier for base petrochemical market price swings. */
    public const BASE_PETRO_VARIANCE = 0.25;
    /** Volatility multiplier for specialty chemical demand shocks. */
    public const SPECIALTY_CHEM_VARIANCE = 0.08;
    /** Volatility multiplier for agrochemical demand shocks. */
    public const AGROCHEM_VARIANCE = 0.15;
    /** AR(1) persistence coefficient for base petrochemical revenue shocks. */
    public const BASE_PETRO_PERSISTENCE = 0.30;
    /** AR(1) persistence coefficient for specialty chemical revenue shocks. */
    public const SPECIALTY_CHEM_PERSISTENCE = 0.15;
    /** AR(1) persistence coefficient for agrochemical revenue shocks. */
    public const AGROCHEM_PERSISTENCE = 0.25;
    /** AR(1) persistence coefficient for feedstock crack spread margin shocks. */
    public const FEEDSTOCK_CRACK_PERSISTENCE = 0.20;

    // --- Structural Variable Cost Multipliers ---
    /** Variable cost multiplier for bulk base petrochemicals with high energy intensity. */
    public const BASE_PETRO_VARIABLE_COST_MULTIPLIER = 1.15;
    /** Variable cost multiplier for high-margin specialty chemicals with patent moats. */
    public const SPECIALTY_CHEM_VARIABLE_COST_MULTIPLIER = 0.80;
    /** Variable cost multiplier for agrochemicals and fertilizer manufacturing. */
    public const AGROCHEM_VARIABLE_COST_MULTIPLIER = 1.00;

    // --- Macro Demand & Industrial Sensitivity ---
    /** Sensitivity of base petrochemical revenue to the macroeconomic output gap. */
    public const BASE_PETRO_OUTPUT_GAP_SCALAR = 1.60;
    /** Sensitivity of base petrochemical revenue to industrial metals demand index. */
    public const BASE_PETRO_METALS_SCALAR = 0.60;
    /** Weight of cyclical industrial demand in aggregate macroeconomic demand shift. */
    public const INDUSTRIAL_DEMAND_WEIGHT = 0.70;
    /** Weight of agricultural commodity shift in aggregate macroeconomic demand shift. */
    public const AGRI_DEMAND_WEIGHT = 0.30;

    // --- Feedstock Cost Curve & Pass-Through ---
    /** Hydrocarbon feedstock cost as a share of revenue (~35%): the variable cost ratio moves by this times the relative feedstock price move. */
    public const ENERGY_FEEDSTOCK_INTENSITY = 0.35;
    /** Proportion of own feedstock cost specialty chemicals pass through via pricing power (~95% at full power). */
    public const SPECIALTY_PASS_THROUGH_RATIO = 0.95;
    /** Share of the marginal naphtha cracker's cost move passed into base chemical prices (~0.7, Ganapati, Shapiro & Walker 2020). */
    public const BASE_PETRO_MARGINAL_PASS_THROUGH = 0.70;
    /** Fertilizer price support per unit of farm price move, recovering feedstock cost only as far as farm prices move with it (~0.6). */
    public const AGRI_PASS_THROUGH_SCALAR = 0.60;
    /** Variable cost ratio move per unit cracker-spread Z per unit of baseline vol (~0.45pp of revenue a sigma at vol 0.15). */
    public const FEEDSTOCK_DRAG_SCALAR = 0.03;

    // --- Manufacturing PMI Transmission ---
    /** Sensitivity of industrial chemical and base petrochemical demand to manufacturing PMI shifts. */
    public const PMI_DEMAND_SENSITIVITY = 0.50;

    // --- Weather Jump Diffusion ---
    /** Annualized Poisson jump intensity for extreme weather shocks impacting agrochemicals. */
    public const WEATHER_JUMP_LAMBDA = 0.25;
    /** Mean log-return impact of extreme weather events on agrochemical demand. */
    public const WEATHER_JUMP_MEAN = 0.00;
    /** Volatility of weather shock jumps. */
    public const WEATHER_JUMP_VOL = 0.12;

    // --- Tail Risk & Shock Event Thresholds ---
    /** Z-score threshold for severe crack spread squeeze triggering event lore. */
    public const CRACK_SPREAD_SQUEEZE_Z = -2.20;
    /** Z-score threshold for positive agrochemical demand boom. */
    public const AGRI_BOOM_Z = 2.20;
    /** Severe energy inflation threshold above baseline triggering crack spread crisis lore during recessions. */
    public const SEVERE_ENERGY_INFLATION_THRESHOLD = 0.30;
    /** Output gap contraction threshold defining recessionary conditions for event triggers. */
    public const RECESSION_OUTPUT_GAP_THRESHOLD = -0.02;
    /** Minimum weather jump multiplier threshold required to trigger agricultural boom event. */
    public const WEATHER_BOOM_MULTIPLIER_THRESHOLD = 1.15;

    // --- Plant Corrosion, Turnaround & Asset Depreciation Physics ---
    /** Compounding quarterly margin decay rate under deferred maintenance and corrosion. */
    public const PLANT_DECAY_RATE = 0.020;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment into advanced chemical synthesis. */
    public const PLANT_MODERNIZATION_GAIN = 0.010;
    /** Structural minimum operating margin floor under severe plant corrosion and downtime. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.05;
    /** Structural maximum operating margin ceiling for optimized chemical manufacturing plants. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.30;

    // --- Valuation, Growth & CapEx Rails ---
    /** Multiplier scaling heavy continuous chemical synthesis and cracking plant capital expenditure cycles. */
    public const CHEMICAL_CAPEX_CYCLICALITY = 3.50;
    /** Weight given to EPS surprise when calculating aggregate earnings surprise. */
    public const SURPRISE_EPS_WEIGHT = 0.45;
    /** Weight given to revenue surprise when calculating aggregate earnings surprise. */
    public const SURPRISE_REVENUE_WEIGHT = 0.55;

    // --- Working Capital Intensities ---
    /** High inventory and hydrocarbon feedstock storage working capital intensity for base petrochemicals. */
    public const BASE_PETRO_NWC_INTENSITY = 0.24;
    /** Moderate working capital intensity for specialty chemicals. */
    public const SPECIALTY_CHEM_NWC_INTENSITY = 0.18;
    /** Seasonal fertilizer inventory stockpiling working capital intensity for agrochemicals. */
    public const AGROCHEM_NWC_INTENSITY = 0.28;

        public function getWholesaleLeverageLimit(): float { return 1.5; }
    public function getReversionSpeed(): float { return 0.12; }
    public function getMoatSpread(): float { return 0.01; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.3; }

    // --- Secular Demand ---
    /** Chemical products value added as a share of US nominal GDP in 1997 (BEA GDP by Industry, value added). */
    public const SECULAR_SHARE_1997 = 0.0202;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0174;

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroEngine::TREND_REAL_GROWTH
            + MathUtility::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    public function getCapexCyclicality(): float
    {
        return self::CHEMICAL_CAPEX_CYCLICALITY;
    }

    public function getSurpriseBlendWeights(): array
    {
        return [
            'eps_weight' => self::SURPRISE_EPS_WEIGHT,
            'revenue_weight' => self::SURPRISE_REVENUE_WEIGHT,
        ];
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::BasePetrochemicalsWeight->value => self::BASE_PETROCHEMICALS_WEIGHT,
            ModelParam::SpecialtyChemicalsWeight->value => self::SPECIALTY_CHEMICALS_WEIGHT,
            ModelParam::AgrochemicalsWeight->value      => self::AGROCHEMICALS_WEIGHT,
        ]);

        $basePetroWeight  = $params[ModelParam::BasePetrochemicalsWeight];
        $specialtyWeight  = $params[ModelParam::SpecialtyChemicalsWeight];
        $agriWeight       = $params[ModelParam::AgrochemicalsWeight];

        $totalWeight = max(0.01, $basePetroWeight + $specialtyWeight + $agriWeight);

        return (($basePetroWeight * self::BASE_PETRO_NWC_INTENSITY) +
                ($specialtyWeight * self::SPECIALTY_CHEM_NWC_INTENSITY) +
                ($agriWeight * self::AGROCHEM_NWC_INTENSITY)) / $totalWeight;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $outputGap = $this->resolveLaggedOutputGap($macroState);
        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;
        $agriShift = ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0;
        $beta = $this->getOperatingCyclicality($stock);

        // Macro demand shift: Driven by industrial demand (output gap + metals + manufacturing PMI) for base chemicals,
        // and agricultural commodities for agrochemicals.
        $pmiShift = MathUtility::calculatePmiDemandShift($macroState->manufacturingPmi, sensitivity: self::PMI_DEMAND_SENSITIVITY);
        $industrialDemand = ($outputGap * self::BASE_PETRO_OUTPUT_GAP_SCALAR) + ($metalsShift * self::BASE_PETRO_METALS_SCALAR) + $pmiShift;
        $blendedDemandShift = ($industrialDemand * $beta * self::INDUSTRIAL_DEMAND_WEIGHT) + ($agriShift * self::AGRI_DEMAND_WEIGHT)
            + $this->resolveFxDemandShift($macroState);

        return [
            'macro_demand_shift' => $blendedDemandShift,
            ...$this->resolvePricingMultipliers($stock, $macroState),
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::BasePetrochemicalsWeight->value => self::BASE_PETROCHEMICALS_WEIGHT,
            ModelParam::SpecialtyChemicalsWeight->value => self::SPECIALTY_CHEMICALS_WEIGHT,
            ModelParam::AgrochemicalsWeight->value      => self::AGROCHEMICALS_WEIGHT,
        ]);

        $pricingPower = $this->resolvePricingPower($stock);
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // Active stream weights with dynamic drift
        $activeWeights = $streams->resolveActiveStreamWeights([
            'base_petrochemicals' => $params[ModelParam::BasePetrochemicalsWeight],
            'specialty_chemicals' => $params[ModelParam::SpecialtyChemicalsWeight],
            'agrochemicals'       => $params[ModelParam::AgrochemicalsWeight],
        ]);

        $basePetroWeight  = $activeWeights['base_petrochemicals'];
        $specialtyWeight  = $activeWeights['specialty_chemicals'];
        $agriWeight       = $activeWeights['agrochemicals'];

        // Z-scores
        $basePetroZ  = $streams->generateZ('base_petrochemicals', self::BASE_PETRO_PERSISTENCE);
        $specialtyZ  = $streams->generateZ('specialty_chemicals', self::SPECIALTY_CHEM_PERSISTENCE);
        $agriZ       = $streams->generateZ('agrochemicals', self::AGROCHEM_PERSISTENCE);
        $feedstockZ  = $streams->generateZ('feedstock_crack', self::FEEDSTOCK_CRACK_PERSISTENCE);

        // 1. Base Petrochemicals: Cyclical stream idiosyncratic volume variance
        $basePetroShock = $basePetroZ * ($baselineVol * self::BASE_PETRO_VARIANCE);
        $basePetroRevenue = max(0.0, $expectedRevenue * $basePetroWeight * (1.0 + $basePetroShock));

        // 2. Specialty Chemicals: Defensive, sticky margins, patent moats, ignores short-term cyclicality
        $specialtyShock = $specialtyZ * ($baselineVol * self::SPECIALTY_CHEM_VARIANCE);
        $specialtyRevenue = max(0.0, $expectedRevenue * $specialtyWeight * (1.0 + $specialtyShock));

        // 3. Agrochemicals: Disconnected from business cycle, driven by agricultural weather jump diffusion
        $dt = 0.25; // Quarterly time step
        $weatherJump = $mathUtility->calculateJumpDiffusion(self::WEATHER_JUMP_LAMBDA, self::WEATHER_JUMP_MEAN, self::WEATHER_JUMP_VOL, $dt);
        $weatherMultiplier = $weatherJump['multiplier'];

        $agriShock = $agriZ * ($baselineVol * self::AGROCHEM_VARIANCE);
        $agriRevenue = max(0.0, $expectedRevenue * $agriWeight * (1.0 + $agriShock) * $weatherMultiplier);

        $streamRevenues = [
            'base_petrochemicals' => $basePetroRevenue,
            'specialty_chemicals' => $specialtyRevenue,
            'agrochemicals'       => $agriRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- Feedstock Cost Curve ---
        // Base chemicals clear at the marginal producer's cost: on the world ethylene cost curve that is the
        // oil-linked naphtha cracker (Masih, Algahtani & De Mello 2010: ethylene cointegrated with crude), passed
        // through at the energy marginal-cost rate (Ganapati, Shapiro & Walker 2020). The firm pays its own
        // ethane/naphtha slate, so a gas-advantaged cracker gains when oil rises against gas. Symmetric in sign.
        $outputGap = $macroState->outputGapEma;
        $agriShift = ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0;
        $oilFeedstockInflation = $macroState->energyCostPushLag / MacroEngine::ENERGY_COST_PUSH_TRANSMISSION;
        $gasFeedstockInflation = ($macroState->naturalGasPriceIndexEma - MacroEngine::NATURAL_GAS_BASELINE) / MacroEngine::NATURAL_GAS_BASELINE;
        $ownFeedstockInflation = (static::GAS_FEEDSTOCK_SHARE * $gasFeedstockInflation) + ((1.0 - static::GAS_FEEDSTOCK_SHARE) * $oilFeedstockInflation);

        $basePetroPriceRecovery = self::BASE_PETRO_MARGINAL_PASS_THROUGH * $oilFeedstockInflation;
        // Specialty chemicals reprice their own feedstock cost with pricing power.
        $specialtyPriceRecovery = $ownFeedstockInflation * min(1.0, self::SPECIALTY_PASS_THROUGH_RATIO * (0.50 + (0.50 * $pricingPower)));
        // Fertilizer prices recover the feedstock move only as far as farm prices move the same way.
        $agriPriceSupport = $agriShift * self::AGRI_PASS_THROUGH_SCALAR;
        $agriPriceRecovery = max(min(0.0, $ownFeedstockInflation), min(max(0.0, $ownFeedstockInflation), $agriPriceSupport));

        // Margin effect per stream: feedstock intensity x (own cost move - price recovery).
        $basePetroCostRatio  = ($realizedVariableMargin * self::BASE_PETRO_VARIABLE_COST_MULTIPLIER) + (($ownFeedstockInflation - $basePetroPriceRecovery) * self::ENERGY_FEEDSTOCK_INTENSITY);
        $specialtyCostRatio  = ($realizedVariableMargin * self::SPECIALTY_CHEM_VARIABLE_COST_MULTIPLIER) + (($ownFeedstockInflation - $specialtyPriceRecovery) * self::ENERGY_FEEDSTOCK_INTENSITY);
        $agriCostRatio       = ($realizedVariableMargin * self::AGROCHEM_VARIABLE_COST_MULTIPLIER) + (($ownFeedstockInflation - $agriPriceRecovery) * self::ENERGY_FEEDSTOCK_INTENSITY);

        $actualVariableCosts = ($basePetroRevenue * $basePetroCostRatio)
            + ($specialtyRevenue * $specialtyCostRatio)
            + ($agriRevenue * $agriCostRatio);

        $effectiveMargin = $actualRevenue > 0.0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;

        // Idiosyncratic cracker-spread shock, mean zero: a negative Z widens the cost ratio, a positive one narrows it.
        $feedstockDrag = -$feedstockZ * self::FEEDSTOCK_DRAG_SCALAR * $baselineVol;
        // Non-feedstock inputs (catalysts, packaging, logistics, plant payroll) come through the shared basket;
        // hydrocarbon feedstock is priced on the cost curve above.
        $ppiCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        $clampedMargin = $this->clampMargin($effectiveMargin + $feedstockDrag + $ppiCostDrag);

        // Shock events
        $eventType = null;
        if ($feedstockZ < self::CRACK_SPREAD_SQUEEZE_Z || ($ownFeedstockInflation > self::SEVERE_ENERGY_INFLATION_THRESHOLD && $outputGap < self::RECESSION_OUTPUT_GAP_THRESHOLD)) {
            $eventType = ShockEvent::CHEMICAL_CRACK_SPREAD_SQUEEZE;
        } elseif ($agriZ > self::AGRI_BOOM_Z && $weatherMultiplier > self::WEATHER_BOOM_MULTIPLIER_THRESHOLD) {
            $eventType = ShockEvent::CHEMICAL_AGRI_BOOM;
        }

        $primaryShockZ = $streams->resolveDominantShockZ([$basePetroZ, $specialtyZ, $agriZ, $feedstockZ]);
        $observableShockZ = ($basePetroZ * $basePetroWeight * self::BASE_PETRO_VARIANCE) +
            ($specialtyZ * $specialtyWeight * self::SPECIALTY_CHEM_VARIANCE) +
            ($agriZ * $agriWeight * self::AGROCHEM_VARIANCE);
        $observableShockZ *= $baselineVol;

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

    /** Physical corrosion and deferred maintenance downtime create margin decay */
    public function getDepreciationDecayRate(): float
    {
        return self::PLANT_DECAY_RATE;
    }

    /** Modernization and continuous flow chemical synthesis expansion */
    public function getModernizationGainRate(): float
    {
        return self::PLANT_MODERNIZATION_GAIN;
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
            'agricultural_commodity_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'industrial_metals_index_ema',
            'manufacturing_pmi',
            'natural_gas_price_index_ema',
            'output_gap_ema',
            'output_gap_lag_6m',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
