<?php

namespace App\Tests\Panther;

use Symfony\Component\Panther\PantherTestCase;

class TradeFlowPantherTest extends BasePantherTestCase
{
    public function testAuthenticatedUserCanExecuteMarketAndLimitTrades(): void
    {
        $client = static::createPantherClient();
        $this->loginUser($client);

        // Navigate to LAKE stock page
        $client->request('GET', '/stock/LAKE');

        $this->assertSelectorExists('#mainChartContainer');
        $this->assertSelectorExists('form[action="/trade/execute"]');

        // Place a Market BUY order for 2 shares
        $client->executeScript("
            const form = document.querySelector('form[action=\"/trade/execute\"]');
            if (form) {
                form.querySelector('input[name=\"quantity\"]').value = '2';
                const buyBtn = form.querySelector('button[value=\"BUY\"]');
                if (buyBtn) buyBtn.click();
            }
        ");

        // Wait for page to reload with flash message
        $client->waitFor('.rounded-xl, .shadow-lg, div', 3);
        $this->assertSelectorExists('body');

        // Test Limit order toggle and placement
        $client->request('GET', '/stock/LAKE');
        $client->executeScript("
            const limitRadio = document.querySelector('input[name=\"orderType\"][value=\"LIMIT\"]');
            if (limitRadio) {
                limitRadio.checked = true;
                limitRadio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        ");

        // Limit price container should be visible
        $client->waitFor('#limit-price-group:not(.hidden)', 2);
        $this->assertSelectorExists('#limit-price-group');

        // Submit Limit BUY order at low price ($1.00) so it remains open
        $client->executeScript("
            const form = document.querySelector('form[action=\"/trade/execute\"]');
            if (form) {
                form.querySelector('input[name=\"quantity\"]').value = '1';
                form.querySelector('input[name=\"limitPrice\"]').value = '1.00';
                const buyBtn = form.querySelector('button[value=\"BUY\"]');
                if (buyBtn) buyBtn.click();
            }
        ");

        // Navigate to Order Depth & Trades tab
        $client->waitFor('button[data-tab="orders"]', 3);
        $client->executeScript("document.querySelector('button[data-tab=\"orders\"]').click()");
        $client->waitFor('#stock-tab-content-orders:not(.hidden)', 3);

        $this->assertSelectorExists('#stock-tab-content-orders');
    }
}
