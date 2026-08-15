<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Data\CeoArchetypes;
use App\Entity\Stock;
use App\Service\Archetype\BoardGovernanceDecorator;
use App\Service\Archetype\CannibalArchetype;
use App\Service\Archetype\ConglomerateArchetype;
use App\Service\Archetype\ConservativeArchetype;
use App\Service\Archetype\CostCutterArchetype;
use App\Service\Archetype\DealmakerArchetype;
use App\Service\Archetype\EmpireBuilderArchetype;
use App\Service\Archetype\OpportunistArchetype;
use App\Service\Archetype\TurnaroundArchetype;
use App\Service\Archetype\VisionaryArchetype;
use App\Service\Archetype\YieldKingArchetype;
use PHPUnit\Framework\TestCase;

class CeoArchetypeTest extends TestCase
{
    private function createHealthyStock(string $archetype, string $industry = 'Software'): Stock
    {
        $stock = new Stock();
        $stock->setName('Test Corp');
        $stock->setTicker('TEST');
        $stock->setCeoArchetype($archetype);
        $stock->setIndustry($industry);
        $stock->setTotalRevenue('10000000000.00'); // 10B
        $stock->setOperatingMargin('0.25'); // 25% margin -> 2.5B EBIT
        $stock->setTotalEquity('20000000000.00'); // 20B Equity
        $stock->setWholesaleDebt('2000000000.00'); // 2B Debt -> healthy low leverage
        $stock->setCustomerDeposits('0.00');
        $stock->setCorporateTreasury('5000000000.00'); // 5B Cash
        $stock->setSharesOutstanding('100000000');
        $stock->setPrice('100.00'); // 10B Market Cap
        $stock->setRetainedEarnings('5000000000.00');

        return $stock;
    }

    public function testArchetypeInstantiations(): void
    {
        $map = [
            CeoArchetypes::OPPORTUNIST => OpportunistArchetype::class,
            CeoArchetypes::EMPIRE_BUILDER => EmpireBuilderArchetype::class,
            CeoArchetypes::CANNIBAL => CannibalArchetype::class,
            CeoArchetypes::YIELD_KING => YieldKingArchetype::class,
            CeoArchetypes::CONSERVATIVE => ConservativeArchetype::class,
            CeoArchetypes::VISIONARY => VisionaryArchetype::class,
            CeoArchetypes::CONGLOMERATE => ConglomerateArchetype::class,
            CeoArchetypes::DEALMAKER => DealmakerArchetype::class,
            CeoArchetypes::TURNAROUND => TurnaroundArchetype::class,
            CeoArchetypes::COST_CUTTER => CostCutterArchetype::class,
        ];

        foreach ($map as $archetypeKey => $expectedClass) {
            $stock = $this->createHealthyStock($archetypeKey);
            $strategy = CeoArchetypes::getStrategy($stock);
            $this->assertInstanceOf($expectedClass, $strategy, "Archetype {$archetypeKey} should instantiate {$expectedClass}");
        }
    }

