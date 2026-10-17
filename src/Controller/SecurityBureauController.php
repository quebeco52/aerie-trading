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
 * The Security Bureau's page: its mandate, its director and the Almanac's record of its cases. Linked from the Council
 * page only.
 */
class SecurityBureauController extends AbstractController
{
    #[Route('/security-bureau', name: 'app_security_bureau', methods: ['GET'])]
    public function index(PoliticsStateProvider $politicsStateProvider, DepartmentPageBuilder $pageBuilder, Almanac $almanac): Response
    {
        return $this->render('security_bureau/index.html.twig', [
            'bureau' => $pageBuilder->securityBureau($politicsStateProvider->liveState()),
            'entries' => $almanac->about('security-bureau'),
        ]);
    }
}
