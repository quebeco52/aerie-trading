<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\BusinessRisk;
use App\Data\Company\EventRisk;
use App\Data\Company\IndustryRisks;
use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\Service\Market\Pricing\SeedRows;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The business-risk and event-risk ladders and the per-industry anchors firms open on: every board industry has both
 * steps, enum levels increase monotonically, every seed row sits within the lore band, and SeedRows refuses rows
 * carrying derived risk keys or out-of-band offsets.
 */
#[Group('unit')]
final class IndustryRisksTest extends TestCase
{
    public function testBusinessRiskStepsIncreaseMonotonically(): void
    {
        $previous = 0.0;
        foreach (BusinessRisk::cases() as $rank => $step) {
            $this->assertSame($rank, $step->rank());
            $this->assertGreaterThan($previous, $step->vol(), "{$step->value} must be riskier than the step below it.");
            $this->assertNotEmpty($step->label());
            $previous = $step->vol();
        }

        $this->assertSame(BusinessRisk::VeryLow, BusinessRisk::Low->shifted(-5), 'A shift stops at the bottom of the ladder.');
        $this->assertSame(BusinessRisk::VeryHigh, BusinessRisk::High->shifted(5), 'And at the top.');
    }

    public function testEventRiskStepsIncreaseMonotonically(): void
    {
        $previous = 0.0;
        foreach (EventRisk::cases() as $rank => $step) {
            $this->assertSame($rank, $step->rank());
            $this->assertGreaterThan($previous, $step->intensity(), "{$step->value} must arrive more often than the step below it.");
            $this->assertNotEmpty($step->label());
            $previous = $step->intensity();
        }

        $this->assertSame(EventRisk::Rare, EventRisk::Occasional->shifted(-5));
        $this->assertSame(EventRisk::Constant, EventRisk::Frequent->shifted(5));
    }

    public function testEveryBoardIndustryHasBothRiskSteps(): void
    {
        $boardIndustries = array_values(array_unique(array_column(InitialMarket::STOCKS, 'industry')));
        foreach ($boardIndustries as $industry) {
            $this->assertArrayHasKey($industry, IndustryRisks::BY_INDUSTRY, "{$industry} has no entry in IndustryRisks.");
            $this->assertArrayHasKey($industry, Sectors::INDUSTRY_METRICS, "{$industry} is not in Sectors.");
        }
        foreach (array_keys(IndustryRisks::BY_INDUSTRY) as $industry) {
            $this->assertArrayHasKey($industry, Sectors::INDUSTRY_METRICS, "IndustryRisks lists unknown industry {$industry}.");
        }

        $fallback = IndustryRisks::forIndustry('Unlisted Industry');
        $this->assertSame(BusinessRisk::Moderate, $fallback['business']);
        $this->assertSame(EventRisk::Occasional, $fallback['events']);
    }

    public function testEverySeedRowSitsWithinTheLoreBandAndStatesNoDerivedKeys(): void
    {
        foreach (InitialMarket::STOCKS as $row) {
            $ticker = (string) $row['ticker'];
            IndustryRisks::assertRow($row);

            $industry = IndustryRisks::forIndustry($row['industry']);
            if (isset($row['risk'])) {
                $this->assertNotSame(0, $row['risk'], "{$ticker} states a zero risk offset; omit it.");
                $this->assertNotSame($industry['business'], IndustryRisks::businessForRow($row), "{$ticker}'s risk offset is clamped at the ladder's edge.");
            }
            if (isset($row['events'])) {
                $this->assertNotSame(0, $row['events'], "{$ticker} states a zero events offset; omit it.");
                $this->assertNotSame($industry['events'], IndustryRisks::eventsForRow($row), "{$ticker}'s events offset is clamped at the ladder's edge.");
            }
        }
    }

    public function testSeedRowsRefusesDerivedRiskKeysAndOutOfBandOffsets(): void
    {
        $base = InitialMarket::STOCKS[0];

        foreach (IndustryRisks::DERIVED_KEYS as $derivedKey) {
            $row = $base + [$derivedKey => 0.20];
            try {
                SeedRows::resolve($row);
                $this->fail("SeedRows::resolve() should refuse derived key '{$derivedKey}'.");
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['risk', 'events'] as $offsetKey) {
            foreach ([IndustryRisks::LORE_BAND_STEPS + 1, -(IndustryRisks::LORE_BAND_STEPS + 1), 'high'] as $bad) {
                $row = $base;
                $row[$offsetKey] = $bad;
                try {
                    SeedRows::resolve($row);
                    $this->fail("SeedRows::resolve() should refuse {$offsetKey}=" . var_export($bad, true));
                } catch (\DomainException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }
}
