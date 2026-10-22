<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\RegistrationController;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/** A sign-up the database or the mailer would refuse is turned away with a reason, before anything is saved. */
class RegistrationRulesTest extends TestCase
{
    public function testAnOrdinarySignUpPasses(): void
    {
        $this->assertNull(RegistrationController::problemWith('trader@example.com', 'trader'));
    }

    public function testAnAddressTheMailerCannotSendToIsRefused(): void
    {
        $this->assertSame('That email address is not valid.', RegistrationController::problemWith('trader@exa mple.com', 'trader'));
    }

    public function testValuesLongerThanTheirColumnsAreRefused(): void
    {
        $longEmail = str_repeat('a', User::EMAIL_MAX_LENGTH) . '@example.com';
        $this->assertSame('Email addresses can be at most 180 characters.', RegistrationController::problemWith($longEmail, 'trader'));
        $this->assertSame('Usernames can be at most 255 characters.', RegistrationController::problemWith('trader@example.com', str_repeat('x', User::USERNAME_MAX_LENGTH + 1)));
        $this->assertNull(RegistrationController::problemWith('trader@example.com', str_repeat('x', User::USERNAME_MAX_LENGTH)));
    }
}
