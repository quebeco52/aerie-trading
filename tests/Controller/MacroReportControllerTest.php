<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MacroReportControllerTest extends WebTestCase
{
    public function testGetMacroReportsReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/macro-reports');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        if (!empty($data)) {
            $first = $data[0];
            $this->assertArrayHasKey('natural_gas_price_index_ema', $first);
            $this->assertArrayHasKey('sovereign_risk_spread_ema', $first);
            $this->assertArrayHasKey('primary_deficit_to_gdp', $first);
            $this->assertArrayHasKey('household_debt_service_ratio_ema', $first);
            $this->assertArrayHasKey('countercyclical_buffer_rate', $first);
            $this->assertArrayHasKey('foreign_output_gap_ema', $first);
            $this->assertArrayHasKey('system_deposit_beta_ema', $first);
        }
    }
}
