<?php

namespace App\Controller;

use App\Entity\Stock;
use App\Repository\StockRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    #[Route('/search/autocomplete', name: 'app_search_autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request, StockRepository $stocks): JsonResponse
    {
        $query = $request->query->get('q', '');
        
        if (strlen($query) < 1) {
            return new JsonResponse([]);
        }

        $results = array_map(fn(Stock $stock) => [
            'ticker' => $stock->getTicker(),
            'name' => $stock->getName(),
        ], $stocks->searchByTickerOrName($query));

        return new JsonResponse($results);
    }

    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function search(Request $request, StockRepository $stocks): Response
    {
        $query = $request->query->get('q', '');

        if (empty($query)) {
            return $this->redirect('/');
        }

        // Land on the company the reader spelled out, not on whichever partial match sorts first.
        $stock = $stocks->findBestMatch($query);

        if ($stock) {
            return $this->redirect('/stock/' . $stock->getTicker());
        }

        $this->addFlash('error', 'No stock found matching "' . $query . '"');
        return $this->redirect('/');
    }
}