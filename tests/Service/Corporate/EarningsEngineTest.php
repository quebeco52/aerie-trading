<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use PHPUnit\Framework\TestCase;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Math\MathUtility;
use App\Service\Math\CorporateMetrics;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\MarketConsensusEngine;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class EarningsEngineTest extends TestCase
{
    private EarningsEngine $earningsEngine;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
        $corporateMetrics = new CorporateMetrics();
        $debtEngine = new DebtEngine($this->mathUtility, $corporateMetrics);
        $capExEngine = new CapExEngine();
        $marketConsensusEngine = new MarketConsensusEngine();

        $ledgerMock = $this->createStub(CorporateLedgerService::class);
        $eventPublisherMock = $this->createStub(MarketEventPublisher::class);
        $narrativeEngineMock = $this->createStub(NarrativeEngine::class);
        $eventDispatcherMock = $this->createStub(EventDispatcherInterface::class);

        $treasuryEngine = new TreasuryEngine(
            $corporateMetrics,
            $debtEngine,
            $capExEngine,
            $this->mathUtility
        );

        $capitalAllocationEngine = new CapitalAllocationEngine(
            $ledgerMock,
            $corporateMetrics,
            $debtEngine,
            $this->mathUtility,
            $treasuryEngine
        );

        $this->earningsEngine = new EarningsEngine(
            $eventDispatcherMock,
            $eventPublisherMock,
            $capitalAllocationEngine,
            $debtEngine,
            $capExEngine,
            $this->mathUtility,
            $corporateMetrics,
            $narrativeEngineMock,
            $marketConsensusEngine
        );
    }

    public function testConsecutiveContractionsDoNotCollapseStructuralMargin(): void
    {
        $stock = new Stock();
        $stock->setTicker('AUTO');
        $stock->setIndustry('Auto Manufacturers');
        $stock->setEarningsPerShare('2.50');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('50.00');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');
        $stock->setBeta('1.5');
        $stock->setTotalEquity('50000000000');
        $stock->setWholesaleDebt('20000000000');
        $stock->setCorporateTreasury('10000000000');
        $stock->setRetainedEarnings('10000000000');
        $stock->setTotalRevenue('80000000000');
        $stock->setOperatingMargin('0.10');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('0.10');
        $stock->setFixedCostRatio(0.60);
        $stock->setCapexRatio('0.50');

        $initialMargin = 0.10; // Baseline Auto Manufacturers margin
        $stock->setStructuralVariableMargin($initialMargin);

        // Simulate a severe recession to trigger cost stickiness
        $macroState = new MacroStateDTO(
            outputGapEma: -0.05,
            nominalGdpIndex: 1.0,
            corporateTaxRate: 0.21,
            marketVolatilityEma: 0.35,
            policyRateEma: 0.05,
            yield5yEma: 0.05,
            macroCreditSpreadEma: 0.04,
            interbankLiquiditySpreadEma: 0.01
        );
        
        $stock->setPreviousRevenue('80000000000.0000'); // $80B annualized
        
        // Quarter 1: Severe contraction
        $this->earningsEngine->calculate($stock, $macroState, 1);
        
        $marginQ1 = (float) $stock->getStructuralVariableMargin();
        // The structural margin should remain stable around 0.10, not collapse to 0.01
        $this->assertGreaterThan(0.08, $marginQ1, "Structural margin dropped too aggressively in Q1 due to cost stickiness overwrite.");
        
        // Quarter 2: Continued contraction (let the engine use previousRevenue updated by Q1)
        $this->earningsEngine->calculate($stock, $macroState, 2);
        
        $marginQ2 = (float) $stock->getStructuralVariableMargin();
        // Even after back-to-back recessions, structural margin must not enter a death spiral
        $this->assertGreaterThan(0.07, $marginQ2, "Structural margin collapsed in Q2. Cost stickiness is causing a death spiral.");
    }

    public function testConsecutiveQuartersDuringBoomDoNotTriggerEmergencyBorrowing(): void
    {
        $stock = new Stock();
        $stock->setTicker('IBHI');
        $stock->setIndustry('Construction & Engineering');
        $stock->setEarningsPerShare('4.00');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('100.00');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');
        $stock->setBeta('1.2');
        $stock->setTotalEquity('50000000000');
        $stock->setWholesaleDebt('25000000000');
        $stock->setCorporateTreasury('15000000000');
        $stock->setRetainedEarnings('20000000000');
        $stock->setTotalRevenue('80000000000');
        $stock->setOperatingMargin('0.15');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('0.10');
        $stock->setFixedCostRatio(0.50);
        $stock->setCapexRatio('0.30');

        $stock->setStructuralVariableMargin(0.15);
        $stock->setPreviousRevenue('80000000000.0000'); // $80B annualized

        // Macro boom
        $boomMacro = new MacroStateDTO(
            outputGapEma: 0.03,
            nominalGdpIndex: 1.10,
            corporateTaxRate: 0.21,
            marketVolatilityEma: 0.12,
            policyRateEma: 0.03,
            yield5yEma: 0.035,
            macroCreditSpreadEma: 0.015,
            interbankLiquiditySpreadEma: 0.001
        );

        // Run 4 consecutive quarters in a boom
        for ($q = 1; $q <= 4; $q++) {
            $events = $this->earningsEngine->calculate($stock, $boomMacro, $q);
            $eventDesc = $events[0]['description'] ?? '';
            
            // In a boom with massive profits, company must NOT be forced into emergency borrowing or dilutive stock offerings
            $this->assertStringNotContainsString('Forced to borrow', $eventDesc, "Quarter {$q} triggered false emergency borrowing during an economic boom.");
            $this->assertStringNotContainsString('emergency stock offering', $eventDesc, "Quarter {$q} triggered false emergency stock offering during an economic boom.");
        }

        // Treasury should remain healthy and positive
        $treasury = (float) $stock->getCorporateTreasury();
        $this->assertGreaterThan(5_000_000_000.0, $treasury, 'Corporate treasury was depleted during a boom.');
    }
}
