<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StockControllerTest extends WebTestCase
{
    public function testHistoryApiRequiresTicker(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/history');

        $this->assertResponseIsSuccessful();
        $this->assertJsonStringEqualsJsonString('[]', $client->getResponse()->getContent());
    }

    public function testHistoryApiWithValidTicker(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/history?ticker=WING&range=1y');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testStockViewLoadsForValidStock(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stock/WING');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('h1');
        $this->assertSelectorTextContains('h1', 'Steel Wings');
        $this->assertSelectorExists('#mainChartContainer');
        $this->assertSelectorExists('.stock-tab-btn[data-tab="overview"]');
        $this->assertSelectorExists('.stock-tab-btn[data-tab="financials"]');
        $this->assertSelectorExists('.stock-tab-btn[data-tab="sector"]');
        $this->assertSelectorExists('.stock-tab-btn[data-tab="orders"]');
    }

    public function testStockViewLoadsForValidEtf(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stock/LBI');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('h1');
        $this->assertSelectorTextContains('h1', 'Lakebird Index');
        $this->assertSelectorExists('#etfPieChart');
        $this->assertSelectorExists('.stock-tab-btn[data-tab="macro"]');
    }

    public function testStockViewThrows404ForNonexistentTicker(): void
    {
        $client = static::createClient();
        $client->request('GET', '/stock/NONEXISTENT_TICKER_XYZ');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testFundamentalsApiReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/fundamentals?ticker=WING');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testEarningsFlowApiReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/earnings-flow?ticker=WING');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }
}
