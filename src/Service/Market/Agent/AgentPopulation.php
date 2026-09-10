<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\Service\Math\FinancialConstants;

/**
 * How capital is distributed across competing beliefs, and how it moves between them.
 *
 * Brock & Hommes (1997, 1998): agents are not committed to a view, they hold whichever one has been
 * paying, and they reallocate by discrete choice on realized profit:
 *
 *     n_h = exp(beta * U_h) / sum_k exp(beta * U_k)
 *
 * The intensity of choice beta is the whole model. At zero, capital never moves and the market is
 * whatever mixture it started as. Raised, the population tips toward whichever belief has been working,
 * and it is that tipping — not any scripted regime — that produces bubbles, crashes and volatility
 * clustering. A market that has been trending attracts extrapolators, whose buying extends the trend,
 * until the price is far enough from fair value that the fundamentalists' return overwhelms them.
 *
 * Only beliefs about where the price is going take part. A market maker and an index fund are in the
 * market for structural reasons and are excluded, since neither is chosen because it beat the other side.
 */
final class AgentPopulation
{
    /**
     * Population shares from accumulated fitness.
     *
     * The exponentials are computed against the best score rather than against zero. exp(beta * U) with a
     * large U overflows to INF and turns every share into NaN, which would silently empty the market;
     * subtracting the maximum first is algebraically identical and cannot overflow.
     *
     * @param array<string, float> $fitness Accumulated score per competing strategy.
     * @return array<string, float> Shares summing to one.
     */
    public function shares(array $fitness): array
    {
        if ($fitness === []) {
            return [];
        }

        $beta = FinancialConstants::AGENT_INTENSITY_OF_CHOICE;
        $best = max($fitness);

        $weights = [];
        $total = 0.0;

        foreach ($fitness as $identifier => $score) {
            $weight = exp($beta * ($score - $best));
            $weights[$identifier] = $weight;
            $total += $weight;
        }

        if ($total <= 0.0 || !is_finite($total)) {
            $even = 1.0 / count($fitness);

            return array_map(static fn (): float => $even, $fitness);
        }

        $shares = [];
        foreach ($weights as $identifier => $weight) {
            $shares[$identifier] = $weight / $total;
        }

        return $this->applyFloor($shares);
    }

    /**
     * Keeps a losing belief from being extinguished entirely.
     *
     * Without a floor, a long enough run in one direction drives the other side's share to numerically
     * zero and it can never recover, because a strategy holding nothing earns nothing and so never scores
     * again. The market would then be permanently one-sided — which is not what happens, and would remove
     * the very switching the model exists to produce.
     *
     * @param array<string, float> $shares
     * @return array<string, float>
     */
    private function applyFloor(array $shares): array
    {
        $floor = FinancialConstants::AGENT_MIN_POPULATION_SHARE;

        // A floor that leaves no room to allocate would make every share identical and the switching
        // meaningless, so it is skipped rather than applied.
        if (count($shares) * $floor >= 1.0) {
            return $shares;
        }

        $free = 1.0 - (count($shares) * $floor);

        $floored = [];
        foreach ($shares as $identifier => $share) {
            $floored[$identifier] = $floor + ($share * $free);
        }

        return $floored;
    }

    /**
     * Scores each belief on what its previous position actually earned, as an annualized rate.
     *
     * Realized profit in the Brock-Hommes sense — the return that followed, times the exposure the strategy
     * was carrying into it — but expressed per year rather than per tick. That matters more than it looks:
     * a raw per-tick profit at a 14,400-tick year is on the order of 1e-5, and no intensity of choice that
     * is not itself absurd can tell two such numbers apart. The population would sit at its initial split
     * forever and the model's entire mechanism would be inert while appearing to run.
     *
     * Annualizing also makes the score independent of the configured tick rate, so the same intensity of
     * choice means the same thing whatever the simulation is stepping at.
     *
     * @param array<string, float> $fitness   Accumulated scores.
     * @param array<string, float> $positions Positions held into the return, in shares.
     * @param float                $logReturn The return that followed.
     * @param float                $capacity  Position size that counts as fully committed, for scaling.
     * @param float                $dt        Elapsed simulated time in years.
     * @return array<string, float> Updated scores.
     */
    public function updateFitness(array $fitness, array $positions, float $logReturn, float $capacity, float $dt): array
    {
        if ($dt <= 0.0) {
            return $fitness;
        }

        // Continuous-time exponential weighting, so the memory is a length of simulated time rather than a
        // number of ticks. Capital chases performance, but over months, not over the last print.
        $phi = exp(-$dt / FinancialConstants::AGENT_FITNESS_HORIZON_YEARS);
        $scale = max(1.0, $capacity);

        $updated = [];
        foreach ($fitness as $identifier => $score) {
            $exposure = ($positions[$identifier] ?? 0.0) / $scale;
            $annualizedProfit = ($exposure * $logReturn) / $dt;

            $updated[$identifier] = ($score * $phi) + ((1.0 - $phi) * $annualizedProfit);
        }

        return $updated;
    }
}
