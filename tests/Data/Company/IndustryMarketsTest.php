<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\IndustryMarkets;
use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\Data\Company\StockModelTuning;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Market\Pricing\SeedRows;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Model\ModelParam;
use App\Service\Model\Sector\AssetManagementBusinessModel;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Industry opening market sizes as multiples of District GDP: table coverage, shared market sizing with a positive
 * unlisted fringe, 4dp column fit for Stock::$samRatio, proportional scaling, and refusal of stated shares or
 * out-of-range inputs.
 */
#[Group('unit')]
final class IndustryMarketsTest extends TestCase
{
    public function testEveryIndustryMarketIsValidAndCoversAllInScopeRows(): void
    {
        $this->assertCount(75, IndustryMarkets::BY_INDUSTRY);

        foreach (IndustryMarkets::BY_INDUSTRY as $industry => $gdpShare) {
            $this->assertArrayHasKey($industry, Sectors::INDUSTRY_METRICS, "IndustryMarkets lists unknown industry {$industry}.");
            $this->assertGreaterThan(0.0, $gdpShare, "IndustryMarkets GDP share must be positive for {$industry}.");
        }

        $metrics = CorporateMetrics::getInstance();
        $inScope = 0;

        foreach (InitialMarket::STOCKS as $raw) {
            $ticker = $raw['ticker'];
            $this->assertArrayNotHasKey('addressable_share', $raw, "{$ticker} must not state addressable_share.");

            $strategy = Sectors::strategyFor($raw['industry']);
            if (!isset($raw['revenue']) || $strategy instanceof AssetManagementBusinessModel) {
                $this->assertArrayNotHasKey($raw['industry'], IndustryMarkets::BY_INDUSTRY, "{$ticker} should sit outside IndustryMarkets.");

                continue;
            }

            $inScope++;
            $resolved = SeedRows::for($ticker);
            $equity = (float) $resolved['total_equity'];
            $ic = $metrics->calculateLiveInvestedCapital($equity, (float) $resolved['wholesale_debt'], (float) $resolved['corporate_treasury']);
            $evalCapital = $strategy->getEvaluationCapital($equity, $ic);
            $actualShare = $metrics->calculateScaleRatio($evalCapital, 1.0, (float) $resolved['sam_ratio']);
            $expectedShare = (float) $raw['revenue'] / (MacroConstants::DISTRICT_GDP_USD * IndustryMarkets::BY_INDUSTRY[$raw['industry']]);

            $this->assertEqualsWithDelta($expectedShare, $actualShare, 1e-6, $ticker);
        }

        $this->assertSame(98, $inScope);
    }

    public function testEveryIndustryHasPositiveUnlistedFringeAndConsistentPeers(): void
    {
        $metrics = CorporateMetrics::getInstance();
        $byIndustry = [];
        foreach (SeedRows::all() as $resolved) {
            if (!isset($resolved['revenue']) || Sectors::strategyFor($resolved['industry']) instanceof AssetManagementBusinessModel) {
                continue;
            }
            $byIndustry[$resolved['industry']][] = $resolved;
        }

        $multiFirm = 0;
        foreach (IndustryMarkets::BY_INDUSTRY as $industry => $gdpShare) {
            $market = MacroConstants::DISTRICT_GDP_USD * $gdpShare;
            $rows = $byIndustry[$industry] ?? [];
            $this->assertNotEmpty($rows, "IndustryMarkets entry {$industry} has no seeded firms.");

            $listedRevenue = 0.0;
            $strategy = Sectors::strategyFor($industry);
            if (count($rows) > 1) {
                $multiFirm++;
            }

            foreach ($rows as $resolved) {
                $ticker = $resolved['ticker'];
                $revenue = (float) $resolved['revenue'];
                $listedRevenue += $revenue;

                $equity = (float) $resolved['total_equity'];
                $ic = $metrics->calculateLiveInvestedCapital($equity, (float) $resolved['wholesale_debt'], (float) $resolved['corporate_treasury']);
                $evalCapital = $strategy->getEvaluationCapital($equity, $ic);
                $share = $metrics->calculateScaleRatio($evalCapital, 1.0, (float) $resolved['sam_ratio']);

                if ($strategy->isFinancial()) {
                    $this->assertLessThanOrEqual(FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD, $share, $ticker);
                } else {
                    $this->assertLessThan(FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD, $share, $ticker);
                }

                $this->assertEqualsWithDelta($market, $revenue / $share, $market * 1e-6, "{$industry}:{$ticker} implied market must match industry market.");
            }

            $this->assertLessThan($market, $listedRevenue, "{$industry} must leave a positive unlisted fringe.");
        }

        $this->assertSame(16, $multiFirm);
    }

