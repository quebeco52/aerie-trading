<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\AerieCouncil;
use PHPUnit\Framework\TestCase;

class AerieCouncilTest extends TestCase
{
    /** The Council sitting at Year 1 was seated before it, each councillor a full term before their term ends. */
    public function testTheCouncilAtYearOneWasSeatedBeforeIt(): void
    {
        $roster = AerieCouncil::roster(0.0);

        $this->assertCount(AerieCouncil::SEATS, $roster);
        $this->assertCount(AerieCouncil::SEATS, AerieCouncil::OPENING_MEMBERS);
        $this->assertSame(array_fill(0, AerieCouncil::SEATS, true), array_column($roster, 'beforeYearOne'));
        foreach ($roster as $seat) {
            $this->assertLessThan(0.0, $seat['since']);
            $this->assertEqualsWithDelta(AerieCouncil::TERM_YEARS, $seat['termEnds'] - $seat['since'], 1e-12);
        }
    }

    /** The terms are staggered over one full term, so a seat falls vacant every 20/13 years, forever. */
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

    /** When a term ends the seat passes to a successor (App\Service\Politics\MonetaryAuthority names them) for a full term, and the roster is the same whenever it is read. */
    public function testAVacantSeatPassesToASuccessorForAFullTerm(): void
    {
        $firstEnd = AerieCouncil::openingTermEnd(0);
        $seat = AerieCouncil::roster($firstEnd + 0.01)[0];

        $this->assertFalse($seat['beforeYearOne']);
        $this->assertEqualsWithDelta($firstEnd, $seat['since'], 1e-12);
        $this->assertEqualsWithDelta($firstEnd + AerieCouncil::TERM_YEARS, $seat['termEnds'], 1e-12);
        $this->assertSame(AerieCouncil::roster(37.3), AerieCouncil::roster(37.3));
    }

    /** No two of the people seated before Year 1 share a name. */
    public function testThePeopleAtYearOneAreDistinct(): void
    {
        $names = array_merge(AerieCouncil::OPENING_MEMBERS, [AerieCouncil::OPENING_GOVERNOR]);
        $this->assertSame($names, array_values(array_unique($names)));
    }
}
