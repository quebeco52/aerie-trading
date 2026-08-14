<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Stress test matrix executing invariant checks across all registered business models.
 */
class BusinessModelContractMatrixTest extends TestCase
{
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
    }

    public static function businessModelProvider(): array
    {
        $dir = dirname(__DIR__, 3) . '/src/Service/Model';
        $files = glob($dir . '/*BusinessModel.php');
        $models = [];

        foreach ($files as $file) {
            $className = 'App\\Service\\Model\\' . basename($file, '.php');
            if (!class_exists($className)) {
                continue;
            }

            $ref = new ReflectionClass($className);
            if ($ref->isAbstract() || $ref->isTrait() || $ref->isInterface()) {
                continue;
            }

            $models[basename($file, '.php')] = [$className];
        }

        return $models;
    }

    #[DataProvider('businessModelProvider')]
    public function testExtremeMacroStressInvariants(string $modelClass): void
    {
        $model = new $modelClass();

        $stock = new Stock();
        $stock->setTicker('STRESS');
        $stock->setName('Stress Test Corp');
        $stock->setBeta('1.5');
        $stock->setTotalEquity('500000000');
        $stock->setWholesaleDebt('1000000000');
        $stock->setOperatingMargin('0.20');
        $stock->setCustomerDeposits('500000000');
        $stock->setBaselineRoe('0.15');
        $stock->setBaselineRoic('0.12');

        // Create extreme macro stress state: yield curve inversion, high inflation, severe recession
        $stressMacro = new MacroStateDTO(
            outputGapEma: -0.05, // -5% output gap depression
            inflationEma: 0.10, // 10% inflation
            policyRate: 0.07,
            policyRateEma: 0.07,
            yield2yEma: 0.05, // 300bps inversion
            yield5yEma: 0.04,
            yield10yEma: 0.02,
            marketVolatilityEma: 0.45, // 45% VIX panic
            macroCreditSpreadEma: 0.06 // 600bps credit spread blowout
        );

        // 1. Target Metrics
        $targetMetrics = $model->getTargetMetrics($stock, $stressMacro, $this->mathUtility);
        $this->assertIsArray($targetMetrics);
        $this->assertArrayHasKey('invested_capital', $targetMetrics);
        $this->assertArrayHasKey('baseline_roic', $targetMetrics);
        $this->assertTrue(is_finite($targetMetrics['invested_capital']));
        $this->assertTrue(is_finite($targetMetrics['baseline_roic']));
        $this->assertGreaterThan(0.0, $targetMetrics['invested_capital']);

        // 2. Coverage Profile
        $coverage = $model->getCoverageProfile($stock);
        $this->assertGreaterThanOrEqual(0.0, $coverage->baseVisibility);
        $this->assertLessThanOrEqual(1.0, $coverage->baseVisibility);
        $this->assertGreaterThanOrEqual(0.0, $coverage->minVisibility);
        $this->assertLessThanOrEqual($coverage->baseVisibility, $coverage->minVisibility);

        // 3. Compute Actual Financials under stress
        $actuals = $model->computeActualFinancials(
            $stock,
            expectedRevenue: 250000000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 50000000.0,
            baselineVol: 0.30,
            macroState: $stressMacro,
            mathUtility: $this->mathUtility
        );

        $this->assertTrue(is_finite($actuals->actualRevenue), "Revenue in {$modelClass} must be finite");
        $this->assertGreaterThanOrEqual(0.0, $actuals->actualRevenue, "Revenue in {$modelClass} must be non-negative");
        $this->assertTrue(is_finite($actuals->actualVariableCosts), "Variable costs in {$modelClass} must be finite");
        $this->assertTrue(is_finite($actuals->ebit), "EBIT in {$modelClass} must be finite");

        // 4. Dynamic ROIC / ROE Update Invariant
        $ret = $model->updateDynamicRoic(
            $stock,
            actualTotalNetIncome: -20000000.0, // Loss quarter
            investedCapital: 1000000000.0,
            ebit: $actuals->ebit,
            corporateTaxRate: 0.21,
            wacc: 0.08,
            costOfEquity: 0.10,
            macroState: $stressMacro
        );

        $this->assertTrue(is_finite($ret), "Return in {$modelClass} must be finite");

        $roeTtm = (float) $stock->getRoeTtm();
        $roicTtm = (float) $stock->getRoicTtm();
        $this->assertTrue(is_finite($roeTtm), "ROE TTM in {$modelClass} must be finite");
        $this->assertTrue(is_finite($roicTtm), "ROIC TTM in {$modelClass} must be finite");
        $this->assertGreaterThanOrEqual(-0.50, $roeTtm);
        $this->assertLessThanOrEqual(1.00, $roeTtm);
        $this->assertGreaterThanOrEqual(-0.50, $roicTtm);
        $this->assertLessThanOrEqual(1.00, $roicTtm);

        // 5. Interest Calculation Invariant
        $debtCalc = $model->calculateInterestExpenseAndWholesaleRate(
            $stock,
            blendedFixedRate: 0.05,
            floatingInterestRate: 0.08,
            currentMarketFixedRate: 0.07,
            policyRate: 0.07,
            equityLimit: 5.0,
            totalEquity: 500000000.0,
            debt: 1000000000.0
        );

        $this->assertIsArray($debtCalc);
        $this->assertArrayHasKey('interest_expense', $debtCalc);
        $this->assertArrayHasKey('wholesale_rate', $debtCalc);
        $this->assertTrue(is_finite($debtCalc['interest_expense']));
        $this->assertTrue(is_finite($debtCalc['wholesale_rate']));
        $this->assertGreaterThanOrEqual(0.0, $debtCalc['interest_expense']);
    }
}
