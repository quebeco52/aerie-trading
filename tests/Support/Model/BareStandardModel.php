<?php

declare(strict_types=1);

namespace App\Tests\Support\Model;

use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Trait\StandardBaseModelTrait;
use App\Service\Model\Trait\StandardCapitalAllocationTrait;
use App\Service\Model\Trait\StandardDebtPhysicsTrait;
use App\Service\Model\Trait\StandardMaTrait;
use App\Service\Model\Trait\StandardOperatingPhysicsTrait;
use App\Service\Model\Trait\StandardTreasuryTrait;
use App\Service\Model\Trait\StandardValuationTrait;

/**
 * The standard trait stack composed with nothing configured on top of it.
 *
 * Almost every default in these traits sits behind a `defined('static::CONST')` gate, so the fallback
 * branch runs only for a model that declines to declare the constant. Every shipped sector model declares
 * most of them, which means those fallbacks are the least-exercised code in the operating physics despite
 * being the behaviour a new sector model inherits on the day it is written. This composer declares only
 * FIRM_FACTOR_LOADING (the one constant the stack reads ungated) so a test can address the defaults
 * directly instead of hoping some sector happens to leave one unset.
 */
class BareStandardModel
{
    use StandardBaseModelTrait;
    use StandardOperatingPhysicsTrait;
    use StandardTreasuryTrait;
    use StandardCapitalAllocationTrait;
    use StandardDebtPhysicsTrait;
    use StandardMaTrait;
    use StandardValuationTrait;

    /** The only constant the standard stack reads without a `defined()` gate. */
    public const FIRM_FACTOR_LOADING = 0.60;

    /** Dollars of the stub's revenue that are pure price, so a test can drive the price/volume cost split. */
    public float $stubPriceRevenue = 0.0;

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
            primaryShockZ: 0.0,
            observableShockZ: 0.0,
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

    public function exposeInputCostDrag(Stock $stock, MacroStateDTO $macroState, \App\DTO\StreamContext $streams, float $pricingPower, float $realizedVariableMargin): float
    {
        return $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);
    }

    public function exposePricingPower(Stock $stock): float
    {
        return $this->resolvePricingPower($stock);
    }

    public function exposeStreamContext(array $momentum, MathUtility $mathUtility, ?MacroStateDTO $macroState = null, ?Stock $stock = null): \App\DTO\StreamContext
    {
        return $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
    }
}
