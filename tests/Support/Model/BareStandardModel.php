<?php

declare(strict_types=1);

namespace App\Tests\Support\Model;

use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\AbstractBusinessModel;

/**
 * The model base with nothing configured on top of it.
 *
 * Every hook constant the standard physics reads is declared once on AbstractBusinessModel with its default. Every
 * shipped sector model redeclares most of them, which means those defaults are the least-exercised values in the
 * operating physics despite being the behaviour a new sector model inherits on the day it is written. This model
 * redeclares only the sector factor loading, to keep the stack on the one-factor firm model, so a test can address
 * the defaults directly instead of hoping some sector happens to leave one unset.
 */
class BareStandardModel extends AbstractBusinessModel
{
    // --- Firm-Level Common Factor ---
    /** Loading on the persistent sector demand factor; zero keeps the bare stack on the one-factor model. */
    public const SECTOR_FACTOR_LOADING = 0.0;

    /** Dollars of the stub's revenue that are pure price, so a test can drive the price/volume cost split. */
    public float $stubPriceRevenue = 0.0;

    /**
     * The root's three operating steps, neutral: the capital and return as the stock holds them, no macro demand
     * shift or repricing, and the trailing return left where it is. Nothing a test observes of the shared physics
     * passes through them.
     */
    public function getTargetMetrics(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        return ['invested_capital' => $stock->getInvestedCapital(), 'baseline_roic' => (float) $stock->getBaselineRoic()];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        return ['macro_demand_shift' => 0.0, 'pricing_power_multiplier' => 1.0, 'input_cost_multiplier' => 1.0];
    }

    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?MacroStateDTO $macroState = null, float $depreciation = 0.0, float $afterTaxSpecialCharges = 0.0): float
    {
        return (float) $stock->getRoicTtm();
    }

    /**
     * The one member of the stack with no default: the template method requires each sector to say how its
     * own revenue is generated. Passing expected revenue and the given margin straight through keeps the
     * sector-specific step neutral, so anything a test observes downstream comes from the shared physics.
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        return new SectorPhysicsResult(
            actualRevenue: $expectedRevenue,
            rawVariableMargin: $realizedVariableMargin,
            streamRevenue: ['core_business' => $expectedRevenue],
            priceRevenue: $this->stubPriceRevenue,
        );
    }

    /**
     * Test-only reachers for the two protected members of the stack. They forward verbatim so a test can
     * address the shared physics directly rather than through whichever sector model happens to expose it.
     */
    public function exposeOperatingBase(Stock $stock): float
    {
        return $this->getOperatingBase($stock);
    }

    public function exposeInputCostDrag(Stock $stock, MacroStateDTO $macroState, \App\Service\Model\StreamContext $streams, float $pricingPower, float $realizedVariableMargin): float
    {
        return $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);
    }

    public function exposePricingPower(Stock $stock): float
    {
        return $this->resolvePricingPower($stock);
    }

    public function exposeStreamContext(array $momentum, MathUtility $mathUtility, ?MacroStateDTO $macroState = null, ?Stock $stock = null): \App\Service\Model\StreamContext
    {
        return $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
    }
}
