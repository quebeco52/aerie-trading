<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `unread_notifications()`: the signed-in account's unread count, for the navigation bell. */
final class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notifications,
    ) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('unread_notifications', $this->unread(...))];
    }

    public function unread(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User && $user->getId() !== null ? $this->notifications->countUnread($user) : 0;
    }
}
