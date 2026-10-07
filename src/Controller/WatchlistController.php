<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\User;
use App\Entity\WatchlistItem;
use App\Service\Notification\PriceAlertService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Following a name, and price alerts on it. Both answer with a redirect back to the page the form was on. */
#[IsGranted('ROLE_USER')]
class WatchlistController extends AbstractController
{
    #[Route('/watchlist/{ticker}', name: 'app_watchlist_toggle', methods: ['POST'])]
    public function toggle(string $ticker, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('watchlist', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->back($request, $ticker);
        }

        $items = $entityManager->getRepository(WatchlistItem::class);
        $existing = $items->findOneBy(['user' => $user, 'ticker' => $ticker]);

        if ($existing instanceof WatchlistItem) {
            $entityManager->remove($existing);
            $entityManager->flush();

            return $this->back($request, $ticker);
        }

        $assetType = match (true) {
            $entityManager->getRepository(Stock::class)->count(['ticker' => $ticker]) > 0 => 'STOCK',
            $entityManager->getRepository(Etf::class)->count(['ticker' => $ticker]) > 0 => 'ETF',
            default => null,
        };
        if ($assetType === null) {
            throw $this->createNotFoundException('Ticker not found');
        }

        if ($items->count(['user' => $user]) >= WatchlistItem::MAX_PER_USER) {
            $this->addFlash('error', sprintf('A watchlist holds up to %d names.', WatchlistItem::MAX_PER_USER));

            return $this->back($request, $ticker);
        }

        $entityManager->persist(new WatchlistItem($user, $ticker, $assetType));
        $entityManager->flush();
        $this->addFlash('success', sprintf('Watching %s. Its news will reach your notifications.', $ticker));

        return $this->back($request, $ticker);
    }

    #[Route('/alerts/{ticker}', name: 'app_alert_create', methods: ['POST'])]
    public function createAlert(string $ticker, Request $request, PriceAlertService $alerts): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('price_alert', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->back($request, $ticker);
        }

        try {
            $alert = $alerts->create($user, $ticker, (float) $request->request->get('price'));
            $this->addFlash('success', sprintf(
                'Alert set: %s %s $%s.',
                $ticker,
                $alert->getDirection() === \App\Entity\PriceAlert::ABOVE ? 'rises to' : 'falls to',
                number_format((float) $alert->getTargetPrice(), 2)
            ));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($request, $ticker);
    }

    #[Route('/alerts/{id}/cancel', name: 'app_alert_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancelAlert(int $id, Request $request, PriceAlertService $alerts): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('price_alert', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->back($request, null);
        }

        try {
            $alerts->cancel($user, $id);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($request, null);
    }

    /** Back to the page the form was on when it is one of ours, otherwise to the instrument or the portfolio. */
    private function back(Request $request, ?string $ticker): RedirectResponse
    {
        $referer = (string) $request->headers->get('referer', '');
        $path = parse_url($referer, PHP_URL_PATH);
        $host = parse_url($referer, PHP_URL_HOST);

        if (is_string($path) && str_starts_with($path, '/') && ($host === null || $host === $request->getHost())) {
            return $this->redirect($path);
        }

        return $ticker !== null
            ? $this->redirectToRoute('app_stock_view', ['ticker' => $ticker])
            : $this->redirectToRoute('app_dashboard');
    }
}
