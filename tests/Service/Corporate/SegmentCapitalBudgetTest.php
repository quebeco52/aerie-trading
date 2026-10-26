<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\DTO\EarningsSimulationContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Earnings\CapitalBudgetEngine;
use App\Service\Corporate\Earnings\FixedAssetLedger;
use App\Service\Corporate\Earnings\WorkingCapitalLedger;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Growth capex for a company that keeps plant by segment: each segment builds toward its own market and is gated at its
 * own industry's price, and a by-product builds with the plant it rides.
 */
final class SegmentCapitalBudgetTest extends TestCase
{
    /**
     * Retail's industry is in a glut its new plant would not pay in, while the ads arm's is balanced: only the ads arm
     * builds, its plant share grows, and its plant to spare is filed apart from the shortfalls so it is not netted
     * against them.
     */
    public function testASegmentInAGlutWaitsWhileOneThatPaysBuilds(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $ctx = $this->context('WEAV', 'Internet Retail', [Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], $ledger);
        // The ads arm anchored on 1,000 of plant and now runs 1,500: a third of it is ahead of its market.
        $ledger->resolveSegmentSupply($ctx->stock, 'ads_and_cloud', 1_000.0, 1_000.0, 0.05, 1.0, 0.0, 0.0, 10, 252);
        $ledger->resolveSegmentSupply($ctx->stock, 'ads_and_cloud', 1_000.0, 1_500.0, 0.05, 1.0, 0.0, 0.0, 20, 252);
        $ctx->segments[Segments::MAIN] = ['price' => 0.75] + $ctx->segments[Segments::MAIN];

        $growthCapEx = $this->growthCapEx($ctx, $ledger);

        $this->assertGreaterThan(0.0, $growthCapEx);
        $this->assertSame(0.0, $ctx->segments[Segments::MAIN]['growth']);
        $this->assertEqualsWithDelta($growthCapEx, $ctx->segments['ads_and_cloud']['growth'], 1e-6);
        $momentum = $ctx->stock->getEarningsMomentumZ() ?? [];
        $this->assertSame(0.0, $momentum[CapitalBudgetEngine::STATE_CAPACITY_GAP]);
        $this->assertEqualsWithDelta(0.25 * log(2.0 / 3.0), $momentum[CapitalBudgetEngine::STATE_CAPACITY_SPARE], 1e-12, 'weighed by its share of the revenue');

        $this->budget($ledger)->rollSegmentPlant($ctx, 0.0);
        $this->assertGreaterThan(0.2, SegmentBooks::plant($ctx->stock->getEarningsMomentumZ() ?? [])['ads_and_cloud'] ?? 0.0);
    }

    /**
     * Belly cargo flies on the passenger aircraft: in a freight glut it still grows with the fleet, and the hold space
     * the glut leaves unsold is not idle plant, so it keeps its share of the plant.
     */
    public function testAByProductBuildsAndWearsWithThePlantItRides(): void
    {
        $ctx = $this->context('TERN', 'Airlines', [Segments::MAIN => 0.9, 'air_cargo' => 0.1], null);
        $ctx->segments['air_cargo'] = ['price' => 0.70, 'load' => 0.50] + $ctx->segments['air_cargo'];

        $growthCapEx = $this->growthCapEx($ctx, null);

        $this->assertGreaterThan(0.0, $growthCapEx);
        $this->assertEqualsWithDelta(0.1 * $growthCapEx, $ctx->segments['air_cargo']['growth'], 1e-6);
        $this->budget(null)->rollSegmentPlant($ctx, 0.0);
        $this->assertEqualsWithDelta(0.1, SegmentBooks::plant($ctx->stock->getEarningsMomentumZ() ?? [])['air_cargo'] ?? 0.0, 1e-12);
    }

    /** @param array<string, float> $plant */
    private function context(string $ticker, string $industry, array $plant, ?IndustryShareLedger $ledger): EarningsSimulationContext
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry($industry);
        $stock->setSharesOutstanding('1000000000');
        $stock->setTotalEquity('8000000000');
        $stock->setWholesaleDebt('2000000000');
        $stock->setTotalRevenue('10000000000');
        $targets = [];
        foreach ($plant as $segment => $share) {
            $targets[SegmentBooks::STATE_TARGET . $segment] = $share === 0.2 ? 0.25 : $share;
        }
        $stock->setEarningsMomentumZ(SegmentBooks::open($plant, 1.0, []) + $targets);

        $macro = new MacroStateDTO(corporateTaxRate: 0.21, inflationEma: 0.02, potentialGdpIndex: 1.0, gdpDeflator: 1.0);
        $ctx = new EarningsSimulationContext($stock, $macro, Sectors::strategyFor($industry), Sectors::businessModelFor($industry));
        $ctx->health = (new DebtEngine(new MathUtility(), new CorporateMetrics()))->analyzeTrailingDebtHealth($stock, $macro);
        $ctx->baselineRoic = 0.20;
        $ctx->corporateTaxRate = 0.21;
        $ctx->assetTurnover = 0.25;
        $ctx->investedCapital = 10_000_000_000.0;
        $ctx->quarterlyDepreciation = 100_000_000.0;
        foreach ($plant as $segment => $share) {
            $ctx->segments[$segment] = ['plant' => $share, 'market' => 0.1, 'price' => 1.0, 'load' => 1.0, 'drain' => 0.0, 'growth' => 0.0, 'leftover' => 0.0];
        }

        return $ctx;
    }

    private function growthCapEx(EarningsSimulationContext $ctx, ?IndustryShareLedger $ledger): float
    {
        return (new ReflectionMethod(CapitalBudgetEngine::class, 'calculateGrowthCapEx'))
            ->invoke($this->budget($ledger), $ctx, 1.0, $ctx->quarterlyDepreciation, 1e15);
    }

    private function budget(?IndustryShareLedger $ledger): CapitalBudgetEngine
    {
        $corporateMetrics = new CorporateMetrics();

        return new CapitalBudgetEngine(new CapExEngine(), $corporateMetrics, new FixedAssetLedger($corporateMetrics), new WorkingCapitalLedger($corporateMetrics), $ledger);
    }
}
