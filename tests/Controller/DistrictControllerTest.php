<?php

namespace App\Tests\Controller;

use App\Data\DistrictMap;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DistrictControllerTest extends WebTestCase
{
    public function testIndexRedirectsToTheStreet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/district');

        $this->assertResponseRedirects('/district/glasswater-row');
    }

    public function testStreetElevationRendersSuccessfully(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', DistrictMap::WARD_NAME);
        $this->assertSelectorExists('[data-controller="district"]');
        $this->assertSelectorExists('svg #skyline');
        $this->assertSelectorExists('[data-district-target="detail"]');

        // The roster is a live ranking, not a fixed set — this pins the count, not which
        // companies qualify. At most STREET_ROSTER_SIZE, since InitialMarket seeds more listed
        // companies than that.
        $this->assertLessThanOrEqual(
            DistrictMap::STREET_ROSTER_SIZE,
            $crawler->filter('[data-district-target="plot"]')->count()
        );
        $this->assertGreaterThan(0, $crawler->filter('[data-district-target="plot"]')->count());
    }

    public function testUnknownWardReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/district/no-such-ward');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testEveryInstitutionRendersWithAStressAttribute(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $institutions = $crawler->filter('[data-district-target="institution"]');
        $this->assertGreaterThan(0, $institutions->count(), 'The street should draw at least one institution');

        $institutions->each(function ($node) {
            $this->assertContains($node->attr('data-stressed'), ['true', 'false']);
            $this->assertNotEmpty($node->attr('data-institution'));
            $this->assertArrayHasKey($node->attr('data-institution'), DistrictMap::INSTITUTIONS);
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
        $this->assertGreaterThan(0, $conduits->count(), 'The street should render at least one macro conduit');

        $conduits->each(function ($node) {
            $this->assertNotEmpty($node->attr('data-conduit-institution'));
            $this->assertNotEmpty($node->attr('data-conduit-building'));
            $this->assertArrayHasKey($node->attr('data-conduit-institution'), DistrictMap::INSTITUTIONS);
        });
    }

    public function testEveryPlotRendersAFlareAndABadge(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $plots = $crawler->filter('[data-district-target="plot"]');
        $flares = $crawler->filter('[data-district-target="flare"]');
        $badges = $crawler->filter('[data-district-target="badge"]');

        $this->assertGreaterThan(0, $plots->count());
        $this->assertCount($plots->count(), $flares, 'Every plot should carry one reusable flare element');
        $this->assertCount($plots->count(), $badges, 'Every plot should carry one event badge');

        $badges->each(function ($node) {
            $this->assertNotEmpty($node->attr('data-badge-for'));
            $this->assertIsNumeric($node->attr('data-count'));
        });
    }

    public function testRevenueMixValueCoversEveryTenant(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $tickers = $crawler->filter('[data-district-target="plot"]')->each(fn ($node) => $node->attr('data-ticker'));

        $raw = $crawler->filter('[data-controller="district"]')->attr('data-district-revenue-mix-value');
        $this->assertNotNull($raw);

        $revenueMix = json_decode($raw, true);
        $this->assertIsArray($revenueMix);

        foreach ($tickers as $ticker) {
            $this->assertArrayHasKey($ticker, $revenueMix, "Revenue mix value is missing tenant {$ticker}");
            $this->assertArrayHasKey('totalRevenue', $revenueMix[$ticker]);
            $this->assertArrayHasKey('streams', $revenueMix[$ticker]);
            $this->assertIsArray($revenueMix[$ticker]['streams']);
        }
    }

    public function testInstitutionsValueCoversEveryRenderedInstitution(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $raw = $crawler->filter('[data-controller="district"]')->attr('data-district-institutions-value');
        $this->assertNotNull($raw);

        $institutions = json_decode($raw, true);
        $this->assertIsArray($institutions);

        $renderedIds = $crawler->filter('[data-district-target="institution"]')->each(fn ($node) => $node->attr('data-institution'));
        $shippedIds = array_column($institutions, 'id');

        sort($renderedIds);
        sort($shippedIds);
        $this->assertSame($renderedIds, $shippedIds);

        foreach ($institutions as $institution) {
            $this->assertArrayHasKey('readouts', $institution);
            $this->assertArrayHasKey('stressRules', $institution);
        }
    }

    public function testPlotsCarrySelectableCompanyData(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        // The roster changes with live market cap, so this asserts the shape every plot carries
        // rather than which specific company holds frontage — see DistrictWardComposerTest for
        // the ranking rule itself.
        $plots = $crawler->filter('[data-district-target="plot"]');
        $this->assertGreaterThan(0, $plots->count());

        $plots->each(function ($node) {
            $this->assertNotEmpty($node->attr('data-ticker'));
            $this->assertNotEmpty($node->attr('data-name'));
            $this->assertNotEmpty($node->attr('data-mcap'));
            $this->assertNotEmpty($node->attr('data-rank'));
            // Each row stands on its own ground line, and the client reads it from here rather
            // than from one shared value — without it every lower-row facade would jump to the
            // upper row on the first live tick.
            $this->assertNotEmpty($node->attr('data-ground-line'));
            $this->assertStringContainsString('district#select', (string) $node->attr('data-action'));
            $this->assertStringContainsString('district#showTooltip', (string) $node->attr('data-action'));
        });
    }

    public function testTheStreetWrapsIntoTwoRowsEachWithItsOwnGroundLine(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $this->assertSelectorExists('svg #skyline-row-0');
        $this->assertSelectorExists('svg #skyline-row-1');

        $groundLines = array_unique($crawler->filter('[data-district-target="plot"]')
            ->each(fn ($node) => $node->attr('data-ground-line')));

        $this->assertCount(2, $groundLines, 'A full roster should occupy both rows, at two distinct ground lines');
    }

    public function testEveryPlotCarriesAKerbPlateWithRankAndPrice(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $plotCount = $crawler->filter('[data-district-target="plot"]')->count();

        $this->assertCount($plotCount, $crawler->filter('[data-district-target="rank"]'));
        $this->assertCount($plotCount, $crawler->filter('[data-district-target="label"]'));
        $this->assertCount($plotCount, $crawler->filter('[data-district-target="kerbPrice"]'));
        $this->assertCount($plotCount, $crawler->filter('[data-district-target="kerbChange"]'));
    }

    public function testMarketCapGridlinesAreDrawnForEveryRow(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $rows = count($crawler->filter('svg g[id^="skyline-row-"]'));

        $this->assertCount(
            $rows * count(DistrictMap::MARKET_CAP_GRIDLINES),
            $crawler->filter('svg .gridline'),
            'A cap maps to a facade height, so each row needs its own set of reference rules'
        );
    }

    public function testSummaryBarAndSectorLegendRender(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-district-target="summaryStress"]');

        // One legend swatch per macro sector.
        $this->assertGreaterThanOrEqual(
            count(DistrictMap::SECTOR_PALETTE),
            $crawler->filter('.rounded-full')->count()
        );
    }

    public function testTooltipAndInstitutionPanelAreAvailable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-district-target="tooltip"]');
        $this->assertSelectorExists('[data-district-target="institutionDetail"]');
    }
}
