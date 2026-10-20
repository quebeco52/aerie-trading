<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\Politics\AerieDiet as Diet;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Politics\Department\Office;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

/**
 * Guards the politics state against drift between its two declarations: the snapshot's constructor, where the fields
 * and their openings are declared, and the engine's mutable copy, which mirrors them and is what Redis keeps.
 */
class PoliticsStateTest extends TestCase
{
    /**
     * PoliticsStateDTO::fromState() carries every field across by name, so both classes must describe the same state.
     */
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

    /**
     * What Redis keeps comes back as it was written: nested positions, bloc leaders, the talks log, the headline, and
     * the Council's, the Authority's and the Regulator's people, candidates and former holders.
     */
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
        $state->authoritySalt = 12345.0;
        $state->councilNames = ['Adelaide Voss', 'Clio Wren'];
        $state->councilStances = [1.0, -1.0];
        $state->councilRegulationStances = [-0.5, 0.75];
        $state->councilFundStances = [0.3, -0.7];
        $state->councilDebtStances = [-0.25, 0.6];
        $state->councilCompetitionStances = [0.2, -0.9];
        $state->councilMarketsStances = [0.5, -0.1];
        $state->heads = [
            Office::Governor->value => ['name' => 'Ilse Marchand', 'passedOver' => [['name' => 'Corvin Ashby', 'birth' => -48.5, 'stance' => 0.0, 'regulation' => 0.25, 'fund' => 0.5, 'debt' => 0.3, 'competition' => 0.0, 'markets' => 0.0], ['name' => 'Odile Fenwright', 'birth' => -51.0, 'stance' => -1.0, 'regulation' => -1.0, 'fund' => -0.2, 'debt' => 0.3, 'competition' => 0.0, 'markets' => 0.0]]] + Office::VACANT,
            Office::Regulator->value => ['name' => 'Leontine Ashby', 'stance' => -0.6, 'passedOver' => [['name' => 'Bram Ellery', 'birth' => 2.0 - 55.0, 'stance' => 1.0, 'regulation' => 0.4, 'fund' => 0.1, 'debt' => 0.3, 'competition' => 0.0, 'markets' => 0.0]], 'appointedAt' => 7.0] + Office::VACANT,
            Office::FundHead->value => ['name' => 'Matthias Okonkwo-Hale', 'stance' => 0.5, 'passedOver' => [['name' => 'Ines Varga', 'birth' => -50.0, 'stance' => 0.0, 'regulation' => 0.0, 'fund' => -0.4, 'debt' => 0.3, 'competition' => 0.0, 'markets' => 0.0]]] + Office::VACANT,
            Office::ExchequerHead->value => ['name' => 'Rosalind Tate', 'stance' => -0.4, 'passedOver' => [['name' => 'Hal Brennan', 'birth' => -47.0, 'stance' => 0.0, 'regulation' => 0.1, 'fund' => 0.2, 'debt' => 0.9, 'competition' => 0.0, 'markets' => 0.0]]] + Office::VACANT,
            Office::FairTradingHead->value => ['name' => 'Odile Farrant', 'stance' => 0.7, 'passedOver' => [['name' => 'Wendell Price', 'birth' => -44.0, 'stance' => 0.0, 'regulation' => 0.3, 'fund' => -0.1, 'debt' => 0.2, 'competition' => -0.6, 'markets' => 0.0]]] + Office::VACANT,
            Office::SecuritiesHead->value => ['name' => 'Thea Lindqvist', 'stance' => -0.3, 'passedOver' => [['name' => 'Arlo Mercer', 'birth' => -46.0, 'stance' => 0.0, 'regulation' => 0.0, 'fund' => 0.0, 'debt' => 0.0, 'competition' => 0.0, 'markets' => 0.8]]] + Office::VACANT,
            Office::SecurityDirector->value => ['name' => 'Augustin Pell', 'birth' => -52.0, 'passedOver' => [['name' => 'Nell Rourke', 'birth' => -49.0, 'stance' => 0.5, 'regulation' => -0.2, 'fund' => 0.1, 'debt' => 0.0, 'competition' => 0.3, 'markets' => -0.4]]] + Office::VACANT,
            Office::CoastGuardCommandant->value => ['name' => 'Imogen Hale', 'termStart' => 2.5, 'passedOver' => [['name' => 'Dorian Shaw', 'birth' => -47.0, 'stance' => -0.2, 'regulation' => 0.1, 'fund' => 0.0, 'debt' => 0.4, 'competition' => 0.0, 'markets' => 0.2]]] + Office::VACANT,
            Office::StateGuardCommander->value => ['name' => 'Gideon Rusk', 'birth' => -51.0, 'passedOver' => [['name' => 'Petra Lowell', 'birth' => -45.0, 'stance' => 0.3, 'regulation' => 0.0, 'fund' => -0.3, 'debt' => 0.0, 'competition' => 0.1, 'markets' => 0.0]]] + Office::VACANT,
        ];
        $state->securitiesPriorStance = 0.6;
        $state->fairTradingPriorStance = -0.375;
        $state->formerNames = ['Corvin Ashby', 'Bram Ellery'];
        $state->bankCapitalRequirement = 0.1012;
        $state->requirementPhaseFrom = 0.094;
        $state->requirementPhaseStart = 7.0;
        $state->regulatorLtvCap = 0.85;

