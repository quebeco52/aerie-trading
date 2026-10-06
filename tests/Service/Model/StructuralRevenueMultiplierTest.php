<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Sectors;
use App\DTO\EarningsSimulationContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Event\NarrativeEngine;
use App\Service\Market\MarketConsensusEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\BiotechBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A franchise level a model carries itself (an approved drug) is capacity: it reaches structural revenue, and with
 * it the cost base, rather than reading as utilization above one (overtime) or below it (idle plant).
 */
#[AllowMockObjectsWithoutExpectations]
final class StructuralRevenueMultiplierTest extends TestCase
{
    private const CAPITAL = 1_000_000_000.0;
    private const QUARTERLY_TURNOVER = 0.25;

    private function buildEngine(): EarningsEngine
    {
        $mathUtility = new MathUtility();
        $corporateMetrics = new CorporateMetrics();
        $debtEngine = new DebtEngine($mathUtility, $corporateMetrics);
        $capExEngine = new CapExEngine();
        $treasuryEngine = new TreasuryEngine($corporateMetrics, $debtEngine, $capExEngine, $mathUtility);

        return new EarningsEngine(
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MarketEventPublisher::class),
            new CapitalAllocationEngine($this->createStub(CorporateLedgerService::class), $corporateMetrics, $debtEngine, $mathUtility, $treasuryEngine),
            $debtEngine,
            $capExEngine,
            $mathUtility,
            $corporateMetrics,
            $this->createStub(NarrativeEngine::class),
            new MarketConsensusEngine(),
            null
        );
    }

    /**
     * @param array<string, float> $state
     * @return array{0: float, 1: float} Structural revenue, expected revenue at full utilization.
     */
    private function capacityRevenue(string $sectorKey, array $state): array
    {
        $stock = new Stock();
        $stock->setTicker('CAP');
        $stock->setEarningsMomentumZ($state);

        $ctx = new EarningsSimulationContext($stock, new MacroStateDTO(), Sectors::getBusinessModelStrategy($sectorKey), $sectorKey);
        $ctx->revenueGeneratingCapital = self::CAPITAL;
        $ctx->assetTurnover = self::QUARTERLY_TURNOVER;
        $ctx->addressableShare = 0.0;

        /** @var array{0: float, 1: float} */
        return (new ReflectionMethod(EarningsEngine::class, 'capacityRevenueAt'))->invoke($this->buildEngine(), $ctx, 1.0, 1.0);
    }

    public function testAnApprovedFranchiseScalesCapacityNotUtilization(): void
    {
        [$structural, $expected] = $this->capacityRevenue('biotech', [BiotechBusinessModel::STATE_STRUCTURAL_MULTIPLIER => 1.2]);

        $this->assertEqualsWithDelta(self::CAPITAL * self::QUARTERLY_TURNOVER * 1.2, $structural, 1e-3);
        $this->assertEqualsWithDelta($structural, $expected, 1e-3);
    }

    public function testAFirmWithNoFranchiseStateRunsOnItsStoredTurnover(): void
    {
        [$biotech] = $this->capacityRevenue('biotech', []);
        [$standard] = $this->capacityRevenue('none', []);

        $this->assertEqualsWithDelta(self::CAPITAL * self::QUARTERLY_TURNOVER, $biotech, 1e-3);
        $this->assertEqualsWithDelta(self::CAPITAL * self::QUARTERLY_TURNOVER, $standard, 1e-3);
    }
}
