<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PriceAlert;
use App\Entity\Season;
use App\Entity\WatchlistItem;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroStateProvider;
use App\Service\Math\FinancialConstants;
use App\Service\Politics\SecuritiesCommission;
use App\Service\Season\SeasonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The Exchange's guide for new investors. Every figure it quotes is read from the rule that applies it. */
class GuideController extends AbstractController
{
    #[Route('/guide', name: 'app_guide')]
    public function index(MacroStateProvider $macroStates): Response
    {
        $macro = $macroStates->liveState();
        $margins = SecuritiesCommission::margins($macro->initialMarginRequirement);

        return $this->render('guide/index.html.twig', [
            'startingCapital' => (float) SeasonService::startingCapital($macro->consumerPriceLevel),
            'stampDutyRate' => $macro->stampDutyRate,
            'cashSpread' => MacroEngine::CASH_YIELD_SPREAD,
            'marginSpread' => FinancialConstants::MARGIN_LOAN_SPREAD,
            'initialMargin' => $margins['initial'],
            'maintenanceLong' => $margins['long'],
            'maintenanceShort' => $margins['short'],
            'contractSize' => FinancialConstants::OPTION_CONTRACT_MULTIPLIER,
            'bondFace' => FinancialConstants::BOND_FACE_VALUE,
            'seasonYears' => Season::LENGTH_YEARS,
            'qualifyingWeeks' => Season::MIN_QUALIFYING_WEEKS,
            'maxAlerts' => PriceAlert::MAX_OPEN_PER_USER,
            'maxWatchlist' => WatchlistItem::MAX_PER_USER,
        ]);
    }
}
