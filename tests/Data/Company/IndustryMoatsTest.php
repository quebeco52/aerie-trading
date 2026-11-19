<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\IndustryBetas;
use App\Data\Company\IndustryMoats;
use App\Data\Company\InitialMarket;
use App\Data\Company\Moat;
use App\Data\Company\Sectors;
use App\Entity\Stock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The moat ladder and the industry moats firms are set around: every board industry has one, lore names a step its
 * row can hold, and a firm sits within the band around its industry.
 */
#[Group('unit')]
class IndustryMoatsTest extends TestCase
{
    public function testTheLadderWidensStepByStep(): void
    {
        $previous = -1.0;
        foreach (Moat::cases() as $rank => $moat) {
            $this->assertSame($rank, $moat->rank());
            $this->assertGreaterThan($previous, $moat->spread(), "{$moat->value} earns more than the step below it.");
            $previous = $moat->spread();
        }
        $this->assertSame(0.0, Moat::None->spread(), 'No moat earns nothing over the cost of capital.');
        $this->assertSame(Moat::None, Moat::Narrow->shifted(-3), 'A shift stops at the bottom of the ladder.');
        foreach (IndustryMoats::INDUSTRY_MAP as $damodaran) {
            $this->assertArrayHasKey($damodaran, IndustryMoats::DAMODARAN);
        }
        $this->assertSame(Moat::VeryWide, Moat::Wide->shifted(3), 'And at the top.');
    }

    /**
     * Every Damodaran industry a board industry maps to has a moat of its own, not the fallback; so has every remap.
     */
    public function testEveryBoardIndustryHasAMoat(): void
    {
        foreach (array_keys(Sectors::INDUSTRY_METRICS) as $industry) {
            $damodaran = IndustryMoats::INDUSTRY_MAP[$industry] ?? IndustryBetas::damodaranIndustryFor($industry);
            $this->assertArrayHasKey($damodaran, IndustryMoats::DAMODARAN, "{$industry} has no industry moat.");
        }
    }

    /**
     * A row names a step on the ladder within the band around its industry's: forRow() holds a step past the band to
     * its edge, and a row that needs the hold is a typo or another industry's economics.
     */
    public function testEveryFirmSitsWithinTheBandOfItsIndustry(): void
    {
        foreach (InitialMarket::STOCKS as $row) {
            if (!isset($row['moat'])) {
                continue;
            }
            $named = Moat::tryFrom((string) $row['moat']);
            $this->assertNotNull($named, "{$row['ticker']} names a moat off the ladder.");
            $industry = IndustryMoats::forIndustry($row['industry']);
            $this->assertLessThanOrEqual(
                IndustryMoats::LORE_BAND_STEPS,
                abs($named->rank() - $industry->rank()),
                "{$row['ticker']} names {$named->value}, past the band around its industry's {$industry->value}.",
            );
            $this->assertNotSame($industry, $named, "{$row['ticker']} names its industry's moat: leave it out.");
        }
    }

    public function testARowPastTheBandIsHeldToItsEdge(): void
    {
        $industry = IndustryMoats::forIndustry('Airlines');
        $row = ['ticker' => 'TEST', 'industry' => 'Airlines', 'moat' => $industry->shifted(IndustryMoats::LORE_BAND_STEPS + 1)->value];

        $this->assertSame($industry->shifted(IndustryMoats::LORE_BAND_STEPS), IndustryMoats::forRow($row));
    }

    public function testAFirmTheSeedDoesNotListTakesItsIndustrysMoat(): void
    {
        $stock = (new Stock())->setTicker('NEWCO')->setIndustry('Airlines');

        $this->assertSame(IndustryMoats::forIndustry('Airlines'), IndustryMoats::forStock($stock));
    }
}
