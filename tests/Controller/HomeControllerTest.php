<?php

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HomeControllerTest extends WebTestCase
{
    public function testLandingPageRendersForUnauthenticatedUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('h1');
        $this->assertSelectorTextContains('h1', 'Aerie Exchange');
        $this->assertSelectorExists('a[href="/register"], a[href="/login"]');
    }

    public function testMarketOverviewRendersForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $userRepo = static::getContainer()->get(UserRepository::class);
        $testUser = $userRepo->findOneBy(['email' => 'test.test@test.se']);

        if (!$testUser) {
            $this->markTestSkipped('Test user not found.');
        }

        $client->loginUser($testUser);
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('h1, table, #mainChartContainer, div');
    }

    public function testApiMarketReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/market');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('etf', $data);
        $this->assertArrayHasKey('stocks', $data);
        $this->assertIsArray($data['stocks']);
    }
}
