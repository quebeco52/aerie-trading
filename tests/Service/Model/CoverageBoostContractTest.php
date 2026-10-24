<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Company\Sectors;
use App\Entity\Stock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Titans and systemic firms get more analyst coverage, whatever model they run on. Eleven of them did not: their models
 * overrode the coverage profile only to declare how visible an announced event is, and the override skipped the boost.
 * Event visibility is now a pair of hook constants the shared profile reads.
 */
#[Group('unit')]
final class CoverageBoostContractTest extends TestCase
{
    public function testEveryModelGivesTitansAndSystemicFirmsTheCoverageBoost(): void
    {
        foreach (array_keys(Sectors::BUSINESS_MODELS) as $identifier) {
            $model = Sectors::getBusinessModelStrategy($identifier);
            $profile = static fn(?string $tier): \App\DTO\SectorCoverageProfile => $model->getCoverageProfile(
                $tier === null ? (new Stock())->setTicker('ZZZZ') : (new Stock())->setTicker('ZZZZ')->setSystemicImportance($tier),
            );
            $plain = $profile(null);

            foreach (['systemic' => [0.10, 0.05], 'titan' => [0.15, 0.10]] as $tier => [$visibility, $floor]) {
                $boosted = $profile($tier);
                $this->assertEqualsWithDelta(min(1.0, $plain->baseVisibility + $visibility), $boosted->baseVisibility, 1e-12, "{$identifier} {$tier}");
                $this->assertEqualsWithDelta(min(1.0, $plain->minVisibility + $floor), $boosted->minVisibility, 1e-12, "{$identifier} {$tier}");
                $this->assertSame($plain->eventBaseVisibility, $boosted->eventBaseVisibility, "{$identifier} {$tier}");
            }
        }
    }
}
