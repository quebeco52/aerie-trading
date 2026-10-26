<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\ManagementStyle;
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

        // The shares are of the capital the plant sells on, and what the arm builds joins that capital.
        $ctx->revenueGeneratingCapital = 5_000_000_000.0;
        $this->budget($ledger)->rollSegmentPlant($ctx, 0.0);
        $this->assertEqualsWithDelta((0.2 * 5_000_000_000.0 + $growthCapEx) / (5_000_000_000.0 + $growthCapEx), SegmentBooks::plant($ctx->stock->getEarningsMomentumZ() ?? [])['ads_and_cloud'] ?? 0.0, 1e-12);
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

    /**
     * A segment whose new plant does not clear the hurdle at its industry's price stops replacing the wear on the plant
     * its market no longer needs: the ads arm runs a third more plant than its market calls for in a glut, so it keeps
     * up two thirds of it, upkeep falls with it, and its share of the plant shrinks. Whether it builds is filed for the
     * deals. An empire builder keeps its weak segment whole.
     */
    public function testAWeakSegmentLetsThePlantItsMarketNoLongerNeedsWearOut(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $ctx = $this->context('WEAV', 'Internet Retail', [Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], $ledger);
        $ledger->resolveSegmentSupply($ctx->stock, 'ads_and_cloud', 1_000.0, 1_000.0, 0.05, 1.0, 0.0, 0.0, 10, 252);
        $ledger->resolveSegmentSupply($ctx->stock, 'ads_and_cloud', 1_000.0, 1_500.0, 0.05, 1.0, 0.0, 0.0, 20, 252);
        $ctx->segments['ads_and_cloud'] = ['price' => 0.75] + $ctx->segments['ads_and_cloud'];

        $this->growthCapEx($ctx, $ledger);

        $this->assertFalse($ctx->segments['ads_and_cloud']['builds'] ?? null);
        $this->assertEqualsWithDelta(2.0 / 3.0, $ctx->segments['ads_and_cloud']['replaced'] ?? null, 1e-12);
        $this->assertSame(1.0, $ctx->segments[Segments::MAIN]['replaced'] ?? null);
        $this->assertSame(0.0, ($ctx->stock->getEarningsMomentumZ() ?? [])[SegmentBooks::STATE_BUILDS . 'ads_and_cloud'] ?? null);
        $this->assertEqualsWithDelta(0.8 + (0.2 * 2.0 / 3.0), (new ReflectionMethod(CapitalBudgetEngine::class, 'plantKept'))->invoke(null, $ctx), 1e-12, 'upkeep on the plant kept');

        $ctx->segments[Segments::MAIN]['growth'] = 0.0;
        $this->budget($ledger)->rollSegmentPlant($ctx, 0.0);
        $base = abs($ctx->investedCapital);
        $ads = (0.2 * $base) - ($ctx->quarterlyDepreciation * 0.2 / 3.0);
        $this->assertEqualsWithDelta($ads / ($ads + (0.8 * $base)), SegmentBooks::plant($ctx->stock->getEarningsMomentumZ() ?? [])['ads_and_cloud'] ?? null, 1e-12);

        $empire = $this->context('WEAV', 'Internet Retail', [Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], $ledger);
        $empire->stock->setManagementStyle(ManagementStyle::EmpireBuilder);
        $empire->segments['ads_and_cloud'] = ['price' => 0.75] + $empire->segments['ads_and_cloud'];
        $this->growthCapEx($empire, $ledger);
        $this->assertEqualsWithDelta(0.6 + (0.4 * 2.0 / 3.0), $empire->segments['ads_and_cloud']['replaced'] ?? null, 1e-12, 'an empire builder keeps much of its weak segment');
    }

    /**
     * When internal funds do not cover every segment's plan, the plan that earns most is filled first; an empire
     * builder spreads much of them pro rata instead.
     */
    public function testScarceFundsGoToTheSegmentThatEarnsMost(): void
    {
        foreach ([ManagementStyle::Operator, ManagementStyle::EmpireBuilder] as $style) {
            $ctx = $this->context('WEAV', 'Internet Retail', [Segments::MAIN => 0.8, 'ads_and_cloud' => 0.2], null);
            $ctx->stock->setManagementStyle($style);
            $ctx->segments[Segments::MAIN] = ['margin' => 0.04, 'turnover' => 1.0] + $ctx->segments[Segments::MAIN];
            $ctx->segments['ads_and_cloud'] = ['margin' => 0.24, 'turnover' => 0.5] + $ctx->segments['ads_and_cloud'];
            $ctx->assetTurnover = 0.9 / 4.0;
            $ctx->stableMargin = ((0.8 / 0.9) * 0.04) + ((0.1 / 0.9) * 0.24);
            $ctx->baselineRoic = 0.40;
            $plans = $this->growthCapEx(clone $ctx, null);

            $funded = $this->growthCapEx($ctx, null, 1.0, 0.3 * $plans);

            $this->assertEqualsWithDelta(0.3 * $plans, $funded, 1e-3);
            $adsPlan = $ctx->segments['ads_and_cloud']['growth'] + $ctx->segments['ads_and_cloud']['leftover'];
            $support = $ctx->stock->getManagementProfile()->weakSegmentSupport();
            $expected = ((1.0 - $support) * min($adsPlan, $funded)) + ($support * $funded * $adsPlan / $plans);
            $this->assertEqualsWithDelta($expected, $ctx->segments['ads_and_cloud']['growth'], 1e-3, $style->value);
            $this->assertEqualsWithDelta($plans - $funded, $ctx->segments['ads_and_cloud']['leftover'] + $ctx->segments[Segments::MAIN]['leftover'], 1e-3, 'what is not funded is left for the treasury');
        }
    }

    /** Belly cargo's plan is funded with the main plan it rides, not after it, when funds are short. */
    public function testAByProductIsFundedWithThePlantItRides(): void
    {
        $ctx = $this->context('TERN', 'Airlines', [Segments::MAIN => 0.9, 'air_cargo' => 0.1], null);
        $plans = $this->growthCapEx(clone $ctx, null);

        $this->growthCapEx($ctx, null, 1.0, 0.5 * $plans);

        $this->assertEqualsWithDelta(0.5 * 0.1 * $plans, $ctx->segments['air_cargo']['growth'], 1e-3);
    }

    /**
     * The revaluation growth spend nets out is the price level's slice of what upkeep spent: with plant a quarter
     * dearer to replace, a fifth of upkeep, however much of the plant upkeep kept.
     */
    public function testTheRevaluationIsThePriceLevelsSliceOfUpkeep(): void
    {
        $ctx = $this->context('TERN', 'Airlines', [Segments::MAIN => 1.0], null);
        $ctx->replacementCostRatio = 1.25;
        $revaluation = new ReflectionMethod(CapitalBudgetEngine::class, 'revaluationBooked');

        $this->assertEqualsWithDelta(20.0, $revaluation->invoke(null, $ctx, 100.0), 1e-12);
        $this->assertEqualsWithDelta(10.0, $revaluation->invoke(null, $ctx, 50.0), 1e-12, 'half the plant kept');
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

    private function growthCapEx(EarningsSimulationContext $ctx, ?IndustryShareLedger $ledger, float $cycle = 1.0, float $funds = 1e15): float
    {
        return (new ReflectionMethod(CapitalBudgetEngine::class, 'calculateGrowthCapEx'))
            ->invoke($this->budget($ledger), $ctx, $cycle, $ctx->quarterlyDepreciation, $funds);
    }

    private function budget(?IndustryShareLedger $ledger): CapitalBudgetEngine
    {
        $corporateMetrics = new CorporateMetrics();

        return new CapitalBudgetEngine(new CapExEngine(), $corporateMetrics, new FixedAssetLedger($corporateMetrics), new WorkingCapitalLedger($corporateMetrics), $ledger);
    }
}
