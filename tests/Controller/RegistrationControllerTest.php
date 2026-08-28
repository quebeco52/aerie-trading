<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RegistrationControllerTest extends WebTestCase
{
    public function testRegistrationPageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
        $this->assertSelectorExists('input#email');
        $this->assertSelectorExists('input#username');
        $this->assertSelectorExists('input#password');
        $this->assertSelectorExists('button[type="submit"]');
    }

    public function testRegistrationFailsWithMissingFields(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');
        $csrfToken = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/register', [
            'email' => '',
            'username' => 'incomplete_user',
            'password' => '',
            '_csrf_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/register');
        $client->followRedirect();
        $this->assertSelectorExists('div:contains("Please fill out all required fields")');
    }

    public function testRegistrationFailsWithInvalidCsrfToken(): void
    {
        $client = static::createClient();

        $client->request('POST', '/register', [
            'email' => 'valid@email.com',
            'username' => 'validuser',
            'password' => 'SecurePassword123!',
            '_csrf_token' => 'invalid_csrf',
        ]);

        $this->assertResponseRedirects('/register');
        $client->followRedirect();
        $this->assertSelectorExists('div:contains("Invalid security token")');
    }

    public function testRegistrationFailsWithDuplicateEmail(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');
        $csrfToken = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        // Use the seeded test user email
        $client->request('POST', '/register', [
            'email' => 'test.test@test.se',
            'username' => 'duplicate_user',
            'password' => 'somePassword123!',
            '_csrf_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/register');
        $client->followRedirect();
        $this->assertSelectorExists('div:contains("already exists")');
    }

    public function testVerifyEmailFailsWithMissingUserId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/verify/email');

        $this->assertResponseRedirects('/register');
        $client->followRedirect();
        $this->assertSelectorExists('div:contains("Invalid verification link")');
    }
}
