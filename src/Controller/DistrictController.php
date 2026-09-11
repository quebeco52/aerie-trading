<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\DistrictMap;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\District\DistrictEventFeed;
use App\Service\District\DistrictMapBuilder;
use App\Service\Market\PriceChangeFeed;
use App\Service\District\DistrictRevenueFeed;
use App\Service\District\DistrictStressEvaluator;
use App\Service\District\DistrictWardComposer;
use Doctrine\ORM\EntityManagerInterface;
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
        EntityManagerInterface $entityManager,
        DistrictWardComposer $wardComposer,
        DistrictMapBuilder $mapBuilder,
        DistrictStressEvaluator $stressEvaluator,
        DistrictEventFeed $eventFeed,
        DistrictRevenueFeed $revenueFeed,
        PriceChangeFeed $priceChangeFeed,
        \Redis $redis,
    ): Response {
        if ($ward !== DistrictMap::WARD_SLUG) {
            throw $this->createNotFoundException(sprintf('No ward "%s" exists in the District.', $ward));
        }

        $stocks = $entityManager->getRepository(Stock::class)->findAll();

        $frontage = $wardComposer->composeFrontage($stocks);

        // Only the tenants that actually took frontage need their change/events/revenue mix
        // fetched — findAll() above deliberately casts wider than the roster so the composer can
        // rank the whole listed universe by market cap.
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

        $macroState = $this->readMacroState($redis);
        $institutionStress = $stressEvaluator->evaluate($macroState);

        return $this->render('district/index.html.twig', [
            'wardName' => DistrictMap::WARD_NAME,
            'wardTagline' => DistrictMap::WARD_TAGLINE,
            'rosterSize' => DistrictMap::STREET_ROSTER_SIZE,
            'viewboxWidth' => $viewboxWidth,
            'viewboxHeight' => $canvas->viewboxHeight,
            'rowGroundLines' => $canvas->rowGroundLines,
            // The lowest row is the only one standing on the water, so it is the only one that reflects.
            'waterLine' => $canvas->waterLine(),
            'kerbDepth' => DistrictMap::KERB_DEPTH,
            'gutterWidth' => DistrictMap::FRONTAGE_GUTTER,
            'frontageMargin' => DistrictMap::FRONTAGE_MARGIN,
            'floorHeight' => DistrictMap::FLOOR_HEIGHT,
            'firstFloorInset' => DistrictMap::FIRST_FLOOR_INSET,
            'window' => [
                'pitch' => DistrictMap::WINDOW_PITCH,
                'width' => DistrictMap::WINDOW_WIDTH,
                'height' => DistrictMap::WINDOW_HEIGHT,
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
            ],
            'events' => $eventFeed->recentEventsByTicker($onStreet),
            // Wall-clock seconds one simulated month currently lasts: what the client needs to
            // let a building's event badge age out while the page stays open.
            'eventBadgeWindowSeconds' => $eventFeed->badgeWindowSeconds(),
            'revenueMix' => $revenueFeed->latestRevenueMixByTicker($onStreet),
            'summary' => $this->buildSummary($plots, $institutionStress, $frontage['nextInLine']),
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
     * @param  array{ticker: string, name: string, marketCap: float}|null $nextInLine
     * @return array<string, mixed>
     */
    private function buildSummary(array $plots, array $institutionStress, ?array $nextInLine): array
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
            'nextInLine' => $nextInLine,
        ];
    }

    /**
     * Same Redis-hydration pattern as StockController::view() — the live macro snapshot the
     * ticker command publishes on every tick, read here once for the server-rendered initial
     * paint. Live updates take over from payload.macro once the WebSocket connects.
     */
    private function readMacroState(\Redis $redis): MacroStateDTO
    {
        $macroStateJson = $redis->get('macroeconomic_state');
        $rawMacroState = $macroStateJson ? json_decode($macroStateJson, true) : [];

        return MacroStateDTO::fromArray(is_array($rawMacroState) ? $rawMacroState : []);
    }
}
