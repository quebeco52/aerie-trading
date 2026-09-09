<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Service\Model\Strategy\OperatingStrategyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Keeps every model's getOperatingMacroFields() declaration honest against the MacroStateDTO fields its
 * operating physics actually reads. The district conduit map is derived from these declarations, so a
 * stale list silently draws (or hides) institution-to-model edges on the map.
 *
 * The rule mirrors OperatingStrategyInterface: fields read by the model's own operating code
 * (calculateSectorPhysics, getMacroPhysics, calculateInterestIncome, processPassiveLiabilityGrowth and the
 * helpers they call), following parent:: delegation, minus the WACC-only reads. Generic trait code is not
 * model-specific coupling and is skipped.
 */
final class BusinessModelMacroFieldDeclarationTest extends TestCase
{
    /** @var list<string> */
    private const OPERATING_METHODS = ['calculateSectorPhysics', 'getMacroPhysics', 'calculateInterestIncome', 'processPassiveLiabilityGrowth'];

    /** Valuation-only reads feeding WACC alone, excluded by the interface contract. */
    private const VALUATION_ONLY_FIELDS = ['equity_risk_premium', 'corporate_tax_rate', 'policy_rate'];

    /**
     * Accessors computed from the state rather than published series. They are not fields any institution
     * reports, so they are neither declarable nor conduit-drawing — calendarQuarter() is elapsed time over
     * a quarter, not a macro observation.
     */
    private const DERIVED_ACCESSORS = ['calendar_quarter'];

    /** Macro field each input-cost basket channel reads (StandardOperatingPhysicsTrait::resolveInputPriceDeviations). */
    private const BASKET_CHANNEL_FIELDS = [
        'energy'  => 'energy_cost_push_lag',
        'metals'  => 'industrial_metals_index_ema',
        'agri'    => 'agricultural_commodity_index_ema',
        'freight' => 'freight_rate_index_ema',
        'ppi'     => 'producer_price_inflation_ema',
        'labor'   => 'wage_growth_ema',
    ];

    /**
     * @return array<string, array{class-string<OperatingStrategyInterface>}>
     */
    public static function businessModelProvider(): array
    {
        $dir = dirname(__DIR__, 3) . '/src/Service/Model/Sector';
        $models = [];

        foreach (glob($dir . '/*BusinessModel.php') ?: [] as $file) {
            /** @var class-string<OperatingStrategyInterface> $className */
            $className = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            $ref = new ReflectionClass($className);
            if ($ref->isAbstract()) {
                continue;
            }
            $models[basename($file, '.php')] = [$className];
        }

        return $models;
    }

    /**
     * @param class-string<OperatingStrategyInterface> $modelClass
     */
    #[DataProvider('businessModelProvider')]
    public function testDeclaredOperatingMacroFieldsMatchSourceReads(string $modelClass): void
    {
        $model = new $modelClass();
        $declared = array_values(array_unique($model->getOperatingMacroFields()));
        sort($declared);

        $actual = $this->collectOperatingMacroReads($modelClass);

        // The input cost basket is generic trait code, but WHICH markets it reads is declared per model through
        // INPUT_COST_EXPOSURES, so a model that invokes the basket couples to every channel it has exposure to.
        // Likewise the pricing pass-through is trait code, but the inflation measure it tracks is declared per
        // model through PRICING_INFLATION_BASIS.
        if ($this->invokesHelper($modelClass, 'resolvePricingMultipliers(') && defined($modelClass . '::PRICING_INFLATION_BASIS')) {
            $actual[] = (string) constant($modelClass . '::PRICING_INFLATION_BASIS');
            $actual = array_values(array_unique($actual));
        }

        // FX is trait code too, but WHETHER a model is exposed at all is a per-model declaration
        // (FX_REVENUE_EXPOSURE), so invoking the helper is a genuine coupling to the exchange rate.
        if ($this->invokesHelper($modelClass, 'resolveFxDemandShift(')) {
            $actual[] = 'exchange_rate_index_ema';
            $actual = array_values(array_unique($actual));
        }

        // Same for the demand transmission lag: the helper lives in a trait, but a model that reads the
        // cycle through it is reading the output gap however long the delay it declares.
        if ($this->invokesHelper($modelClass, 'resolveLaggedOutputGap(')) {
            $actual[] = 'output_gap_ema';
            $actual = array_values(array_unique($actual));
        }

        if ($this->invokesInputCostBasket($modelClass) && method_exists($model, 'getInputCostExposures')) {
            foreach ($model->getInputCostExposures() as $channel => $share) {
                if ($share > 0.0 && isset(self::BASKET_CHANNEL_FIELDS[$channel])) {
                    $actual[] = self::BASKET_CHANNEL_FIELDS[$channel];
                }
            }
            $actual = array_values(array_unique($actual));
        }
        sort($actual);

        $this->assertSame($actual, $declared, sprintf(
            "%s declares operating macro fields that drift from its source.\nRead but not declared: [%s]\nDeclared but never read: [%s]",
            $modelClass,
            implode(', ', array_diff($actual, $declared)),
            implode(', ', array_diff($declared, $actual))
        ));
    }

