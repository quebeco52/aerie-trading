<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SearchControllerTest extends WebTestCase
{
    public function testAutocompleteReturnsEmptyArrayOnEmptyQuery(): void
    {
        $client = static::createClient();
        $client->request('GET', '/search/autocomplete');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
        $this->assertJsonStringEqualsJsonString('[]', $client->getResponse()->getContent());
    }

    public function testAutocompleteReturnsMatchingStocks(): void
    {
        $client = static::createClient();
        $client->request('GET', '/search/autocomplete?q=WING');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertEquals('WING', $data[0]['ticker']);
    }

    public function testSearchRedirectsToStockPageOnExactMatch(): void
    {
        $client = static::createClient();
        $client->request('GET', '/search?q=WING');

        $this->assertResponseRedirects('/stock/WING');
    }

    public function testSearchRedirectsToHomeOnEmptyQuery(): void
    {
        $client = static::createClient();
        $client->request('GET', '/search');

        $this->assertResponseRedirects('/');
    }

    public function testSearchRedirectsHomeWithErrorOnUnknownQuery(): void
    {
        $client = static::createClient();
        $client->request('GET', '/search?q=UNKNOWN_NONEXISTENT_TICKER_XYZ');

        $this->assertResponseRedirects('/');
        $client->followRedirect();
        $this->assertSelectorExists('div:contains("No stock found")');
    }
}
