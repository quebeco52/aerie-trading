<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroStateProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The server-rendered pages all read the live snapshot through this, so that a ticker is priced
 * against one economy no matter which page is open. Three pages used to decode it themselves and
 * each answered the cold-Redis case differently.
 */
#[AllowMockObjectsWithoutExpectations]
class MacroStateProviderTest extends TestCase
{
    private function provider(mixed $stored): MacroStateProvider
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->atLeastOnce())
            ->method('get')
            ->with(MacroEngine::REDIS_MACRO_STATE)
            ->willReturn($stored);

        return new MacroStateProvider($redis);
    }

    public function testThePublishedSnapshotIsRead(): void
    {
        $state = $this->provider(json_encode([
            'policy_rate' => 0.0625,
            'inflation' => 0.041,
            'output_gap' => -0.012,
        ]))->liveState();

        $this->assertSame(0.0625, $state->policyRate);
        $this->assertSame(0.041, $state->inflation);
        $this->assertSame(-0.012, $state->outputGap);
    }

    /**
     * A cold Redis is the state before the ticker has ever run, and every page must see the same
     * economy then — the snapshot's own documented openings.
     */
    public function testAMissingSnapshotFallsBackToTheStandingOpenings(): void
    {
        $expected = \App\DTO\MacroStateDTO::fromArray([]);

        foreach ([false, null, ''] as $absent) {
            $state = $this->provider($absent)->liveState();

            $this->assertSame($expected->policyRate, $state->policyRate);
            $this->assertSame($expected->outputGap, $state->outputGap);
            $this->assertSame($expected->yield10y, $state->yield10y);
        }
    }

    public function testACorruptSnapshotIsTreatedAsAbsentRatherThanFatal(): void
    {
        foreach (['not json at all', '"a bare string"', '12345'] as $corrupt) {
            $state = $this->provider($corrupt)->liveState();

            $this->assertSame(\App\DTO\MacroStateDTO::fromArray([])->policyRate, $state->policyRate);
        }
    }

    public function testTheRawPayloadIsAvailableForTheAdminView(): void
    {
        $payload = $this->provider(json_encode(['policy_rate' => 0.05, 'nairu' => 0.041]))->livePayload();

        $this->assertSame(['policy_rate' => 0.05, 'nairu' => 0.041], $payload);
        $this->assertSame([], $this->provider(false)->livePayload());
    }
}
