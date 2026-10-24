<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Macro\Recorder\OutputGapProbe;
use App\Service\Macro\Subsystem\Aggregate\ConsumerPrices;
use App\Service\Macro\Subsystem\Aggregate\DomesticDemand;
use App\Service\Macro\Subsystem\Aggregate\IndustryIndicators;
use App\Service\Macro\Subsystem\Aggregate\MovingAverages;
use App\Service\Macro\Subsystem\Aggregate\NationalAccounts;
use App\Service\Macro\Subsystem\Aggregate\OutputGap;
use App\Service\Macro\Subsystem\Aggregate\ProductivityGrowth;
use App\Service\Math\MathUtility;

/**
 * The core macroeconomic aggregate loop, in the order MacroEngine steps it: productivity and the natural rate
 * (ProductivityGrowth), breakeven inflation, the capital stock overhang and the output gap (DomesticDemand, OutputGap,
 * with InventoryAndTiming and OpenEconomy beside it), the industry readings (IndustryIndicators), consumer prices
 * (ConsumerPrices), the smoothing filters (MovingAverages) and the national accounts (NationalAccounts).
 */
class MacroAggregateSubsystem
{
    public function __construct(
        private readonly ProductivityGrowth $productivity,
        private readonly OutputGap $outputGap,
        private readonly ConsumerPrices $consumerPrices,
        private readonly IndustryIndicators $industry,
    ) {}

    /**
     * The subsystem as a test or a headless harness builds it: every part drawing from one generator, and the probes
     * when handed in.
     */
    public static function headless(MathUtility $math, ?OutputGapProbe $gapProbe = null, ?MacroDiagnosticsProbe $diagnostics = null): self
    {
        return new self(
            new ProductivityGrowth($math, $diagnostics),
            new OutputGap($math, $gapProbe, $diagnostics),
            new ConsumerPrices($math, $diagnostics),
            new IndustryIndicators($math),
        );
    }

    /** Steps TFP's level and returns its trend growth (ProductivityGrowth::calculateTotalFactorProductivity()). */
    public function calculateTotalFactorProductivity(MacroState $state, float $dt): float
    {
        return $this->productivity->calculateTotalFactorProductivity($state, $dt);
    }

    /**
     * Steps output's and potential's absorption of TFP shocks and returns the productivity growth potential is built on
     * (ProductivityGrowth::absorbProductivityShocks()).
     */
    public function absorbProductivityShocks(MacroState $state, float $trendRate, float $dt): float
    {
        return $this->productivity->absorbProductivityShocks($state, $trendRate, $dt);
    }

    /** Steps the natural rate toward the one trend growth sets (ProductivityGrowth::calculateNaturalRate()). */
    public function calculateNaturalRate(MacroState $state, float $trendGrowthRate, float $dt): void
    {
        $this->productivity->calculateNaturalRate($state, $trendGrowthRate, $dt);
    }

    /** The ten-year breakeven, expected inflation without the quote's liquidity premium (ConsumerPrices). */
    public function calculateTipsBreakeven(MacroState $state, float $targetInflation): float
    {
        return $this->consumerPrices->calculateTipsBreakeven($state, $targetInflation);
    }

    /** Steps the capital half of the Kaldor-Kalecki cycle, before the gap reads it (DomesticDemand). */
    public function updateCapitalStockOverhang(MacroState $state, float $dt): void
    {
        DomesticDemand::updateCapitalStockOverhang($state, $dt);
    }

    /** Steps the output gap and returns it (OutputGap::calculateOutputGap()). */
    public function calculateOutputGap(MacroState $state, float $yield5y, float $naturalRate, float $expectedInflation, float $dt, float $stressMultiplier): float
    {
        return $this->outputGap->calculateOutputGap($state, $yield5y, $naturalRate, $expectedInflation, $dt, $stressMultiplier);
    }

    /** Capacity utilisation off the gap and the capital overhang (IndustryIndicators). */
    public function calculateCapacityUtilization(MacroState $state): void
    {
        $this->industry->calculateCapacityUtilization($state);
    }

    /** The ten-year breakeven the market quotes, with the TIPS liquidity premium (ConsumerPrices). */
    public static function quotedTipsBreakeven(float $breakeven, float $excessBondPremium): float
    {
        return ConsumerPrices::quotedTipsBreakeven($breakeven, $excessBondPremium);
    }

    /** Steps services repricing on their own schedules (ConsumerPrices). */
    public function calculateServicesRepricing(MacroState $state, float $dt): void
    {
        $this->consumerPrices->calculateServicesRepricing($state, $dt);
    }

    /** Steps the three baskets and returns headline inflation (ConsumerPrices::calculateInflation()). */
    public function calculateInflation(MacroState $state, float $targetInflation, float $productivityGrowthRate, float $dt): float
    {
        return $this->consumerPrices->calculateInflation($state, $targetInflation, $productivityGrowthRate, $dt);
    }

    /** Factory-gate inflation (IndustryIndicators::calculateProducerPriceInflation()). */
    public function calculateProducerPriceInflation(MacroState $state, float $tfpGrowthRate, float $dt): void
    {
        $this->industry->calculateProducerPriceInflation($state, $tfpGrowthRate, $dt);
    }

    /** Steps the manufacturing PMI (IndustryIndicators::calculateManufacturingPmi()). */
    public function calculateManufacturingPmi(MacroState $state, float $dt): void
    {
        $this->industry->calculateManufacturingPmi($state, $dt);
    }

    /** Steps every indicator's EMA and measured trend (MovingAverages). */
    public function updateExponentialMovingAverages(MacroState $state, float $dt): void
    {
        MovingAverages::updateExponentialMovingAverages($state, $dt);
    }

    /**
     * Accumulates potential and nominal GDP and the price levels (NationalAccounts), stepping TFP first when no growth
     * rate is handed in.
     */
    public function calculatePotentialAndNominalGdp(MacroState $state, float $dt, ?float $tfpGrowthRate = null): void
    {
        if ($tfpGrowthRate === null) {
            $tfpGrowthRate = $this->calculateTotalFactorProductivity($state, $dt);
        }

        NationalAccounts::calculatePotentialAndNominalGdp($state, $dt, $tfpGrowthRate);
    }

    /** The statistics office's quarterly real GDP and recession call (NationalAccounts). */
    public static function recordQuarterlyRealGdp(MacroState $state, float $dt): void
    {
        NationalAccounts::recordQuarterlyRealGdp($state, $dt);
    }
}
