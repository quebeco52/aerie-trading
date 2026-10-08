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
 * The District's government: a front page with a headline from each institution, and pages for the Diet, the coming
 * election, the budget, the Aerie Council, the Monetary Authority and each party.
 */
class GovernmentController extends AbstractController
{
    #[Route('/government', name: 'app_government', methods: ['GET'])]
    public function index(MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, GovernmentPageBuilder $pageBuilder): Response
    {
        return $this->render('government/index.html.twig', $pageBuilder->buildHub($macroStateProvider->liveState(), $politicsStateProvider->liveState()));
    }

    #[Route('/government/diet', name: 'app_government_diet', methods: ['GET'])]
    public function diet(MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, GovernmentPageBuilder $pageBuilder): Response
    {
        return $this->render('government/diet.html.twig', $pageBuilder->buildDiet($macroStateProvider->liveState(), $politicsStateProvider->liveState()));
    }

    #[Route('/government/election', name: 'app_government_election', methods: ['GET'])]
    public function election(MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, GovernmentPageBuilder $pageBuilder): Response
    {
        return $this->render('government/election.html.twig', $pageBuilder->buildElection($macroStateProvider->liveState(), $politicsStateProvider->liveState()));
    }

    #[Route('/government/budget', name: 'app_government_budget', methods: ['GET'])]
    public function budget(MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, GovernmentPageBuilder $pageBuilder): Response
    {
        return $this->render('government/budget.html.twig', $pageBuilder->buildBudget($macroStateProvider->liveState(), $politicsStateProvider->liveState()));
    }

    #[Route('/council', name: 'app_council', methods: ['GET'])]
    public function council(MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, GovernmentPageBuilder $pageBuilder): Response
    {
        return $this->render('government/council.html.twig', $pageBuilder->buildCouncil($macroStateProvider->liveState(), $politicsStateProvider->liveState()));
    }

    #[Route('/authority', name: 'app_authority', methods: ['GET'])]
    public function authority(MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, GovernmentPageBuilder $pageBuilder): Response
    {
        return $this->render('government/authority.html.twig', $pageBuilder->buildAuthority($macroStateProvider->liveState(), $politicsStateProvider->liveState()));
    }

    #[Route('/government/parties/{slug}', name: 'app_government_party', requirements: ['slug' => '[a-z-]+'], methods: ['GET'])]
    public function party(string $slug, MacroStateProvider $macroStateProvider, PoliticsStateProvider $politicsStateProvider, GovernmentPageBuilder $pageBuilder): Response
    {
        $party = AeriePartyProfiles::partyForSlug($slug) ?? throw $this->createNotFoundException('No such party.');

        return $this->render('government/party.html.twig', $pageBuilder->buildParty($macroStateProvider->liveState(), $politicsStateProvider->liveState(), $party));
    }
}
