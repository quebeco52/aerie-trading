<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\DTO\PoliticsStateDTO;

/**
 * The politics engine's mutable working copy of its state, advanced in place every tick.
 *
 * Its fields and their openings are declared once, on App\DTO\PoliticsStateDTO's constructor: this class mirrors the
 * names (App\Tests\Service\Politics\PoliticsStateTest fails on a mismatch) and takes its opening values from there.
 */
class PoliticsState
{
    public float $totalTime;
    public ?string $eventType;
    public float $lastElectionAt;

    // The Diet, each map keyed by party: seats, vote shares and their change at the last vote, each party's position by
    // axis, the cabinet, the parties supporting it from outside, and the leader of the bloc each party campaigned in.
    /** @var array<string, float> */
    public array $dietSeats;
    /** @var array<string, float> */
    public array $dietVoteShares;
    /** @var array<string, float> */
    public array $dietVoteSwings;
    /** @var array<string, array<string, float>> */
    public array $partyPositions;
    /** @var array<string, float> */
    public array $governingCoalition;
    /** @var array<string, float> */
    public array $supportParties;
    /** @var array<string, string> */
    public array $dietBlocs;
    // Each party's short-term swing at the last vote, in log share: candidates and campaigns that do not outlast the vote.
    /** @var array<string, float> */
    public array $partyShortTermShocks;
    public float $coalitionFormedAt;
    // The talks after a vote or a fall (CoalitionFormation): the cabinet and supporters they produced, the day they take
    // office (-1: no talks pending, the government sits), when a cabinet last took office, and the talks attempt by attempt.
    /** @var array<string, float> */
    public array $pendingCoalition;
    /** @var array<string, float> */
    public array $pendingSupport;
    public float $coalitionTakesOfficeAt;
    public float $lastGovernmentFormedAt;
    /** @var list<array{day: float, formateur: string, formed: bool, cabinet: list<string>, support: list<string>}> */
    public array $formationLog;
    // When the last talks began (-1: none yet), when the sitting cabinet will fall (-1: it sees out the term or talks are
    // pending), and when a cabinet last fell.
    public float $talksStartedAt;
    public float $cabinetFallsAt;
    public float $lastCabinetFellAt;
    // The cabinet that went into the last vote, the talks' status quo.
    /** @var array<string, float> */
    public array $electionOutgoingCabinet;
    // What the vote reads: the deflator where the term began and real GDP where the campaign began (-1: not yet marked).
    public float $termStartedAt;
    public float $termStartDeflator;
    public float $campaignStartedAt;
    public float $campaignStartRealGdp;
    // The last vote's economy against trend and target, and the governing parties' change in share.
    public float $electionGrowthGap;
    public float $electionInflationGap;
    public float $electionIncumbentSwing;

    // The levers the last budget set, which the economy reads (App\DTO\GovernmentPolicyDTO), and when a budget last
    // changed one and when the Council's debt brake last held one back (-1: never).
    public float $corporateTaxPolicyShift;
    public float $importTariffRate;
    public float $laborForceGrowthRate;
    public float $mergerReviewLeniency;
    public float $greenBeltStringency;
    public float $carbonPrice;
    public float $extractionStringency;
    public float $stampDutyRate;
    public float $bankLevyRate;
    public float $lastBudgetEnactedAt;
    public float $lastCouncilBrakeAt;

    // The Council and its departments (CouncilAppointments, MonetaryAuthority, FinancialRegulator): the salt their draws
    // are hashed from; each councillor's, the governor's and each committee member's name, birth, seat date and stance on
    // money, and each councillor's on the banks; the candidates passed over at the last Council vacancy and for the
    // governorship; the committee's balance and supermajority; the last rate meeting; when a governor or councillor was
    // last seated and the majority last shifted; the Financial Regulator's head and the requirement in force; and the
    // Sovereign Reserve Fund's head.
    public float $authoritySalt;
    /** @var list<string> */
    public array $councilNames;
    /** @var list<float> */
    public array $councilBirths;
    /** @var list<float> */
    public array $councilSince;
    /** @var list<float> */
    public array $councilStances;
    /** @var array<int, float> */
    public array $councilRegulationStances;
    /** @var array<int, float> */
    public array $councilFundStances;
    /** @var list<array{name: string, birth: float, stance: float, regulation: float, fund: float}> */
    public array $councillorPassedOver;
    public string $governorName;
    public float $governorBirth;
    public float $governorTermStart;
    public float $governorStance;
    /** @var list<array{name: string, birth: float, stance: float, regulation: float, fund: float}> */
    public array $governorPassedOver;
    /** @var list<string> */
    public array $memberNames;
    /** @var list<float> */
    public array $memberBirths;
    /** @var list<float> */
    public array $memberSince;
    /** @var list<float> */
    public array $memberStances;
    public float $committeeBalance;
    public float $committeeMajority;
    public float $lastMeetingAt;
    public float $lastMeetingRate;
    public float $lastMeetingChange;
    /** @var list<float> */
    public array $lastMeetingVotes;
    public float $lastGovernorAppointedAt;
    public float $lastCouncillorSeatedAt;
    public float $lastMajorityShiftAt;
    public string $regulatorName;
    public float $regulatorBirth;
    public float $regulatorTermStart;
    public float $regulatorStance;
    /** @var list<array{name: string, birth: float, stance: float, regulation: float, fund: float}> */
    public array $regulatorPassedOver;
    public float $bankCapitalRequirement;
    public float $requirementPhaseFrom;
    public float $requirementPhaseStart;
    public float $lastRegulatorAppointedAt;
    public string $fundHeadName;
    public float $fundHeadBirth;
    public float $fundHeadTermStart;
    public float $fundHeadStance;
    /** @var list<array{name: string, birth: float, stance: float, regulation: float, fund: float}> */
    public array $fundHeadPassedOver;
    public float $lastFundHeadAppointedAt;

