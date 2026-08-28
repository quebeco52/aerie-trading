<?php

namespace App\Tests\Panther;

class DashboardPantherTest extends BasePantherTestCase
{
    public function testDashboardRendersStatsAndHandlesTabSwitching(): void
    {
        $client = static::createPantherClient();
        $this->loginUser($client);

        $client->request('GET', '/dashboard');
        $client->waitFor('#portfolio-total-value', 5);

        // Verify Hero Metrics
        $this->assertSelectorExists('#portfolio-total-value');
        $this->assertSelectorExists('#available-cash');
        $this->assertSelectorExists('#total-invested');
        $this->assertSelectorExists('#portfolioChartContainer');

        // Test Tab: Orders & Trades
        $client->executeScript("document.querySelector('button.portfolio-tab-btn[data-tab=\"orders\"]').click()");
        $client->waitFor('#tab-content-orders:not(.hidden)', 2);
        $this->assertSelectorExists('#tab-content-orders');

        // Test Tab: Analytics & Risk
        $client->executeScript("document.querySelector('button.portfolio-tab-btn[data-tab=\"analytics\"]').click()");
        $client->waitFor('#tab-content-analytics:not(.hidden)', 2);
        $this->assertSelectorExists('#tab-content-analytics');

        // Test Tab: Account & Cash
        $client->executeScript("document.querySelector('button.portfolio-tab-btn[data-tab=\"account\"]').click()");
        $client->waitFor('#tab-content-account:not(.hidden)', 2);
        $this->assertSelectorExists('#tab-content-account');

        // Test Tab: Back to Holdings
        $client->executeScript("document.querySelector('button.portfolio-tab-btn[data-tab=\"holdings\"]').click()");
        $client->waitFor('#tab-content-holdings:not(.hidden)', 2);
        $this->assertSelectorExists('#tab-content-holdings');
    }

    public function testDashboardChartRangeButtons(): void
    {
        $client = static::createPantherClient();
        $this->loginUser($client);

        $client->request('GET', '/dashboard');
        $client->waitFor('.portfolio-range-btn[data-range="1w"]', 5);

        $this->assertSelectorExists('.portfolio-range-btn[data-range="1w"]');
        $this->assertSelectorExists('.portfolio-range-btn[data-range="1y"]');
        $this->assertSelectorExists('.portfolio-range-btn[data-range="max"]');

        // Click 1W Range
        $client->executeScript("document.querySelector('.portfolio-range-btn[data-range=\"1w\"]').click()");
        $this->assertSelectorExists('#portfolioChartContainer');
    }
}
