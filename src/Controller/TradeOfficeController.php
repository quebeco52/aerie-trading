<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Macro\MacroStateProvider;
use App\Service\Politics\PoliticsStateProvider;
use App\Service\View\GovernmentPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The Trade & Migration Office's page: the tariff, the migration regime and the tax on foreign home buyers it administers. */
class TradeOfficeController extends AbstractController
{
    #[Route('/trade-office', name: 'app_trade_office', methods: ['GET'])]
    public function index(MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, GovernmentPageBuilder $pageBuilder): Response
    {
        return $this->render('trade_office/index.html.twig', ['office' => $pageBuilder->tradeOffice($politicsStateProvider->liveState(), $macroStateProvider->liveState())]);
    }
}
