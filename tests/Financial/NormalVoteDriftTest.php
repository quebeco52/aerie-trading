<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Data\Politics\AerieDiet as Diet;
use App\Service\Math\MathUtility;
use App\Service\Politics\Diet\Election;
use App\Service\Politics\Diet\OpinionPolls;
use PHPUnit\Framework\TestCase;

/**
 * The normal votes' walk over decades: a share starting at its founding share keeps that mean, and its variance grows
 * as n (1 - n) times the drift variance the pull back leaves (Election::normalVoteDriftVariance()), the Wright-Fisher
 * form the walk takes once the shares are put back on a whole.
 */
class NormalVoteDriftTest extends TestCase
{
    private const SEED = 20261009;
    private const PATHS = 3000;
    private const YEARS = 40;

    public function testANormalVoteKeepsItsMeanAndSpreadsWithTheYears(): void
    {
        mt_srand(self::SEED);
        $math = new MathUtility();
        $final = [];
        for ($path = 0; $path < self::PATHS; ++$path) {
            $normals = Diet::SEED_VOTE_SHARES;
            for ($year = 0; $year < self::YEARS; ++$year) {
                $draws = [];
                foreach (Diet::PARTIES as $party) {
                    $draws[$party] = $math->generateStandardNormal();
                }
                [$normals] = OpinionPolls::walkNormals($normals, [], 1.0, $draws);
            }
            $final[] = $normals;
        }

        foreach ([Diet::CIVIC, Diet::EXCHANGE] as $party) {
            $start = Diet::SEED_VOTE_SHARES[$party];
            $shares = array_column($final, $party);
            $mean = array_sum($shares) / self::PATHS;
            $variance = array_sum(array_map(static fn(float $x): float => ($x - $mean) ** 2, $shares)) / (self::PATHS - 1);
            $expected = Election::normalVoteDriftVariance(self::YEARS) * $start * (1.0 - $start);

            $this->assertLessThan(4.0 * sqrt($variance / self::PATHS), abs($mean - $start), "{$party} drifts on average.");
            // Over forty years the variance compounds a little beyond the law (the shares themselves move), so the band
            // is the law within a fifth.
            $this->assertEqualsWithDelta($expected, $variance, 0.2 * $expected, "{$party}'s spread after forty years.");
        }
    }
}
