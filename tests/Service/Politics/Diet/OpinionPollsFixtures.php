<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Diet;

use App\Data\Politics\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\Diet\Election;
use App\Service\Politics\PoliticsState;

/** The fixtures OpinionPollsTest and OpinionPollsRealismTest share: the founding term, the economy and quiet draws. */
trait OpinionPollsFixtures
{
    /** The first vote on the calendar. */
    protected const ELECTION_AT = Election::TERM_YEARS;

    /**
     * The founding Diet a coalition of two governs, at the start of the first term, the term counted from Year 1 and
     * the game's salt drawn.
     */
    private function termStart(): PoliticsState
    {
        $state = new PoliticsState();
        $state->governingCoalition = Diet::membership([Diet::VANGUARD, Diet::EXCHANGE]);
        $state->supportParties = [];
        $state->cabinetFallsAt = 100.0;
        $state->termStartedAt = 0.0;
        $state->termStartDeflator = $this->economy(0.0)->consumerPriceLevel;
        $state->campaignStartedAt = 0.0;
        $state->campaignStartRealGdp = Election::realGdp($this->economy(0.0));
        // The game's salt, which a fresh game draws on its first tick, after that tick's poll would have been
        // published.
        $state->authoritySalt = 7.0;

        return $state;
    }

    /** The economy on trend: output growing at its trend and prices at the target. */
    private function economy(float $totalTime): MacroStateDTO
    {
        return new MacroStateDTO(
            totalTime: $totalTime,
            potentialGdpIndex: exp((MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE + MacroConstants::TFP_DRIFT) * $totalTime),
            consumerPriceLevel: exp(MacroConstants::TARGET_INFLATION * $totalTime),
        );
    }

    /** Normals are zero; the other draws stay live. */
    private function quietMath(): MathUtility
    {
        return new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
    }
}
