<?php

declare(strict_types=1);

namespace App\DTO;

use App\Data\AerieDiet;
use App\Service\Macro\MacroEngine;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;

/**
 * Immutable snapshot of the District's politics: the Diet, the government, the talks, and the levers in force.
 *
 * Its fields and their openings are declared once, on this constructor: App\Service\Politics\PoliticsState mirrors the
 * names and takes its opening values from here, so a fresh engine and every reader start from the founding Diet.
 */
readonly class PoliticsStateDTO
{
    public function __construct(
        /** Simulation time the politics were last advanced to, the macro clock's. */
        public float $totalTime = 0.0,
        /** The tick's political headline (App\Service\Event\ShockEvent), cleared on the next tick. */
        public ?string $eventType = null,
        public float $lastElectionAt = -1.0,
        /** @var array<string, float> Diet seats by party (AerieDiet::PARTIES). */
        public array $dietSeats = AerieDiet::SEED_SEATS,
        /** @var array<string, float> Vote share by party at the last election. */
        public array $dietVoteShares = AerieDiet::SEED_VOTE_SHARES,
        /** @var array<string, float> Change in each party's vote share at the last election. */
        public array $dietVoteSwings = [],
        /** @var array<string, array<string, float>> Each party's position by axis; the axis it is defined by never moves. */
        public array $partyPositions = AerieDiet::HOME_POSITIONS,
        /** @var array<string, float> 1.0 for a party in the cabinet. */
        public array $governingCoalition = AerieDiet::SEED_COALITION,
        /** @var array<string, float> 1.0 for a party supporting a minority cabinet from outside. */
        public array $supportParties = AerieDiet::SEED_SUPPORT,
        /** @var array<string, string> The leader of the bloc each party declared for before the last vote. */
        public array $dietBlocs = AerieDiet::SEED_BLOCS,
        /** @var array<string, float> Each party's short-term log swing at the last vote, given back at the next. */
        public array $partyShortTermShocks = [],
        public float $coalitionFormedAt = 0.0,
        /** @var array<string, float> 1.0 for a party in the cabinet the talks produced, until it takes office. */
        public array $pendingCoalition = [],
        /** @var array<string, float> 1.0 for a party that will support that cabinet. */
        public array $pendingSupport = [],
        public float $coalitionTakesOfficeAt = -1.0,
        public float $lastGovernmentFormedAt = -1.0,
        /** @var list<array{day: float, formateur: string, formed: bool, cabinet: list<string>, support: list<string>}> The last talks, attempt by attempt. */
        public array $formationLog = [],
        public float $talksStartedAt = -1.0,
        public float $cabinetFallsAt = -1.0,
        public float $lastCabinetFellAt = -1.0,
        /** @var array<string, float> 1.0 for a party in the cabinet that went into the last vote. */
        public array $electionOutgoingCabinet = [],
        public float $termStartedAt = -1.0,
        public float $termStartDeflator = 0.0,
        public float $campaignStartedAt = -1.0,
        public float $campaignStartRealGdp = 0.0,
        public float $electionGrowthGap = 0.0,
        public float $electionInflationGap = 0.0,
        public float $electionIncumbentSwing = 0.0,
        /** The corporate rate's shift from the neutral rate, as the last budget set it. */
        public float $corporateTaxPolicyShift = 0.0,
        public float $importTariffRate = 0.0,
        public float $laborForceGrowthRate = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE,
        /** Where merger review stands between the 2023 guidelines (0) and the 2010 guidelines (1). */
        public float $mergerReviewLeniency = 0.0,
        public float $lastBudgetEnactedAt = -1.0,
        public float $lastCouncilBrakeAt = -1.0,
    ) {}

    /** Snapshots the engine's working state. */
    public static function fromState(PoliticsState $state): self
    {
        $arguments = [];
        foreach (array_keys(PoliticsState::openings()) as $field) {
            $arguments[$field] = $state->$field;
        }

        return new self(...$arguments);
    }

    /**
     * A snapshot off the persisted payload; a field the payload omits keeps its opening.
     *
     * @param array<string, mixed> $data Decoded payload keyed by field name.
     */
    public static function fromArray(array $data): self
    {
        return self::fromState(PoliticsState::fromArray($data));
    }

    /**
     * What the government hands the economy: the levers in force and the election calendar's pull on policy uncertainty.
     */
    public function policy(): GovernmentPolicyDTO
    {
        return new GovernmentPolicyDTO(
            corporateTaxPolicyShift: $this->corporateTaxPolicyShift,
            importTariffRate: $this->importTariffRate,
            laborForceGrowthRate: $this->laborForceGrowthRate,
            mergerReviewLeniency: $this->mergerReviewLeniency,
            electionPulse: PoliticsEngine::electionPulse($this->totalTime, $this->lastElectionAt, $this->coalitionTakesOfficeAt),
        );
    }
}
