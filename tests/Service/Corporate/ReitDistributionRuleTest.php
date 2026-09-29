<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

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
use App\Service\Model\Sector\ReitBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A District REIT keeps its pass-through status only by distributing 85% of its taxable income, so that share of
 * its earnings is never the trust's to reinvest. Before the rule, a REIT planned acquisitions out of the cash its
 * dividend needed and cut the dividend to nothing once the cash ran out.
 */
#[AllowMockObjectsWithoutExpectations]
final class ReitDistributionRuleTest extends TestCase
{
    private const QUARTERLY_NET_INCOME = 1_000_000_000.0;

    public function testOnlyReitsOweADistribution(): void
    {
        $this->assertSame(0.85, ReitBusinessModel::DISTRIBUTION_REQUIREMENT);
        foreach (Sectors::INDUSTRY_METRICS as $industry => $metrics) {
            $expected = $metrics['business_model'] === 'reit' ? ReitBusinessModel::DISTRIBUTION_REQUIREMENT : 0.0;
            $this->assertSame($expected, Sectors::strategyFor($industry)->getMinimumDistributionRatio(), "{$industry} owes the wrong distribution.");
        }
    }

    /**
     * With no spare cash, a REIT eager to build funds its growth from the 15% of earnings the rule leaves it: the
     * distribution is not internal cash flow. Maintenance exactly replaces depreciation, so earnings are all there is.
     */
    public function testGrowthCapexIsFundedOnlyFromWhatTheDistributionLeaves(): void
    {
        $retained = self::QUARTERLY_NET_INCOME * (1.0 - ReitBusinessModel::DISTRIBUTION_REQUIREMENT);

        $this->assertEqualsWithDelta($retained, $this->growthCapEx(0.0), 1.0);
    }

    /** A treasury short of its operating floor is refilled before anything is built, or the dividend pays for the gap. */
    public function testATreasuryBelowItsFloorIsRefilledBeforeGrowth(): void
    {
        $retained = self::QUARTERLY_NET_INCOME * (1.0 - ReitBusinessModel::DISTRIBUTION_REQUIREMENT);

        $this->assertEqualsWithDelta($retained - 100_000_000.0, $this->growthCapEx(-100_000_000.0), 1.0);
        $this->assertSame(0.0, $this->growthCapEx(-2.0 * $retained));
    }

    /** Growth capex for the fixture REIT holding its operating floor plus the given cash. */
    private function growthCapEx(float $cashOverFloor): float
    {
        $ctx = $this->reitContext();
        $stock = $ctx->stock;
        $operatingBase = (new CorporateMetrics())->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $floor = $ctx->strategy->calculateMinOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $stock->setCorporateTreasury((string) ($floor + $cashOverFloor));

        return (new ReflectionMethod(EarningsEngine::class, 'calculateGrowthCapEx'))
            ->invoke($this->buildEngine(), $ctx, 1.0, 250_000_000.0, 0.0);
    }

    private function reitContext(): EarningsSimulationContext
    {
        $stock = new Stock();
        $stock->setTicker('TRST');
        $stock->setIndustry('REIT - Industrial');
        $stock->setSharesOutstanding('1000000000');
        $stock->setTotalEquity('40000000000');
        $stock->setWholesaleDebt('45000000000');
        $stock->setTotalRevenue('8000000000');
        $stock->setCapexRatio('0.65');

        $ctx = new EarningsSimulationContext(
            $stock,
            new MacroStateDTO(corporateTaxRate: 0.21),
            Sectors::getBusinessModelStrategy('reit'),
            'reit'
        );
        $ctx->baselineRoic = 0.20;
        $ctx->investedCapital = 85_000_000_000.0;
        $ctx->actualQuarterlyNetIncome = self::QUARTERLY_NET_INCOME;
        $ctx->quarterlyDepreciation = 250_000_000.0;

        return $ctx;
    }

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
}
