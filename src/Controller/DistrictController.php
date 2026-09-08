<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\DistrictMap;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\District\DistrictEventFeed;
use App\Service\District\DistrictMapBuilder;
use App\Service\District\DistrictRevenueFeed;
use App\Service\District\DistrictStressEvaluator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders the illustrated ward elevations of the Aerie Autonomous District.
 * Each ward is a clickable skyline whose facades are driven by live company fundamentals, and
 * whose macro conduits are wired and gated by the same MacroStateDTO the rest of the app reads.
 */
class DistrictController extends AbstractController
{
    #[Route('/district/{ward}', name: 'app_district', methods: ['GET'])]
    public function ward(
        string $ward,
        EntityManagerInterface $entityManager,
        DistrictMapBuilder $mapBuilder,
        DistrictStressEvaluator $stressEvaluator,
        DistrictEventFeed $eventFeed,
        DistrictRevenueFeed $revenueFeed,
        \Redis $redis,
    ): Response {
        $wardConfig = DistrictMap::WARDS[$ward] ?? null;
        if ($wardConfig === null) {
            throw $this->createNotFoundException(sprintf('No ward "%s" exists in the District.', $ward));
        }

        $tickers = DistrictMap::tickersForWard($ward);
        $stocks = $tickers === []
            ? []
            : $entityManager->getRepository(Stock::class)->findBy(['ticker' => $tickers]);

        $shares = [];
        foreach ($stocks as $stock) {
            $shares[$stock->getTicker()] = (float) $stock->getSharesOutstanding();
        }

        // Same Redis-hydration pattern as StockController::view() — the live macro snapshot the
        // ticker command publishes on every tick, read here once for the server-rendered initial
        // paint. Live updates take over from payload.macro once the WebSocket connects.
        $macroStateJson = $redis->get('macroeconomic_state');
        $rawMacroState = $macroStateJson ? json_decode($macroStateJson, true) : [];
        $macroState = MacroStateDTO::fromArray(is_array($rawMacroState) ? $rawMacroState : []);

        return $this->render('district/index.html.twig', [
            'ward' => $wardConfig,
            'wardSlug' => $ward,
            'plots' => $mapBuilder->buildWard($ward, $stocks),
            'shares' => $shares,
            'institutions' => DistrictMap::INSTITUTIONS,
            'institutionStress' => $stressEvaluator->evaluate($macroState),
            // Only for rendering the readout config's `field` lookups server-side on first paint —
            // live ticks read the same fields straight off payload.macro instead of this snapshot.
            'macroSnapshot' => $macroState->toArray(),
            'institutionBand' => [
                'top' => DistrictMap::INSTITUTION_BAND_TOP,
                'height' => DistrictMap::INSTITUTION_BAND_HEIGHT,
                'outletY' => DistrictMap::INSTITUTION_OUTLET_Y,
            ],
            'events' => $eventFeed->recentEventsByTicker($stocks),
            'revenueMix' => $revenueFeed->latestRevenueMixByTicker($stocks),
            'envelope' => [
                'minHeight' => DistrictMap::MIN_FACADE_HEIGHT,
                'maxHeight' => DistrictMap::MAX_FACADE_HEIGHT,
                'logFloor' => DistrictMap::MARKET_CAP_LOG_FLOOR,
                'logCeiling' => DistrictMap::MARKET_CAP_LOG_CEILING,
                'groundLine' => $wardConfig['ground_line'],
            ],
        ]);
    }
}
