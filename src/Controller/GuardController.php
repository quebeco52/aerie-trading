<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Lore\Almanac;
use App\Service\Politics\PoliticsStateProvider;
use App\Service\View\DepartmentPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Coast Guard's and the State Guard's pages: each force's mandate, its commander and the Almanac's record of it.
 * Linked from the Council page only.
 */
class GuardController extends AbstractController
{
    #[Route('/coast-guard', name: 'app_coast_guard', methods: ['GET'])]
    public function coastGuard(PoliticsStateProvider $politicsStateProvider, DepartmentPageBuilder $pageBuilder, Almanac $almanac): Response
    {
        return $this->render('guard/index.html.twig', [
            'force' => $pageBuilder->coastGuard($politicsStateProvider->liveState()),
            'entries' => $almanac->about('coast-guard'),
        ]);
    }

    #[Route('/state-guard', name: 'app_state_guard', methods: ['GET'])]
    public function stateGuard(PoliticsStateProvider $politicsStateProvider, DepartmentPageBuilder $pageBuilder, Almanac $almanac): Response
    {
        return $this->render('guard/index.html.twig', [
            'force' => $pageBuilder->stateGuard($politicsStateProvider->liveState()),
            'entries' => $almanac->about('state-guard'),
        ]);
    }
}
