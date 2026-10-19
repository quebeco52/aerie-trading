<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\Politics\AerieDiet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Message\ForecastElectionMessage;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\Department\CoastGuard;
use App\Service\Politics\Department\Exchequer;
use App\Service\Politics\Department\FairTradingBoard;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\Department\SecuritiesCommission;
use App\Service\Politics\Department\SecurityBureau;
use App\Service\Politics\Department\SovereignReserveFund;
use App\Service\Politics\Department\StateGuard;
use App\Service\Politics\Diet\CoalitionFormation;
use App\Service\Politics\Diet\Election;
use App\Service\Politics\Diet\ElectionForecast;
use App\Service\Politics\Diet\OpinionPolls;
use App\Service\Politics\Diet\PartyLeaders;
use App\Service\Politics\Policy\BudgetRound;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The Aerie Diet, tick by tick: the polls, the vote on the fixed calendar (Election::hold()) and the talks that follow,
 * cabinet falls between votes, a new government taking office, budget rounds (BudgetRound), the Council's appointments
 * and the departments, and the election forecast, which an off-tick worker can work out. The state is kept as JSON in
 * Redis. Politics runs beside the economy: it reads the tick's macro snapshot and hands back only policy
 * (PoliticsStateDTO::policy()), which the economy reads on the next tick.
 */
class PoliticsEngine
{
    // --- Persistence ---
    /** Redis key the politics state is kept under, beside the macro state. */
    public const REDIS_POLITICS_STATE = 'politics_state';
    /** Redis key the forecaster leaves a forecast worked out off the tick under, with when it was asked for. */
    public const REDIS_POLITICS_FORECAST = 'politics_forecast';

    public function __construct(
        private readonly MathUtility $mathUtility,
        private readonly \Redis $redis,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?MessageBusInterface $messageBus = null,
    ) {}

    /**
     * Advances the politics by one tick on the economy's snapshot of the same tick, and keeps the result.
     *
     * @param float $dt              Time increment in years.
     * @param bool  $forecastOffTick Whether the forecaster works out the election forecast, sparing the ticker's tick.
     */
    public function updatePolitics(MacroStateDTO $macro, float $dt, bool $forecastOffTick = false): PoliticsStateDTO
    {
        $state = $this->loadState();
        $offTick = $forecastOffTick && $this->messageBus !== null;
        $this->advance($state, $macro, $dt, $offTick);
        if ($offTick) {
            $this->exchangeForecast($state);
        }
        $this->saveState($state);

        return PoliticsStateDTO::fromState($state);
    }

    /**
     * Sends the forecaster the politics as they stand on the tick a forecast is asked for, or publishes the forecast it
     * has sent back for the request still pending. A message that cannot be sent leaves the request to come due.
     */
    private function exchangeForecast(PoliticsState $state): void
    {
        if ($state->forecastRequestedAt < 0.0) {
            return;
        }

        if ($state->forecastRequestedAt === $state->totalTime) {
            try {
                $this->messageBus?->dispatch(new ForecastElectionMessage($state->toArray()));
            } catch (\Throwable $e) {
                $this->logger?->warning('Failed to send the election forecast to the forecaster: ' . $e->getMessage());
            }

            return;
        }

        try {
            $raw = $this->redis->get(self::REDIS_POLITICS_FORECAST);
            $sent = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to read the election forecast back: ' . $e->getMessage());

            return;
        }
        if (is_array($sent) && is_numeric($sent['requestedAt'] ?? null) && is_array($sent['forecast'] ?? null)) {
            ElectionForecast::receive($state, self::hydrateForecast($sent['forecast']), (float) $sent['requestedAt']);
        }
    }

    /**
     * A forecast off the wire, typed as ElectionForecast::forecast() returns it.
     *
     * @param array<mixed> $forecast
     * @return array{cabinets: array<string, array{cabinet: list<string>, support: list<string>, chance: float}>, leaders: array<string, float>, seats: array<string, float>, levers: array<string, float>}
     */
    private static function hydrateForecast(array $forecast): array
    {
        $map = static fn(mixed $values): array => is_array($values) ? array_map('floatval', $values) : [];
        $parties = static fn(mixed $names): array => is_array($names) ? array_values(array_map('strval', $names)) : [];
        $cabinets = [];
        foreach (is_array($forecast['cabinets'] ?? null) ? $forecast['cabinets'] : [] as $key => $option) {
            if (is_array($option)) {
                $cabinets[(string) $key] = [
                    'cabinet' => $parties($option['cabinet'] ?? null),
                    'support' => $parties($option['support'] ?? null),
                    'chance' => (float) ($option['chance'] ?? 0.0),
                ];
            }
        }

        return ['cabinets' => $cabinets, 'leaders' => $map($forecast['leaders'] ?? null), 'seats' => $map($forecast['seats'] ?? null), 'levers' => $map($forecast['levers'] ?? null)];
    }

    /** The politics as last kept, or the founding Diet when nothing has been. */
    public function liveState(): PoliticsStateDTO
    {
        return PoliticsStateDTO::fromState($this->loadState());
    }

