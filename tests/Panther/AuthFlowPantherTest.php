<?php

namespace App\Tests\Panther;

use Symfony\Component\Panther\PantherTestCase;

class AuthFlowPantherTest extends BasePantherTestCase
{
    public function testLoginPageLoads(): void
    {
        $client = static::createPantherClient();
        $client->getCookieJar()->clear();
        $client->request('GET', '/logout');

        $client->request('GET', '/login');

        $this->assertSelectorExists('form');
        $this->assertSelectorExists('input[name="_username"]');
        $this->assertSelectorExists('input[name="_password"]');
    }

    public function testDashboardRedirectsToLoginWhenUnauthenticated(): void
    {
        $client = static::createPantherClient();
        $client->getCookieJar()->clear();
        $client->request('GET', '/logout');

        $client->request('GET', '/dashboard');

        $this->assertSelectorExists('form');
        $this->assertStringContainsString('/login', $client->getCurrentURL());
    }
}