        $decoded = json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);

        $this->assertEquals($state, PoliticsState::fromArray($decoded));
        $this->assertEquals(PoliticsStateDTO::fromState($state), PoliticsStateDTO::fromArray($decoded));

        $legacy = $decoded;
        $legacy['heads'][Office::Governor->value]['passedOver'] = [['name' => 'Corvin Ashby', 'birth' => -48.5, 'stance' => 0.0]];
        $this->assertSame(0.0, PoliticsState::fromArray($legacy)->head(Office::Governor)['passedOver'][0]['regulation'], 'A candidate saved before the banks question reads as neutral on it.');
        $this->assertSame(0.0, PoliticsState::fromArray($legacy)->head(Office::Governor)['passedOver'][0]['fund'], 'And on the reserves.');
    }

    /**
     * A state saved before the posts were kept by office, each in its own fields, reads into the same holders, and is
     * written back in the new shape alone; a post the payload never had stays vacant.
     */
    public function testAStateSavedWithAFieldPerHeadReadsIntoTheSameHeads(): void
    {
        $candidate = static fn(string $name, float $birth): array => ['name' => $name, 'birth' => $birth, 'stance' => -1.0, 'regulation' => 0.25, 'fund' => 0.5, 'debt' => -0.5, 'competition' => 0.75, 'markets' => -0.25];
        $legacy = ['totalTime' => 41.0, 'governorSwinger' => 1.0, 'fairTradingPriorStance' => 0.125, 'securitiesPriorStance' => -0.5];
        $expected = [];
        $fields = [
            Office::Governor->value => ['governor', 'Governor'],
            Office::Regulator->value => ['regulator', 'Regulator'],
            Office::FundHead->value => ['fundHead', 'FundHead'],
            Office::ExchequerHead->value => ['exchequerHead', 'ExchequerHead'],
            Office::FairTradingHead->value => ['fairTradingHead', 'FairTradingHead'],
            Office::SecuritiesHead->value => ['securitiesHead', 'SecuritiesHead'],
            Office::SecurityDirector->value => ['securityDirector', 'SecurityDirector'],
            Office::CoastGuardCommandant->value => ['coastGuardCommandant', 'CoastGuardCommandant'],
            Office::StateGuardCommander->value => ['stateGuardCommander', 'StateGuardCommander'],
        ];
        $i = 0;
        foreach ($fields as $office => [$prefix, $capitalised]) {
            ++$i;
            $head = ['name' => "Holder {$i}", 'birth' => -40.0 - $i, 'termStart' => 30.0 + $i / 8, 'stance' => $i < 7 ? $i / 10 - 0.35 : 0.0, 'passedOver' => [$candidate("Rival {$i}", -45.0 - $i)], 'appointedAt' => 30.5 + $i];
            $legacy += ["{$prefix}Name" => $head['name'], "{$prefix}Birth" => $head['birth'], "{$prefix}TermStart" => $head['termStart'], "{$prefix}PassedOver" => $head['passedOver'], "last{$capitalised}AppointedAt" => $head['appointedAt']];
            if ($i < 7) {
                $legacy["{$prefix}Stance"] = $head['stance'];
            }
            $expected[$office] = $head;
        }
        $legacy = json_decode(json_encode($legacy, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);

        $state = PoliticsState::fromArray($legacy);

        $this->assertSame($expected, $state->heads);
        foreach ($expected as $office => $head) {
            $this->assertSame($head, $state->head(Office::from($office)));
            $this->assertSame($head, PoliticsStateDTO::fromArray($legacy)->head(Office::from($office)));
        }
        $this->assertSame([1.0, 0.125, -0.5], [$state->governorSwinger, $state->fairTradingPriorStance, $state->securitiesPriorStance]);
        $saved = $state->toArray();
        $this->assertSame($expected, $saved['heads']);
        $perHead = array_diff(array_keys($legacy), ['totalTime', 'governorSwinger', 'fairTradingPriorStance', 'securitiesPriorStance']);
        $this->assertSame([], array_values(array_intersect($perHead, array_keys($saved))), 'Only the new shape is written.');
        $this->assertSame($expected, PoliticsState::fromArray($saved)->heads);

        unset($legacy['stateGuardCommanderName'], $legacy['stateGuardCommanderBirth'], $legacy['stateGuardCommanderTermStart'], $legacy['stateGuardCommanderPassedOver'], $legacy['lastStateGuardCommanderAppointedAt']);
        $this->assertArrayNotHasKey(Office::StateGuardCommander->value, PoliticsState::fromArray($legacy)->heads);
        $this->assertSame(Office::VACANT, PoliticsState::fromArray($legacy)->head(Office::StateGuardCommander));
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
        $this->assertNull($state->regulatorLtvCap, 'A state saved before the mortgage cap runs none.');
    }
}
