<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Entity\User;
use App\Service\User\DevAdminAccount;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The README's admin sign-in exists wherever seed or reset runs, except production. */
class DevAdminAccountTest extends TestCase
{
    public function testProductionGetsNoKnownPasswordAdministrator(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $this->assertFalse((new DevAdminAccount($entityManager, $this->createStub(UserPasswordHasherInterface::class), 'prod'))->ensure());
    }

    public function testDevelopmentGetsAVerifiedAdministrator(): void
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $persisted = null;
        $entityManager->expects($this->once())->method('persist')->willReturnCallback(static function (object $user) use (&$persisted): void {
            $persisted = $user;
        });

        $this->assertTrue((new DevAdminAccount($entityManager, $this->createStub(UserPasswordHasherInterface::class), 'dev'))->ensure());

        $this->assertInstanceOf(User::class, $persisted);
        $this->assertSame(DevAdminAccount::EMAIL, $persisted->getEmail());
        $this->assertContains('ROLE_ADMIN', $persisted->getRoles());
        $this->assertTrue($persisted->isVerified());
    }
}
