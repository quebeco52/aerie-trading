<?php

declare(strict_types=1);

namespace App\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;
use App\Service\Math\FinancialConstants;

/**
 * Buys whatever it has just noticed.
 *
 * Barber & Odean (2008), *All That Glitters: The Effect of Attention and News on the Buying Behavior of
 * Individual and Institutional Investors*. Individuals are net buyers of attention-grabbing stocks — ones
 * with abnormal volume, an extreme return, or news — and the finding that matters is the ASYMMETRY. An
 * individual chooses what to buy from thousands of names and can only sell the handful they already own,
 * so attention drives purchases and barely touches sales. A signal symmetric in the sign of the return
 * would be a different model and worth very little.
 *
 * Note that the return leg is on the ABSOLUTE move. Big losers grab attention exactly as big winners do,
 * and the paper finds retail buying into both. That is what separates this from momentum, which it would
 * otherwise quietly duplicate.
 *
 * Every other participant in this market is some kind of sophisticated: six strategies, all with a view.
 * This is the one that is wrong on purpose, and without someone to be wrong the informed money has nobody
 * to be right against.
 *
 * Outside the discrete-choice switching. Retail is a persistent population rather than a belief selected
 * on last quarter's profit, and putting it in the Brock-Hommes choice would let it be competed out of
 * existence — the one thing retail flow empirically never does.
 */
final class AttentionRetailStrategy implements AgentStrategyInterface
{
    // --- Attention-Driven Retail (Barber & Odean 2008, "All That Glitters") ---
    /** Share of agent capital retail holds in a name nobody is talking about; the book it sits on between episodes. */
    public const AGENT_RETAIL_BASE_SHARE = 0.10;
    /** Most retail adds to a name on top of the base share when its attention score saturates. Bounded because attention buying is an episode, not a regime. */
    public const AGENT_RETAIL_MAX_ATTENTION_TILT = 0.35;
    /** Standard deviations of one tick's move at which the extreme-return leg of attention saturates. Barber & Odean rank on the previous day's return, at either sign. */
    public const AGENT_RETAIL_RETURN_SIGMA = 2.50;
    /** Multiple of expected volume at which the abnormal-volume leg saturates; the paper's own sort is on volume far above a name's normal. Must stay above one: at or below it, every ordinary tick would read as an attention episode. */
    public const AGENT_RETAIL_VOLUME_MULTIPLE = 3.00;
    /** Attention contributed by a name being in the news at all, before any move or volume. News is the third of the paper's three sorts and the only one that is not a market statistic. */
    public const AGENT_RETAIL_NEWS_ATTENTION = 0.50;

    public function identifier(): string
    {
        return 'attention_retail';
    }

    public function signal(AgentMarketViewDTO $view, array $positions): float
    {
        return max(0.0, min(
            1.0,
            self::AGENT_RETAIL_BASE_SHARE
                + (self::AGENT_RETAIL_MAX_ATTENTION_TILT * $this->attention($view))
        ));
    }

    /**
     * How hard this name is to miss today, in [0, 1].
     *
     * The two market legs are combined with a max rather than a sum because they are one episode observed
     * two ways: a name that just moved three sigma is also, almost always, a name trading three times its
     * normal volume, and adding them would count that episode twice. News is genuinely separate
     * information and adds on top.
     */
    private function attention(AgentMarketViewDTO $view): float
    {
        $market = max($this->extremeReturnLeg($view), $this->abnormalVolumeLeg($view));
        $news = $view->recentNews * self::AGENT_RETAIL_NEWS_ATTENTION;

        return min(1.0, $market + $news);
    }

    /** The last trading day's move, in units of what this name's day normally does. */
    private function extremeReturnLeg(AgentMarketViewDTO $view): float
    {
        if ($view->dt <= 0.0 || $view->annualizedVolatility <= 0.0) {
            return 0.0;
        }

        // Standardized per name, not in raw percent: a 3% day is unremarkable for a speculative small cap
        // and front-page news for a utility, and attention is about the second reading. The day's move is
        // a leaky sum of returns, whose stationary variance is sigma^2 dt / (1 - phi^2): sigma^2 tau / 2 at
        // a fine tick, about a day's variance at a daily one.
        $phi = exp(-$view->dt / FinancialConstants::AGENT_RETAIL_ATTENTION_HORIZON_YEARS);
        $daySigma = $view->annualizedVolatility * sqrt($view->dt / (1.0 - ($phi * $phi)));
        $saturation = $daySigma * self::AGENT_RETAIL_RETURN_SIGMA;

        if ($saturation <= 0.0) {
            return 0.0;
        }

        return min(1.0, abs($view->recentMove) / $saturation);
    }

    /**
     * Volume above what the name normally prints, which is the paper's primary sort.
     *
     * The headroom is the saturation multiple less the ordinary tick it is measured from, and the multiple
     * is above one by definition — a saturation point at or below normal volume would mean every tick was
     * an attention episode.
     */
    private function abnormalVolumeLeg(AgentMarketViewDTO $view): float
    {
        $headroom = self::AGENT_RETAIL_VOLUME_MULTIPLE - 1.0;

        return max(0.0, min(1.0, ($view->recentAbnormalVolume - 1.0) / $headroom));
    }

    public function competesForCapital(): bool
    {
        return false;
    }
}
