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
use App\Service\Model\Sector\SpaceSystemsBusinessModel;
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

    /**
     * At the same industry price, the segment whose plant earns more builds and the one that earns less waits: new
     * plant is judged on the segment's own margin and turnover, not the company's blend of them.
     */
    public function testEachSegmentsPlantIsJudgedOnItsOwnReturn(): void
    {
        $ctx = $this->context('WEAV', 'Internet Retail', [Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], null);
        $ctx->segments[Segments::MAIN] = ['margin' => 0.04, 'turnover' => 1.0] + $ctx->segments[Segments::MAIN];
        $ctx->segments['ads_and_cloud'] = ['margin' => 0.24, 'turnover' => 0.5] + $ctx->segments['ads_and_cloud'];
        $ctx->assetTurnover = 0.9 / 4.0;
        $ctx->stableMargin = ((0.8 / 0.9) * 0.04) + ((0.1 / 0.9) * 0.24);
        // The company as a whole earns exactly its hurdle.
        $ctx->baselineRoic = $ctx->stock->getManagementProfile()->growthHurdle($ctx->strategy->getHurdleRate($ctx->health), false);

        $growthCapEx = $this->growthCapEx($ctx, null);

        $this->assertGreaterThan(0.0, $growthCapEx);
        $this->assertSame(0.0, $ctx->segments[Segments::MAIN]['growth'], 'retail earns less than the blend');
        $this->assertEqualsWithDelta($growthCapEx, $ctx->segments['ads_and_cloud']['growth'], 1e-6);
    }

    /**
     * A segment on its model's own path (satellite internet signing subscribers up) builds its industry's growth plus
     * the step its path takes before the next report, from the level the ledger priced to the one the quarter leaves,
     * on its trend plant and apart from the cycle; the main segment builds at its own growth.
     */
    public function testASegmentOnItsOwnPathBuildsTheStepItsPathTakes(): void
    {
        $ctx = $this->context('SKYL', 'Space Systems', [Segments::MAIN => 0.9, 'satellite_internet' => 0.1], null);
        $space = Sectors::strategyFor('Space Systems');
        $priced = (new Stock())->setTicker('SKYL')->setIndustry('Space Systems')->setEarningsMomentumZ([SpaceSystemsBusinessModel::STATE_YEARS_LISTED => 4.75]);
        $pricedLevel = $space->resolveSegmentPath($priced, 'satellite_internet')['level'] ?? 0.0;
        $ctx->segments['satellite_internet'] = ['path' => $pricedLevel] + $ctx->segments['satellite_internet'];
        $ctx->stock->setEarningsMomentumZ([SpaceSystemsBusinessModel::STATE_YEARS_LISTED => 5.0] + ($ctx->stock->getEarningsMomentumZ() ?? []));
        $step = ($space->resolveSegmentPath($ctx->stock, 'satellite_internet')['level'] ?? 0.0) - $pricedLevel;
        $inflation = (float) $ctx->macroState->inflationEma;
        $base = abs($ctx->investedCapital);
        $main = 0.9 * $base * ($space->getFadedSecularGrowthRate($ctx->stock, 0.0) + $inflation) * $ctx->dt * 0.8;
        $internet = (0.1 * $base * (Sectors::strategyFor('Telecom Services')->getFadedSecularGrowthRate($ctx->stock, 0.0) + $inflation) * $ctx->dt * 0.8)
            + (0.1 * $base * (exp($step) - 1.0));
        $this->assertGreaterThan(0.3, $step, 'a steep stretch of the curve');

        $growthCapEx = $this->growthCapEx($ctx, null, 0.8);

        $this->assertEqualsWithDelta($main + $internet, $growthCapEx, 1e-3);
        $this->assertEqualsWithDelta($internet, $ctx->segments['satellite_internet']['growth'], 1e-3);
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
        $ctx->stableMargin = 0.10;
        foreach ($plant as $segment => $share) {
            $ctx->segments[$segment] = ['plant' => $share, 'sales' => $share, 'margin' => 0.10, 'turnover' => 1.0, 'market' => 0.1, 'price' => 1.0, 'load' => 1.0, 'drain' => 0.0, 'growth' => 0.0, 'leftover' => 0.0];
        }

        return $ctx;
    }

    private function growthCapEx(EarningsSimulationContext $ctx, ?IndustryShareLedger $ledger, float $cycle = 1.0): float
    {
        return (new ReflectionMethod(CapitalBudgetEngine::class, 'calculateGrowthCapEx'))
            ->invoke($this->budget($ledger), $ctx, $cycle, $ctx->quarterlyDepreciation, 1e15);
    }

    private function budget(?IndustryShareLedger $ledger): CapitalBudgetEngine
    {
        $corporateMetrics = new CorporateMetrics();

        return new CapitalBudgetEngine(new CapExEngine(), $corporateMetrics, new FixedAssetLedger($corporateMetrics), new WorkingCapitalLedger($corporateMetrics), $ledger);
    }
}
