<?php

namespace App\Twig\Extension; // <-- Updated Namespace!

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

class WebsocketExtension extends AbstractExtension
{
    public function __construct(
        private Security $security,
        private \Redis $redis
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('generate_ws_ticket', [$this, 'generateTicket']),
        ];
    }

    public function generateTicket(): string
    {
        $user = $this->security->getUser();
        
        // If no user is logged in, return an empty string
        if (!$user instanceof User) {
            return '';
        }

        // Generate and save to Redis
        $ticket = bin2hex(random_bytes(16));
        $this->redis->setex("ws_ticket:{$ticket}", 30, $user->getId());

        return $ticket;
    }
}