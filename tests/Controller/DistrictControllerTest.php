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
            // Orthogonal routing: the client redraws the last drop from these on every tick.
            $this->assertIsNumeric($node->attr('data-lane-y'));
            $this->assertIsNumeric($node->attr('data-tx'));
        });
    }

    public function testEveryPlotCarriesRoofFurnitureAKerbLightAndKeyedWindows(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $plots = $crawler->filter('[data-district-target="plot"]');
        $this->assertGreaterThan(0, $plots->count());
        $this->assertCount($plots->count(), $crawler->filter('.roof-furniture'), 'Every plot should carry one roof furniture symbol');
        $this->assertCount($plots->count(), $crawler->filter('[data-district-target="kerbLight"]'), 'Every plot should carry one kerb light');

        $plots->each(function ($node) {
            $this->assertContains($node->attr('data-return-field'), ['current_roe', 'current_roic']);
        });

        $windows = $crawler->filter('.window');
        $this->assertGreaterThan(0, $windows->count());
        $windows->each(function ($node) {
            $this->assertIsNumeric($node->attr('data-key'));
        });
        $this->assertGreaterThan(0, $crawler->filter('.window[data-twinkle="true"]')->count(), 'Some windows should flicker');

        $this->assertCount(
            $crawler->filter('[data-district-target="institutionReadout"] .readout-value')->count(),
            $crawler->filter('[data-district-target="readoutSpark"]'),
            'Every readout should carry a sparkline strip',
        );
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

    public function testTheStreetWrapsIntoEveryRowEachWithItsOwnGroundLine(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        for ($row = 0; $row < DistrictMap::ROW_COUNT; $row++) {
            $this->assertSelectorExists(sprintf('svg #skyline-row-%d', $row));
        }

        $groundLines = array_unique($crawler->filter('[data-district-target="plot"]')
            ->each(fn ($node) => $node->attr('data-ground-line')));

        $this->assertCount(DistrictMap::ROW_COUNT, $groundLines, 'A full roster should occupy every row, each at its own ground line');
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
        $gridlines = $crawler->filter('svg .gridline')->count();

        // Each row's sky is sized to its own skyline, so lower/shorter rows carry fewer rules than
        // the top row, but every row carries at least one reference rule.
        $this->assertGreaterThan(0, $rows);
        $this->assertGreaterThanOrEqual($rows, $gridlines);
    }

    public function testSectorBracketsRunAlongTheKerb(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();

        $runs = $crawler->filter('[data-district-target="districtRun"]');
        $this->assertGreaterThan(0, $runs->count(), 'A street with tenants has at least one district run');
        $runs->each(function ($node) {
            foreach (explode('|', (string) $node->attr('data-sectors')) as $sector) {
                $this->assertArrayHasKey($sector, DistrictMap::SECTOR_PALETTE, 'Every trade a bracket lists is a legend chip');
            }
        });

        $this->assertCount(
            count(DistrictMap::SECTOR_PALETTE),
            $crawler->filter('[data-district-target="sectorChip"]'),
            'Every sector in the legend is a filter chip'
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

    public function testTerraceEmbankmentRendersAndTheWaterReflectionDoesNot(): void
    {
        $client = static::createClient();
        $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('svg #terrace-embankment', 'Terrace embankment wall should render');
        // The mirrored-water effect was removed at the user's request and must not creep back.
        $this->assertSelectorNotExists('svg #glasswater-reflections');
        $this->assertSelectorNotExists('svg .facade-reflection');
        $this->assertSelectorNotExists('svg .water-shimmer');
    }

    public function testConduitFilterModeToolbarRendersWithAllOptions(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();
        $buttons = $crawler->filter('[data-district-target="conduitModeBtn"]');
        $this->assertCount(4, $buttons, 'Toolbar should contain 4 conduit filter mode buttons');

        $modes = $buttons->each(fn ($node) => $node->attr('data-mode'));
        $this->assertSame(['all', 'stressed', 'focused', 'muted'], $modes);
    }

    public function testPositionAndQuickTradeElementsRenderForGuest(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-district-target="detailPositionWrap"]');
        $this->assertSelectorExists('[data-district-target="detailPosition"]');
        $this->assertSelectorExists('[data-district-target="tooltipPosition"]');

        $container = $crawler->filter('[data-controller="district"]');
        $this->assertNotEmpty($container->attr('data-district-user-holdings-value'));
        $this->assertIsNumeric($container->attr('data-district-user-cash-value'));
        $this->assertContains($container->attr('data-district-margin-enabled-value'), ['true', 'false']);
    }

    public function testQuickTradeFormRendersForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $userRepo = static::getContainer()->get(\App\Repository\UserRepository::class);
        $testUser = $userRepo->findOneBy(['email' => 'test.test@test.se']);

        if (!$testUser) {
            $this->markTestSkipped('Test user not found in test database.');
        }

        $client->loginUser($testUser);
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-district-target="quickTradeForm"]');
        $this->assertSelectorExists('[data-district-target="quickTradeTickerInput"]');
        $this->assertSelectorExists('[data-district-target="quickTradeHolding"]');
        $this->assertSelectorExists('[data-district-target="quickTradeEstimate"]');
        $this->assertSelectorExists('[data-district-target="quickTradeQuantity"]');
        $this->assertSelectorExists('[data-district-target="quickTradeSubmit"]');
    }

    public function testTheStreetIsAReloadableFrameWithAReconstitutionCountdown(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/district/glasswater-row');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('turbo-frame#district-ward [data-controller="district"]');
        $this->assertSelectorExists('[data-district-target="summaryReconstitution"]');
        $this->assertSelectorExists('[data-district-target="reconstitutionNotice"]');
        $this->assertMatchesRegularExpression(
            '/^(in \d+d|now)$/',
            trim($crawler->filter('[data-district-target="summaryReconstitution"]')->text())
        );

        $schedule = json_decode($crawler->filter('[data-controller="district"]')->attr('data-district-reconstitution-value'), true);
        $this->assertIsArray($schedule);
        $this->assertGreaterThan(0, $schedule['nextTick']);
        $this->assertGreaterThan(0, $schedule['ticksPerYear']);
    }
}
