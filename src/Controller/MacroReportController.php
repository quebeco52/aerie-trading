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

        // SELECT * so a newly recorded observable reaches the charts without touching this line. The probes'
        // accounts and the run identity are the columns that are not observables: nested diagnostics that would
        // arrive here as JSON strings no chart can plot, and a label. App\Controller\Admin\MacroController serves
        // the accounts, decoded, to the panel that reads them.
        foreach (array_keys($results) as $index) {
            unset(
                $results[$index]['gap_channels'],
                $results[$index]['quarter_diagnostics'],
                $results[$index]['config_fingerprint'],
                $results[$index]['ticks_per_year']
            );
        }

        // Reverse to chronological order for Chart.js
        return new JsonResponse(array_reverse($results));
    }
}