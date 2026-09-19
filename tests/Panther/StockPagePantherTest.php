<?php

namespace App\Tests\Panther;

use Symfony\Component\Panther\PantherTestCase;

class StockPagePantherTest extends BasePantherTestCase
{
    public function testEtfPageLoadsAndRendersInteractiveElements(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/stock/LBI');

        $this->assertSelectorExists('#mainChartContainer');
        $this->assertSelectorExists('#etfPieChart');
        $this->assertSelectorTextContains('h1', 'Skein Lakebird 30 ETF');

        // Test Chart Range Buttons
        $client->executeScript("document.querySelector('button.range-btn[data-range=\"1w\"]').click()");
        $this->assertSelectorExists('.range-btn[data-range="1w"]');

        $client->executeScript("document.querySelector('button.range-btn[data-range=\"max\"]').click()");
        $this->assertSelectorExists('.range-btn[data-range="max"]');

        // Click Constituents tab
        $client->executeScript("document.querySelector('button[data-tab=\"constituents\"]').click()");
        $client->waitFor('#stock-tab-content-constituents:not(.hidden)', 2);

        $this->assertSelectorExists('#stock-tab-content-constituents table');

        // Click Order Depth tab
        $client->executeScript("document.querySelector('button[data-tab=\"orders\"]').click()");
        $client->waitFor('#stock-tab-content-orders:not(.hidden)', 2);

        $this->assertSelectorExists('#stock-tab-content-orders');
    }

    public function testRegularStockPageLoadsWithFinancialsAndSectorTabs(): void
    {
        $client = static::createPantherClient();
        $this->loginUser($client);

        // Pick an active listed stock
        $client->request('GET', '/screener');
        $crawler = $client->waitFor('table tbody tr a[href^="/stock/"]', 3);
        $stockLink = $crawler->filter('table tbody tr a[href^="/stock/"]:not([href="/stock/LBI"]):not([href="/stock/LSD"])')->first();
        $stockUrl = $stockLink->count() > 0 ? $stockLink->attr('href') : '/stock/SWAN';

        $client->request('GET', $stockUrl);

        $this->assertSelectorExists('#mainChartContainer');
        $this->assertSelectorExists('h1');

        // Click Financials & Fundamentals tab
        $client->executeScript("document.querySelector('button[data-tab=\"financials\"]').click()");
        $client->waitFor('#stock-tab-content-financials:not(.hidden)', 2);

        $this->assertSelectorExists('#netIncomeChart');

        // Click Company & Sector tab
        $client->executeScript("document.querySelector('button[data-tab=\"sector\"]').click()");
        $client->waitFor('#stock-tab-content-sector:not(.hidden)', 2);

        $this->assertSelectorExists('#stock-tab-content-sector');

        // Test Order Form or Trading Halted state
        $isHalted = (bool) $client->executeScript("return document.body.textContent.includes('Trading Halted');");
        if (!$isHalted) {
            $this->assertSelectorExists('form[action="/trade/execute"]');
            $client->executeScript("
                const limitRadio = document.querySelector('input[name=\"orderType\"][value=\"LIMIT\"]');
                if (limitRadio) {
                    limitRadio.checked = true;
                    limitRadio.dispatchEvent(new Event('change', { bubbles: true }));
                }
            ");
            $client->waitFor('#limit-price-group:not(.hidden)', 2);
            $this->assertSelectorExists('#limit-price-group');
        } else {
            $this->assertSelectorTextContains('body', 'Trading Halted');
        }
    }
}
