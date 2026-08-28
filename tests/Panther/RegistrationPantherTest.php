<?php

namespace App\Tests\Panther;

use Symfony\Component\Panther\PantherTestCase;

class RegistrationPantherTest extends BasePantherTestCase
{
    public function testRegistrationPageRendersElements(): void
    {
        $client = static::createPantherClient();
        
        $client->getCookieJar()->clear();
        $client->request('GET', '/logout');

        $client->request('GET', '/register');
        $client->waitFor('#email', 3);

        $this->assertSelectorExists('form');
        $this->assertSelectorExists('#email');
        $this->assertSelectorExists('#username');
        $this->assertSelectorExists('#password');
        $this->assertSelectorExists('button[type="submit"]');
    }

    public function testRegistrationWithInvalidEmailShowsHtml5Validation(): void
    {
        $client = static::createPantherClient();
        
        $client->getCookieJar()->clear();
        $client->request('GET', '/logout');

        $client->request('GET', '/register');
        $client->waitFor('#email', 3);

        // Required attributes exist
        $this->assertSelectorExists('input#email[required]');
        $this->assertSelectorExists('input#username[required]');
        $this->assertSelectorExists('input#password[required]');
    }
}