    /** @var array<string, mixed>|null */
    private static ?array $openings = null;

    public function __construct()
    {
        foreach (self::openings() as $field => $opening) {
            $this->$field = $opening;
        }
    }

    /**
     * Every field's opening value, as PoliticsStateDTO's constructor declares it, in its order.
     *
     * @return array<string, mixed>
     */
    public static function openings(): array
    {
        if (self::$openings !== null) {
            return self::$openings;
        }

        $openings = [];
        foreach ((new \ReflectionClass(PoliticsStateDTO::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $openings[$parameter->getName()] = $parameter->getDefaultValue();
        }

        return self::$openings = $openings;
    }

    /**
     * The state off its persisted payload; a field the payload omits keeps its opening.
     *
     * @param array<string, mixed> $data Decoded payload keyed by field name.
     */
    public static function fromArray(array $data): self
    {
        $state = new self();
        foreach (self::openings() as $field => $opening) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            $state->$field = match (true) {
                $field === 'eventType' => is_scalar($value) ? (string) $value : null,
                is_array($opening) && !is_array($value) => $opening,
                $field === 'partyPositions' => self::hydratePositions($value),
                $field === 'dietBlocs' => array_map('strval', $value),
                $field === 'formationLog' => array_values($value),
                $field === 'councilNames' || $field === 'memberNames' => array_values(array_map('strval', $value)),
                in_array($field, ['councillorPassedOver', 'governorPassedOver', 'regulatorPassedOver', 'fundHeadPassedOver'], true) => self::hydrateCandidates($value),
                is_string($opening) => is_scalar($value) ? (string) $value : $opening,
                is_array($opening) => array_map('floatval', $value),
                default => is_numeric($value) ? (float) $value : $opening,
            };
        }

        return $state;
    }

    /**
     * The payload persisted to Redis.
     *
     * @return array<string, mixed> Field name => value.
     */
    public function toArray(): array
    {
        $payload = [];
        foreach (array_keys(self::openings()) as $field) {
            $payload[$field] = $this->$field;
        }

        return $payload;
    }

    /**
     * Candidates off the wire: each one's name, birth date, stance on money and, once candidates held them, on the banks
     * and on the reserves.
     *
     * @param array<mixed> $candidates Candidates as decoded.
     * @return list<array{name: string, birth: float, stance: float, regulation: float, fund: float}>
     */
    private static function hydrateCandidates(array $candidates): array
    {
        $hydrated = [];
        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $hydrated[] = [
                    'name' => is_scalar($candidate['name'] ?? null) ? (string) $candidate['name'] : '',
                    'birth' => is_numeric($candidate['birth'] ?? null) ? (float) $candidate['birth'] : 0.0,
                    'stance' => is_numeric($candidate['stance'] ?? null) ? (float) $candidate['stance'] : 0.0,
                    'regulation' => is_numeric($candidate['regulation'] ?? null) ? (float) $candidate['regulation'] : 0.0,
                    'fund' => is_numeric($candidate['fund'] ?? null) ? (float) $candidate['fund'] : 0.0,
                ];
            }
        }

        return $hydrated;
    }

    /**
     * Party positions off the wire: each party's coordinates by axis, as floats.
     *
     * @param array<mixed> $positions Positions by party and axis, as decoded.
     * @return array<string, array<string, float>>
     */
    private static function hydratePositions(array $positions): array
    {
        $hydrated = [];
        foreach ($positions as $party => $point) {
            if (is_array($point)) {
                $hydrated[(string) $party] = array_map('floatval', $point);
            }
        }

        return $hydrated;
    }
}