    public function testOpeningSamRatioFitsFourDecimalColumnWithinTightTolerance(): void
    {
        $column = (new \ReflectionProperty(Stock::class, 'samRatio'))->getAttributes(Column::class)[0]->newInstance();
        $this->assertSame(7, $column->precision);
        $this->assertSame(4, $column->scale);
        $this->assertSame('1.0000', (new Stock())->getSamRatio());

        $stock = (new Stock())->setSamRatio('0.0246');
        $this->assertSame('0.0246', $stock->getSamRatio());

        $worstDistortion = 0.0;
        foreach (SeedRows::all() as $row) {
            $sam = (float) $row['sam_ratio'];
            $rounded = round($sam, 4);
            $distortion = abs($rounded - $sam) / $sam;
            $worstDistortion = max($worstDistortion, $distortion);
            $this->assertLessThan(0.001, $distortion, "{$row['ticker']} sam_ratio {$sam} loses precision at 4 decimal places.");
        }

        $this->assertGreaterThan(0.0, $worstDistortion);
        foreach (['SKYL', 'TSTN', 'CLAW', 'ROOK'] as $smallTicker) {
            $sam = (float) SeedRows::for($smallTicker)['sam_ratio'];
            $this->assertLessThan(0.001, abs(round($sam, 4) - $sam) / $sam, $smallTicker);
        }
    }

    public function testScalingRevenueScalesShareAndLeavesIndustryMarketUnchanged(): void
    {
        $grip = $this->rawRow('GRIP');
        $base = SeedRows::resolve($grip);
        $scaled = SeedRows::resolve(['revenue' => $grip['revenue'] * 1.2] + $grip);

        $metrics = CorporateMetrics::getInstance();
        $baseIc = $metrics->calculateLiveInvestedCapital((float) $base['total_equity'], (float) $base['wholesale_debt'], (float) $base['corporate_treasury']);
        $scaledIc = $metrics->calculateLiveInvestedCapital((float) $scaled['total_equity'], (float) $scaled['wholesale_debt'], (float) $scaled['corporate_treasury']);

        $baseShare = $metrics->calculateScaleRatio($baseIc, 1.0, (float) $base['sam_ratio']);
        $scaledShare = $metrics->calculateScaleRatio($scaledIc, 1.0, (float) $scaled['sam_ratio']);
        $expectedMarket = MacroConstants::DISTRICT_GDP_USD * IndustryMarkets::BY_INDUSTRY['Aerospace & Defense'];

        $this->assertEqualsWithDelta(1.2, $scaledShare / $baseShare, 1e-6);
        $this->assertEqualsWithDelta($expectedMarket, $base['revenue'] / $baseShare, $expectedMarket * 1e-6);
        $this->assertEqualsWithDelta($expectedMarket, $scaled['revenue'] / $scaledShare, $expectedMarket * 1e-6);
    }

