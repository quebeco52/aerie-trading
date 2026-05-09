<?php

namespace App\Controller;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    #[Route('/search/autocomplete', name: 'app_search_autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $query = $request->query->get('q', '');
        
        if (strlen($query) < 1) {
            return new JsonResponse([]);
        }

        // Find up to 5 stocks that match either the ticker or the company name
        $stocks = $entityManager->getRepository(Stock::class)->createQueryBuilder('s')
            ->where('s.ticker LIKE :query OR s.name LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        $results = array_map(fn(Stock $stock) => [
            'ticker' => $stock->getTicker(),
            'name' => $stock->getName(),
        ], $stocks);

        return new JsonResponse($results);
    }

    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function search(Request $request, EntityManagerInterface $entityManager): Response
    {
        $query = $request->query->get('q', '');

        if (empty($query)) {
            return $this->redirect('/');
        }

        // Determine the absolute best match if the user hit "Enter" without clicking an option
        $stock = $entityManager->getRepository(Stock::class)->createQueryBuilder('s')
            ->where('s.ticker = :exact OR s.ticker LIKE :query OR s.name LIKE :query')
            ->setParameter('exact', strtoupper($query))
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($stock) {
            return $this->redirect('/stock/' . $stock->getTicker());
        }

        $this->addFlash('error', 'No stock found matching "' . $query . '"');
        return $this->redirect('/');
    }
}