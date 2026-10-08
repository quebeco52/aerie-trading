<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Service\Model\Sector\BiotechBusinessModel;
use App\Service\View\BiotechPipelineBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The pipeline card reads the model's own ledger: the ladder only regroups the cohorts it carries, so its shares
 * account for the whole marketed book, and the runway is cash over the last quarter's free cash outflow.
 */
#[AllowMockObjectsWithoutExpectations]
class BiotechPipelineBuilderTest extends TestCase
{
    private const LATE_STAGE_ASSETS = 3.4;

    private function builder(?CorporateReport $latest = null): BiotechPipelineBuilder
    {
        $reports = $this->createMock(CorporateReportRepository::class);
        $reports->method('findLatestFor')->willReturn($latest);

        return new BiotechPipelineBuilder($reports);
    }

    private static function cohort(int $slot, float $peak, float $adoption, float $exclusivity): array
    {
        $prefix = BiotechBusinessModel::STATE_COHORT_PREFIX . $slot . ':';

        return [$prefix . 'peak' => $peak, $prefix . 'adoption' => $adoption, $prefix . 'exclusivity' => $exclusivity];
    }

    /** A drug company after a report: cohorts in four rungs and one past expiry, plus folded off-patent revenue. */
    private static function biotech(?array $momentum = null): Stock
    {
        $stock = new Stock();
        $stock->setTicker('TSTB');
        $stock->setIndustry('Biotechnology');
        $stock->setEarningsMomentumZ($momentum ?? [
            BiotechBusinessModel::STATE_LATE_STAGE_ASSETS => self::LATE_STAGE_ASSETS,
            BiotechBusinessModel::STATE_PROTECTED_SHARE => 0.71,
            BiotechBusinessModel::STATE_FRANCHISE_INDEX => 1.08,
            BiotechBusinessModel::STATE_RND_REPLACEMENT_RATIO => 0.92,
            BiotechBusinessModel::STATE_OFF_PATENT_BIOLOGIC => 0.04,
            BiotechBusinessModel::STATE_OFF_PATENT_SMALL_MOLECULE => 0.01,
            BiotechBusinessModel::STATE_ESTABLISHED_PRODUCTS => 0.10,
        ]
            + self::cohort(0, 0.30, 1.0, 2.0)    // half a year left
            + self::cohort(1, 0.20, 1.0, 10.0)   // two and a half years
            + self::cohort(2, 0.15, 0.95, 30.0)  // seven and a half years
            + self::cohort(3, 0.12, 0.0, 50.0)   // approved this quarter, nothing sold yet
            + self::cohort(4, 0.08, 0.40, 46.0)  // still ramping
            + self::cohort(5, 0.20, 1.0, -3.0)); // four quarters past expiry, eroding

        return $stock;
    }

    public function testACompanyOutsideDrugsHasNoPipeline(): void
    {
        $stock = self::biotech();
        $stock->setIndustry('Banks - Regional');

        $this->assertNull($this->builder()->build($stock)['pipeline']);
    }

    public function testADrugCompanyBeforeItsFirstReportHasNoPipeline(): void
    {
        $this->assertNull($this->builder()->build(self::biotech([]))['pipeline']);
    }

    public function testTheLadderAccountsForTheWholeMarketedBook(): void
    {
        $pipeline = $this->builder()->build(self::biotech())['pipeline'];
        $this->assertNotNull($pipeline);

        $this->assertEqualsWithDelta(1.0, array_sum(array_column($pipeline['ladder'], 'share')), 1e-12);
        $counts = array_column($pipeline['ladder'], 'count', 'label');
        $this->assertSame(1, $counts['Within 1 year']);
        $this->assertSame(1, $counts['1 to 3 years']);
        $this->assertSame(0, $counts['3 to 5 years']);
        $this->assertSame(1, $counts['5 to 10 years']);
        $this->assertSame(2, $counts['Over 10 years']);
        $this->assertSame(1, $counts['Exclusivity lost, still eroding']);
        $this->assertNull($counts['Off patent and established']);
    }

    /** An expired cohort counts at what generics have left of it, not at its pre-expiry level. */
    public function testAnExpiredMedicineCountsNetOfItsErosion(): void
    {
        $pipeline = $this->builder()->build(self::biotech())['pipeline'];
        $shares = array_column($pipeline['ladder'], 'share', 'label');

        // The lead cohort and the expired one had the same peak level before expiry (0.30 vs 0.20 x 1.0).
        $this->assertLessThan(0.20 / 0.30 * $shares['Within 1 year'], $shares['Exclusivity lost, still eroding']);
        $this->assertGreaterThan(0.0, $shares['Exclusivity lost, still eroding']);
    }

    public function testTheHeadlineFiguresAreTheLedgers(): void
    {
        $pipeline = $this->builder()->build(self::biotech())['pipeline'];

        $this->assertSame(self::LATE_STAGE_ASSETS, $pipeline['lateStageAssets']);
        $this->assertEqualsWithDelta(self::LATE_STAGE_ASSETS / BiotechBusinessModel::PHASE_III_DURATION_YEARS, $pipeline['readoutsPerYear'], 1e-12);
        $this->assertSame(0.71, $pipeline['protectedShare']);
        $this->assertEqualsWithDelta(0.08, $pipeline['franchiseChange'], 1e-12);
        $this->assertSame(0.92, $pipeline['rndReplacement']);
        // The new approval and the one at 40% of plateau are both below peak adoption; expired cohorts are not launches.
        $this->assertSame(2, $pipeline['ramping']);
    }

    public function testTheRunwayIsCashOverTheQuarterlyBurn(): void
    {
        $report = (new CorporateReport())->setTreasury('1000.0000')->setFreeCashFlow('-50.0000');
        $runway = $this->builder($report)->build(self::biotech())['pipeline']['runway'];

        $this->assertEqualsWithDelta(5.0, $runway['years'], 1e-12);
        $this->assertSame(50.0, $runway['quarterlyBurn']);

        $generating = (new CorporateReport())->setTreasury('1000.0000')->setFreeCashFlow('20.0000');
        $this->assertNull($this->builder($generating)->build(self::biotech())['pipeline']['runway']['years']);
        $this->assertNull($this->builder()->build(self::biotech())['pipeline']['runway']);
    }
}
