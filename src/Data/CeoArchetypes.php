<?php

namespace App\Data;

class CeoArchetypes
{
    public const OPPORTUNIST = 'opportunist';
    public const EMPIRE_BUILDER = 'empire_builder';
    public const CANNIBAL = 'cannibal';
    public const YIELD_KING = 'yield_king';
    public const CONSERVATIVE = 'conservative';
    public const VISIONARY = 'visionary';
    public const CONGLOMERATE = 'conglomerate';
    public const DEALMAKER = 'dealmaker';
    public const TURNAROUND = 'turnaround';
    public const COST_CUTTER = 'cost_cutter';

    public const ARCHETYPES = [
        self::OPPORTUNIST,
        self::EMPIRE_BUILDER,
        self::CANNIBAL,
        self::YIELD_KING,
        self::CONSERVATIVE,
        self::VISIONARY,
        self::CONGLOMERATE,
        self::DEALMAKER,
        self::TURNAROUND,
        self::COST_CUTTER,
    ];

    /**
     * Returns a random CEO archetype, dynamically weighted based on the macroeconomic state.
     */
    public static function getRandomArchetype(?\App\DTO\MacroStateDTO $macroState = null): string
    {
        $weights = [
            self::OPPORTUNIST    => 50,
            self::CONSERVATIVE   => 20,
            self::CONGLOMERATE   => 10,
            self::EMPIRE_BUILDER => 10,
            self::DEALMAKER      => 10,
            self::CANNIBAL       => 5,
            self::VISIONARY      => 5,
            self::YIELD_KING     => 5,
            self::TURNAROUND     => 5,
            self::COST_CUTTER    => 5,
        ];

        if ($macroState !== null) {
            $outputGap = $macroState->outputGap;
            $policyRate = $macroState->policyRate;
            $marketVol  = $macroState->marketVolatility;

            if ($outputGap < -0.02) {
                // Recession: Board wants safety and restructuring
                $weights[self::CONSERVATIVE] += 30;
                $weights[self::TURNAROUND]   += 20;
                $weights[self::YIELD_KING]   += 10;
                $weights[self::COST_CUTTER]  += 10;

                $weights[self::EMPIRE_BUILDER] = max(1, $weights[self::EMPIRE_BUILDER] - 8);
                $weights[self::DEALMAKER]      = max(1, $weights[self::DEALMAKER] - 8);
                $weights[self::VISIONARY]      = max(1, $weights[self::VISIONARY] - 4);
            } elseif ($outputGap > 0.02 && $policyRate < 0.03) {
                // Boom & Low Rates: Board wants aggressive expansion
                $weights[self::EMPIRE_BUILDER] += 25;
                $weights[self::DEALMAKER]      += 20;
                $weights[self::VISIONARY]      += 15;

                $weights[self::CONSERVATIVE] = max(1, $weights[self::CONSERVATIVE] - 10);
                $weights[self::COST_CUTTER]  = max(1, $weights[self::COST_CUTTER] - 4);
            }

            if ($marketVol > 0.35) {
                // High Volatility: Board wants efficiency and cash hoarding
                $weights[self::COST_CUTTER]  += 15;
                $weights[self::CONSERVATIVE] += 15;
                $weights[self::TURNAROUND]   += 10;
            }
        }

        $rand = mt_rand(1, array_sum($weights));
        $cumulative = 0;

        foreach ($weights as $archetype => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                return $archetype;
            }
        }

