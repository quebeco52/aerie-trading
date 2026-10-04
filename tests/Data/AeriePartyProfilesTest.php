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
            foreach (['founded', 'voters', 'heartland', 'relations'] as $field) {
                $this->assertNotSame('', $profile[$field], $party . ' ' . $field);
            }
            foreach (['agenda', 'history'] as $list) {
                $this->assertNotEmpty($profile[$list], $party . ' ' . $list);
                foreach ($profile[$list] as $entry) {
                    $this->assertNotSame('', $entry['title'], $party);
                    $this->assertNotSame('', $entry['text'], $party);
                }
            }
        }
    }

    /**
     * Every party says something on each of the four questions, and calls a question its founding principle exactly
     * where the Diet fixes its position, so the prose and the place the page draws it never disagree.
     */
    public function testEachPartySpeaksToEveryQuestionAndNamesItsFoundingPrinciples(): void
    {
        foreach (AeriePartyProfiles::PROFILES as $party => $profile) {
            $this->assertSame(AerieDiet::AXES, array_keys($profile['questions']), $party);
            foreach ($profile['questions'] as $axis => $line) {
                $this->assertSame(AerieDiet::isFixed($party, $axis), str_contains(strtolower($line), 'founding principle'), $party . ' on ' . $axis);
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
