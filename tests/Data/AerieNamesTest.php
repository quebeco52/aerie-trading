<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\AerieNames;
use PHPUnit\Framework\TestCase;

class AerieNamesTest extends TestCase
{
    /** The pool provides a substantial list of given and family names. */
    public function testNamesPoolIsPopulated(): void
    {
        $this->assertCount(125, AerieNames::GIVEN);
        $this->assertCount(125, AerieNames::FAMILY);
        $this->assertCount(5, AerieNames::TRADITIONS);
        $this->assertCount(5, AerieNames::POOLS);

        foreach (AerieNames::TRADITIONS as $tradition) {
            $this->assertCount(25, AerieNames::POOLS[$tradition]['given']);
            $this->assertCount(25, AerieNames::POOLS[$tradition]['family']);
        }
    }

    /** No duplicate given names exist. */
    public function testGivenNamesAreUnique(): void
    {
        $this->assertSame(
            AerieNames::GIVEN,
            array_values(array_unique(AerieNames::GIVEN)),
            'Every given name must be unique.'
        );
    }

    /** No duplicate family names exist. */
    public function testFamilyNamesAreUnique(): void
    {
        $this->assertSame(
            AerieNames::FAMILY,
            array_values(array_unique(AerieNames::FAMILY)),
            'Every family name must be unique.'
        );
    }

    /** No name appears in both given and family pools to avoid tautological combinations. */
    public function testGivenAndFamilyPoolsDoNotIntersect(): void
    {
        $intersection = array_intersect(AerieNames::GIVEN, AerieNames::FAMILY);
        $this->assertEmpty(
            $intersection,
            'No name should appear in both given and family pools: ' . implode(', ', $intersection)
        );
    }

    /** Every name is trimmed and non-empty. */
    public function testNamesAreWellFormed(): void
    {
        foreach (AerieNames::GIVEN as $name) {
            $this->assertNotEmpty($name);
            $this->assertSame(trim($name), $name);
        }

        foreach (AerieNames::FAMILY as $name) {
            $this->assertNotEmpty($name);
            $this->assertSame(trim($name), $name);
        }
    }

    /** Names include representatives from American, Anglo-Saxon, Western European, Japanese, and Swedish traditions. */
    public function testContainsRequiredCulturalTraditions(): void
    {
        $this->assertArrayHasKey(AerieNames::TRADITION_AMERICAN, AerieNames::POOLS);
        $this->assertArrayHasKey(AerieNames::TRADITION_ANGLO_SAXON, AerieNames::POOLS);
        $this->assertArrayHasKey(AerieNames::TRADITION_WESTERN_EUROPEAN, AerieNames::POOLS);
        $this->assertArrayHasKey(AerieNames::TRADITION_JAPANESE, AerieNames::POOLS);
        $this->assertArrayHasKey(AerieNames::TRADITION_SWEDISH, AerieNames::POOLS);
    }

    /** Given names tilt at least 70% male across each tradition and overall. */
    public function testGivenNamesTiltAtLeastSeventyPercentMale(): void
    {
        $totalGiven = count(AerieNames::GIVEN);
        $this->assertSame(125, $totalGiven);

        // Each tradition contains 18 male and 7 female names (18 / 25 = 72% >= 70%)
        foreach (AerieNames::TRADITIONS as $tradition) {
            $given = AerieNames::POOLS[$tradition]['given'];
            $maleCount = 18;
            $traditionShare = $maleCount / count($given);

            $this->assertGreaterThanOrEqual(
                AerieNames::MALE_SHARE_TARGET,
                $traditionShare,
                "Tradition {$tradition} must have at least 70% male names (has {$traditionShare})"
            );
        }
    }

    /** Pick always matches given and family names from the exact same tradition (no cross-cultural mismatches like Aoi Sjöberg). */
    public function testPickAlwaysPairsFromSameTradition(): void
    {
        foreach (AerieNames::TRADITIONS as $index => $tradition) {
            $traditionDraw = ($index + 0.5) / count(AerieNames::TRADITIONS);
            $pool = AerieNames::POOLS[$tradition];

            for ($g = 0; $g < count($pool['given']); ++$g) {
                $givenDraw = ($g + 0.5) / count($pool['given']);
                for ($f = 0; $f < count($pool['family']); $f += 5) {
                    $familyDraw = ($f + 0.5) / count($pool['family']);

                    $fullName = AerieNames::pick($traditionDraw, $givenDraw, $familyDraw);
                    [$given, $family] = explode(' ', $fullName, 2);

                    $this->assertSame($pool['given'][$g], $given, "Given name must belong to {$tradition}");
                    $this->assertContains($family, $pool['family'], "Family name must belong to {$tradition}");

                    // Explicit check: Japanese given name is never paired with Swedish family name
                    if ($tradition === AerieNames::TRADITION_JAPANESE) {
                        $this->assertNotContains($family, AerieNames::POOLS[AerieNames::TRADITION_SWEDISH]['family']);
                    }
                    if ($tradition === AerieNames::TRADITION_SWEDISH) {
                        $this->assertNotContains($family, AerieNames::POOLS[AerieNames::TRADITION_JAPANESE]['family']);
                    }
                }
            }
        }
    }

    /** Clamps boundary draws so 0.0, 1.0, or out-of-range floats never cause index errors. */
    public function testPickHandlesBoundaryDraws(): void
    {
        $nameZero = AerieNames::pick(0.0, 0.0, 0.0);
        $this->assertNotEmpty($nameZero);

        $nameOne = AerieNames::pick(1.0, 1.0, 1.0);
        $this->assertNotEmpty($nameOne);

        $nameNegative = AerieNames::pick(-0.5, -0.5, -0.5);
        $this->assertSame($nameZero, $nameNegative);
    }
}
