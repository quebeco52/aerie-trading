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
        
        // Fetch the last 60 quarterly snapshots (15 years of history)
        $sql = "SELECT * FROM macro_report ORDER BY id DESC LIMIT 60";
        $results = $conn->fetchAllAssociative($sql);
        
        // Reverse to chronological order for Chart.js
        return new JsonResponse(array_reverse($results));
    }
}