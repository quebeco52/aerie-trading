<?php

declare(strict_types=1);

namespace App\DTO;

use App\Data\AerieDiet;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Politics\ElectionForecast;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use App\Service\Politics\PoliticalPressure;
use App\Service\Politics\SovereignReserveFund;

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
        /** @var array<string, float> Each party's short-term log swing in support, fading between votes (App\Service\Politics\OpinionPolls). */
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
        /** How far the green belt stands between the founding planning regime (0) and the strictest on record (1). */
        public float $greenBeltStringency = 0.0,
        /** The carbon price on power and industry, in dollars a tonne of CO2. */
        public float $carbonPrice = 0.0,
        /** How far the rules on extraction stand between the founding ones (0) and the strictest on record (1). */
        public float $extractionStringency = 0.0,
        /** Stamp duty on each side of a share trade, paid into the reserve fund. */
        public float $stampDutyRate = FinancialConstants::STAMP_DUTY_RATE,
        /** Bank levy on short-term funding, a year (half that on long-term funding). */
        public float $bankLevyRate = 0.0,
        /** Share of the reserve fund's expected long-term real return the budget spends. */
        public float $reserveDrawShare = MacroEngine::RESERVE_DRAW_CEILING,
        public float $lastBudgetEnactedAt = -1.0,
        public float $lastCouncilBrakeAt = -1.0,
        /** Salt the Council's hashed draws are taken from, drawn once when it forms (-1: not yet formed; App\Service\Politics\CouncilAppointments). */
        public float $authoritySalt = -1.0,
        /** @var list<string> Each Council seat's holder, by seat. */
        public array $councilNames = [],
        /** @var list<float> Each holder's birth date, in years (negative before Year 1). */
        public array $councilBirths = [],
        /** @var list<float> When each seat's term in progress began, on the Council's schedule (App\Data\AerieCouncil::roster()). */
        public array $councilSince = [],
        /** @var array<int, float> When each holder took the seat: the term's start, or the day a successor filled a seat left vacant mid-term. */
        public array $councilSeatedAt = [],
        /** @var array<int, float> When each holder will leave before their term ends, by death or resignation, drawn when they were seated (-1: serves the term out). */
        public array $councilLeavesAt = [],
        /** The term length, in years, the councillors' seats are dated on; 0 for a state that predates the field. */
        public float $councilTermYears = 0.0,
        /** @var list<float> Each councillor's stance on money: 1 a hawk, -1 a dove; a swing vote's, the camp they lean to. */
        public array $councilStances = [],
        /** @var array<int, float> Whether each councillor is a swing vote (1), who changes camp now and then, or keeps theirs (0). */
        public array $councilSwingers = [],
        /** @var array<int, float> Each councillor's stance on the banks: -1 the lightest regime on record, 1 the strictest (App\Service\Politics\FinancialRegulator). */
        public array $councilRegulationStances = [],
        /** @var array<int, float> Each councillor's stance on the reserves: -1 the most cautious policy mix on record, 1 the boldest (App\Service\Politics\SovereignReserveFund). */
        public array $councilFundStances = [],
        /** @var list<array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}> The candidates the Council passed over when it last filled a seat of its own. */
        public array $councillorPassedOver = [],
        /** @var array<int, array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}> The Council Appointment Board's members, by seat (App\Service\Politics\CouncilAppointments::boardShortlist()). */
        public array $boardMembers = [],
        /** @var array<int, float> When each board member's term began. */
        public array $boardSince = [],
        /** The governor in office: name, birth date, when their term began, and stance. */
        public string $governorName = '',
        public float $governorBirth = 0.0,
        public float $governorTermStart = 0.0,
        public float $governorStance = 0.0,
        /** Whether the governor is a swing vote (1) or not (0); -1 for a state that predates the field. */
        public float $governorSwinger = -1.0,
        /** @var list<array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}> The candidates the Council passed over when it named the governor. */
        public array $governorPassedOver = [],
        /** @var list<string> The rate committee's members beside the governor, by seat: names, birth dates, when each took their seat, and stances. */
        public array $memberNames = [],
        /** @var list<float> */
        public array $memberBirths = [],
        /** @var list<float> */
        public array $memberSince = [],
        /** @var list<float> */
        public array $memberStances = [],
        /** @var array<int, float> Whether each committee member is a swing vote (1) or not (0). */
        public array $memberSwingers = [],
        /** The committee's hawk-dove balance, the governor's stance weighing half, and the supermajority it makes: 1 hawkish, -1 dovish, 0 neither. */
        public float $committeeBalance = 0.0,
        public float $committeeMajority = 0.0,
        /** When the last rate meeting sat (-1: none yet), the policy rate it left, its change since the meeting before, and each vote (governor first; 1 for a higher rate, -1 for a lower, 0 with the decision). */
        public float $lastMeetingAt = -1.0,
        public float $lastMeetingRate = 0.0,
        public float $lastMeetingChange = 0.0,
        /** @var list<float> */
        public array $lastMeetingVotes = [],
        public float $lastGovernorAppointedAt = -1.0,
        public float $lastCouncillorSeatedAt = -1.0,
        /** When a councillor last left before their term ended (-1: never), who, and why: 'died' or 'resigned'. */
        public float $lastCouncilVacancyAt = -1.0,
        public string $lastVacancyName = '',
        public string $lastVacancyCause = '',
        /** When an appointment last tipped the committee into or out of a supermajority. */
        public float $lastMajorityShiftAt = -1.0,
        /** The Financial Regulator's head in office: name, birth date, when their term began, and stance on the banks. */
        public string $regulatorName = '',
        public float $regulatorBirth = 0.0,
        public float $regulatorTermStart = 0.0,
        public float $regulatorStance = 0.0,
        /** @var list<array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}> The candidates the Council passed over when it named the head. */
        public array $regulatorPassedOver = [],
        /** The CET1 requirement on the District's banks in force, as a share of risk-weighted assets, and where the head's last rise began phasing in from, and when. */
        public float $bankCapitalRequirement = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT,
        public float $requirementPhaseFrom = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT,
        public float $requirementPhaseStart = -1.0,
        public float $lastRegulatorAppointedAt = -1.0,
        /** The mortgage loan-to-value cap the head runs, as a share of the property's value, in force from their seating; null for none. */
        public ?float $regulatorLtvCap = null,
        /** The Sovereign Reserve Fund's head in office: name, birth date, when their term began, and stance on the reserves. */
        public string $fundHeadName = '',
        public float $fundHeadBirth = 0.0,
        public float $fundHeadTermStart = 0.0,
        public float $fundHeadStance = 0.0,
        /** @var list<array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}> The candidates the Council passed over when it named the head. */
        public array $fundHeadPassedOver = [],
        public float $lastFundHeadAppointedAt = -1.0,
        /** @var list<string> Everyone who has left a Council seat or a post at the Monetary Authority, the Financial Regulator or the Sovereign Reserve Fund, whose names no later appointee takes. */
        public array $formerNames = [],
        /** The cabinet's pressure on the Monetary Authority (App\Service\Politics\PoliticalPressure): when the episode under way began (-1: none), the cabinet that began it (when it formed), whether the Authority is giving ground (1) or holding firm (0), and when the last episode began. */
        public float $pressureSince = -1.0,
        public float $pressureCabinet = -1.0,
        public float $pressureGivingIn = 0.0,
        public float $lastPressureAt = -1.0,
        /** @var array<string, string> Each party's leader (App\Service\Politics\PartyLeaders), drawn on the first tick; the prime minister is the leader of the cabinet's largest party. */
        public array $leaderNames = [],
        /** @var array<string, float> When each leader was born, in simulation years. */
        public array $leaderBirths = [],
        /** @var array<string, float> When each leader took the party's lead. */
        public array $leaderSince = [],
        /** @var array<string, float> The yearly hazard each leader has run through since taking the lead. */
        public array $leaderHazardUsed = [],
        /** @var array<string, float> When a leader the last vote has doomed steps down (-1: none). */
        public array $leaderExitAt = [],
        /** @var array<string, list<array{name: string, birth: float, since: float, until: float}>> Each party's former leaders, oldest first. */
        public array $leaderHistory = [],
        /** The vote every leader who fought it was last weighed against (-1: none). */
        public float $leadersReviewedElection = -1.0,
        /** When a party last changed its leader, and which party. */
        public float $lastLeaderChangeAt = -1.0,
        public string $lastLeaderChangeParty = '',
        /** @var array<string, float> Support between votes (App\Service\Politics\OpinionPolls): each party's lasting share, its short-term swing set aside (empty: read off the last vote). */
        public array $supportLasting = [],
        /** @var array<string, float> Each party's log swing this term for or against the governments it served in, folded into its lasting share at the vote. */
        public array $supportIncumbency = [],
        /** When support was last brought up to date (-1: never), and the growth and the inflation the voters had counted by then this term. */
        public float $supportUpdatedAt = -1.0,
        public float $supportGrowthCounted = 0.0,
        public float $supportInflationCounted = 0.0,
        /** @var list<array{t: float, shares: array<string, float>}> The polls published since the last vote, oldest first. */
        public array $polls = [],
        /** @var array<string, float> The market's average of the polls (App\Service\Politics\ElectionForecast), by party (empty: none yet). */
        public array $pollAverage = [],
        /** @var array<string, float> Its variance by party, in shares squared. */
        public array $pollAverageVariance = [],
        /** When the average last took a poll or a result (-1: never). */
        public float $pollAveragedAt = -1.0,
        /** @var list<array{cabinet: list<string>, support: list<string>, chance: float}> The governments the market gives a chance, likeliest first. */
        public array $forecastCabinets = [],
        /** @var array<string, float> Each party's chance of leading the next government. */
        public array $forecastLeaders = [],
        /** @var array<string, float> The seats the market expects each party to win. */
        public array $forecastSeats = [],
        /** @var array<string, float> The laws the market expects after the vote, each government's budget weighted by its chance (PoliticsEngine::LEVER_FIELDS keys). */
        public array $forecastLevers = [],
        /** @var array<string, float> The laws the sitting government will pass at its next budget round (the laws in force for a caretaker). */
        public array $sittingLevers = [],
        /** The vote the forecast is for, and when it was made (-1: none). */
        public float $forecastFor = -1.0,
        public float $forecastAt = -1.0,
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
            greenBeltStringency: $this->greenBeltStringency,
            carbonPrice: $this->carbonPrice,
            extractionStringency: $this->extractionStringency,
            stampDutyRate: $this->stampDutyRate,
            bankLevyRate: $this->bankLevyRate,
            reserveDrawShare: $this->reserveDrawShare,
            electionPulse: PoliticsEngine::electionPulse($this->totalTime, $this->lastElectionAt, $this->coalitionTakesOfficeAt),
            authorityMajority: $this->authoritySalt < 0.0 ? null : $this->committeeMajority,
            bankCapitalRequirement: $this->regulatorName === '' ? null : $this->bankCapitalRequirement,
            mortgageLtvCap: $this->regulatorLtvCap,
            reserveFundEquityShare: $this->fundHeadName === '' || $this->fundHeadTermStart < 0.0 ? null : SovereignReserveFund::equityShare($this->fundHeadStance),
            authorityConcession: $this->authoritySalt < 0.0 ? null : PoliticalPressure::concession($this),
            sittingLevers: $this->sittingLevers === [] ? null : $this->sittingLevers,
            sittingPolicyFrom: $this->sittingLevers === [] ? null : ElectionForecast::sittingTakesEffect($this),
            expectedLevers: $this->forecastLevers === [] ? null : $this->forecastLevers,
            expectedPolicyFrom: $this->forecastLevers === [] ? null : ElectionForecast::takesEffect($this),
        );
    }
}
