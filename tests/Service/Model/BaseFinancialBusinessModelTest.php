<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Model\Sector\BaseFinancialBusinessModel;
use PHPUnit\Framework\TestCase;

class BaseFinancialBusinessModelTest extends TestCase
{
    private BaseFinancialBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new class extends BaseFinancialBusinessModel {
            public function getModelThresholds(): array
            {
                return [
                    'min_icr' => 1.05,
                    'bankrupt_equity' => 2.0,
                    'distress_equity' => 4.0,
                    'warning_equity' => 6.0,
                    'wholesale_leverage_limit' => 2.0,
                    'dividend_crisis_icr' => 1.05,
                    'buyback_min_icr' => 1.15,
                    'reversion_speed' => 0.18,
                    'moat_spread' => 0.010,
                    'nwc_intensity' => 0.0,
                    'capex_completion_rate' => 1.0,
                ];
            }

            public function getCoverageProfile(Stock $stock): \App\DTO\SectorCoverageProfile
            {
                return new \App\DTO\SectorCoverageProfile(0.65, 0.06);
            }

            public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
            {
                return [];
            }

            public function getSecularGrowthRate(Stock $stock): float
            {
                return 0.02;
            }

            public function getCapexCyclicality(): float
            {
                return 0.0;
            }

            public function getSurpriseBlendWeights(): array
            {
                return ['vol' => 0.5, 'perf' => 0.5];
            }

            protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, \App\Service\Math\MathUtility $mathUtility): \App\DTO\SectorPhysicsResult
            {
                return new \App\DTO\SectorPhysicsResult($expectedRevenue, $realizedVariableMargin, $fixedCosts, $baselineVol);
            }

            public function computeActualFinancials(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, \App\Service\Math\MathUtility $mathUtility): \App\DTO\ActualFinancialsDTO
            {
                return new \App\DTO\ActualFinancialsDTO(100.0, 50.0, 20.0, 30.0, 30.0, 30.0, 0.0, 0.0);
            }

            public function getTargetMetrics(Stock $stock, \App\DTO\MacroStateDTO $macroState, \App\Service\Math\MathUtility $mathUtility): array
            {
                return ['baseline_roic' => 0.12, 'invested_capital' => 1000.0];
            }

            public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
            {
                return ['interest_expense' => 50.0, 'wholesale_rate' => 0.05];
            }
        };
    }

    public function testFinancialEvaluationCapitalEvaluatesOnEquity(): void
    {
        $equity = 5000.0;
        $investedCapital = 12000.0;

        // Financial institutions evaluate scale on Equity rather than Invested Capital (FinancialPhysicsTrait override)
        $evalCapital = $this->model->getEvaluationCapital($equity, $investedCapital);
        $this->assertEquals($equity, $evalCapital);
    }

    public function testFinancialTrueReturnReturnsRoeTtm(): void
    {
        $stock = new Stock();
        $stock->setRoeTtm('0.1450');
        $stock->setRoicTtm('0.0800');

        // Financial physics returns ROE instead of ROIC
        $this->assertEquals(0.1450, $this->model->getTrueReturn($stock));
    }

    public function testFinancialOrganicGrowthSpeedIsRestricted(): void
    {
        // Financial physics returns 0.12 (normal), 0.20 (hoarder), 0.35 (mega hoarder)
        $this->assertEquals(0.12, $this->model->getMaxOrganicGrowthSpeed(false, false));
        $this->assertEquals(0.20, $this->model->getMaxOrganicGrowthSpeed(true, false));
        $this->assertEquals(0.35, $this->model->getMaxOrganicGrowthSpeed(true, true));
    }

    public function testValuationStrategyCalculation(): void
    {
        $earningsValue = 100.0;
        $pbFairValue = 80.0;
        $normalizedEps = 5.0;

        $fairValue = $this->model->calculateFairValue($earningsValue, $pbFairValue, $normalizedEps, 0.0);
        // (100 * 0.90) + (80 * 0.10) = 90 + 8 = 98.0
        $this->assertEquals(98.0, $fairValue);
    }
}
