<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EconomyControllerTest extends WebTestCase
{
    public function testTheEconomyPageRendersItsVitalsAndSeriesGrid(): void
    {
        $client = static::createClient();
        $client->request('GET', '/economy');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Economy');
        $this->assertSelectorExists('#macro-inflation');
        $this->assertSelectorExists('#macro-output-gap');
        $this->assertSelectorExists('#macro-policy-rate');
        $this->assertSelectorExists('#macro-yield');
        $this->assertSelectorExists('#macroChartsGrid');
        $this->assertSelectorExists('#macroChartsGrid .chart-card canvas');
        $this->assertSelectorExists('[data-macro-timeframe="10Y"]');
    }

    public function testTheEconomyIsAMainNavigationEntry(): void
    {
        $client = static::createClient();
        $client->request('GET', '/economy');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('nav a[href="/economy"][aria-current="page"]');
    }
}
