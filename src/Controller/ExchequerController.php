<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Macro\MacroStateProvider;
use App\Service\Politics\PoliticsStateProvider;
use App\Service\View\GovernmentPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The Exchequer's page: its head, the debt and how long it is borrowed for, and what that costs. */
class ExchequerController extends AbstractController
{
    #[Route('/exchequer', name: 'app_exchequer', methods: ['GET'])]
    public function index(MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, GovernmentPageBuilder $pageBuilder): Response
    {
        return $this->render('exchequer/index.html.twig', ['exchequer' => $pageBuilder->exchequer($politicsStateProvider->liveState(), $macroStateProvider->liveState())]);
    }
}