    private function loadState(): PoliticsState
    {
        $raw = $this->redis->get(self::REDIS_POLITICS_STATE);
        if (!is_string($raw) || trim($raw) === '') {
            return new PoliticsState();
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger?->error('Failed to decode the politics state from Redis: ' . $e->getMessage());

            return new PoliticsState();
        }

        return is_array($decoded) ? PoliticsState::fromArray($decoded) : new PoliticsState();
    }

    private function saveState(PoliticsState $state): void
    {
        try {
            $this->redis->set(self::REDIS_POLITICS_STATE, json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        } catch (\Throwable $e) {
            $this->logger?->critical('Failed to persist the politics state to Redis: ' . $e->getMessage());
        }
    }

    /**
     * Keeps the campaign and term marks, holds the vote on the tick a term ends, seats the government the talks produce
     * when its day comes, passes the budget at each round a government sits through, and names the tick's headline.
     *
     * @param float $dt              Time increment in years.
     * @param bool  $forecastOffTick Whether the forecast is asked of the forecaster (ElectionForecast::advance()).
     */
    public function advance(PoliticsState $state, MacroStateDTO $macro, float $dt, bool $forecastOffTick = false): void
    {
        $state->totalTime = $macro->totalTime;
        $realGdp = Election::realGdp($macro);

        // A state that predates the marks opens them where it stands and measures from there.
        if ($state->termStartedAt < 0.0) {
            $state->termStartedAt = $state->totalTime;
            $state->termStartDeflator = $macro->consumerPriceLevel;
        }
        if (
            $state->campaignStartedAt < 0.0
            || TimeSeries::crossedSimulatedBoundary($state->totalTime + Election::CAMPAIGN_WINDOW_YEARS, $dt, Election::TERM_YEARS)
        ) {
            $state->campaignStartedAt = $state->totalTime;
            $state->campaignStartRealGdp = $realGdp;
        }

        // Snapped to the tick grid, so the vote falls on the same tick as a budget round, not one tick late when
        // accumulated time lands a hair short of the term.
        if (TimeSeries::crossedSimulatedBoundary($state->totalTime, $dt, Election::TERM_YEARS)) {
            $state->lastElectionAt = $state->totalTime;
            $this->holdElection($state, $macro, $realGdp);

            $state->termStartedAt = $state->totalTime;
            $state->termStartDeflator = $macro->consumerPriceLevel;
        }

        // Support is brought up to date before any change of cabinet, so the month's swing lands on those who governed
        // it.
        OpinionPolls::advance($state, $macro, $dt, $this->mathUtility);

        if ($state->cabinetFallsAt >= 0.0 && $state->totalTime >= $state->cabinetFallsAt) {
            $this->fall($state);
        }

        if ($state->coalitionTakesOfficeAt >= 0.0 && $state->totalTime >= $state->coalitionTakesOfficeAt) {
            self::takeOffice($state);
        }

        // A sitting cabinet without a fall date draws one: on taking office, or on the first tick of a state that has
        // none.
        if ($state->cabinetFallsAt < 0.0 && $state->coalitionTakesOfficeAt < 0.0) {
            $hazard = Election::fallHazard(AerieDiet::governingParties($state->governingCoalition), $state->dietSeats);
            if ($hazard > 0.0) {
                $state->cabinetFallsAt = $state->totalTime + ($this->mathUtility->generateExponential() / $hazard);
            }
        }

        // A government's first budget is the round after the one it took office on; a caretaker passes none.
        if (
            $state->coalitionTakesOfficeAt < 0.0
            && $state->coalitionFormedAt < $state->totalTime
            && TimeSeries::crossedSimulatedBoundary($state->totalTime, $dt, MacroConstants::BUDGET_ROUND_PERIOD_YEARS)
        ) {
            BudgetRound::enactBudget($state, MacroEngine::recessionDeclared($macro));
        }

        CouncilAppointments::advance($state, $this->mathUtility);
        MonetaryAuthority::advance($state, $macro, $dt, $this->mathUtility);
        FinancialRegulator::advance($state, $this->mathUtility);
        SovereignReserveFund::advance($state, $this->mathUtility);
        Exchequer::advance($state, $this->mathUtility);
        FairTradingBoard::advance($state, $this->mathUtility);
        SecuritiesCommission::advance($state, $this->mathUtility);
        SecurityBureau::advance($state, $this->mathUtility);
        CoastGuard::advance($state, $this->mathUtility);
        StateGuard::advance($state, $this->mathUtility);
        PartyLeaders::advance($state, $dt, $this->mathUtility);
        ElectionForecast::advance($state, MacroEngine::recessionDeclared($macro), $forecastOffTick);

        $state->eventType = self::headline($state);
    }

    /**
     * The tick's political headline, the first that applies in the order below: the vote, a fall, a new government or
     * prime minister, a budget that changed a lever, an appointment, and last a rate meeting that moved the rate a full
     * step or split its committee. A single-tick pulse, gone on the next tick.
     */
    public static function headline(PoliticsState $state): ?string
    {
        return match (true) {
            $state->lastElectionAt === $state->totalTime => ShockEvent::ELECTION_HELD,
            $state->lastCabinetFellAt === $state->totalTime => ShockEvent::GOVERNMENT_FELL,
            $state->lastGovernmentFormedAt === $state->totalTime => ShockEvent::GOVERNMENT_FORMED,
            $state->lastLeaderChangeAt === $state->totalTime && $state->lastLeaderChangeParty === PartyLeaders::primeMinisterParty($state) => ShockEvent::PRIME_MINISTER_CHANGED,
            $state->lastBudgetEnactedAt === $state->totalTime => ShockEvent::BUDGET_ENACTED,
            $state->lastGovernorAppointedAt === $state->totalTime => ShockEvent::GOVERNOR_APPOINTED,
            $state->lastRegulatorAppointedAt === $state->totalTime => ShockEvent::REGULATOR_APPOINTED,
            $state->lastFundHeadAppointedAt === $state->totalTime => ShockEvent::FUND_HEAD_APPOINTED,
            $state->lastExchequerHeadAppointedAt === $state->totalTime => ShockEvent::EXCHEQUER_HEAD_APPOINTED,
            $state->lastFairTradingHeadAppointedAt === $state->totalTime => ShockEvent::FAIR_TRADING_HEAD_APPOINTED,
            $state->lastSecuritiesHeadAppointedAt === $state->totalTime => ShockEvent::SECURITIES_HEAD_APPOINTED,
            $state->lastSecurityDirectorAppointedAt === $state->totalTime => ShockEvent::SECURITY_DIRECTOR_APPOINTED,
            $state->lastCoastGuardCommandantAppointedAt === $state->totalTime => ShockEvent::COAST_GUARD_COMMANDANT_APPOINTED,
            $state->lastStateGuardCommanderAppointedAt === $state->totalTime => ShockEvent::STATE_GUARD_COMMANDER_APPOINTED,
            $state->lastMajorityShiftAt === $state->totalTime => ShockEvent::AUTHORITY_MAJORITY_SHIFT,
            $state->lastCouncillorSeatedAt === $state->totalTime => ShockEvent::COUNCILLOR_SEATED,
            $state->lastLeaderChangeAt === $state->totalTime => ShockEvent::PARTY_LEADER_CHANGED,
            MonetaryAuthority::meetingMadeNews($state) => ShockEvent::MONETARY_DECISION,
            default => null,
        };
    }

    /** The government the talks produced takes office; the caretaker steps down. */
    public static function takeOffice(PoliticsState $state): void
    {
        $state->governingCoalition = $state->pendingCoalition;
        $state->supportParties = $state->pendingSupport;
        $state->coalitionFormedAt = $state->totalTime;
        $state->lastGovernmentFormedAt = $state->totalTime;
        $state->coalitionTakesOfficeAt = -1.0;
    }

    /** The vote (Election::hold()), the blocs declared going into it, and the talks for the next government. */
    private function holdElection(PoliticsState $state, MacroStateDTO $macro, float $realGdp): void
    {
        $outgoingSeats = $state->dietSeats;
        Election::hold($state, $macro, $realGdp, $this->mathUtility);
        // The parties declared their blocs on the platforms they ran on and the seats they went into the vote with.
        $state->dietBlocs = CoalitionFormation::declareBlocs($outgoingSeats, $state->partyPositions, $state->dietBlocs);

        // A cabinet that fell just before the vote goes into it as caretaker, with no incumbency to weigh.
        $statusQuo = $state->coalitionTakesOfficeAt >= 0.0 ? [] : AerieDiet::governingParties($state->governingCoalition);
        $state->electionOutgoingCabinet = $state->governingCoalition;
        $state->cabinetFallsAt = -1.0;
        self::beginTalks($state, CoalitionFormation::talks($state->dietSeats, $state->dietVoteShares, $state->partyPositions, $statusQuo, $state->dietBlocs, $this->mathUtility));
    }

    /**
     * The cabinet loses the Diet between votes: it stays on as caretaker and the parties talk on the last vote's seats,
     * with no election (the calendar is fixed). The parties that fell may form again, but without the incumbency weight
     * a sitting cabinet carries into talks after a vote.
     */
    private function fall(PoliticsState $state): void
    {
        $state->lastCabinetFellAt = $state->totalTime;
        $state->cabinetFallsAt = -1.0;
        self::beginTalks($state, CoalitionFormation::talks($state->dietSeats, $state->dietVoteShares, $state->partyPositions, [], $state->dietBlocs, $this->mathUtility));
    }

    /**
     * Sets the talks' outcome pending until the day it takes office.
     *
     * @param array{cabinet: list<string>, support: list<string>, days: float, log: list<array{day: float, formateur: string, formed: bool, cabinet: list<string>, support: list<string>}>} $talks
     */
    private static function beginTalks(PoliticsState $state, array $talks): void
    {
        $state->pendingCoalition = AerieDiet::membership($talks['cabinet']);
        $state->pendingSupport = AerieDiet::membership($talks['support']);
        $state->talksStartedAt = $state->totalTime;
        $state->coalitionTakesOfficeAt = $state->totalTime + ($talks['days'] / FinancialConstants::DAYS_PER_YEAR);
        $state->formationLog = $talks['log'];
    }
}
