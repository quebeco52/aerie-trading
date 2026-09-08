<?php

namespace App\Tests\Controller;

use App\Data\DistrictMap;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DistrictControllerTest extends WebTestCase
{
    public function testWardElevationRendersSuccessfully(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Glasswater Row');
        $this->assertSelectorExists('[data-controller="district"]');
        $this->assertSelectorExists('svg #skyline');
        $this->assertSelectorExists('[data-district-target="detail"]');

        $this->assertCount(
            count(DistrictMap::plotsForWard('glasswater-row')),
            $crawler->filter('[data-district-target="plot"]'),
            'Every authored plot should be rendered on the elevation'
        );
    }

    public function testEveryInstitutionRendersWithAStressAttribute(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $institutions = $crawler->filter('[data-district-target="institution"]');
        $this->assertCount(count(DistrictMap::INSTITUTIONS), $institutions);

        $institutions->each(function ($node) {
            $this->assertContains($node->attr('data-stressed'), ['true', 'false']);
            $this->assertNotEmpty($node->attr('data-institution'));
        });
    }

    public function testConduitsOnlyConnectWiredTenantsToTheirInstitutions(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        // Which conduits exist per tenant is pinned by DistrictConduitTopologyTest; this only
        // checks that whatever renders here is well-formed and references real institutions.
        $conduits = $crawler->filter('[data-district-target="conduit"]');
        $this->assertGreaterThan(0, $conduits->count(), 'Glasswater Row should render at least one macro conduit');

        $conduits->each(function ($node) {
            $this->assertNotEmpty($node->attr('data-conduit-institution'));
            $this->assertNotEmpty($node->attr('data-conduit-building'));
            $this->assertArrayHasKey($node->attr('data-conduit-institution'), DistrictMap::INSTITUTIONS);
        });
    }

    public function testEveryOccupiedPlotRendersAFlareAndABadge(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $occupiedTickers = array_values(array_filter(array_map(
            static fn (array $plot): ?string => $plot['ticker'],
            DistrictMap::plotsForWard('glasswater-row'),
        )));

        $flares = $crawler->filter('[data-district-target="flare"]');
        $badges = $crawler->filter('[data-district-target="badge"]');

        $this->assertCount(count($occupiedTickers), $flares, 'Every occupied plot should carry one reusable flare element');
        $this->assertCount(count($occupiedTickers), $badges, 'Every occupied plot should carry one event badge');

        $badges->each(function ($node) {
            $this->assertNotEmpty($node->attr('data-badge-for'));
            $this->assertIsNumeric($node->attr('data-count'));
        });
    }

    public function testRevenueMixValueCoversEveryOccupiedTenant(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $occupiedTickers = array_values(array_filter(array_map(
            static fn (array $plot): ?string => $plot['ticker'],
            DistrictMap::plotsForWard('glasswater-row'),
        )));

        $raw = $crawler->filter('[data-controller="district"]')->attr('data-district-revenue-mix-value');
        $this->assertNotNull($raw);

        $revenueMix = json_decode($raw, true);
        $this->assertIsArray($revenueMix);

        foreach ($occupiedTickers as $ticker) {
            $this->assertArrayHasKey($ticker, $revenueMix, "Revenue mix value is missing tenant {$ticker}");
            $this->assertArrayHasKey('totalRevenue', $revenueMix[$ticker]);
            $this->assertArrayHasKey('streams', $revenueMix[$ticker]);
            $this->assertIsArray($revenueMix[$ticker]['streams']);
        }
    }

    public function testOccupiedPlotsCarrySelectableCompanyData(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $lakebird = $crawler->filter('[data-ticker="LAKE"]');
        $this->assertCount(1, $lakebird, 'Lakebird Bank should hold frontage on Glasswater Row');
        $this->assertNotEmpty($lakebird->attr('data-name'));
        $this->assertNotEmpty($lakebird->attr('data-mcap'));
        $this->assertStringContainsString('district#select', (string) $lakebird->attr('data-action'));
    }

    public function testUnknownWardReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/district/no-such-ward');

        $this->assertResponseStatusCodeSame(404);
    }
}
