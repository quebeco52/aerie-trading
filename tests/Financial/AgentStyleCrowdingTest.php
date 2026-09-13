<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\AgentMarketViewDTO;
use App\Service\Market\Agent\AgentFlowEngine;
use App\Service\Market\Agent\AgentPopulation;
use App\Service\Market\Agent\FundamentalistStrategy;
use App\Service\Market\Agent\InMemoryAgentStateStore;
use App\Service\Market\Agent\MomentumStrategy;
use App\Service\Market\Flow\InMemoryOrderFlowStore;
use PHPUnit\Framework\TestCase;

/**
 * Capital moving at the level of a style, across a whole market of names.
 *
 * Barberis & Shleifer (2003): investors allocate to styles on how the style has paid, not stock by stock.
 * The observable consequence is that the populations of unrelated names move together — a market that has
 * been trending puts momentum capital into every name, and the day it turns takes it out of every name at
 * once. Sixty independent single-name populations would each turn on their own schedule and the market as
 * a whole would never crowd into anything.
 *
 * Driven with synthetic prices rather than the full engine: what is under test is the population, and the
 * variance budget the price side has to respect is covered by AgentMarketDynamicsTest.
 */
class AgentStyleCrowdingTest extends TestCase
{
    private const NAMES = 12;
    private const TICKS = 12000;
    private const WARMUP_TICKS = 2000;
    private const TICKS_PER_YEAR = 14400;
    private const COMMON_VOLATILITY = 0.16;
    private const IDIOSYNCRATIC_VOLATILITY = 0.24;

    /**
     * A market of names sharing one factor, with or without the style channel.
     *
     * The control runs the same engine and the same prices but never opens or closes a tick, so the style
     * record is never written and every name is judged on itself alone — the pre-crowding model.
     *
     * @return array{shares: list<list<float>>} Momentum share per name per sampled tick.
     */
    private function simulate(bool $styleChannel, int $seed): array
    {
        mt_srand($seed);

        $engine = new AgentFlowEngine(
            new AgentPopulation(),
            new InMemoryAgentStateStore(),
            new InMemoryOrderFlowStore(),
            [new FundamentalistStrategy(), new MomentumStrategy()]
        );

        $dt = 1.0 / self::TICKS_PER_YEAR;
        $momentumPhi = exp(-$dt / 0.50);
        $commonSigma = self::COMMON_VOLATILITY * sqrt($dt);
        $idioSigma = self::IDIOSYNCRATIC_VOLATILITY * sqrt($dt);

        $prices = array_fill(0, self::NAMES, 100.0);
        $trends = array_fill(0, self::NAMES, 0.0);
        $shares = array_fill(0, self::NAMES, []);

        for ($tick = 0; $tick < self::TICKS; $tick++) {
            // One common draw for the market, one of its own for each name. Both runs walk the same path.
            $common = $commonSigma * $this->gaussian();

            if ($styleChannel) {
                $engine->beginTick();
            }

            for ($name = 0; $name < self::NAMES; $name++) {
                $logReturn = $common + ($idioSigma * $this->gaussian());
                $prices[$name] *= exp($logReturn);
                $trends[$name] = max(-0.5, min(0.5, ($trends[$name] * $momentumPhi) + $logReturn));

                $traded = $engine->trade(new AgentMarketViewDTO(
                    ticker: 'N' . $name,
                    price: $prices[$name],
                    perceivedFairValue: 100.0,
                    momentumTrend: $trends[$name],
                    averageDailyVolume: 1000000.0,
                    logReturn: $logReturn,
                    financialConditions: 0.0,
                    dt: $dt,
                    riskFreeRate: 0.04,
                    annualizedVolatility: 0.29
                ));

                if ($tick >= self::WARMUP_TICKS && $tick % 20 === 0) {
                    $shares[$name][] = $traded['shares']['momentum'];
                }
            }

            if ($styleChannel) {
                $engine->endTick();
            }
        }

        return ['shares' => $shares];
    }

    private function gaussian(): float
    {
        $u1 = max(1e-12, mt_rand() / mt_getrandmax());
        $u2 = mt_rand() / mt_getrandmax();

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    /**
     * Mean correlation of the momentum share across every pair of names.
     *
     * @param list<list<float>> $series
     */
    private function meanPairwiseCorrelation(array $series): float
    {
        $count = count($series);
        $total = 0.0;
        $pairs = 0;

        for ($a = 0; $a < $count; $a++) {
            for ($b = $a + 1; $b < $count; $b++) {
                $total += $this->correlation($series[$a], $series[$b]);
                $pairs++;
            }
        }

        return $total / max(1, $pairs);
    }

    /**
     * @param list<float> $x
     * @param list<float> $y
     */
    private function correlation(array $x, array $y): float
    {
        $n = min(count($x), count($y));
        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;
        $cov = 0.0;
        $varX = 0.0;
        $varY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $cov += $dx * $dy;
            $varX += $dx * $dx;
            $varY += $dy * $dy;
        }

        if ($varX <= 0.0 || $varY <= 0.0) {
            return 0.0;
        }

        return $cov / sqrt($varX * $varY);
    }

