<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ReserveControllerTest extends WebTestCase
{
    public function testTheReservePageRendersItsHeadlineFigures(): void
    {
        $client = static::createClient();
        $client->request('GET', '/reserve');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Sovereign Reserve Fund');
        $this->assertSelectorExists('#reserve-value');
        $this->assertSelectorExists('#reserve-to-gdp');
        $this->assertSelectorExists('#reserve-draw');
        $this->assertSelectorExists('#reserve-duty');
        $this->assertSelectorExists('#reserve-ownership');
        $this->assertSelectorExists('#reserve-programme');
        $this->assertSelectorExists('a[href="/economy"]');
    }

    public function testTheReserveIsAMainNavigationEntry(): void
    {
        $client = static::createClient();
        $client->request('GET', '/reserve');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('nav a[href="/reserve"][aria-current="page"]');
    }
}
