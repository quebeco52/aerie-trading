<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Math\MathUtility;
use App\Service\Model\StreamContext;
use PHPUnit\Framework\TestCase;

/**
 * A customer-demand line keeps its news: its quarter-to-quarter change barely reverses, where a line drawn at a short
 * memory gives most of each quarter back the next (the change of an AR(1) has lag-1 autocorrelation -(1 - phi) / 2).
 * Both stay unit-variance scores.
 */
class DemandStreamPersistenceTest extends TestCase
{
    private const QUARTERS = 40_000;

    private const FIRM_LOADING = 0.60;

    public function testADemandLineKeepsItsNewsAtUnitVariance(): void
    {
        $phi = StreamContext::demandStreamPersistence();
        [$variance, $changeAutocorrelation] = $this->moments(static fn(StreamContext $streams): float => $streams->generateDemandZ('revenue'));

        $this->assertEqualsWithDelta(0.93, $phi, 0.01, 'Control: about 93% of a demand shock is kept each quarter.');
        $this->assertEqualsWithDelta(1.0, $variance, 0.12);
        $this->assertEqualsWithDelta(-(1.0 - $phi) / 2.0, $changeAutocorrelation, 0.02, 'a won customer stays won');
        $this->assertGreaterThan(-0.08, $changeAutocorrelation);
    }

    public function testALineDrawnAtItsOwnShortMemoryStillGivesItsQuarterBack(): void
    {
        [$variance, $changeAutocorrelation] = $this->moments(static fn(StreamContext $streams): float => $streams->generateZ('trading', 0.15));

        $this->assertEqualsWithDelta(1.0, $variance, 0.05);
        $this->assertEqualsWithDelta(-(1.0 - 0.15) / 2.0, $changeAutocorrelation, 0.02);
    }

    /**
     * Variance of the score and lag-1 autocorrelation of its quarterly change over a long run, each quarter's context
     * opened on the last one's persisted map as the engine does.
     *
     * @param callable(StreamContext): float $draw
     *
     * @return array{0: float, 1: float}
     */
    private function moments(callable $draw): array
    {
        $source = MathUtility::ownStream(41);
        $momentum = [];
        $z = [];
        for ($quarter = 0; $quarter < self::QUARTERS; ++$quarter) {
            $streams = new StreamContext($momentum, $source, self::FIRM_LOADING);
            $z[] = $draw($streams);
            $momentum = $streams->getStreamZ();
        }

        $change = [];
        for ($i = 1; $i < count($z); ++$i) {
            $change[] = $z[$i] - $z[$i - 1];
        }

        return [self::variance($z), self::autocorrelation($change)];
    }

    /** @param list<float> $xs */
    private static function variance(array $xs): float
    {
        $mean = array_sum($xs) / count($xs);

        return array_sum(array_map(static fn(float $x): float => ($x - $mean) ** 2, $xs)) / count($xs);
    }

    /** @param list<float> $xs */
    private static function autocorrelation(array $xs): float
    {
        $mean = array_sum($xs) / count($xs);
        $covariance = 0.0;
        for ($i = 1; $i < count($xs); ++$i) {
            $covariance += ($xs[$i] - $mean) * ($xs[$i - 1] - $mean);
        }

        return $covariance / (count($xs) - 1) / self::variance($xs);
    }
}
