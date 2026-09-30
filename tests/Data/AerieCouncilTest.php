<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\AerieCouncil;
use PHPUnit\Framework\TestCase;

class AerieCouncilTest extends TestCase
{
    public function testTheFoundingCouncilSitsAtTheFounding(): void
    {
        $roster = AerieCouncil::roster(0.0);

        $this->assertCount(AerieCouncil::SEATS, $roster);
        $this->assertSame(AerieCouncil::FOUNDING_MEMBERS, array_column($roster, 'name'));
        $this->assertSame(array_fill(0, AerieCouncil::SEATS, true), array_column($roster, 'founding'));
    }

    /** The founders' terms are staggered over one full term, so a seat falls vacant every 20/13 years, forever. */
    public function testASeatFallsVacantEveryTermOverSeats(): void
    {
        $spacing = AerieCouncil::TERM_YEARS / AerieCouncil::SEATS;
        $vacancies = [];
        $time = 0.0;
        while ($time < 100.0) {
            $next = AerieCouncil::nextVacancy($time)['termEnds'];
            $vacancies[] = $next;
            $time = $next + 1e-6;
        }

        for ($i = 1; $i < count($vacancies); ++$i) {
            $this->assertEqualsWithDelta($spacing, $vacancies[$i] - $vacancies[$i - 1], 1e-9);
        }
    }

    /** When a term ends the seat passes to a successor for a full term, and the roster is the same whenever it is read. */
    public function testAVacantSeatPassesToASuccessorForAFullTerm(): void
    {
        $firstEnd = AerieCouncil::foundingTermEnd(0);
        $seat = AerieCouncil::roster($firstEnd + 0.01)[0];

        $this->assertFalse($seat['founding']);
        $this->assertSame(AerieCouncil::SUCCESSORS[0], $seat['name']);
        $this->assertEqualsWithDelta($firstEnd, $seat['since'], 1e-12);
        $this->assertEqualsWithDelta($firstEnd + AerieCouncil::TERM_YEARS, $seat['termEnds'], 1e-12);
        $this->assertSame(AerieCouncil::roster(37.3), AerieCouncil::roster(37.3));
    }

    /** No two councillors sitting together share a name. */
    public function testSittingCouncillorsAreDistinct(): void
    {
        for ($time = 0.0; $time < 200.0; $time += 0.5) {
            $names = array_column(AerieCouncil::roster($time), 'name');
            $this->assertSame($names, array_unique($names), "Duplicate councillor at year {$time}.");
        }
    }
}
