<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Deal;

use App\Data\Company\Sectors;
use App\Service\Corporate\Deal\PrivateCompany;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class PrivateCompanyTest extends TestCase
{
    /** A target is named for the trade it is in: a shoreline landlord is a property company, not heavy industry. */
    public function testATargetIsNamedForItsIndustry(): void
    {
        $this->assertSame('Saltmarsh Properties', PrivateCompany::name([0, 0], 'REIT - Retail'));
        $this->assertSame('Blackwater Petroleum', PrivateCompany::name([15, 0], 'Oil & Gas E&P'));
        $this->assertSame('Saltmarsh Water Works', PrivateCompany::name([0, 0], 'Utilities - Regulated Water'));
        $this->assertSame('Saltmarsh Industries', PrivateCompany::name([0, 0], null));
    }

    /** Every industry the board trades in gets a well-formed coastal name. */
    public function testEveryIndustryGetsACoastalName(): void
    {
        foreach (array_keys(Sectors::INDUSTRY_METRICS) as $industry) {
            foreach ([[0, 0], [7, 6], [15, 11]] as $draws) {
                $name = PrivateCompany::name($draws, $industry);
                $this->assertMatchesRegularExpression('/^[A-Z][a-z]+( [A-Z][a-z]+)? [A-Z&][A-Za-z& ]+$/', $name, $industry);
            }
        }
    }
}
