<?php

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TradeControllerTest extends WebTestCase
{
    public function testExecuteTradeRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/trade/execute', [
            'ticker' => 'WING',
            'action' => 'BUY',
            'orderType' => 'MARKET',
            'quantity' => 10,
        ]);

        $this->assertResponseRedirects('/login');
    }

    public function testExecuteTradeFailsWithInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $userRepo = static::getContainer()->get(UserRepository::class);
        $testUser = $userRepo->findOneBy(['email' => 'test.test@test.se']);

        if (!$testUser) {
            $this->markTestSkipped('Test user not found in database.');
        }

        $client->loginUser($testUser);
        $client->request('POST', '/trade/execute', [
            'ticker' => 'WING',
            'action' => 'BUY',
            'orderType' => 'MARKET',
            'quantity' => 5,
            '_token' => 'invalid_csrf_token',
        ], [], ['HTTP_REFERER' => '/stock/WING']);

        $this->assertResponseRedirects('/stock/WING');
        $client->followRedirect();
        $this->assertSelectorExists('.bg-red-500\\/10, .bg-red-950\\/20, div:contains("Invalid security token")');
    }

    public function testExecuteTradeFailsWithInvalidQuantity(): void
    {
        $client = static::createClient();
        $userRepo = static::getContainer()->get(UserRepository::class);
        $testUser = $userRepo->findOneBy(['email' => 'test.test@test.se']);

        if (!$testUser) {
            $this->markTestSkipped('Test user not found in database.');
        }

        $client->loginUser($testUser);
        $crawler = $client->request('GET', '/stock/WING');
        $csrfToken = $crawler->filter('form[action="/trade/execute"] input[name="_token"]')->attr('value');

        $client->request('POST', '/trade/execute', [
            'ticker' => 'WING',
            'action' => 'BUY',
            'orderType' => 'MARKET',
            'quantity' => 0,
            '_token' => $csrfToken,
        ], [], ['HTTP_REFERER' => '/stock/WING']);

        $this->assertResponseRedirects('/stock/WING');
        $client->followRedirect();
        $this->assertSelectorExists('div:contains("Invalid quantity")');
    }

    public function testExecuteMarketBuyOrderSuccess(): void
    {
        $client = static::createClient();
        $userRepo = static::getContainer()->get(UserRepository::class);
        $testUser = $userRepo->findOneBy(['email' => 'test.test@test.se']);

        if (!$testUser) {
            $this->markTestSkipped('Test user not found in database.');
        }

        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $testUser->setCashBalance('1000000.00');
        $em->flush();

        $client->loginUser($testUser);
        $crawler = $client->request('GET', '/stock/WING');
        $csrfToken = $crawler->filter('form[action="/trade/execute"] input[name="_token"]')->attr('value');

        $client->request('POST', '/trade/execute', [
            'ticker' => 'WING',
            'action' => 'BUY',
            'orderType' => 'MARKET',
            'quantity' => 2,
            '_token' => $csrfToken,
        ], [], ['HTTP_REFERER' => '/stock/WING']);

        $this->assertResponseRedirects('/stock/WING');
        $client->followRedirect();
        $this->assertSelectorExists('div:contains("Order placed successfully")');
    }

    public function testCancelTradeRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/trade/cancel/999');

        $this->assertResponseRedirects('/login');
    }
}
