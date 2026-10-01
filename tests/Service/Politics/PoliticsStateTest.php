<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\AerieDiet as Diet;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

/**
 * Guards the politics state against drift between its two declarations: the snapshot's constructor, where the fields
 * and their openings are declared, and the engine's mutable copy, which mirrors them and is what Redis keeps.
 */
class PoliticsStateTest extends TestCase
{
    /** PoliticsStateDTO::fromState() carries every field across by name, so both classes must describe the same state. */
    public function testTheWorkingStateDeclaresEveryFieldTheSnapshotDoes(): void
    {
        $snapshot = [];
        foreach ((new \ReflectionClass(PoliticsStateDTO::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $snapshot[$parameter->getName()] = (string) $parameter->getType();
        }
        $working = [];
        foreach ((new \ReflectionClass(PoliticsState::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $working[$property->getName()] = (string) $property->getType();
        }

        $this->assertSame($snapshot, $working);
    }

    public function testAFreshStateIsTheFoundingDiet(): void
    {
        $this->assertEquals(new PoliticsStateDTO(), PoliticsStateDTO::fromState(new PoliticsState()));
        $this->assertSame(Diet::SEED_SEATS, (new PoliticsState())->dietSeats);
    }

    /** What Redis keeps comes back as it was written: nested positions, bloc leaders, the talks log, and the headline. */
    public function testTheStateSurvivesTheRoundTripThroughRedis(): void
    {
        $state = new PoliticsState();
        $state->totalTime = 8.25;
        $state->eventType = ShockEvent::GOVERNMENT_FORMED;
        $state->dietSeats[Diet::CIVIC] = 90.0;
        $state->partyPositions[Diet::CHARTISTS][Diet::AXIS_STATE] = 0.4;
        $state->dietBlocs[Diet::IRON_HARBOR] = Diet::VANGUARD;
        $state->formationLog = [['day' => 12.5, 'formateur' => Diet::CIVIC, 'formed' => true, 'cabinet' => [Diet::CIVIC], 'support' => [Diet::COMMON_LOT]]];
        $state->importTariffRate = 0.05;
        $state->coalitionTakesOfficeAt = 8.3;

        $decoded = json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);

        $this->assertEquals($state, PoliticsState::fromArray($decoded));
        $this->assertEquals(PoliticsStateDTO::fromState($state), PoliticsStateDTO::fromArray($decoded));
    }

    /** A payload that omits a field, or carries one it cannot read, leaves that field at its opening. */
    public function testWhatAPayloadLacksKeepsItsOpening(): void
    {
        $state = PoliticsState::fromArray(['totalTime' => 2.0, 'importTariffRate' => 'not a rate', 'dietSeats' => 'not seats', 'eventType' => null]);

        $this->assertSame(2.0, $state->totalTime);
        $this->assertSame(0.0, $state->importTariffRate);
        $this->assertSame(Diet::SEED_SEATS, $state->dietSeats);
        $this->assertNull($state->eventType);
        $this->assertSame(Diet::SEED_COALITION, $state->governingCoalition);
    }
}
