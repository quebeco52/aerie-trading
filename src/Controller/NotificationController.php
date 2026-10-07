<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    /** Messages on one page of the account's inbox. */
    private const PER_PAGE = 30;

    #[Route('/notifications', name: 'app_notifications')]
    public function index(Request $request, NotificationRepository $notifications): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $total = $notifications->countFor($user);
        $items = $notifications->page($user, $page, self::PER_PAGE);

        // Rendered with their read state as it was, then marked: the page shows what was new on this visit.
        $response = $this->render('notifications/index.html.twig', [
            'notifications' => $items,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
        $notifications->markAllRead($user);

        return $response;
    }

    #[Route('/api/notifications/unread', name: 'api_notifications_unread', methods: ['GET'])]
    public function unread(NotificationRepository $notifications): JsonResponse
    {
        $user = $this->getUser();

        return $this->json(['count' => $user instanceof User ? $notifications->countUnread($user) : 0]);
    }
}
