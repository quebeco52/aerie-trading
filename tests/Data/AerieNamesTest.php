<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\AerieNames;
use App\Data\UsNameFrequencies as Tables;
use PHPUnit\Framework\TestCase;

class AerieNamesTest extends TestCase
{
    /** Every table row carries a positive count and group shares that add to 100%, the Census suppressions filled. */
    public function testTheTablesAreWellFormed(): void
    {
        $groups = count(Tables::GROUPS);
        $this->assertEqualsWithDelta(100.0, array_sum(Tables::POPULATION_SHARES), 0.05);
        $this->assertEqualsWithDelta(100.0, array_sum(Tables::GIVEN_UNLISTED_SHARES), 0.05);

        $previous = PHP_INT_MAX;
        foreach (Tables::SURNAMES as $surname => $row) {
            $this->assertCount($groups + 1, $row, (string) $surname);
            $this->assertLessThanOrEqual($previous, $row[0], 'Most common first.');
            $previous = $row[0];
            $this->assertEqualsWithDelta(100.0, array_sum(array_slice($row, 1)), 0.1, (string) $surname);
            $this->assertSame(trim((string) $surname), (string) $surname);
        }
        foreach (Tables::GIVEN_SHARES as $name => $shares) {
            $this->assertCount($groups, $shares);
            $this->assertEqualsWithDelta(100.0, array_sum($shares), 0.1, (string) $name);
        }

        $decades = array_keys(Tables::GIVEN);
        $this->assertSame(range($decades[0], $decades[count($decades) - 1], 10), $decades, 'One table a decade, none skipped.');
        foreach (Tables::GIVEN as $decade => $sexes) {
            $this->assertSame(['M', 'F'], array_keys($sexes));
            foreach ($sexes as $sex => $names) {
                $this->assertNotEmpty($names, "{$decade}{$sex}");
                $this->assertGreaterThan(0, min($names));
            }
        }
    }

    /** The Census strips apostrophes, spaces and case; the tables restore the usual US spelling. */
    public function testSurnamesKeepTheirUsualSpelling(): void
    {
        foreach (['Smith', 'McDonald', "O'Brien", 'De La Cruz', 'MacDonald', 'Nguyen'] as $surname) {
            $this->assertArrayHasKey($surname, Tables::SURNAMES);
        }
        foreach (array_keys(Tables::SURNAMES) as $surname) {
            $this->assertDoesNotMatchRegularExpression('/^[A-Z]{3,}$/', (string) $surname, 'No surname left in capitals.');
        }
    }

    /**
     * Surname and group come from the Census's surname-group cells, each group scaled to its share of the US Congress: an
     * even sweep of the surname draw, the group draw on a Weyl sequence beside it, lands on each group in that share,
     * whiter than the country (74% to 64%).
     */
    public function testGroupsComeInTheCongresssMix(): void
    {
        $counts = array_fill(0, count(Tables::GROUPS), 0);
        $steps = 50000;
        $golden = (sqrt(5.0) - 1.0) / 2.0;
        for ($i = 0; $i < $steps; ++$i) {
            ++$counts[AerieNames::surname(($i + 0.5) / $steps, fmod(($i + 0.5) * $golden, 1.0))[1]];
        }

        $this->assertSame(533, array_sum(AerieNames::OFFICEHOLDERS), 'The voting members, each once.');
        $this->assertSame(533 - 139, AerieNames::OFFICEHOLDERS[0], 'Pew: 139 identify as Black, Hispanic, Asian American or Native American.');
        foreach (Tables::GROUPS as $group => $label) {
            $this->assertEqualsWithDelta(AerieNames::OFFICEHOLDERS[$group] / 533, $counts[$group] / $steps, 0.002, $label);
        }
        $this->assertGreaterThan(Tables::POPULATION_SHARES[0] / 100.0 + 0.09, $counts[0] / $steps);
    }