    /**
     * Whether the model's own operating physics (following parent delegation) calls the shared basket.
     *
     * @param class-string $concreteClass
     */
    private function invokesInputCostBasket(string $concreteClass): bool
    {
        return $this->invokesHelper($concreteClass, 'resolveInputCostDrag(');
    }

    /**
     * Whether the model's own operating physics (following parent and helper delegation, traits excluded)
     * contains a call to the named shared helper.
     *
     * @param class-string $concreteClass
     */
    private function invokesHelper(string $concreteClass, string $needle): bool
    {
        $fields = [];
        $visited = [];
        foreach (self::OPERATING_METHODS as $method) {
            $this->walk($concreteClass, $concreteClass, $method, $fields, $visited);
        }

        foreach (array_keys($visited) as $key) {
            [$class, , $method] = explode('::', $key);
            if (str_contains($class, '\\Trait\\') || !method_exists($class, $method)) {
                continue;
            }
            if (str_contains($this->methodSource(new ReflectionMethod($class, $method)), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param class-string $concreteClass
     * @return list<string>
     */
    private function collectOperatingMacroReads(string $concreteClass): array
    {
        $fields = [];
        $visited = [];
        foreach (self::OPERATING_METHODS as $method) {
            $this->walk($concreteClass, $concreteClass, $method, $fields, $visited);
        }

        $snake = array_map([$this, 'canonicalKey'], $fields);

        return array_values(array_unique(array_diff($snake, self::VALUATION_ONLY_FIELDS, self::DERIVED_ACCESSORS)));
    }

    /**
     * Maps a MacroStateDTO property name to the snake_case key MacroStateDTO::toArray() emits for it,
     * which is the vocabulary DistrictConduitResolver and every declaration use.
     */
    private function canonicalKey(string $property): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (array_keys((new MacroStateDTO())->toArray()) as $key) {
                $map[strtolower(str_replace('_', '', $key))] = $key;
            }
        }

        return $map[strtolower($property)] ?? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));
    }

    /**
     * @param class-string $concreteClass Class whose late-bound $this-> helpers resolve.
     * @param class-string $ownerClass    Class level whose implementation of $method is being read.
     * @param list<string> $fields
     * @param array<string, bool> $visited
     */
    private function walk(string $concreteClass, string $ownerClass, string $method, array &$fields, array &$visited): void
    {
        if (!method_exists($ownerClass, $method)) {
            return;
        }

        $reflection = new ReflectionMethod($ownerClass, $method);
        $key = $reflection->getDeclaringClass()->getName() . '::' . $reflection->getFileName() . '::' . $method;
        if (isset($visited[$key])) {
            return;
        }
        $visited[$key] = true;

        // Generic physics shared by every model (traits) is not a model-specific macro coupling.
        if (str_contains((string) $reflection->getFileName(), '/Service/Model/Trait/')) {
            return;
        }

        $body = $this->methodSource($reflection);

        $macroVariable = 'macroState';
        if (preg_match('/MacroStateDTO\s+\$([a-zA-Z0-9_]+)/', $body, $signature) === 1) {
            $macroVariable = $signature[1];
        }
        if (preg_match_all('/\$' . preg_quote($macroVariable, '/') . '->([a-zA-Z0-9]+)/', $body, $reads) > 0) {
            foreach ($reads[1] as $field) {
                $fields[] = $field;
            }
        }

        // Helpers invoked on $this resolve late-bound against the concrete class.
        if (preg_match_all('/\$this->([a-zA-Z0-9_]+)\(/', $body, $helpers) > 0) {
            foreach (array_unique($helpers[1]) as $helper) {
                if ($helper === $method || !method_exists($concreteClass, $helper)) {
                    continue;
                }
                $helperOwner = (new ReflectionMethod($concreteClass, $helper))->getDeclaringClass()->getName();
                $this->walk($concreteClass, $helperOwner, $helper, $fields, $visited);
            }
        }

        // parent::method() delegation continues one level up the hierarchy.
        $parent = $reflection->getDeclaringClass()->getParentClass();
        if ($parent !== false && str_contains($body, 'parent::' . $method . '(')) {
            $this->walk($concreteClass, $parent->getName(), $method, $fields, $visited);
        }
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if ($file === false || $start === false || $end === false) {
            return '';
        }

        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
