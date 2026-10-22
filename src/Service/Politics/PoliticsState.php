<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\DTO\PoliticsStateDTO;
use App\Service\Politics\Department\Office;

/**
 * The politics engine's mutable working copy of its state, advanced in place every tick. Its fields and openings are
 * declared on App\DTO\PoliticsStateDTO's constructor: this class mirrors the names (PoliticsStateTest fails on a
 * mismatch) and takes its openings from there. Times and birth dates are on the simulation clock, in years.
 *
 * @phpstan-import-type Head from Office
 */
class PoliticsState
{
    public float $totalTime;
    public ?string $eventType;
    public float $lastElectionAt;

    // The Diet, each map keyed by party.
    /** @var array<string, float> */
    public array $dietSeats;
    /** @var array<string, float> Vote shares at the last vote. */
    public array $dietVoteShares;
    /** @var array<string, float> Change in vote share at the last vote. */
    public array $dietVoteSwings;
    /** @var array<string, array<string, float>> Position by axis. */
    public array $partyPositions;
    /** @var array<string, float> 1.0 for a party in the cabinet. */
    public array $governingCoalition;
    /** @var array<string, float> 1.0 for a party supporting the cabinet from outside. */
    public array $supportParties;
    /** @var array<string, string> Leader of the bloc the party campaigned in. */
    public array $dietBlocs;
    /** @var array<string, float> Short-term swing at the last vote, in log share; it does not outlast the vote. */
    public array $partyShortTermShocks;
    public float $coalitionFormedAt;
    // The talks after a vote or a fall (CoalitionFormation): the day their cabinet takes office (-1: none pending, the
    // government sits), when a cabinet last took office, and the talks attempt by attempt.
    /** @var array<string, float> 1.0 for a party in the cabinet the talks produced. */
    public array $pendingCoalition;
    /** @var array<string, float> 1.0 for a party that will support it. */
    public array $pendingSupport;
    public float $coalitionTakesOfficeAt;
    public float $lastGovernmentFormedAt;
    /** @var list<array{day: float, formateur: string, formed: bool, cabinet: list<string>, support: list<string>}> */
    public array $formationLog;
    // When the last talks began (-1: none yet), when the sitting cabinet will fall (-1: it sees out the term or talks
    // are pending), and when a cabinet last fell.
    public float $talksStartedAt;
    public float $cabinetFallsAt;
    public float $lastCabinetFellAt;
    /** @var array<string, float> The cabinet that went into the last vote, the talks' status quo. */
    public array $electionOutgoingCabinet;
    // What the vote reads: the term's start and the deflator then, the campaign's start and real GDP then (-1: unset).
    public float $termStartedAt;
    public float $termStartDeflator;
    public float $campaignStartedAt;
    public float $campaignStartRealGdp;
    // The last vote's growth against trend, inflation against target, and the governing parties' change in share.
    public float $electionGrowthGap;
    public float $electionInflationGap;
    public float $electionIncumbentSwing;

    // The levers the last budget set, which the economy reads (App\DTO\GovernmentPolicyDTO): rates and shares as
    // fractions, the carbon price in dollars a tonne, stringencies 0 (founding) to 1 (strictest), the debt target a
    // share of GDP and its pace in points a year; and when a budget last changed one (-1: never).
    public float $publicSpendingShare;
    public float $corporateTaxPolicyShift;
    public float $importTariffRate;
    public float $laborForceGrowthRate;
    public float $foreignBuyerTaxRate;
    public float $greenBeltStringency;
    public float $carbonPrice;
    public float $extractionStringency;
    public float $stampDutyRate;
    public float $bankLevyRate;
    public float $reserveDrawShare;
    public float $consolidationTaxShare;
    public float $reserveHomeEquityShare;
    public float $utilityRateCaseGrantShare;
    public float $dividendTaxRate;
    public float $debtTarget;
    public float $debtPace;
    public float $lastBudgetEnactedAt;

