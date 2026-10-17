<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\View\DashboardPageBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for the user's private portfolio dashboard.
 */
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    /**
     * The player's portfolio: holdings, cash, performance, allocation, open orders and trade history; see
     * App\Service\View\DashboardPageBuilder.
     */
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(Request $request, DashboardPageBuilder $dashboard): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('dashboard/index.html.twig', $dashboard->build($user, $request->query->getInt('trades', 1)));
    }

    /** Closes the account's book and reopens it with starting capital; see App\Service\User\FreshStart. */
    #[Route('/account/fresh-start', name: 'app_fresh_start', methods: ['POST'])]
    public function freshStart(Request $request, \App\Service\User\FreshStart $freshStart): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('fresh_start', (string) $request->request->get('_token')) || $request->request->get('confirm') !== '1') {
            $this->addFlash('error', 'Tick the box to confirm before starting afresh.');

            return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);
        }

        try {
            $freshStart->restart($user);
            $this->addFlash('success', sprintf('Your account starts afresh with %s in cash.', '$' . number_format((float) $user->getCashBalance(), 2)));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * API endpoint returning historical portfolio NAV (Net Asset Value) performance points.
     */
    #[Route('/api/portfolio/history', name: 'api_portfolio_history', methods: ['GET'])]
    public function history(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json([], Response::HTTP_UNAUTHORIZED);
        }

        $range = $request->query->get('range', '1m');
        $limits = [
            '1w' => 100,
            '1m' => 400,
            '1y' => 1500,
            'max' => 5000,
        ];
        $limit = $limits[$range] ?? 400;

        $conn = $entityManager->getConnection();
        $sql = 'SELECT total_value, recorded_at FROM portfolio_history WHERE user_id = :user_id ORDER BY recorded_at DESC LIMIT ' . (int) $limit;
        $rows = $conn->fetchAllAssociative($sql, ['user_id' => $user->getId()]);

        // An account with no snapshots yet gets an empty series; the page plots the portfolio value it
        // rendered with, which counts holdings and open orders rather than cash alone.
        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'price' => (float) $row['total_value'],
                'recorded_at' => $row['recorded_at'],
            ];
        }
        $results = array_reverse($results);

        return $this->json([
            'portfolio' => $results,
            'index' => $results === [] ? [] : $this->indexOver($conn, $results[0]['recorded_at'], $results[count($results) - 1]['recorded_at']),
            'indexName' => 'Lakebird 30',
        ]);
    }

    /** Points the index line draws at most; the fund's history is thinned evenly to this. */
    private const INDEX_LINE_POINTS = 600;

    /**
     * The benchmark fund's price over the same wall-clock window as the portfolio series, for the comparison line.
     * The page rebases it to the portfolio's first value, so the two lines start together.
     *
     * @return list<array{price: float, recorded_at: string}>
     */
    private function indexOver(\Doctrine\DBAL\Connection $conn, string $from, string $to): array
    {
        $rows = $conn->fetchAllAssociative(
            'SELECT h.price, h.recorded_at FROM etf_history h JOIN etfs e ON e.id = h.etf_id
             WHERE e.ticker = :ticker AND h.recorded_at BETWEEN :from AND :to
             ORDER BY h.recorded_at',
            ['ticker' => \App\Entity\Season::BENCHMARK_TICKER, 'from' => $from, 'to' => $to],
        );

        $step = max(1, (int) ceil(count($rows) / self::INDEX_LINE_POINTS));
        $points = [];
        foreach ($rows as $i => $row) {
            if ($i % $step === 0 || $i === count($rows) - 1) {
                $points[] = ['price' => (float) $row['price'], 'recorded_at' => (string) $row['recorded_at']];
            }
        }

        return $points;
    }
}
