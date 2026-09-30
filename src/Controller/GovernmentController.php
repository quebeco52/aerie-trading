<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Macro\MacroStateProvider;
use App\Service\View\GovernmentPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The District's government: the Diet and the coalition that governs it, the parties in the policy space, the Council,
 * and every vote on record.
 */
class GovernmentController extends AbstractController
{
    #[Route('/government', name: 'app_government', methods: ['GET'])]
    public function index(MacroStateProvider $macroStateProvider, GovernmentPageBuilder $pageBuilder): Response
    {
        return $this->render('government/index.html.twig', $pageBuilder->build($macroStateProvider->liveState()));
    }
}
