<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Macro\MacroStateProvider;
use App\Service\Politics\PoliticsStateProvider;
use App\Service\View\DepartmentPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The Securities Commission's page: its head, the margins investors put up and what listed firms must disclose. */
class SecuritiesController extends AbstractController
{
    #[Route('/securities', name: 'app_securities', methods: ['GET'])]
    public function index(MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, DepartmentPageBuilder $pageBuilder): Response
    {
        return $this->render('securities/index.html.twig', ['commission' => $pageBuilder->securities($politicsStateProvider->liveState(), $macroStateProvider->liveState())]);
    }
}
