<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Politics\PoliticsStateProvider;
use App\Service\View\DepartmentPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The Fair Trading Board's page: its head and the screens a merger must clear. */
class FairTradingController extends AbstractController
{
    #[Route('/fair-trading', name: 'app_fair_trading', methods: ['GET'])]
    public function index(PoliticsStateProvider $politicsStateProvider, DepartmentPageBuilder $pageBuilder): Response
    {
        return $this->render('fair_trading/index.html.twig', ['board' => $pageBuilder->fairTrading($politicsStateProvider->liveState())]);
    }
}
