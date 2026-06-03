<?php

namespace App\Twig;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Firebase\JWT\JWT;

class WebSocketTicketExtension extends AbstractExtension
{
    public function __construct(
        private Security $security,
        #[Autowire('%kernel.secret%')] private string $appSecret
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ws_ticket', [$this, 'getWsTicket']),
        ];
    }

    public function getWsTicket(): string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return '';
        }

        $payload = [
            'uid' => $user->getId(),
            'exp' => time() + (3600 * 4) // 4 hours
        ];

        return JWT::encode($payload, $this->appSecret, 'HS256');
    }
}