<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityControllerTest extends WebTestCase
{
    public function testLoginPageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        // Depending on Twig template, verify the form exists
        $this->assertSelectorExists('form');
    }

    public function testLogoutRouteRedirects(): void
    {
        $client = static::createClient();
        $client->request('GET', '/logout');

        $this->assertResponseRedirects();
    }
}