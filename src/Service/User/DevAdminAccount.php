<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The development sign-in that seed and reset create: an administrator with a password published in the README.
 * Anyone who has read the repository could use it, so production never gets one.
 */
class DevAdminAccount
{
    public const EMAIL = 'test.test@test.se';
    private const PASSWORD = 'test';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {}

    /**
     * Creates the account if it is missing and keeps it verified, outside production; the caller flushes. An existing
     * account keeps its password and roles.
     *
     * @return bool Whether the account was ensured; false in production.
     */
    public function ensure(): bool
    {
        if ($this->environment === 'prod') {
            return false;
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        if (!$user instanceof User) {
            $user = new User();
            $user->setEmail(self::EMAIL);
            $user->setRoles(['ROLE_ADMIN']);
            $user->setCashBalance('10000.00');
            $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));
            $this->entityManager->persist($user);
        }

        $user->setUsername('Test');
        $user->setIsVerified(true);

        return true;
    }
}
