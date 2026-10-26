<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\IndustryAdjacency;
use App\Data\Company\Sectors;
use App\Service\Corporate\Segment\SegmentEntry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class IndustryAdjacencyTest extends TestCase
{
    /**
     * Every link joins two industries the game knows, at one of the three closenesses, once; every industry a company
     * can enter is one whose business can be built on plant its market prices, and has a name for the business; and an
     * industry no company enters is never offered as a neighbour.
     */
    public function testEveryLinkLeadsWhereABusinessCanBeBuilt(): void
    {
        $known = array_keys((array) (new \ReflectionClassConstant(Sectors::class, 'INDUSTRY_METRICS'))->getValue());
        $seen = [];
        $targets = [];
        foreach (IndustryAdjacency::pairs() as [$first, $second, $closeness]) {
            $this->assertContains($first, $known, $first);
            $this->assertContains($second, $known, $second);
            $this->assertNotSame($first, $second);
            $this->assertContains($closeness, [IndustryAdjacency::CLOSE, IndustryAdjacency::RELATED, IndustryAdjacency::DISTANT]);
            $key = implode('|', [min($first, $second), max($first, $second)]);
            $this->assertArrayNotHasKey($key, $seen, "{$first} and {$second} are linked once");
            $seen[$key] = true;
            $open = array_diff([$first, $second], IndustryAdjacency::sourcesOnly());
            $this->assertNotEmpty($open, "{$first} and {$second}: one of them can be entered");
            $targets = [...$targets, ...$open];
        }

        foreach (array_unique($targets) as $industry) {
            $this->assertTrue(SegmentEntry::isEnterable($industry, Sectors::strategyFor($industry)), "{$industry} can be built into");
            $this->assertNotNull(IndustryAdjacency::label($industry), "{$industry} has a business name");
        }
        foreach (IndustryAdjacency::sourcesOnly() as $industry) {
            $this->assertContains($industry, $known, $industry);
            foreach ($known as $any) {
                $this->assertArrayNotHasKey($industry, IndustryAdjacency::neighbours($any), "nothing enters {$industry}");
            }
        }
    }

    /** A link runs both ways between two industries a company can enter, and one way out of one it cannot. */
    public function testNeighboursRunBothWaysWhereBothCanBeEntered(): void
    {
        $this->assertSame(IndustryAdjacency::RELATED, IndustryAdjacency::neighbours('Auto Manufacturers')['Farm & Heavy Construction Machinery'] ?? null);
        $this->assertSame(IndustryAdjacency::RELATED, IndustryAdjacency::neighbours('Farm & Heavy Construction Machinery')['Auto Manufacturers'] ?? null);
        $this->assertSame(IndustryAdjacency::RELATED, IndustryAdjacency::neighbours('Railroads')['Passenger Transit'] ?? null);
        $this->assertArrayNotHasKey('Railroads', IndustryAdjacency::neighbours('Passenger Transit'), 'no one lays a railroad');
        $this->assertSame([], IndustryAdjacency::neighbours('Banks - Regional'));
    }
}
