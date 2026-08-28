<?php

namespace App\Tests\Panther;

use Symfony\Component\Panther\PantherTestCase;

class ScreenerLeaderboardPantherTest extends BasePantherTestCase
{
    public function testScreenerPageLoadsAndFilters(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/screener');

        $this->assertSelectorExists('input#screener-search');
        $this->assertSelectorExists('table#screener-table');
        $this->assertSelectorTextContains('h1', 'Stock Screener');

        // Test search input typing
        $client->executeScript("
            const input = document.getElementById('screener-search');
            if (input) {
                input.value = 'WING';
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        ");

        $this->assertSelectorExists('tr[data-ticker="WING"]');

        // Test sector filter button click
        $client->executeScript("
            const allBtn = document.querySelector('.sector-filter-btn[data-sector=\"ALL\"]');
            if (allBtn) allBtn.click();
        ");
        $this->assertSelectorExists('#sector-filters');

        // Test column header sorting click
        $client->executeScript("
            const sortHeader = document.querySelector('th[data-col=\"price\"]');
            if (sortHeader) sortHeader.click();
        ");
        $this->assertSelectorExists('#screener-table');
    }

    public function testLeaderboardPageLoads(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/leaderboard');

        $this->assertSelectorExists('h1');
        $this->assertSelectorTextContains('h1', 'Leaderboard');
        $this->assertSelectorExists('table');
    }
}