    /**
     * A surname comes up as often as its bearers, each counted at their group's weight (its share of the Congress over
     * its share of the listed surnames' bearers): Smith first, Johnson second.
     */
    public function testSurnamesFollowTheirBearers(): void
    {
        $counts = [];
        $steps = 100000;
        for ($i = 0; $i < $steps; ++$i) {
            $surname = AerieNames::surname(($i + 0.5) / $steps, 0.5)[0];
            $counts[$surname] = ($counts[$surname] ?? 0) + 1;
        }
        arsort($counts);

        $this->assertSame(['Smith', 'Johnson'], array_slice(array_keys($counts), 0, 2));
        $bearers = array_fill(0, count(Tables::GROUPS), 0.0);
        foreach (Tables::SURNAMES as $row) {
            foreach (array_slice($row, 1) as $group => $share) {
                $bearers[$group] += $row[0] * $share;
            }
        }
        $weight = static fn(array $row): float => array_sum(array_map(
            static fn(int $group, float $share): float => $row[0] * $share * (AerieNames::OFFICEHOLDERS[$group] / array_sum(AerieNames::OFFICEHOLDERS)) / ($bearers[$group] / array_sum($bearers)),
            array_keys(array_slice($row, 1)),
            array_slice($row, 1)
        ));
        $this->assertEqualsWithDelta($weight(Tables::SURNAMES['Smith']) / array_sum(array_map($weight, Tables::SURNAMES)), $counts['Smith'] / $steps, 0.0005);
        $this->assertCount(count(Tables::SURNAMES), $counts, 'Every listed surname can be drawn.');
    }

    /**
     * Within a decade, sex and group a given name's chance is its applicants times the group's share of its bearers,
     * over the same for every name: Bayes' rule on the decade's names.
     */
    public function testAGivenNameIsWeighedByTheGroupsShareOfItsBearers(): void
    {
        $hispanic = array_search('hispanic', Tables::GROUPS, true);
        $white = array_search('white', Tables::GROUPS, true);
        $this->assertIsInt($hispanic);
        $this->assertIsInt($white);

        $chance = static function (int $group, string $name): float {
            $weight = static fn(string $n, int $applicants): float => $applicants * (Tables::GIVEN_SHARES[$n] ?? Tables::GIVEN_UNLISTED_SHARES)[$group];
            $total = 0.0;
            foreach (Tables::GIVEN[1960]['M'] as $n => $applicants) {
                $total += $weight((string) $n, $applicants);
            }

            return $weight($name, Tables::GIVEN[1960]['M'][$name]) / $total;
        };
        $realized = static function (int $group, string $name): float {
            $hits = 0;
            $steps = 100000;
            for ($i = 0; $i < $steps; ++$i) {
                $hits += AerieNames::givenName(1960, 'M', $group, ($i + 0.5) / $steps) === $name ? 1 : 0;
            }

            return $hits / $steps;
        };

        $this->assertEqualsWithDelta($chance($hispanic, 'Jose'), $realized($hispanic, 'Jose'), 1e-4);
        $this->assertEqualsWithDelta($chance($white, 'Jose'), $realized($white, 'Jose'), 1e-4);
        $this->assertEqualsWithDelta($chance($white, 'David'), $realized($white, 'David'), 1e-4);
        $this->assertGreaterThan(20.0 * $chance($white, 'Jose'), $chance($hispanic, 'Jose'), 'Jose is far likelier for a Hispanic surname.');
        foreach (Tables::GIVEN as $decade => $sexes) {
            foreach (array_keys($sexes) as $sex) {
                foreach (array_keys(Tables::GROUPS) as $group) {
                    $this->assertNotSame('', AerieNames::givenName($decade, $sex, $group, 0.999), 'Every group has names to draw in every decade.');
                }
            }
        }
    }

    /** Year 1 is 2009: someone born 50 years before it is named from the 1950s, and births beyond the tables from their ends. */
    public function testTheBirthDecadePicksTheTable(): void
    {
        $this->assertSame(1950, AerieNames::birthDecade(-50.0));
        $this->assertSame(2000, AerieNames::birthDecade(0.0));
        $this->assertSame(2000, AerieNames::birthDecade(0.999));
        $this->assertSame(array_key_first(Tables::GIVEN), AerieNames::birthDecade(-500.0));
        $this->assertSame(array_key_last(Tables::GIVEN), AerieNames::birthDecade(300.0));
    }