        return self::OPPORTUNIST;
    }

    /**
     * Returns a human-readable title for the archetype.
     */
    public static function getTitle(string $archetype): string
    {
        return match ($archetype) {
            self::OPPORTUNIST => 'an Opportunist',
            self::EMPIRE_BUILDER => 'an Empire Builder',
            self::CANNIBAL => 'a Cannibal',
            self::YIELD_KING => 'a Yield King',
            self::CONSERVATIVE => 'a Conservative',
            self::VISIONARY => 'a Visionary',
            self::CONGLOMERATE => 'a Conglomerate Builder',
            self::DEALMAKER => 'a Dealmaker',
            self::TURNAROUND => 'a Turnaround Specialist',
            self::COST_CUTTER => 'a Cost Cutter',
            default => 'an Opportunist',
        };
    }

    /**
     * Tooltip descriptions of how this archetype manages capital and operations.
     */
    public const DESCRIPTIONS = [
        self::OPPORTUNIST => 'The Opportunist follows standard data-driven capital allocation, balancing growth with shareholder returns based on market conditions.',
        self::EMPIRE_BUILDER => 'The Empire Builder ignores market saturation and aggressively pursues organic CapEx and massive Leveraged Buyouts (M&A) at any cost.',
        self::CANNIBAL => 'The Cannibal is obsessed with inflating EPS. They ignore valuation caps and mechanically execute massive share buybacks down to their last dollar.',
        self::YIELD_KING => 'The Yield King treats the dividend as sacred. They force a massive minimum payout ratio and refuse to cut the dividend unless facing imminent bankruptcy.',
        self::CONSERVATIVE => 'The Conservative is paranoid about debt. They hoard massive cash reserves and trigger aggressive deleveraging sweeps to pay down debt early.',
        self::VISIONARY => 'The Tech Visionary pursues massive moonshots, resulting in extreme idiosyncratic volatility, bloated fixed costs, but massive growth potential. They hate dividends.',
        self::CONGLOMERATE => 'The Conglomerate Builder hoards cash efficiently, refuses to overpay for M&A, runs extreme lean operations, and is highly trusted by bond markets.',
        self::DEALMAKER => 'The Dealmaker views the company as a trading desk. They execute LBOs at high frequencies with massive leverage tolerance.',
        self::TURNAROUND => 'The Turnaround Specialist focuses on saving distressed companies by slashing fixed costs, aggressively deleveraging, and readily cutting dividends.',
        self::COST_CUTTER => 'The Cost Cutter is obsessed with operational efficiency, hoarding cash, and executing massive share repurchases while avoiding risky M&A.',
    ];

    /**
     * Factory method to return the concrete strategy object for an archetype.
     */
    public static function getStrategy(\App\Entity\Stock $stock): \App\Service\Archetype\ArchetypeInterface
    {
        $archetype = $stock->getCeoArchetype();
        $baseStrategy = match ($archetype) {
            self::OPPORTUNIST => new \App\Service\Archetype\OpportunistArchetype(),
            self::EMPIRE_BUILDER => new \App\Service\Archetype\EmpireBuilderArchetype(),
            self::CANNIBAL => new \App\Service\Archetype\CannibalArchetype(),
            self::YIELD_KING => new \App\Service\Archetype\YieldKingArchetype(),
            self::CONSERVATIVE => new \App\Service\Archetype\ConservativeArchetype(),
            self::VISIONARY => new \App\Service\Archetype\VisionaryArchetype(),
            self::CONGLOMERATE => new \App\Service\Archetype\ConglomerateArchetype(),
            self::DEALMAKER => new \App\Service\Archetype\DealmakerArchetype(),
            self::TURNAROUND => new \App\Service\Archetype\TurnaroundArchetype(),
            self::COST_CUTTER => new \App\Service\Archetype\CostCutterArchetype(),
            default => new \App\Service\Archetype\OpportunistArchetype(),
        };

        // If the company is in severe distress, the Board of Directors overrides the CEO
        $metrics = new \App\Service\Math\CorporateMetrics();
        // Use a simple proxy for EBIT and Sales for Z-score calculation if current quarterly data isn't perfectly available yet
        $annualRevenue = max(1.0, (float) $stock->getTotalRevenue());
        $estimatedEbit = $annualRevenue * (float) $stock->getOperatingMargin();
        $zScore = $metrics->calculateAltmanZScore($stock, $estimatedEbit, $annualRevenue);
        
        // Z-Score < 1.81 is the classic Altman distress zone
        if ($zScore < 1.81) {
            return new \App\Service\Archetype\BoardGovernanceDecorator($baseStrategy);
        }

        return $baseStrategy;
    }
}
