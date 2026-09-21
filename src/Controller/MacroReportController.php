<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class MacroReportController extends AbstractController
{
    #[Route('/api/macro-reports', name: 'api_macro_reports', methods: ['GET'])]
    public function getMacroReports(EntityManagerInterface $em): JsonResponse
    {
        $conn = $em->getConnection();
        
        // Fetch the last 100 quarterly snapshots (25 years of history)
        $sql = "SELECT * FROM macro_report ORDER BY id DESC LIMIT 100";
        $results = $conn->fetchAllAssociative($sql);

        // SELECT * so a newly recorded observable reaches the charts without touching this line. The gap
        // decomposition is the one column that is not an observable: it is a nested diagnostic that would
        // arrive here as a JSON string no chart can plot, and it is eighteen channels wide per row.
        // App\Controller\Admin\MacroController serves it, decoded, to the panel that reads it.
        foreach (array_keys($results) as $index) {
            unset($results[$index]['gap_channels']);
        }

        // Reverse to chronological order for Chart.js
        return new JsonResponse(array_reverse($results));
    }
}