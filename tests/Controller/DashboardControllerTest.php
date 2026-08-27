<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardControllerTest extends WebTestCase
{
    public function testDashboardRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        $this->assertResponseRedirects('/login');
    }

    public function testPortfolioHistoryRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/portfolio/history');

        $this->assertResponseRedirects('/login');
    }
}
