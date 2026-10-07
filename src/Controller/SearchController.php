<?php

namespace App\Controller;

use App\Entity\Bond;
use App\Entity\Etf;
use App\Entity\Stock;
use App\Repository\BondRepository;
use App\Repository\EtfRepository;
use App\Repository\StockRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    #[Route('/search/autocomplete', name: 'app_search_autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request, StockRepository $stocks, EtfRepository $etfs, BondRepository $bonds): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        
        if (strlen($query) < 1) {
            return new JsonResponse([]);
        }

        $results = [];

        foreach ($stocks->searchByTickerOrName($query, 5) as $stock) {
            $results[] = [
                'ticker' => $stock->getTicker(),
                'name' => $stock->getName(),
                'type' => 'Stock',
                'url' => '/stock/' . $stock->getTicker(),
            ];
        }

        foreach ($etfs->searchByTickerOrName($query, 3) as $etf) {
            $results[] = [
                'ticker' => $etf->getTicker(),
                'name' => $etf->getName(),
                'type' => 'Index',
                'url' => '/stock/' . $etf->getTicker(),
            ];
        }

        foreach ($bonds->searchByTickerOrName($query, 3) as $bond) {
            $results[] = [
                'ticker' => $bond->getTicker(),
                'name' => $bond->getName(),
                'type' => 'Bond',
                'url' => '/bond/' . $bond->getTicker(),
            ];
        }

        return new JsonResponse($results);
    }

    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function search(Request $request, StockRepository $stocks, EtfRepository $etfs, BondRepository $bonds): Response
    {
        $query = trim((string) $request->query->get('q', ''));

        if (empty($query)) {
            return $this->redirect('/');
        }

        $upper = strtoupper($query);

        // 1. Exact ticker match: Stock -> ETF -> Bond
        $stock = $stocks->findOneByTicker($upper);
        if ($stock) {
            return $this->redirect('/stock/' . $stock->getTicker());
        }

        $etf = $etfs->findOneByTicker($upper);
        if ($etf) {
            return $this->redirect('/stock/' . $etf->getTicker());
        }

        $bond = $bonds->findOneByTicker($upper);
        if ($bond && $bond->getStatus() === Bond::STATUS_ACTIVE) {
            return $this->redirect('/bond/' . $bond->getTicker());
        }

        // 2. Partial matches by ticker or name: one goes straight to its page, several are listed.
        $results = [];
        foreach ($stocks->searchByTickerOrName($query, 20) as $match) {
            $results[] = ['ticker' => $match->getTicker(), 'name' => $match->getName(), 'type' => 'Shares', 'url' => '/stock/' . $match->getTicker(), 'price' => (float) $match->getPrice()];
        }
        foreach ($etfs->searchByTickerOrName($query, 5) as $match) {
            $results[] = ['ticker' => $match->getTicker(), 'name' => $match->getName(), 'type' => 'Index fund', 'url' => '/stock/' . $match->getTicker(), 'price' => (float) $match->getPrice()];
        }
        foreach ($bonds->searchByTickerOrName($query, 20) as $match) {
            if ($match->getStatus() !== Bond::STATUS_ACTIVE) {
                continue;
            }
            $results[] = ['ticker' => $match->getTicker(), 'name' => $match->getName(), 'type' => 'Bond', 'url' => '/bond/' . $match->getTicker(), 'price' => (float) $match->getCleanPrice()];
        }

        if (count($results) === 1) {
            return $this->redirect($results[0]['url']);
        }

        return $this->render('search/results.html.twig', ['query' => $query, 'results' => $results]);
    }
}