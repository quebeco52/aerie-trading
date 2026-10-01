<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\AeriePartyProfiles;
use App\Service\Macro\MacroStateProvider;
use App\Service\Politics\PoliticsStateProvider;
use App\Service\View\GovernmentPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The District's government: the Diet and the coalition that governs it, the parties in the policy space, the Council,
 * and every vote on record; and a page for each party.
 */
class GovernmentController extends AbstractController
{
    #[Route('/government', name: 'app_government', methods: ['GET'])]
    public function index(MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, GovernmentPageBuilder $pageBuilder): Response
    {
        return $this->render('government/index.html.twig', $pageBuilder->build($macroStateProvider->liveState(), $politicsStateProvider->liveState()));
    }

    #[Route('/government/parties/{slug}', name: 'app_government_party', requirements: ['slug' => '[a-z-]+'], methods: ['GET'])]
    public function party(string $slug, MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, GovernmentPageBuilder $pageBuilder): Response
    {
        $party = AeriePartyProfiles::partyForSlug($slug) ?? throw $this->createNotFoundException('No such party.');

        return $this->render('government/party.html.twig', $pageBuilder->buildParty($macroStateProvider->liveState(), $politicsStateProvider->liveState(), $party));
    }
}
