<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SearchControllerTest extends WebTestCase
{
    public function testAutocompleteReturnsEmptyArrayOnEmptyQuery(): void
    {
        $client = static::createClient();
        $client->request('GET', '/search/autocomplete');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
        $this->assertJsonStringEqualsJsonString('[]', $this->responseBody($client));
    }

    public function testAutocompleteReturnsMatchingStocks(): void
    {
        $client = static::createClient();
        $client->request('GET', '/search/autocomplete?q=WING');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->responseBody($client), true);
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

    /** The response body; a JSON endpoint sends it as a string, never as a stream. */
    private function responseBody(KernelBrowser $client): string
    {
        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);

        return $content;
    }
}