    public function testNamesCrowdIntoAStyleTogetherRatherThanEachOnTheirOwnSchedule(): void
    {
        foreach ([11, 23, 47] as $seed) {
            $alone = $this->meanPairwiseCorrelation($this->simulate(false, $seed)['shares']);
            $crowded = $this->meanPairwiseCorrelation($this->simulate(true, $seed)['shares']);

            $this->assertGreaterThan(
                $alone + 0.15,
                $crowded,
                sprintf('Seed %d: momentum share co-moves at %.2f with the style channel vs %.2f without.', $seed, $crowded, $alone)
            );
        }
    }

    public function testWhenTheMarketTurnsMomentumLosesCapitalInEveryNameAtOnce(): void
    {
        // A momentum crash is market-wide. In the month the market's momentum share falls hardest, the
        // style channel takes capital out of the name that held up best too; judged alone, that name has
        // its own schedule and can even be adding. The market-wide average itself barely moves — the
        // style score is the average of the names' own scores, so the channel redistributes the swing
        // across names rather than amplifying it.
        foreach ([11, 23, 47] as $seed) {
            $alone = $this->simulate(false, $seed)['shares'];
            $crowded = $this->simulate(true, $seed)['shares'];

            [$aloneMarket, $aloneBest] = $this->worstCommonMonth($alone);
            [$crowdedMarket, $crowdedBest] = $this->worstCommonMonth($crowded);

            $this->assertLessThan(
                $aloneBest,
                $crowdedBest,
                sprintf('Seed %d: the best-holding name fell %.3f with the style channel vs %.3f without.', $seed, $crowdedBest, $aloneBest)
            );
            $this->assertLessThan(0.0, $crowdedBest, sprintf('Seed %d: with the style channel no name is spared.', $seed));
            $this->assertEqualsWithDelta($aloneMarket, $crowdedMarket, 0.01, sprintf('Seed %d: the channel redistributes, it does not amplify.', $seed));
        }
    }

    public function testTheStyleChannelMakesNamesLookAlikeRatherThanMoreVolatile(): void
    {
        // Cross-sectional dispersion of the momentum share, averaged over time, is what the channel
        // removes: idiosyncratic switching becomes common switching.
        foreach ([11, 23, 47] as $seed) {
            $alone = $this->meanDispersion($this->simulate(false, $seed)['shares']);
            $crowded = $this->meanDispersion($this->simulate(true, $seed)['shares']);

            $this->assertLessThan($alone * 0.75, $crowded, sprintf('Seed %d: dispersion %.3f crowded vs %.3f alone.', $seed, $crowded, $alone));
        }
    }

    /**
     * Cross-sectional standard deviation of the momentum share across names, averaged over the sampled ticks.
     *
     * @param list<list<float>> $series
     */
    private function meanDispersion(array $series): float
    {
        $names = count($series);
        $length = count($series[0]);
        $total = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $mean = 0.0;
            foreach ($series as $one) {
                $mean += $one[$i];
            }
            $mean /= $names;

            $variance = 0.0;
            foreach ($series as $one) {
                $variance += ($one[$i] - $mean) ** 2;
            }
            $total += sqrt($variance / $names);
        }

        return $total / $length;
    }

    /**
     * The market's worst month for momentum: the largest one-month fall in the share averaged ACROSS names,
     * and, in that same month, the change in the share of the name that held up best.
     *
     * @param list<list<float>> $series
     * @return array{float, float} Market-average change, best single name's change.
     */
    private function worstCommonMonth(array $series): array
    {
        $names = count($series);
        $length = count($series[0]);
        $window = (int) (self::TICKS_PER_YEAR / 12 / 20);

        $market = [];
        for ($i = 0; $i < $length; $i++) {
            $sum = 0.0;
            foreach ($series as $one) {
                $sum += $one[$i];
            }
            $market[] = $sum / $names;
        }

        $worst = 0.0;
        $at = $window;
        for ($i = $window; $i < $length; $i++) {
            $change = $market[$i] - $market[$i - $window];
            if ($change < $worst) {
                $worst = $change;
                $at = $i;
            }
        }

        $best = -1.0;
        foreach ($series as $one) {
            $best = max($best, $one[$at] - $one[$at - $window]);
        }

        return [$worst, $best];
    }
}
