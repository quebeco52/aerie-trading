<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserInterface;

class UserCheckerTest extends TestCase
{
    private UserChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new UserChecker();
    }

    public function testCheckPreAuthThrowsWhenUserNotVerified(): void
    {
        $user = new User();
        $user->setIsVerified(false);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Your email address is not verified.');

        $this->checker->checkPreAuth($user);
    }

    public function testCheckPreAuthPassesWhenUserIsVerified(): void
    {
        $user = new User();
        $user->setIsVerified(true);

        $this->checker->checkPreAuth($user);
        $this->assertTrue($user->isVerified());
    }

    public function testCheckPreAuthIgnoresNonAppUserInterface(): void
    {
        $genericUserStub = $this->createStub(UserInterface::class);

        // Should not throw
        $this->checker->checkPreAuth($genericUserStub);
        $this->checker->checkPostAuth($genericUserStub);
        $this->assertTrue(true);
    }
}
