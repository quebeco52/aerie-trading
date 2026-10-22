<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\Politics\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\Diet\Election;
use App\Service\Politics\PoliticsEngine as Politics;
use App\Service\Politics\PoliticsState;

/** The fixtures PoliticsEngineTest and PoliticsEngineRealismTest share: founding cabinets, economies and engines. */
trait PoliticsEngineFixtures
{
    /** The Vanguard's whole bloc at the founding, a majority cabinet of 160 seats with no one outside to answer to. */
    protected const RIGHT_BLOC = [Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS, Diet::NEW_HORIZON];

    /** The first vote on the calendar. */
    protected const ELECTION_AT = Election::TERM_YEARS;

    /**
     * The politics going into the first vote, with the campaign and term marks set so that, on the economy at its
     * openings on the day, growth and inflation run the given distance from trend and target.
     */
    private function electionTick(float $growthGap = 0.0, float $inflationGap = 0.0): PoliticsState
    {
        $state = new PoliticsState();
        $economy = $this->economy(self::ELECTION_AT);

        $trendGrowth = MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE + MacroConstants::TFP_DRIFT;
        $state->campaignStartedAt = self::ELECTION_AT - Election::CAMPAIGN_WINDOW_YEARS;
        $state->campaignStartRealGdp = Election::realGdp($economy) * exp(-($trendGrowth + $growthGap) * Election::CAMPAIGN_WINDOW_YEARS);
        $state->termStartedAt = 0.0;
        $state->termStartDeflator = $economy->consumerPriceLevel * exp(-(MacroConstants::TARGET_INFLATION + $inflationGap) * self::ELECTION_AT);

        return $state;
    }

    /** The tick of the first vote, on the economy at its openings. */
    private function vote(Politics $engine, PoliticsState $state): void
    {
        $engine->advance($state, $this->economy(self::ELECTION_AT), 0.01);
    }

    /**
     * The first vote with this cabinet in office and no support parties, growth and inflation the given gaps off
     * trend and target.
     *
     * @param list<string> $cabinet
     */
    private function votedUnder(Politics $engine, array $cabinet, float $growthGap = 0.0, float $inflationGap = 0.0): PoliticsState
    {
        $state = $this->electionTick($growthGap, $inflationGap);
        $state->governingCoalition = Diet::membership($cabinet);
        $state->supportParties = Diet::membership([]);
        $this->vote($engine, $state);

        return $state;
    }

    /**
     * In how many of 300 first votes the talks after the outgoing cabinet form this one.
     *
     * @param list<string> $outgoing
     * @param list<string> $cabinet
     */
    private function timesFormedAfter(Politics $engine, array $outgoing, array $cabinet): int
    {
        $count = 0;
        for ($trial = 0; $trial < 300; ++$trial) {
            $state = $this->electionTick();
            $state->governingCoalition = Diet::membership($outgoing);
            $this->vote($engine, $state);
            $count += Diet::governingParties($state->pendingCoalition) === $cabinet ? 1 : 0;
        }

        return $count;
    }

    /** The economy as the macro hands it over: at its openings, on the given tick, debt and labour force growth. */
    private function economy(float $totalTime, float $debtToGdp = 0.5, float $laborForceGrowthRate = MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE): MacroStateDTO
    {
        return new MacroStateDTO(totalTime: $totalTime, sovereignDebtToGdp: $debtToGdp, laborForceGrowthRate: $laborForceGrowthRate);
    }

    /**
     * Politics between votes with the given cabinet and supporters on the given seats and the founding positions.
     *
     * @param array<string, float> $seats   Seats of the cabinet's parties; the rest of the Diet holds none unless
     *                                      supporting.
     * @param array<string, float> $support Seats of the support parties.
     */
    private function governedBy(array $seats, array $support = []): PoliticsState
    {
        $state = new PoliticsState();
        $state->totalTime = 10.0;
        $state->dietSeats = [];
        foreach (Diet::PARTIES as $party) {
            $state->dietSeats[$party] = $seats[$party] ?? $support[$party] ?? 0.0;
        }
        $state->governingCoalition = Diet::membership(array_keys($seats));
        $state->supportParties = Diet::membership(array_keys($support));
        // The cabinet sees out the term.
        $state->cabinetFallsAt = 100.0;

        return $state;
    }

    /**
     * The day after the first vote, with the talks settled on a Vanguard cabinet the Exchange Party and the Chartists
     * support.
     */
    private function pendingGovernment(float $takesOfficeAt): PoliticsState
    {
        $economy = $this->economy(self::ELECTION_AT);
        $state = new PoliticsState();
        $state->totalTime = self::ELECTION_AT;
        $state->lastElectionAt = self::ELECTION_AT;
        $state->termStartedAt = self::ELECTION_AT;
        $state->termStartDeflator = $economy->consumerPriceLevel;
        $state->campaignStartedAt = self::ELECTION_AT - Election::CAMPAIGN_WINDOW_YEARS;
        $state->campaignStartRealGdp = Election::realGdp($economy);
        $state->pendingCoalition = Diet::membership([Diet::VANGUARD]);
        $state->pendingSupport = Diet::membership([Diet::EXCHANGE, Diet::CHARTISTS]);
        $state->coalitionTakesOfficeAt = $takesOfficeAt;

        return $state;
    }

    /** The same Diet a term later, going into the next vote, with the economy at trend over the term just ended. */
    private function nextElectionTick(PoliticsState $previous): PoliticsState
    {
        $next = $this->electionTick();
        $next->dietSeats = $previous->dietSeats;
        $next->dietVoteShares = $previous->dietVoteShares;
        $next->partyPositions = $previous->partyPositions;
        $next->governingCoalition = $previous->governingCoalition;
        $next->supportParties = $previous->supportParties;
        $next->partyShortTermShocks = $previous->partyShortTermShocks;

        return $next;
    }

    private function engine(MathUtility $draws): Politics
    {
        return new Politics($draws, $this->inMemoryRedis());
    }

    /** Every draw is zero: the vote is its economic part alone and positions hold. */
    private function quietMath(): MathUtility
    {
        return new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
    }

    private function inMemoryRedis(): \Redis
    {
        return new class extends \Redis {
            /** @var array<string, string> */
            private array $store = [];

            public function get(mixed $key): mixed
            {
                return $this->store[(string) $key] ?? false;
            }

            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->store[$key] = (string) $value;

                return true;
            }
        };
    }
}
