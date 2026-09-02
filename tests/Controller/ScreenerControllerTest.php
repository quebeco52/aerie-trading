<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ScreenerControllerTest extends WebTestCase
{
    public function testScreenerPageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $client->request('GET', '/screener');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('h1');
        $this->assertSelectorTextContains('h1', 'Stock Screener');
        $this->assertSelectorExists('table');
        $this->assertSelectorExists('input#screener-search');
        $this->assertSelectorExists('[data-controller="screener"]');
        $this->assertSelectorExists('[data-screener-target="searchInput"]');
    }
}