    public function testDirectModelAndSeedRowsRefuseStatedShareUnknownIndustryOrOversizedRevenue(): void
    {
        $opening = new MacroStateDTO();
        $sampleTickers = ['GRIP', 'SHOR', 'LAKE', 'POOL', 'SAFE', 'KING', 'ACC'];

        foreach ($sampleTickers as $ticker) {
            $row = $this->rawRow($ticker);
            $directRow = ['depreciation_rate' => Sectors::metricsFor($row['industry'])['depreciation']] + $row;
            $strategy = Sectors::strategyFor($row['industry']);
            $oversized = MacroConstants::DISTRICT_GDP_USD * IndustryMarkets::BY_INDUSTRY[$row['industry']] * 1.01;

            foreach ([
                ['addressable_share' => 0.30] + $directRow,
                ['industry' => 'General'] + $directRow,
                ['revenue' => 0.0] + $directRow,
                ['revenue' => -1.0] + $directRow,
                ['revenue' => $oversized] + $directRow,
            ] as $badRow) {
                try {
                    SeedRows::resolve($badRow, $opening);
                    $this->fail("Expected SeedRows::resolve() to reject {$ticker}.");
                } catch (\DomainException) {
                    $this->addToAssertionCount(1);
                }

                try {
                    $strategy->resolveOpeningBooks($badRow, $opening);
                    $this->fail("Expected direct resolveOpeningBooks() to reject {$ticker}.");
                } catch (\DomainException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function testSegmentMarketsCoverMultiEntrantSegmentOnlyIndustriesAndLeaveFringe(): void
    {
        $streamWeights = [
            'technology_programmes' => ModelParam::TechnologyProgrammeWeight,
            'martech_consulting' => ModelParam::MartechConsultingWeight,
            'cloud_infrastructure' => ModelParam::CloudInfrastructureWeight,
        ];

        $segmentRevenueByIndustry = [];
        foreach (Segments::tickers() as $ticker) {
            $row = $this->rawRow($ticker);
            $firmRevenue = (float) $row['revenue'];
            $plan = Segments::forTicker($ticker);
            $this->assertNotNull($plan);
            foreach ($plan['segments'] as $segment) {
                $industry = $segment['industry'];
                if (!isset(IndustryMarkets::SEGMENT_MARKETS[$industry])) {
                    continue;
                }
                $weight = 0.0;
                foreach ($segment['streams'] as $stream) {
                    $this->assertArrayHasKey($stream, $streamWeights, "{$ticker}:{$stream} mapped");
                    $weight += StockModelTuning::get($ticker, $streamWeights[$stream], 0.0);
                }
                $this->assertGreaterThan(0.0, $weight, "{$ticker} in {$industry} must have positive weight");
                $segmentRevenueByIndustry[$industry] = ($segmentRevenueByIndustry[$industry] ?? 0.0) + ($firmRevenue * $weight);
            }
        }

        foreach (IndustryMarkets::SEGMENT_MARKETS as $industry => $gdpShare) {
            $this->assertArrayHasKey($industry, Sectors::INDUSTRY_METRICS);
            $this->assertArrayNotHasKey($industry, IndustryMarkets::BY_INDUSTRY);
            $this->assertGreaterThan(0.0, $gdpShare);
            $this->assertTrue(IndustryMarkets::hasIndustry($industry));
            $this->assertEqualsWithDelta($gdpShare, IndustryMarkets::gdpShare($industry), 1e-12);

            $listedSegmentRevenue = $segmentRevenueByIndustry[$industry] ?? 0.0;
            $this->assertGreaterThan(0.0, $listedSegmentRevenue, "SEGMENT_MARKETS entry {$industry} must serve seeded segments");
            $this->assertLessThan(IndustryMarkets::openingMarket($industry), $listedSegmentRevenue, "{$industry} must leave a positive unlisted fringe");
        }
    }

    /** @return array<string, mixed> */
    private function rawRow(string $ticker): array
    {
        foreach (InitialMarket::STOCKS as $row) {
            if ($row['ticker'] === $ticker) {
                return $row;
            }
        }

        $this->fail("No seed row for {$ticker}.");
    }
}