    // The Council and its departments (CouncilAppointments, the Department classes): the salt the Council's draws are
    // hashed from (-1: not yet formed), then each office's holders and the candidates passed over; stances run -1 to 1.
    public float $authoritySalt;
    /** @var array<int, string> Each Council seat's holder. */
    public array $councilNames;
    /** @var array<int, float> Each holder's birth date. */
    public array $councilBirths;
    /** @var array<int, float> When each seat's term in progress began. */
    public array $councilSince;
    /** @var array<int, float> When each holder took the seat. */
    public array $councilSeatedAt;
    /** @var array<int, float> When each holder leaves before the term ends (-1: serves it out). */
    public array $councilLeavesAt;
    public float $councilTermYears;
    /** @var array<int, float> Stance on money: 1 a hawk, -1 a dove. */
    public array $councilStances;
    /** @var array<int, float> 1 for a swing vote, 0 otherwise. */
    public array $councilSwingers;
    /** @var array<int, float> Stance on the banks, lightest regime to strictest. */
    public array $councilRegulationStances;
    /** @var array<int, float> Stance on the reserves, most cautious to boldest. */
    public array $councilFundStances;
    /** @var array<int, float> Stance on the debt, shortest maturity to longest. */
    public array $councilDebtStances;
    /** @var array<int, float> Stance on competition, lightest merger rules to strictest. */
    public array $councilCompetitionStances;
    /** @var array<int, float> Stance on the markets, lightest rules to strictest. */
    public array $councilMarketsStances;
    /**
     * @var list<array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float}>
     */
    public array $councillorPassedOver;
    /**
     * @var array<int, array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float}>
     */
    public array $boardMembers;
    /** @var array<int, float> When each board member's term began. */
    public array $boardSince;
    /** @var array<string, Head> Each department post's holder by Office value; a post not yet filled is absent. */
    public array $heads;
    public float $governorSwinger;
    /** @var array<int, string> The rate committee's members beside the governor, by seat. */
    public array $memberNames;
    /** @var array<int, float> Each member's birth date. */
    public array $memberBirths;
    /** @var array<int, float> When each member took their seat. */
    public array $memberSince;
    /** @var array<int, float> Each member's stance on money: 1 a hawk, -1 a dove. */
    public array $memberStances;
    /** @var array<int, float> 1 for a swing vote, 0 otherwise. */
    public array $memberSwingers;
    // The committee's balance and supermajority (1 hawkish, -1 dovish), and the last rate meeting (-1: none yet).
    public float $committeeBalance;
    public float $committeeMajority;
    public float $lastMeetingAt;
    public float $lastMeetingRate;
    public float $lastMeetingChange;
    /** @var list<float> Each vote, governor first: 1 for a higher rate, -1 for a lower, 0 with the decision. */
    public array $lastMeetingVotes;
    public float $lastCouncillorSeatedAt;
    public float $lastCouncilVacancyAt;
    public string $lastVacancyName;
    public string $lastVacancyCause;
    public float $lastMajorityShiftAt;
    // The CET1 requirement in force and the level its last rise phases in from (shares of risk-weighted assets) and
    // when; the Regulator's head's mortgage loan-to-value cap (null: none) and neutral countercyclical buffer.
    public float $bankCapitalRequirement;
    public float $requirementPhaseFrom;
    public float $requirementPhaseStart;
    public ?float $regulatorLtvCap;
    public float $regulatorNeutralBuffer;
    // The stance of the Fair Trading Board's and the Securities Commission's heads before the sitting ones.
    public float $fairTradingPriorStance;
    public ?float $securitiesPriorStance;
    /** @var list<string> Everyone who has left a Council seat or a post, whose names no later appointee takes. */
    public array $formerNames;
    // The parties' leaders, each map keyed by party.
    /** @var array<string, string> The leader; the prime minister leads the cabinet's largest party. */
    public array $leaderNames;
    /** @var array<string, float> The leader's birth date. */
    public array $leaderBirths;
    /** @var array<string, float> When the leader took the lead. */
    public array $leaderSince;
    /** @var array<string, float> The yearly hazard the leader has run through since. */
    public array $leaderHazardUsed;
    /** @var array<string, float> When a leader the last vote doomed steps down (-1: none). */
    public array $leaderExitAt;
    /** @var array<string, list<array{name: string, birth: float, since: float, until: float}>> Oldest first. */
    public array $leaderHistory;
    public float $leadersReviewedElection;
    public float $lastLeaderChangeAt;
    public string $lastLeaderChangeParty;
    // Support between votes (OpinionPolls), each map keyed by party.
    /** @var array<string, float> Lasting share, the short-term swing set aside (empty: read off the last vote). */
    public array $supportLasting;
    /** @var array<string, float> Normal vote, drifting over the decades. */
    public array $normalVoteShares;
    /** @var array<string, float> Log swing this term for or against the governments the party served in. */
    public array $supportIncumbency;
    /** @var array<string, float> Years of lasting drift so far this term; empty at a vote. */
    public array $supportDriftYears;
    /** @var array<string, float> Unsettled opinion the polls misread, in standard deviations. */
    public array $pollUnsettled;
    // When support was last brought up to date (-1: never), and the growth and inflation counted by then this term.
    public float $supportUpdatedAt;
    public float $supportGrowthCounted;
    public float $supportInflationCounted;
    /** @var list<array{t: float, shares: array<string, float>}> Polls since the last vote, oldest first. */
    public array $polls;
    /** @var array<string, float> The market's average of the polls (empty: none yet). */
    public array $pollAverage;
    /** @var array<string, float> Its variance, in shares squared. */
    public array $pollAverageVariance;
    public float $pollAveragedAt;
    // The market's forecast of the next government (ElectionForecast).
    /** @var list<array{cabinet: list<string>, support: list<string>, chance: float}> Likeliest first. */
    public array $forecastCabinets;
    /** @var array<string, float> Each party's chance of leading the next government. */
    public array $forecastLeaders;
    /** @var array<string, float> Seats each party is expected to win. */
    public array $forecastSeats;
    /** @var array<string, float> Levers expected after the vote, each government's budget weighted by its chance. */
    public array $forecastLevers;
    /** @var array<string, float> Levers the sitting government will pass at its next budget round. */
    public array $sittingLevers;
    public float $forecastFor;
    public float $forecastAt;
    public float $forecastRequestedAt;

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
     * The office's holder, or a vacant post (no name) before the first is seated.
     *
     * @return Head
     */
    public function head(Office $office): array
    {
        return $this->heads[$office->value] ?? Office::VACANT;
    }

