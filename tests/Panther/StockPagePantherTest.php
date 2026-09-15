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

        // Click Macro tab
        $client->executeScript("document.querySelector('button[data-tab=\"macro\"]').click()");
        $client->waitFor('#stock-tab-content-macro:not(.hidden)', 2);

        $this->assertSelectorExists('#macroEconomyChart');
        $this->assertSelectorExists('#macroRatesChart');

        // Click Order Depth tab
        $client->executeScript("document.querySelector('button[data-tab=\"orders\"]').click()");
        $client->waitFor('#stock-tab-content-orders:not(.hidden)', 2);

        $this->assertSelectorExists('#stock-tab-content-orders');
    }

    public function testRegularStockPageLoadsWithFinancialsAndSectorTabs(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/stock/LAKE');

        $this->assertSelectorExists('#mainChartContainer');
        $this->assertSelectorTextContains('h1', 'Lakebird Bank');

        // Click Financials & Fundamentals tab
        $client->executeScript("document.querySelector('button[data-tab=\"financials\"]').click()");
        $client->waitFor('#stock-tab-content-financials:not(.hidden)', 2);

        $this->assertSelectorExists('#netIncomeChart');

        // Click Company & Sector tab
        $client->executeScript("document.querySelector('button[data-tab=\"sector\"]').click()");
        $client->waitFor('#stock-tab-content-sector:not(.hidden)', 2);

        $this->assertSelectorExists('#stock-tab-content-sector');

        // Test Order Form Limit toggle
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
    }
}
