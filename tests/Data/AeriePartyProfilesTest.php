<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\AerieDiet;
use App\Data\AeriePartyProfiles;
use PHPUnit\Framework\TestCase;

class AeriePartyProfilesTest extends TestCase
{
    /** Every party in the Diet has a profile, and only they do. */
    public function testEveryPartyHasAProfile(): void
    {
        $this->assertEqualsCanonicalizing(AerieDiet::PARTIES, array_keys(AeriePartyProfiles::PROFILES));
        foreach (AeriePartyProfiles::PROFILES as $party => $profile) {
            $this->assertNotSame('', $profile['family'], $party);
            $this->assertNotSame('', $profile['motto'], $party);
            $this->assertNotEmpty($profile['about'], $party);
            $this->assertNotEmpty($profile['agenda'], $party);
            foreach ($profile['agenda'] as $plank) {
                $this->assertNotSame('', $plank['title'], $party);
                $this->assertNotSame('', $plank['text'], $party);
            }
        }
    }

    /** Each party has its own address, one the party route accepts, and the address leads back to it. */
    public function testEachAddressLeadsToItsParty(): void
    {
        $slugs = array_column(AeriePartyProfiles::PROFILES, 'slug');
        $this->assertSame($slugs, array_unique($slugs));
        foreach (AeriePartyProfiles::PROFILES as $party => $profile) {
            $this->assertMatchesRegularExpression('/^[a-z-]+$/', $profile['slug']);
            $this->assertSame($party, AeriePartyProfiles::partyForSlug($profile['slug']));
        }
        $this->assertNull(AeriePartyProfiles::partyForSlug('the-council'));
    }
}
