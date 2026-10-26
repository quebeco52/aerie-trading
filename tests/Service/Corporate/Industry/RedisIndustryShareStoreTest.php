<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Industry;

use App\Service\Corporate\Industry\RedisIndustryShareStore;
use App\Tests\Support\InMemoryRedis;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class RedisIndustryShareStoreTest extends TestCase
{
    /**
     * Every field a record carries comes back as it was written: the store rebuilds each record from a list of fields,
     * and one left off that list is dropped on every read (a company in several markets lost its blended outlook that
     * way). A record without the optional fields comes back without them.
     */
    public function testARecordComesBackWithEveryFieldItWasWrittenWith(): void
    {
        $store = new RedisIndustryShareStore(new InMemoryRedis());
        $full = [
            'revenue' => 1_000.0, 'gain' => 0.01, 'capacity' => 1_200.0,
            'anchor_capacity_share' => 0.002, 'anchor_demand_share' => 0.005, 'anchor_time' => 1.25, 'anchor_outlook' => 0.03,
            'addressable_share' => 0.4, 'tick' => 90, 'consumed_tick' => 80, 'segment_outlook_gap' => -0.07, 'books_main_share' => 0.9,
            'path' => -1.5, 'anchor_path' => -4.0,
        ];
        $plain = [
            'revenue' => 500.0, 'gain' => -0.02, 'capacity' => 600.0,
            'anchor_capacity_share' => 0.001, 'anchor_demand_share' => 0.003, 'anchor_time' => 0.25, 'anchor_outlook' => 0.0,
            'addressable_share' => 0.2, 'tick' => 95, 'consumed_tick' => 85,
        ];

        $store->writeRecord('Airlines', 'TERN', $full);
        $store->writeRecord('Airlines', 'GULL', $plain);
        $records = $store->readIndustry('Airlines');

        $this->assertSame($full, $records['TERN']);
        $this->assertSame($plain, $records['GULL']);
    }
}
