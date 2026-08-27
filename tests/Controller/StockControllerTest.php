<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StockControllerTest extends WebTestCase
{
    public function testHistoryApiRequiresTicker(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/history');

        $this->assertResponseIsSuccessful();
        $this->assertJsonStringEqualsJsonString('[]', $client->getResponse()->getContent());
    }
}