    /**
     * The state off its persisted payload; a field the payload omits keeps its opening, and a payload saved before
     * $heads has its posts read from their own fields.
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
                $field === 'councilNames' || $field === 'memberNames' || $field === 'formerNames' => array_values(array_map('strval', $value)),
                $field === 'leaderNames' => array_map('strval', $value),
                $field === 'leaderHistory' => self::hydrateLeaderHistory($value),
                $field === 'polls' => self::hydratePolls($value),
                $field === 'forecastCabinets' => self::hydrateCabinets($value),
                $field === 'councillorPassedOver' || $field === 'boardMembers' => self::hydrateCandidates($value),
                $field === 'heads' => self::hydrateHeads($value),
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
     * Candidates off the wire: name, birth date and stances, 0 for a stance the payload lacks; a swing only if held.
     *
     * @param array<mixed> $candidates Candidates as decoded.
     * @return list<array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float}>
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
                    'debt' => is_numeric($candidate['debt'] ?? null) ? (float) $candidate['debt'] : 0.0,
                    'competition' => is_numeric($candidate['competition'] ?? null) ? (float) $candidate['competition'] : 0.0,
                    'markets' => is_numeric($candidate['markets'] ?? null) ? (float) $candidate['markets'] : 0.0,
                ] + (is_numeric($candidate['swing'] ?? null) ? ['swing' => (float) $candidate['swing']] : []);
            }
        }

        return $hydrated;
    }

    /**
     * Posts' holders off the wire, by Office value in the offices' order; an unknown office is dropped.
     *
     * @param array<mixed> $heads Holders as decoded.
     * @return array<string, Head>
     */
    private static function hydrateHeads(array $heads): array
    {
        $hydrated = [];
        foreach (Office::cases() as $office) {
            if (is_array($heads[$office->value] ?? null)) {
                $hydrated[$office->value] = self::hydrateHead($heads[$office->value]);
            }
        }

        return $hydrated;
    }

