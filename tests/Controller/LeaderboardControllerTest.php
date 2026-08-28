<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LeaderboardControllerTest extends WebTestCase
{
    public function testLeaderboardPageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $client->request('GET', '/leaderboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('h1');
        $this->assertSelectorTextContains('h1', 'Leaderboard');
        $this->assertSelectorExists('table');
    }
}
