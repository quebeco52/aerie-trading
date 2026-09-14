<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\StreamContext;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class StreamContextTest extends TestCase
{
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
    }

    public function testResolveActiveStreamWeightsOnInitialRun(): void
    {
        $context = new StreamContext([], $this->mathUtility);

        $targets = [
            'stream_a' => 0.60,
            'stream_b' => 0.20,
            'stream_c' => 0.20,
        ];

        $weights = $context->resolveActiveStreamWeights($targets);

        $this->assertEqualsWithDelta(0.60, $weights['stream_a'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $weights['stream_b'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $weights['stream_c'], 0.0001);
        $this->assertEqualsWithDelta(1.0, array_sum($weights), 0.0001);

        $streamZ = $context->getStreamZ();
        $this->assertEqualsWithDelta(0.60, $streamZ['weight:stream_a'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $streamZ['weight:stream_b'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $streamZ['weight:stream_c'], 0.0001);
    }

    public function testResolveActiveStreamWeightsWithRealizedShareDrift(): void
    {
        // Previous quarter had stream_c surge to 60% of sales
        $previousMomentum = [
            'weight:stream_a' => 0.60,
            'weight:stream_b' => 0.20,
            'weight:stream_c' => 0.20,
            'share:stream_a'  => 0.25,
            'share:stream_b'  => 0.15,
            'share:stream_c'  => 0.60,
        ];

        $context = new StreamContext($previousMomentum, $this->mathUtility);

        $targets = [
            'stream_a' => 0.60,
            'stream_b' => 0.20,
            'stream_c' => 0.20,
        ];

        $active = $context->resolveActiveStreamWeights(
            $targets,
            adaptationRate: 0.15,
            reversionSpeed: 0.08
        );

        // Stream C should have drifted above 0.20
        $this->assertGreaterThan(0.20, $active['stream_c']);
        // Stream A should have drifted below 0.60
        $this->assertLessThan(0.60, $active['stream_a']);
        // Simplex property
        $this->assertEqualsWithDelta(1.0, array_sum($active), 0.0001);
    }

    public function testResolveActiveStreamWeightsStrategicMeanReversion(): void
    {
        // Stream C was previously expanded to 0.40, but realized share dropped back to target 0.20
        $previousMomentum = [
            'weight:stream_a' => 0.45,
            'weight:stream_b' => 0.15,
            'weight:stream_c' => 0.40,
            'share:stream_a'  => 0.60,
            'share:stream_b'  => 0.20,
            'share:stream_c'  => 0.20,
        ];

        $context = new StreamContext($previousMomentum, $this->mathUtility);

        $targets = [
            'stream_a' => 0.60,
            'stream_b' => 0.20,
            'stream_c' => 0.20,
        ];

        $active = $context->resolveActiveStreamWeights(
            $targets,
            adaptationRate: 0.15,
            reversionSpeed: 0.08
        );

        // Stream C active weight should pull back toward 0.20 (below previous 0.40)
        $this->assertLessThan(0.40, $active['stream_c']);
        // Stream A active weight should pull up toward 0.60 (above previous 0.45)
        $this->assertGreaterThan(0.45, $active['stream_a']);
        $this->assertEqualsWithDelta(1.0, array_sum($active), 0.0001);
    }

    public function testResolveActiveStreamWeightsClampingBounds(): void
    {
        // Extreme past share of 100% on stream_c
        $previousMomentum = [
            'weight:stream_a' => 0.10,
            'weight:stream_b' => 0.10,
            'weight:stream_c' => 0.80,
            'share:stream_a'  => 0.00,
            'share:stream_b'  => 0.00,
            'share:stream_c'  => 1.00,
        ];

        $context = new StreamContext($previousMomentum, $this->mathUtility);

        $targets = [
            'stream_a' => 0.60,
            'stream_b' => 0.20,
            'stream_c' => 0.20,
        ];

        $active = $context->resolveActiveStreamWeights(
            $targets,
            adaptationRate: 0.50,
            reversionSpeed: 0.00,
            minFloor: 0.05,
            maxCeiling: 0.85
        );

        $this->assertGreaterThanOrEqual(0.05, $active['stream_a']);
        $this->assertGreaterThanOrEqual(0.05, $active['stream_b']);
        $this->assertLessThanOrEqual(0.90, $active['stream_c']);
        $this->assertEqualsWithDelta(1.0, array_sum($active), 0.0001);
    }

    public function testRecordStreamShares(): void
    {
        $context = new StreamContext([], $this->mathUtility);

        $revenues = [
            'stream_a' => 6000.0,
            'stream_b' => 2000.0,
            'stream_c' => 2000.0,
        ];

        $context->recordStreamShares($revenues);

        $streamZ = $context->getStreamZ();
        $this->assertEqualsWithDelta(0.60, $streamZ['share:stream_a'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $streamZ['share:stream_b'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $streamZ['share:stream_c'], 0.0001);
    }

    public function testResolveActiveStreamWeightsWithZeroTargetWeightDoesNotClampToFloor(): void
    {
        // Quarter 2 with past momentum for a pure-play firm with a 0% stream (e.g., PERE with 0% advisory)
        $previousMomentum = [
            'weight:advisory'               => 0.00,
            'weight:trading'                => 0.40,
            'weight:options_premium_income' => 0.60,
            'share:advisory'                => 0.00,
            'share:trading'                 => 0.40,
            'share:options_premium_income'  => 0.60,
        ];

        $context = new StreamContext($previousMomentum, $this->mathUtility);

        $targets = [
            'advisory'               => 0.00,
            'trading'                => 0.40,
            'options_premium_income' => 0.60,
        ];

        $active = $context->resolveActiveStreamWeights($targets);

        $this->assertSame(0.0, $active['advisory'], 'Stream with 0.0 target must remain strictly 0.0 and never clamp to the 5% floor.');
        $this->assertEqualsWithDelta(0.40, $active['trading'], 0.0001);
        $this->assertEqualsWithDelta(0.60, $active['options_premium_income'], 0.0001);
        $this->assertEqualsWithDelta(1.0, array_sum($active), 0.0001);
    }

    public function testResolveActiveStreamWeightsWithSubFloorTargetWeight(): void
    {
        // Target weight of 2% (0.02) which is below the default 5% minFloor
        $previousMomentum = [
            'weight:core'  => 0.98,
            'weight:niche' => 0.02,
            'share:core'   => 0.98,
            'share:niche'  => 0.02,
        ];

        $context = new StreamContext($previousMomentum, $this->mathUtility);

        $targets = [
            'core'  => 0.98,
            'niche' => 0.02,
        ];

        $active = $context->resolveActiveStreamWeights($targets);

        $this->assertEqualsWithDelta(0.02, $active['niche'], 0.0001, 'A 2% target stream must not be artificially forced up to 5%.');
        $this->assertEqualsWithDelta(0.98, $active['core'], 0.0001);
    }

    public function testResolveActiveStreamWeightsWithHighConcentrationTarget(): void
    {
        // Target weight of 90% (0.90) which is above the default 85% maxCeiling
        $previousMomentum = [
            'weight:dominant'  => 0.90,
            'weight:secondary' => 0.10,
            'share:dominant'   => 0.90,
            'share:secondary'  => 0.10,
        ];

        $context = new StreamContext($previousMomentum, $this->mathUtility);

        $targets = [
            'dominant'  => 0.90,
            'secondary' => 0.10,
        ];

        $active = $context->resolveActiveStreamWeights($targets);

        $this->assertEqualsWithDelta(0.90, $active['dominant'], 0.0001, 'A 90% dominant target stream must not be capped at 85%.');
        $this->assertEqualsWithDelta(0.10, $active['secondary'], 0.0001);
    }

    public function testResolveDominantShockZ(): void
    {
        $context = new StreamContext([], $this->mathUtility);

        // Stream with highest absolute magnitude dominates
        $this->assertSame(-2.5, $context->resolveDominantShockZ([1.0, -2.5, 0.5]));
        $this->assertSame(-3.2, $context->resolveDominantShockZ([1.0, -3.2, 2.5]));

        // Event shock with higher magnitude overrides streams
        $this->assertSame(4.0, $context->resolveDominantShockZ([1.0, -2.5], 4.0));
        $this->assertSame(-4.5, $context->resolveDominantShockZ([1.0, 2.5], -4.5));

        // When stream shock is larger than event shock
        $this->assertSame(-3.0, $context->resolveDominantShockZ([1.0, -3.0], 2.0));

        // Dictionary of named streams
        $this->assertSame(-2.8, $context->resolveDominantShockZ(['oem' => 1.2, 'mro' => -2.8], 0.5));

        // Empty streams with event shock
        $this->assertSame(1.5, $context->resolveDominantShockZ([], 1.5));
        $this->assertSame(0.0, $context->resolveDominantShockZ([]));
    }
    public function testEvolveRegimeStartsOnOnsetHazardAndCountsQuarters(): void
    {
        $math = $this->createStub(MathUtility::class);
        $math->method('checkProbability')->willReturnCallback(fn (float $p): bool => $p >= 0.5);

        // Inactive with a certain onset: the regime starts this quarter.
        $context = new StreamContext([], $math);
        $this->assertSame(1, $context->evolveRegime('strike', 1.0, 0.0));
        $this->assertSame(1, $context->getRegimeElapsed('strike'));
        $this->assertSame(1.0, $context->getStreamZ()[StreamContext::REGIME_STATE_PREFIX . 'strike']);

        // Active with no exit: the clock advances.
        $next = new StreamContext($context->getStreamZ(), $math);
        $this->assertSame(2, $next->evolveRegime('strike', 0.0, 0.0));

        // Active with a certain exit: the regime ends and the clock resets.
        $ended = new StreamContext($next->getStreamZ(), $math);
        $this->assertSame(0, $ended->evolveRegime('strike', 0.0, 1.0));
        $this->assertSame(0.0, $ended->getStreamZ()[StreamContext::REGIME_STATE_PREFIX . 'strike']);
    }

    public function testStartRegimeForcesOnsetAndIsIdempotentWhileActive(): void
    {
        $math = $this->createStub(MathUtility::class);
        $math->method('checkProbability')->willReturn(false);

        $context = new StreamContext([], $math);
        $this->assertSame(0, $context->evolveRegime('price_war', 0.0, 0.25));
        $this->assertSame(1, $context->startRegime('price_war'));

        $next = new StreamContext($context->getStreamZ(), $math);
        $this->assertSame(2, $next->evolveRegime('price_war', 0.0, 0.25));
        $this->assertSame(2, $next->startRegime('price_war'), 'startRegime must not reset an active clock');
    }

    public function testGenerateZLoadsStreamsOnTheSharedFirmInnovation(): void
    {
        // One-factor model: with the firm innovation pinned at +1 and idiosyncratic draws at 0,
        // every loaded stream returns rho * F and exogenous streams stay at zero.
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal'])->getMock();
        $math->method('generateStandardNormal')->willReturnOnConsecutiveCalls(1.0, 0.0, 0.0, 0.0, 0.0);

        $context = new StreamContext([], $math, 0.60);
        $this->assertEqualsWithDelta(0.60, $context->generateZ('sales', 0.0), 1e-9);
        $this->assertEqualsWithDelta(0.60, $context->generateZ('services', 0.0), 1e-9);
        $this->assertEqualsWithDelta(0.0, $context->generateExogenousZ('event', 0.0), 1e-9);
        $this->assertEqualsWithDelta(0.30, $context->generateZ('niche', 0.0, 0.30), 1e-9);
    }

    public function testRecognizeBacklogSeedsAtSteadyStateAndDampsOrderShocks(): void
    {
        $math = new MathUtility();

        // First use seeds the backlog at steady state: flat orders recognize exactly expected revenue.
        $flat = (new StreamContext([], $math))->recognizeBacklog('oem', 1000.0, 1.0, 0.25);
        $this->assertEqualsWithDelta(1000.0, $flat['revenue'], 1e-9);
        $this->assertEqualsWithDelta(3.0, $flat['backlog_quarters'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $flat['book_to_bill'], 1e-9);

        // A +20% order shock reaches revenue only at the 25% burn rate; the rest is booked into the backlog.
        $shocked = new StreamContext([], $math);
        $shock = $shocked->recognizeBacklog('oem', 1000.0, 1.2, 0.25);
        $this->assertEqualsWithDelta(1050.0, $shock['revenue'], 1e-9);
        $this->assertEqualsWithDelta(3.15, $shock['backlog_quarters'], 1e-9);
        $this->assertEqualsWithDelta(1200.0 / 1050.0, $shock['book_to_bill'], 1e-9);

        // The booked orders persist: the next quarter recognizes above run-rate on flat intake.
        $next = (new StreamContext($shocked->getStreamZ(), $math))->recognizeBacklog('oem', 1000.0, 1.0, 0.25);
        $this->assertEqualsWithDelta(1037.5, $next['revenue'], 1e-9);
        $this->assertLessThan(1.0, $next['book_to_bill']);
    }

    public function testRecognizeBacklogIsCappedAndPersistedUnderItsOwnPrefix(): void
    {
        $math = new MathUtility();
        $context = new StreamContext([StreamContext::BACKLOG_STATE_PREFIX . 'oem' => 50.0], $math);
        $book = $context->recognizeBacklog('oem', 1000.0, 1.0, 0.25);

        $this->assertLessThanOrEqual(StreamContext::MAX_BACKLOG_QUARTERS, $book['backlog_quarters']);
        $this->assertArrayHasKey(StreamContext::BACKLOG_STATE_PREFIX . 'oem', $context->getStreamZ());
    }

    public function testTheSectorFactorsPartOfEachStreamsZIsKeptBesideIt(): void
    {
        $mathUtility = new MathUtility();
        $withSector = new StreamContext([], $mathUtility, 0.6, 2.0, 0.3);
        $withSector->generateZ('revenue', 0.25);
        $withSector->generateExogenousZ('catastrophe', 0.0);

        // sqrt(1 - phi^2) * rho_s * S: the sector's contribution to the fresh innovation, at the AR(1) weight.
        $this->assertEqualsWithDelta(sqrt(1.0 - 0.0625) * 0.3 * 2.0, $withSector->getSectorZ()['revenue'], 1e-12);
        $this->assertSame(0.0, $withSector->getSectorZ()['catastrophe'], 'an exogenous draw loads on no sector');

        $noSector = new StreamContext([], $mathUtility, 0.6, null, 0.3);
        $noSector->generateZ('revenue', 0.25);
        $this->assertSame(0.0, $noSector->getSectorZ()['revenue']);
    }
}
