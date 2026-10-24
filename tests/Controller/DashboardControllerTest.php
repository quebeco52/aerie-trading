<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
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

    public function testDashboardRendersForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $userRepo = static::getContainer()->get(UserRepository::class);
        $testUser = $userRepo->findOneBy(['email' => 'test.test@test.se']);

        if (!$testUser) {
            $this->markTestSkipped('Test user not found.');
        }

        $client->loginUser($testUser);
        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#portfolio-total-value');
        $this->assertSelectorExists('#available-cash');
        $this->assertSelectorExists('#total-invested');
        $this->assertSelectorExists('#portfolioChartContainer');
        $this->assertSelectorExists('[data-controller="tabs"]');
        $this->assertSelectorExists('.tab-btn[data-tab="holdings"]');
        $this->assertSelectorExists('.tab-btn[data-tab="orders"]');
        $this->assertSelectorExists('.tab-btn[data-tab="analytics"]');
        $this->assertSelectorExists('.tab-btn[data-tab="account"]');
    }

    public function testPortfolioHistoryReturnsJsonForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $userRepo = static::getContainer()->get(UserRepository::class);
        $testUser = $userRepo->findOneBy(['email' => 'test.test@test.se']);

        if (!$testUser) {
            $this->markTestSkipped('Test user not found.');
        }

        $client->loginUser($testUser);
        $client->request('GET', '/api/portfolio/history?range=1m');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);
        $data = json_decode($content, true);
        $this->assertIsArray($data);
    }
}
