<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Macro\MacroStateProvider;
use App\Service\Politics\PoliticsStateProvider;
use App\Service\View\SovereignReservePageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Sovereign Reserve Fund's page: its head and the mix they set, its size and budget draw, its weights against policy
 * and band, its stake in every listed company, and its rules. The history comes from /api/macro-reports, the same series
 * the economy page draws.
 */
class ReserveController extends AbstractController
{
    #[Route('/reserve', name: 'app_reserve', methods: ['GET'])]
    public function index(MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, SovereignReservePageBuilder $pageBuilder): Response
    {
        return $this->render('reserve/index.html.twig', $pageBuilder->build($macroStateProvider->liveState(), $politicsStateProvider->liveState()));
    }
}
