<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Service\Math\MacroConstants;
use App\Service\Politics\Policy\Lever;
use App\Service\View\LawCatalogue;
use PHPUnit\Framework\TestCase;

/** The government pages list every Diet law once, each under a name of its own. */
final class LawCatalogueTest extends TestCase
{
    public function testThePagesListEveryLawOnce(): void
    {
        $listed = array_map(static fn(Lever $lever): string => $lever->value, LawCatalogue::ORDER);
        $all = array_map(static fn(Lever $lever): string => $lever->value, Lever::cases());

        $this->assertSame(array_unique($listed), $listed, 'No law is listed twice.');
        $this->assertEqualsCanonicalizing($all, $listed, 'A new lever needs its place in the order.');
    }

    public function testEachLawHasANameAndAShortNameOfItsOwn(): void
    {
        $names = array_map(LawCatalogue::name(...), Lever::cases());
        $short = array_map(LawCatalogue::shortName(...), Lever::cases());

        $this->assertSame(array_unique($names), $names);
        $this->assertSame(array_unique($short), $short);
    }

    /** The page shows the corporate rate itself, and the extraction rules as what they add to cost. */
    public function testTheCorporateTaxShowsAsTheRateAndTheFoundingRulesAddNothing(): void
    {
        $this->assertSame(MacroConstants::BASE_CORPORATE_TAX_RATE - 0.02, LawCatalogue::display(Lever::CorporateTax, -0.02));
        $this->assertEqualsWithDelta(0.0, LawCatalogue::display(Lever::Extraction, 0.0), 1e-12);
        $this->assertGreaterThan(0.0, LawCatalogue::display(Lever::Extraction, 1.0));
        $this->assertSame(0.031, LawCatalogue::display(Lever::BankLevy, 0.031));
        $this->assertSame('usd_t', LawCatalogue::unit(Lever::CarbonPrice));
    }
}
