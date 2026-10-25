<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\IndustryOutlooks;
use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class IndustryOutlooksTest extends TestCase
{
    /**
     * An outlook only means something where the industry's price answers its capacity: a lender sells a yield, output
     * declared non-substitutable skips the capacity balance, and a walk there would only rescale every other industry.
     */
    public function testNoOutlookDriftsWhereThereIsNoCapacityBalanceToMove(): void
    {
        foreach (array_unique(array_column(InitialMarket::STOCKS, 'industry')) as $industry) {
            $strategy = Sectors::strategyFor((string) $industry);
            if ($strategy->isFinancial() || $strategy->getIndustrySubstitutability() <= 0.0) {
                $this->assertSame(0.0, IndustryOutlooks::driftVol((string) $industry), (string) $industry);
            }
        }
    }

    public function testEveryListedPlantIndustryHasASettingAndTheyRunFromSettledToVolatile(): void
    {
        foreach (array_unique(array_column(InitialMarket::STOCKS, 'industry')) as $industry) {
            $strategy = Sectors::strategyFor((string) $industry);
            if (!$strategy->isFinancial() && $strategy->getIndustrySubstitutability() > 0.0) {
                $this->assertGreaterThanOrEqual(0.0, IndustryOutlooks::driftVol((string) $industry), (string) $industry);
                $this->assertGreaterThan(0.0, IndustryOutlooks::learningHalfLife((string) $industry), (string) $industry);
            }
        }
        $this->assertLessThan(IndustryOutlooks::driftVol('Semiconductors'), IndustryOutlooks::driftVol('Grocery Stores'));
        $this->assertSame(0.0, IndustryOutlooks::driftVol('Gold'), 'a world commodity is priced by the macro');
    }
}
