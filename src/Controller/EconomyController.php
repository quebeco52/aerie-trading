<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Macro\MacroStateProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The macroeconomic page: the live state of the District's economy and twenty-five years of its history.
 *
 * This used to be a tab on the index fund's page, where it was the least discoverable thing on the site:
 * the economy is not a property of one fund, it is what every listed company is priced against. The series
 * themselves come from /api/macro-reports; this renders the vitals for the first paint, and the live frame
 * takes over from there.
 */
class EconomyController extends AbstractController
{
    #[Route('/economy', name: 'app_economy', methods: ['GET'])]
    public function index(MacroStateProvider $macroStateProvider): Response
    {
        $macroState = $macroStateProvider->liveState();

        return $this->render('economy/index.html.twig', [
            'macro' => $macroState,
            'economic_cycle' => $macroState->economicCycleLabel(),
        ]);
    }
}
