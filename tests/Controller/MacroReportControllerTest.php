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
    }
}
