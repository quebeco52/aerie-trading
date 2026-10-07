<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Macro\MacroStateProvider;
use App\Service\Politics\PoliticsStateProvider;
use App\Service\View\RegulatorPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The Financial Regulator's page: its head, the capital rule and the mortgage rule, and the banks it supervises. */
class RegulatorController extends AbstractController
{
    #[Route('/regulator', name: 'app_regulator', methods: ['GET'])]
    public function index(MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, RegulatorPageBuilder $pageBuilder): Response
    {
        return $this->render('regulator/index.html.twig', $pageBuilder->build($macroStateProvider->liveState(), $politicsStateProvider->liveState()));
    }
}