    /** Seven draws in ten are men; the given name comes from the birth decade's table for the sex, the surname from the Census. */
    public function testAPickJoinsTheDecadesGivenNameToASurname(): void
    {
        $men = 0;
        $steps = 1000;
        foreach ([-55.0, 5.0] as $birth) {
            $decade = AerieNames::birthDecade($birth);
            for ($i = 0; $i < $steps; ++$i) {
                $sexDraw = ($i + 0.5) / $steps;
                $sex = $sexDraw < AerieNames::MALE_SHARE ? 'M' : 'F';
                $men += $sex === 'M' ? 1 : 0;
                [$surname, $group] = AerieNames::surname(0.37, 0.61);
                $given = AerieNames::givenName($decade, $sex, $group, 0.42);

                $this->assertSame("{$given} {$surname}", AerieNames::pick($birth, 0.37, 0.61, $sexDraw, 0.42));
                $this->assertArrayHasKey($given, Tables::GIVEN[$decade][$sex]);
                $this->assertArrayHasKey($surname, Tables::SURNAMES);
            }
        }

        $this->assertSame(1400, $men);
    }

    /** Draws at or past the ends of [0, 1) are clamped, never out of the tables. */
    public function testBoundaryDrawsStayInTheTables(): void
    {
        $this->assertSame(AerieNames::pick(-40.0, 0.0, 0.0, 0.0, 0.0), AerieNames::pick(-40.0, -0.5, -0.5, -0.5, -0.5));
        $this->assertSame(AerieNames::pick(-40.0, 1.0, 1.0, 1.0, 1.0), AerieNames::pick(-40.0, 1.5, 1.5, 1.5, 1.5));
    }

    /** Every famous name the tables can make is listed, and only those; the best known of them are caught. */
    public function testTheFamousNamesAreDrawable(): void
    {
        $given = [];
        foreach (Tables::GIVEN as $sexes) {
            foreach ($sexes as $names) {
                $given += $names;
            }
        }
        foreach (Tables::FAMOUS as $name) {
            $split = false;
            $parts = explode(' ', $name);
            for ($cut = 1; $cut < count($parts) && !$split; ++$cut) {
                $split = isset($given[implode(' ', array_slice($parts, 0, $cut))]) && isset(Tables::SURNAMES[implode(' ', array_slice($parts, $cut))]);
            }
            $this->assertTrue($split, "{$name} is a given name and a listed surname.");
        }
        foreach (['Michael Jordan', 'George Washington', 'Alexander Hamilton', 'Jerome Powell', 'Robert Rubin', 'Henry Paulson'] as $famous) {
            $this->assertTrue(AerieNames::isFamous($famous), $famous);
        }
        $this->assertFalse(AerieNames::isFamous('Joyce Hall'));
    }

    /** A draw that lands on a famous name or one already taken is drawn again on fresh uniforms; the same uniforms give the same name. */
    public function testADrawPassesOverFamousAndTakenNames(): void
    {
        $uniform = static fn(string $seed): \Closure => static fn(string $key): float => (hexdec(substr(hash('sha256', "{$seed}:{$key}"), 0, 13)) + 0.5) / (2 ** 52);

        $famousSeed = null;
        for ($i = 0; $famousSeed === null; ++$i) {
            $draw = $uniform("famous:{$i}");
            if (AerieNames::isFamous(AerieNames::pick(-45.0, $draw('surname:0'), $draw('group:0'), $draw('sex:0'), $draw('given:0')))) {
                $famousSeed = "famous:{$i}";
            }
        }
        $draw = $uniform($famousSeed);
        $first = AerieNames::pick(-45.0, $draw('surname:0'), $draw('group:0'), $draw('sex:0'), $draw('given:0'));
        $named = AerieNames::draw(-45.0, $draw, []);
        $this->assertNotSame($first, $named);
        $this->assertFalse(AerieNames::isFamous($named));

        $draw = $uniform('taken');
        $free = AerieNames::draw(-45.0, $draw, []);
        $this->assertSame($free, AerieNames::draw(-45.0, $draw, []));
        $this->assertNotSame($free, AerieNames::draw(-45.0, $draw, [$free]));
    }
}
