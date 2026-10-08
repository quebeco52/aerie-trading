<?php

namespace App\Controller;

use App\Service\View\MacroHistoryPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class MacroReportController extends AbstractController
{
    #[Route('/api/macro-reports', name: 'api_macro_reports', methods: ['GET'])]
    public function getMacroReports(EntityManagerInterface $em, MacroHistoryPresenter $presenter): JsonResponse
    {
        $conn = $em->getConnection();

        // Fetch the last 100 quarterly snapshots (25 years of history)
        $sql = "SELECT * FROM macro_report ORDER BY id DESC LIMIT 100";
        $results = $conn->fetchAllAssociative($sql);

        // SELECT * so a newly recorded observable reaches the charts without touching this line; the presenter drops
        // the columns that are not observables and dates and groups what the charts read.
        return new JsonResponse($presenter->present(array_reverse($results)));
    }
}
