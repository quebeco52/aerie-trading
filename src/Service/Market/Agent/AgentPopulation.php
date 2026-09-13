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
     * What a name's population actually chooses on: part how each belief has paid here, part how it has
     * paid everywhere.
     *
     * Barberis & Shleifer (2003): much of the capital that switches does so at the level of a style, on
     * the style's performance across the market, not stock by stock. A population scored only on its own
     * name cannot do what real capital does — leave momentum in every name at once when the market turns.
     * Purely style-level switching would be the other extreme, a single population wearing sixty tickers.
     * The weight sets the mix; the style score is the average of the names' own scores, so the two are in
     * the same units and a market of one name is the plain single-asset model.
     *
     * With no style history yet — the first tick the market runs, or a store that could not be read — a
     * name is judged on itself alone rather than against a zero that would mean "every style is losing".
     *
     * @param array<string, float> $local Fitness of each competing belief on this name.
     * @param array<string, float> $style Market-wide fitness of each competing belief, same keys.
     * @return array<string, float>
     */
    public function crowdedFitness(array $local, array $style): array
    {
        if ($style === []) {
            return $local;
        }

        $weight = max(0.0, min(1.0, FinancialConstants::AGENT_STYLE_CROWDING_WEIGHT));

        $crowded = [];
        foreach ($local as $identifier => $score) {
            $crowded[$identifier] = ((1.0 - $weight) * $score) + ($weight * ($style[$identifier] ?? $score));
        }

        return $crowded;
    }

    /**
     * Keeps a losing belief from being extinguished entirely.
     *
     * A safety net rather than the mechanism. A belief is scored on what one of its agents holds, so a
     * belief that has been starved of capital still scores on its conviction and can win it back — but a
     * long enough run drives the loser's share to numerically zero all the same, and a share that has
     * underflowed cannot be multiplied back into existence. The market would then be permanently
     * one-sided, which is not what happens and would remove the very switching the model exists to produce.
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
     * Scores each belief on the risk-adjusted excess return one of its agents actually earned, as an
     * annualized rate.
     *
     * Brock & Hommes (1998) mean-variance fitness, U_h = pi_h - (a/2) sigma^2 z_h^2, where z_h is the
     * exposure ONE agent of type h was carrying into the return that followed, as a fraction of a full
     * commitment. The population share n_h does not enter: in BH it only appears in market clearing, and
     * the switching is on what an agent of each type made, not on what the type made in aggregate.
     * Scoring the aggregate book instead — which is what this did at first — handed every belief a
     * score proportional to its own share: the minority's fitness was compressed toward zero whatever it
     * believed, the majority gained share when both were right and lost it when both were wrong, and the
     * floor on the population share became the only thing keeping a starved belief alive.
     *
     * Two things about the profit term matter:
     *
     *   - It is an EXCESS return. Cash earns the risk-free rate, so a long book that made the policy rate
     *     made nothing, and a short book sitting on its proceeds earns it. Scoring raw returns would hand
     *     every long belief a free edge equal to the rate.
     *   - It is charged for risk. Raw profit rewards whichever belief simply carries more exposure, and the
     *     chartist signal saturates at full commitment far more often than the fundamentalist one does, so
     *     without the penalty the chartists hold a structural fitness edge unrelated to being right.
     *
     * Expressed per year rather than per tick. That matters more than it looks: a raw per-tick profit at a
     * 14,400-tick year is on the order of 1e-5, and no intensity of choice that is not itself absurd can
     * tell two such numbers apart. The population would sit at its initial split forever and the model's
     * entire mechanism would be inert while appearing to run. Annualizing also makes the score independent
     * of the configured tick rate, so the same intensity of choice means the same thing whatever the
     * simulation is stepping at.
     *
     * @param array<string, float> $fitness      Accumulated scores.
     * @param array<string, float> $exposures    Exposure one agent of each belief held into the return, in [-1, 1].
     * @param float                $logReturn    The total return that followed, dividends included, measured before any split.
     * @param float                $dt           Elapsed simulated time in years.
     * @param float                $riskFreeRate Annual rate cash earns.
     * @param float                $variance     Annualized return variance the exposure is charged for, as it was known when the exposure was taken.
     * @return array<string, float> Updated scores.
     */
    public function updateFitness(
        array $fitness,
        array $exposures,
        float $logReturn,
        float $dt,
        float $riskFreeRate,
        float $variance
    ): array {
        if ($dt <= 0.0) {
            return $fitness;
        }

        // Continuous-time exponential weighting, so the memory is a length of simulated time rather than a
        // number of ticks. Capital chases performance, but over months, not over the last print.
        $phi = exp(-$dt / FinancialConstants::AGENT_FITNESS_HORIZON_YEARS);
        $riskCharge = 0.5 * FinancialConstants::AGENT_RISK_AVERSION * max(0.0, $variance);

        $updated = [];
        foreach ($fitness as $identifier => $score) {
            $exposure = $exposures[$identifier] ?? 0.0;
            $excessRate = $exposure * (($logReturn / $dt) - $riskFreeRate);
            $utility = $excessRate - ($riskCharge * $exposure * $exposure);

            $updated[$identifier] = ($score * $phi) + ((1.0 - $phi) * $utility);
        }

        return $updated;
    }

    /**
     * Realized variance of a name's returns, annualized, as an exponentially weighted mean.
     *
     * RiskMetrics-style: sigma^2_t = phi sigma^2_{t-1} + (1 - phi) r_t^2 / dt, with the memory set as a
     * length of simulated time so the same window means the same thing at any tick rate. This is the
     * volatility the agents are allowed to see. It is built from the returns they have already observed,
     * so a jump raises it in the tick it prints and it decays over the window afterwards — which is how a
     * vol-control book or a maker's risk desk actually experiences a shock, rather than by reading the
     * process's own instantaneous variance the way an earlier version did.
     *
     * @param float $variance  Last estimate.
     * @param float $logReturn The return just observed, measured before any split.
     * @param float $dt        Elapsed simulated time in years.
     */
    public function realizedVariance(float $variance, float $logReturn, float $dt): float
    {
        if ($dt <= 0.0) {
            return $variance;
        }

        $phi = exp(-$dt / FinancialConstants::AGENT_REALIZED_VOLATILITY_HORIZON_YEARS);

        return (max(0.0, $variance) * $phi) + ((1.0 - $phi) * (($logReturn * $logReturn) / $dt));
    }
}