    /**
     * A holder off the wire; a field the payload lacks or garbles keeps its vacant value (Office::VACANT).
     *
     * @param array<mixed> $head Holder as decoded.
     * @return Head
     */
    private static function hydrateHead(array $head): array
    {
        $number = static fn(mixed $value, float $vacant): float => is_numeric($value) ? (float) $value : $vacant;

        return [
            'name' => is_scalar($head['name'] ?? null) ? (string) $head['name'] : Office::VACANT['name'],
            'birth' => $number($head['birth'] ?? null, Office::VACANT['birth']),
            'termStart' => $number($head['termStart'] ?? null, Office::VACANT['termStart']),
            'stance' => $number($head['stance'] ?? null, Office::VACANT['stance']),
            'passedOver' => is_array($head['passedOver'] ?? null) ? self::hydrateCandidates($head['passedOver']) : Office::VACANT['passedOver'],
            'appointedAt' => $number($head['appointedAt'] ?? null, Office::VACANT['appointedAt']),
        ];
    }

    /**
     * Former party leaders off the wire: by party, each one's name, birth, and when they led from and until.
     *
     * @param array<mixed> $history Histories by party, as decoded.
     * @return array<string, list<array{name: string, birth: float, since: float, until: float}>>
     */
    private static function hydrateLeaderHistory(array $history): array
    {
        $hydrated = [];
        foreach ($history as $party => $leaders) {
            if (!is_array($leaders)) {
                continue;
            }
            $hydrated[(string) $party] = [];
            foreach ($leaders as $leader) {
                if (is_array($leader)) {
                    $hydrated[(string) $party][] = [
                        'name' => is_scalar($leader['name'] ?? null) ? (string) $leader['name'] : '',
                        'birth' => is_numeric($leader['birth'] ?? null) ? (float) $leader['birth'] : 0.0,
                        'since' => is_numeric($leader['since'] ?? null) ? (float) $leader['since'] : 0.0,
                        'until' => is_numeric($leader['until'] ?? null) ? (float) $leader['until'] : 0.0,
                    ];
                }
            }
        }

        return $hydrated;
    }

    /**
     * Published polls off the wire: each one's date and shares by party.
     *
     * @param array<mixed> $polls Polls as decoded.
     * @return list<array{t: float, shares: array<string, float>}>
     */
    private static function hydratePolls(array $polls): array
    {
        $hydrated = [];
        foreach ($polls as $poll) {
            if (is_array($poll) && is_numeric($poll['t'] ?? null) && is_array($poll['shares'] ?? null)) {
                $hydrated[] = ['t' => (float) $poll['t'], 'shares' => array_map('floatval', $poll['shares'])];
            }
        }

        return $hydrated;
    }

    /**
     * Forecast governments off the wire: each one's cabinet, support parties and chance.
     *
     * @param array<mixed> $cabinets Governments as decoded.
     * @return list<array{cabinet: list<string>, support: list<string>, chance: float}>
     */
    private static function hydrateCabinets(array $cabinets): array
    {
        $hydrated = [];
        foreach ($cabinets as $option) {
            if (is_array($option) && is_array($option['cabinet'] ?? null) && is_numeric($option['chance'] ?? null)) {
                $hydrated[] = [
                    'cabinet' => array_values(array_map('strval', $option['cabinet'])),
                    'support' => is_array($option['support'] ?? null) ? array_values(array_map('strval', $option['support'])) : [],
                    'chance' => (float) $option['chance'],
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