    public function testBoardGovernanceDecoratorTriggersInDistressForCorporates(): void
    {
        $stock = new Stock();
        $stock->setName('Distressed Corp');
        $stock->setTicker('DIST');
        $stock->setCeoArchetype(CeoArchetypes::EMPIRE_BUILDER);
        $stock->setIndustry('Software');
        $stock->setTotalRevenue('1000000.00');
        $stock->setOperatingMargin('-0.50'); // Negative EBIT
        $stock->setTotalEquity('1000000.00');
        $stock->setWholesaleDebt('50000000.00'); // Massive debt -> Altman Z < 1.81
        $stock->setCustomerDeposits('0.00');
        $stock->setCorporateTreasury('10000.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setPrice('0.50');
        $stock->setRetainedEarnings('-10000000.00');

        $strategy = CeoArchetypes::getStrategy($stock);
        $this->assertInstanceOf(BoardGovernanceDecorator::class, $strategy);

        // Verify board override policies
        $this->assertSame(0.0, $strategy->modifyTargetPayoutRatio(0.50));
        $this->assertFalse($strategy->shouldResistDividendCut(false, false, false));
        $this->assertSame(0.0, $strategy->modifyBuybackAggression(1.0));
        $this->assertSame(0.0, $strategy->modifyAcquisitionAggression(1.0));
        $this->assertEqualsWithDelta(0.075, $strategy->modifyInvestmentProbability(0.50, 0.10), 0.001);
        $this->assertGreaterThan(100.0, $strategy->modifyTargetOperatingCash(100.0));
    }

    public function testBoardGovernanceDoesNotTriggerOnFinancialsDueToStructuralLeverage(): void
    {
        $stock = new Stock();
        $stock->setName('Bank Corp');
        $stock->setTicker('BANK');
        $stock->setCeoArchetype(CeoArchetypes::CONSERVATIVE);
        $stock->setIndustry('Banks - Diversified');
        $stock->setTotalRevenue('5000000000.00');
        $stock->setOperatingMargin('0.30');
        $stock->setTotalEquity('10000000000.00');
        $stock->setWholesaleDebt('5000000000.00');
        $stock->setCustomerDeposits('85000000000.00'); // 85B deposits (high leverage is standard for banks)
        $stock->setCorporateTreasury('10000000000.00');
        $stock->setSharesOutstanding('100000000');
        $stock->setPrice('50.00');
        $stock->setRetainedEarnings('3000000000.00');

        $strategy = CeoArchetypes::getStrategy($stock);
        $this->assertInstanceOf(ConservativeArchetype::class, $strategy);
    }

    public function testOpportunistArchetypeDefaults(): void
    {
        $archetype = new OpportunistArchetype();

        $this->assertSame(100.0, $archetype->modifyTargetOperatingCash(100.0));
        $this->assertSame(0.40, $archetype->modifyTargetPayoutRatio(0.40));
        $this->assertFalse($archetype->shouldResistDividendCut(false, false));
        $this->assertSame(0.50, $archetype->modifyBuybackAggression(0.50));
        $this->assertSame(1.0, $archetype->modifyAcquisitionAggression(1.0));
        $this->assertSame(['min' => 0.9, 'max' => 1.1], $archetype->modifyMAndASynergyRange(0.9, 1.1));
    }

    public function testEmpireBuilderArchetype(): void
    {
        $archetype = new EmpireBuilderArchetype();

        $this->assertGreaterThan(0.50, $archetype->modifyDebtToleranceLimit(0.50, 0.05));
        $this->assertSame(0.75, $archetype->modifyInvestmentProbability(0.50, 0.10));
        $this->assertSame(0.05, $archetype->modifySaturationPenalty(0.10));
        $this->assertSame(5.0, $archetype->modifyAcquisitionAggression(1.0));
        $this->assertSame(0.05, $archetype->modifyBuybackAggression(0.50));

        $synergy = $archetype->modifyMAndASynergyRange(1.0, 1.0);
        $this->assertLessThan(1.0, $synergy['min']);
        $this->assertLessThan(1.0, $synergy['max']);
    }

    public function testCannibalArchetype(): void
    {
        $archetype = new CannibalArchetype();

        $this->assertSame(0.75, $archetype->modifyBuybackAggression(0.50));
        $this->assertSame(0.35, $archetype->modifyTargetPayoutRatio(0.50));
        $this->assertEqualsWithDelta(0.425, $archetype->modifyInvestmentProbability(0.50, 0.10), 0.001);
        $this->assertFalse($archetype->shouldResistDividendCut(false, false));
    }

    public function testYieldKingArchetype(): void
    {
        $archetype = new YieldKingArchetype();

        $this->assertSame(0.60, $archetype->modifyTargetPayoutRatio(0.50));
        $this->assertTrue($archetype->shouldResistDividendCut(false, false));
        $this->assertFalse($archetype->shouldResistDividendCut(true, false)); // Cannot resist liquidity crisis
        $this->assertSame(0.25, $archetype->modifyBuybackAggression(0.50));
    }

    public function testConservativeArchetype(): void
    {
        $archetype = new ConservativeArchetype();

        $this->assertSame(150.0, $archetype->modifyTargetOperatingCash(100.0));
        $this->assertLessThan(0.50, $archetype->modifyDebtToleranceLimit(0.50, 0.05));
        $this->assertTrue($archetype->shouldResistDividendCut(false, false, false));
        $this->assertFalse($archetype->shouldResistDividendCut(false, false, true)); // Cuts in deep distress
    }

    public function testVisionaryArchetype(): void
    {
        $archetype = new VisionaryArchetype();

        $this->assertSame(0.0, $archetype->modifyTargetPayoutRatio(0.50));
        $this->assertFalse($archetype->shouldResistDividendCut(false, false));
        $this->assertEqualsWithDelta(0.90, $archetype->modifyInvestmentProbability(0.30, 0.10), 0.001);
        $this->assertSame(0.10, $archetype->modifyBuybackAggression(0.50));
    }

    public function testConglomerateArchetype(): void
    {
        $archetype = new ConglomerateArchetype();

        $this->assertSame(200.0, $archetype->modifyTargetOperatingCash(100.0));
        $this->assertSame(0.25, $archetype->modifyAcquisitionAggression(1.0));
        $this->assertSame(0.25, $archetype->modifyInvestmentProbability(0.50, 0.10));

        $synergy = $archetype->modifyMAndASynergyRange(1.0, 1.0);
        $this->assertGreaterThan(1.0, $synergy['min']);
        $this->assertGreaterThan(1.0, $synergy['max']);
    }

    public function testDealmakerArchetype(): void
    {
        $archetype = new DealmakerArchetype();

        $this->assertSame(2.5, $archetype->modifyAcquisitionAggression(1.0));
        $this->assertGreaterThan(0.50, $archetype->modifyDebtToleranceLimit(0.50, 0.05));

        $synergy = $archetype->modifyMAndASynergyRange(1.0, 1.0);
        $this->assertLessThan(1.0, $synergy['min']);
        $this->assertGreaterThan(1.0, $synergy['max']);
    }

    public function testTurnaroundArchetype(): void
    {
        $archetype = new TurnaroundArchetype();

        $this->assertSame(0.10, $archetype->modifyTargetPayoutRatio(0.50));
        $this->assertFalse($archetype->shouldResistDividendCut(false, false));
        $this->assertSame(0.05, $archetype->modifyBuybackAggression(0.50));
        $this->assertSame(0.10, $archetype->modifyAcquisitionAggression(1.0));
        $this->assertSame(150.0, $archetype->modifyTargetOperatingCash(100.0));
    }

    public function testCostCutterArchetype(): void
    {
        $archetype = new CostCutterArchetype();

        $this->assertSame(0.25, $archetype->modifyInvestmentProbability(0.50, 0.10));
        $this->assertSame(0.20, $archetype->modifyAcquisitionAggression(1.0));
        $this->assertSame(0.625, $archetype->modifyBuybackAggression(0.50));
        $this->assertSame(150.0, $archetype->modifyTargetOperatingCash(100.0));
    }
}
