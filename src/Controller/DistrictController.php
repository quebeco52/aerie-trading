<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\DistrictMap;
use App\Entity\Stock;
use App\Service\District\DistrictEventFeed;
use App\Service\District\DistrictHoldingsFeed;
use App\Service\District\DistrictMapBuilder;
use App\Service\Market\CreditRatingAgency;
use App\Service\Market\PriceChangeFeed;
use App\Service\District\DistrictRevenueFeed;
use App\Service\District\DistrictRoster;
use App\Service\District\DistrictStressEvaluator;
use App\Repository\StockRepository;
use App\Service\Macro\MacroStateProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders Glasswater Row, the Aerie Autonomous District's one street: a clickable skyline held by
 * whichever ~30 listed companies currently rank largest by market cap, whose facades are driven
 * by live company fundamentals and whose macro conduits are wired and gated by the same
 * MacroStateDTO the rest of the app reads.
 */
class DistrictController extends AbstractController
{
    #[Route('/district', name: 'app_district_index', methods: ['GET'])]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('app_district', ['ward' => DistrictMap::WARD_SLUG]);
    }

    #[Route('/district/{ward}', name: 'app_district', methods: ['GET'])]
    public function ward(
        string $ward,
        StockRepository $stockRepository,
        DistrictRoster $districtRoster,
        DistrictMapBuilder $mapBuilder,
        DistrictStressEvaluator $stressEvaluator,
        DistrictEventFeed $eventFeed,
        DistrictRevenueFeed $revenueFeed,
        DistrictHoldingsFeed $holdingsFeed,
        PriceChangeFeed $priceChangeFeed,
        MacroStateProvider $macroStateProvider,
        \Redis $redis,
    ): Response {
        if ($ward !== DistrictMap::WARD_SLUG) {
            throw $this->createNotFoundException(sprintf('No ward "%s" exists in the District.', $ward));
        }

        $stocks = $stockRepository->findAll();

        // The street as frozen at the last reconstitution, laid out against today's universe —
        // see DistrictRoster. findAll() casts wider than the roster because the register only
        // holds tickers, and because a fresh market takes its first roster right here.
        $tickCount = (int) ($redis->get('simulation_tick_count') ?: 0);
        $frontage = $districtRoster->frontageFor($stocks, $tickCount);
        $reconstitution = $districtRoster->schedule($tickCount);

        // Only the tenants that actually took frontage need their change/events/revenue mix fetched.
        $rosterTickers = array_column($frontage['slots'], 'ticker');
        $onStreet = array_filter($stocks, static fn (Stock $s) => in_array($s->getTicker(), $rosterTickers, true));

        $changeByTicker = $priceChangeFeed->changeByTicker($onStreet);

        // One envelope for the facades, the gutter rules and the client's live resize alike —
        // fitted to this request's roster, not to a fixed window a long-running market outgrows.
        // The canvas then gives each row the sky that envelope says it needs.
        $envelope = $mapBuilder->resolveEnvelope($frontage['slots'], $stocks);
        $canvas = $mapBuilder->resolveCanvas($frontage['slots'], $stocks, $envelope, $frontage['rowCount']);
        $plots = $mapBuilder->buildWard($frontage['slots'], $stocks, $envelope, $canvas, $changeByTicker);
        $viewboxWidth = $mapBuilder->resolveViewboxWidth($plots, $frontage['viewboxWidth']);
        $institutions = $mapBuilder->buildInstitutions($plots, $viewboxWidth, $canvas);

        $shares = [];
        foreach ($onStreet as $stock) {
            $shares[$stock->getTicker()] = (float) $stock->getSharesOutstanding();
        }

        $macroState = $macroStateProvider->liveState();
        $institutionStress = $stressEvaluator->evaluate($macroState);

        $user = $this->getUser();
        $userHoldings = $user instanceof \App\Entity\User ? $holdingsFeed->holdingsForUser($user) : [];
        $userCash = $user instanceof \App\Entity\User ? (float) $user->getCashBalance() : 0.0;
        $marginEnabled = $user instanceof \App\Entity\User && $user->isMarginEnabled();

        return $this->render('district/index.html.twig', [
            'wardName' => DistrictMap::WARD_NAME,
            'wardTagline' => DistrictMap::WARD_TAGLINE,
            'rosterSize' => DistrictMap::STREET_ROSTER_SIZE,
            'viewboxWidth' => $viewboxWidth,
            'viewboxHeight' => $canvas->viewboxHeight,
            'rowGroundLines' => $canvas->rowGroundLines,
            'kerbDepth' => DistrictMap::KERB_DEPTH,
            'terraceWallHeight' => DistrictMap::TERRACE_WALL_HEIGHT,
            'positionPennant' => [
                'width' => DistrictMap::POSITION_PENNANT_WIDTH,
                'height' => DistrictMap::POSITION_PENNANT_HEIGHT,
                'inset' => DistrictMap::POSITION_PENNANT_INSET,
            ],
            'userHoldings' => $userHoldings,
            'userCash' => $userCash,
            'marginEnabled' => $marginEnabled,
            'gutterWidth' => DistrictMap::FRONTAGE_GUTTER,
            'frontageMargin' => DistrictMap::FRONTAGE_MARGIN,
            'floorHeight' => DistrictMap::FLOOR_HEIGHT,
            'firstFloorInset' => DistrictMap::FIRST_FLOOR_INSET,
            'window' => [
                'pitch' => DistrictMap::WINDOW_PITCH,
                'width' => DistrictMap::WINDOW_WIDTH,
                'height' => DistrictMap::WINDOW_HEIGHT,
                'twinklePeriod' => DistrictMap::WINDOW_TWINKLE_PERIOD_SECONDS,
            ],
            // What the client needs to relight windows and recolour masonry on a live tick — the
            // same rule DistrictMapBuilder applied on first paint, never a second copy of it.
            'lighting' => [
                'floor' => DistrictMap::WINDOW_LIT_SHARE_FLOOR,
                'atBaseline' => DistrictMap::WINDOW_LIT_SHARE_AT_BASELINE,
            ],
            'condition' => [
                'ratingRanks' => CreditRatingAgency::RATING_RANKS,
                'investmentGradeRank' => DistrictMap::INVESTMENT_GRADE_RANK,
            ],
            'roofFurniture' => [
                'width' => DistrictMap::ROOF_FURNITURE_WIDTH,
                'height' => DistrictMap::ROOF_FURNITURE_HEIGHT,
                'eastMargin' => DistrictMap::ROOF_FURNITURE_EAST_MARGIN,
            ],
            'kerbLight' => [
                'depth' => DistrictMap::KERB_LIGHT_DEPTH,
                'opacity' => DistrictMap::KERB_LIGHT_OPACITY,
            ],
            'gridlines' => $mapBuilder->buildGridlines($envelope, $canvas),
            'sectorPalette' => DistrictMap::SECTOR_PALETTE,
            'sectorRuns' => $mapBuilder->buildSectorRuns($plots),
            'sectorBracket' => [
                'ruleOffset' => DistrictMap::SECTOR_BRACKET_RULE_OFFSET,
                'labelOffset' => DistrictMap::SECTOR_BRACKET_LABEL_OFFSET,
                'labelSize' => DistrictMap::SECTOR_BRACKET_LABEL_SIZE,
            ],
            'plots' => $plots,
            'shares' => $shares,
            'institutions' => $institutions,
            'institutionStress' => $institutionStress,
            // Only for rendering the readout config's `field` lookups server-side on first paint —
            // live ticks read the same fields straight off payload.macro instead of this snapshot.
            'macroSnapshot' => $macroState->toArray(),
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
            'events' => $eventFeed->recentEventsByTicker($onStreet),
            // Wall-clock seconds one simulated month currently lasts: what the client needs to
            // let a building's event badge age out while the page stays open.
            'eventBadgeWindowSeconds' => $eventFeed->badgeWindowSeconds(),
            'revenueMix' => $revenueFeed->latestRevenueMixByTicker($onStreet),
            'summary' => $this->buildSummary($plots, $institutionStress),
            'reconstitution' => $reconstitution + [
                'simDaysUntilNext' => ($reconstitution['nextTick'] - $tickCount) / $reconstitution['ticksPerYear'] * 365,
            ],
            'envelope' => $envelope->toArray() + [
                'minHeight' => DistrictMap::MIN_FACADE_HEIGHT,
                'maxHeight' => DistrictMap::MAX_FACADE_HEIGHT,
            ],
        ]);
    }

    /**
     * Street-level aggregates for the summary bar. Every figure is read off plots already built —
     * no extra query, and nothing here is a new financial model: a sum, a weighted mean, and a max.
     *
     * @param  list<\App\DTO\DistrictPlotDTO>                        $plots
     * @param  array<string, bool>                                   $institutionStress
     * @return array<string, mixed>
     */
    private function buildSummary(array $plots, array $institutionStress): array
    {
        $totalMarketCap = 0.0;
        $changeWeightedCap = 0.0;
        $weightedChange = 0.0;
        $biggestMover = null;

        foreach ($plots as $plot) {
            $totalMarketCap += $plot->marketCap;

            if ($plot->changePercent === null) {
                continue;
            }

            // Cap-weighted so the street's aggregate move reflects the street, not its smallest tenant.
            $changeWeightedCap += $plot->marketCap;
            $weightedChange += $plot->changePercent * $plot->marketCap;

            if ($biggestMover === null || abs($plot->changePercent) > abs($biggestMover['changePercent'])) {
                $biggestMover = ['ticker' => $plot->ticker, 'changePercent' => $plot->changePercent];
            }
        }

        return [
            'tenantCount' => count($plots),
            'totalMarketCap' => $totalMarketCap,
            'changePercent' => $changeWeightedCap > 0.0 ? $weightedChange / $changeWeightedCap : null,
            'biggestMover' => $biggestMover,
            'stressedInstitutions' => count(array_filter($institutionStress)),
            'totalInstitutions' => count($institutionStress),
        ];
    }

}
