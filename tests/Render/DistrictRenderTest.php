<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Data\Company\InitialMarket;
use App\Data\District\DistrictMap;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\District\DistrictMapBuilder;
use App\Service\District\DistrictStressEvaluator;
use App\Service\District\DistrictWardComposer;
use App\Service\Market\Bond\CreditRatingAgency;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Renders Glasswater Row from the seed universe with stub prices, ratings and monthly changes, through the real
 * composer and map builder, for a screenshot. Not in any suite: bin/render-pages runs it. The view variables mirror
 * DistrictController::ward(); keep the two in step.
 */
final class DistrictRenderTest extends KernelTestCase
{
    /** Ratings drawn for the stub street, weighted to investment grade as the live board is. */
    private const RATINGS = ['AA', 'A', 'A', 'A', 'BBB', 'BBB', 'BBB', 'BB', 'B', 'CCC'];

    public function testRender(): void
    {
        mt_srand(7);

        $stocks = [];
        foreach (InitialMarket::STOCKS as $s) {
            $shares = (float) $s['shares_outstanding'];
            $baselineRoe = (float) ($s['baseline_roe'] ?? 0.12);
            $baselineRoic = (float) ($s['baseline_roic'] ?? 0.10);
            $stock = (new Stock())
                ->setTicker($s['ticker'])
                ->setName($s['name'])
                ->setSector($s['sector'])
                ->setIndustry($s['industry']);
            $stock->setPrice((string) round((float) $s['total_equity'] * mt_rand(80, 400) / 100 / $shares, 2));
            $stock->setSharesOutstanding((string) $shares);
            $stock->setCreditRating(self::RATINGS[mt_rand(0, count(self::RATINGS) - 1)]);
            $stock->setSystemicImportance($s['systemic_importance'] ?? 'none');
            $stock->setBaselineRoe((string) $baselineRoe);
            $stock->setCurrentRoe((string) ($baselineRoe * mt_rand(20, 160) / 100));
            $stock->setBaselineRoic((string) $baselineRoic);
            $stock->setCurrentRoic((string) ($baselineRoic * mt_rand(20, 160) / 100));
            $stock->setIsBankrupt(false);
            $stocks[] = $stock;
        }

        self::bootKernel();
        $container = static::getContainer();
        $composer = $container->get(DistrictWardComposer::class);
        $builder = $container->get(DistrictMapBuilder::class);

        // The roster is taken while every house is solvent; one then fails, and keeps its plot as a shell until the
        // next reconstitution, which is the only way a defunct house stands on the street.
        $roster = $composer->rosterOrder($stocks);
        $failed = $stocks[array_search($roster[7], array_map(static fn (Stock $s) => $s->getTicker(), $stocks), true)];
        $failed->setIsBankrupt(true);
        $failed->setPrice('0');
        $failed->setCreditRating('D');
        $frontage = $composer->composeFrontageForRoster($stocks, $roster);

        $changes = [];
        foreach ($frontage['slots'] as $slot) {
            $changes[$slot['ticker']] = mt_rand(-1500, 1500) / 10000;
        }
        $envelope = $builder->resolveEnvelope($frontage['slots'], $stocks);
        $canvas = $builder->resolveCanvas($frontage['slots'], $stocks, $envelope, $frontage['rowCount']);
        $plots = $builder->buildWard($frontage['slots'], $stocks, $envelope, $canvas, $changes);
        $viewboxWidth = $builder->resolveViewboxWidth($plots, $frontage['viewboxWidth']);
        $macro = new MacroStateDTO();
        $stress = (new DistrictStressEvaluator())->evaluate($macro);

        $data = [
            'wardName' => DistrictMap::WARD_NAME,
            'wardTagline' => DistrictMap::WARD_TAGLINE,
            'rosterSize' => DistrictMap::STREET_ROSTER_SIZE,
            'viewboxWidth' => $viewboxWidth,
            'viewboxHeight' => $canvas->viewboxHeight,
            'rowGroundLines' => $canvas->rowGroundLines,
            'kerbDepth' => DistrictMap::KERB_DEPTH,
            'terraceWallHeight' => DistrictMap::TERRACE_WALL_HEIGHT,
            'positionPennant' => ['width' => DistrictMap::POSITION_PENNANT_WIDTH, 'height' => DistrictMap::POSITION_PENNANT_HEIGHT, 'inset' => DistrictMap::POSITION_PENNANT_INSET],
            'userHoldings' => [],
            'userCash' => 0.0,
            'marginEnabled' => false,
            'gutterWidth' => DistrictMap::FRONTAGE_GUTTER,
            'frontageMargin' => DistrictMap::FRONTAGE_MARGIN,
            'floorHeight' => DistrictMap::FLOOR_HEIGHT,
            'firstFloorInset' => DistrictMap::FIRST_FLOOR_INSET,
            'window' => ['pitch' => DistrictMap::WINDOW_PITCH, 'width' => DistrictMap::WINDOW_WIDTH, 'height' => DistrictMap::WINDOW_HEIGHT],
            'lighting' => ['floor' => DistrictMap::WINDOW_LIT_SHARE_FLOOR, 'atBaseline' => DistrictMap::WINDOW_LIT_SHARE_AT_BASELINE],
            'condition' => ['ratingRanks' => CreditRatingAgency::RATING_RANKS, 'distressedRank' => DistrictMap::DISTRESSED_RANK],
            'roofFurniture' => ['width' => DistrictMap::ROOF_FURNITURE_WIDTH, 'height' => DistrictMap::ROOF_FURNITURE_HEIGHT, 'eastMargin' => DistrictMap::ROOF_FURNITURE_EAST_MARGIN],
            'kerbLight' => ['depth' => DistrictMap::KERB_LIGHT_DEPTH, 'opacity' => DistrictMap::KERB_LIGHT_OPACITY],
            'gridlines' => $builder->buildGridlines($envelope, $canvas),
            'sectorPalette' => DistrictMap::SECTOR_PALETTE,
            'sectorPaletteFallback' => DistrictMap::SECTOR_PALETTE_FALLBACK,
            'districtRuns' => $builder->buildDistrictRuns($plots),
            'sectorBracket' => ['ruleOffset' => DistrictMap::SECTOR_BRACKET_RULE_OFFSET, 'labelOffset' => DistrictMap::SECTOR_BRACKET_LABEL_OFFSET, 'labelSize' => DistrictMap::SECTOR_BRACKET_LABEL_SIZE],
            'plots' => $plots,
            'shares' => [],
            'institutions' => $builder->buildInstitutions($plots, $viewboxWidth, $canvas),
            'institutionStress' => $stress,
            'macroSnapshot' => $macro->toArray(),
            'institutionBand' => [
                'top' => DistrictMap::INSTITUTION_BAND_TOP,
                'height' => DistrictMap::INSTITUTION_BAND_HEIGHT,
                'outletY' => DistrictMap::INSTITUTION_OUTLET_Y,
                'labelOffset' => DistrictMap::INSTITUTION_LABEL_OFFSET,
                'readoutTopOffset' => DistrictMap::INSTITUTION_READOUT_TOP_OFFSET,
                'readoutPitch' => DistrictMap::INSTITUTION_READOUT_PITCH,
                'sparklineWidth' => DistrictMap::INSTITUTION_SPARKLINE_WIDTH,
                'sparklineHeight' => DistrictMap::INSTITUTION_SPARKLINE_HEIGHT,
                'sparklineGap' => DistrictMap::INSTITUTION_SPARKLINE_GAP,
            ],
            'events' => [],
            'eventBadgeWindowSeconds' => 60.0,
            'revenueMix' => [],
            'summary' => [
                'tenantCount' => count($plots),
                'totalMarketCap' => array_sum(array_map(static fn ($p) => $p->marketCap, $plots)),
                'changePercent' => 0.012,
                'biggestMover' => null,
                'stressedInstitutions' => count(array_filter($stress)),
                'totalInstitutions' => count($stress),
            ],
            'reconstitution' => ['nextTick' => 100, 'ticksPerYear' => 365, 'simDaysUntilNext' => 41],
            'envelope' => $envelope->toArray() + [
                'minHeight' => DistrictMap::MIN_FACADE_HEIGHT,
                'maxHeight' => DistrictMap::MAX_FACADE_HEIGHT,
            ],
        ];

        $twig = $container->get('twig');
        $twig->disableStrictVariables();
        $request = Request::create('/district/' . DistrictMap::WARD_SLUG);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);

        $this->assertFileExists(OfflinePage::write('district', $twig->render('district/index.html.twig', $data)));
    }
}
