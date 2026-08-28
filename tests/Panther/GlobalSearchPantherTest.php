<?php

namespace App\Tests\Panther;

use Symfony\Component\Panther\PantherTestCase;

class GlobalSearchPantherTest extends BasePantherTestCase
{
    public function testGlobalSearchAutocompleteAndNavigation(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/');

        $this->assertSelectorExists('#market-search-input');
        $this->assertSelectorExists('#search-results-dropdown');

        // Type into the search input
        $client->executeScript("
            const input = document.getElementById('market-search-input');
            if (input) {
                input.value = 'WING';
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        ");

        // Wait for autocomplete results to be injected into dropdown
        $client->waitFor('#search-results-dropdown:not(.hidden)', 3);
        $this->assertSelectorExists('#search-results-dropdown');

        // Submit search form
        $client->executeScript("
            const form = document.getElementById('search-form');
            if (form) form.submit();
        ");

        $client->waitFor('#mainChartContainer', 3);
        $this->assertSelectorExists('#mainChartContainer');
        $this->assertStringContainsString('/stock/WING', $client->getCurrentURL());
    }
}
