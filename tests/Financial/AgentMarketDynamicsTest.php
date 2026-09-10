<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\AgentMarketViewDTO;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Market\Agent\AgentFlowEngine;
use App\Service\Market\Agent\AgentPopulation;
use App\Service\Market\Agent\FundamentalistStrategy;
use App\Service\Market\Agent\IndexFundStrategy;
use App\Service\Market\Agent\InMemoryAgentStateStore;
use App\Service\Market\Agent\MarketMakerStrategy;
use App\Service\Market\Agent\MomentumStrategy;
use App\Service\Market\Flow\InMemoryOrderFlowStore;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\MarketEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The agent population driven against the real price process.
 *
 * Two claims, and the first is the one that governs the whole market layer: putting a simulated
 * institutional book into the market must not change how volatile the market is. Agent flow reaches the
 * price through the same impact function a player's fill does, so the diffusion gives back the same
 * measured variance and the SVJJ parameters keep meaning what they were calibrated to mean.
 *
 * The second is that the population is not inert. Capital has to actually move between beliefs, because
 * the switching IS the model — and an implementation where it silently cannot still runs, still allocates,
 * and still looks entirely correct from the outside. That failure happened here on the first pass.
 */
class AgentMarketDynamicsTest extends TestCase
{
    private const TICKS = 30000;
    private const WARMUP_TICKS = 4000;
    private const TICKS_PER_YEAR = 14400;
    private const BASELINE_VOLATILITY = 0.28;

    /** @var array<string, array{volatility: float, minShare: float, maxShare: float}> */
    private static array $runs = [];

    private function stock(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('AGT')
            ->setName('agent test')
            ->setSharesOutstanding('100000000')
            ->setPublicFloatPercentage('0.90')
            ->setVolatility((string) self::BASELINE_VOLATILITY)
            ->setCurrentVolatility((string) self::BASELINE_VOLATILITY)
            ->setPrice('100')
            ->setBeta('1.00')
            ->setJumpIntensity('2.00')
            ->setJumpVol('0.10');

        $stock->setTurnoverRatio(LiquidityEngine::structuralTurnoverRatio(self::BASELINE_VOLATILITY));
        $stock->setImpactVarianceEma(0.0);

        return $stock;
    }

    /**
     * Runs the price process forward with or without the agent population.
     *
     * Both scenarios walk the same random path: the agents are deterministic given the price, so enabling
     * them consumes no extra draws and the comparison measures the population rather than the seed.
     *
     * @return array{volatility: float, minShare: float, maxShare: float}
     */
    private function simulate(bool $agents, int $seed): array
    {
        $key = sprintf('%d|%d', $seed, (int) $agents);
        if (isset(self::$runs[$key])) {
            return self::$runs[$key];
        }

        mt_srand($seed);

        $math = new MathUtility();
        $engine = new MarketEngine($math);
        $liquidity = new LiquidityEngine($math);
        $orderFlow = new InMemoryOrderFlowStore();

        $agentEngine = new AgentFlowEngine(
            new AgentPopulation(),
            new InMemoryAgentStateStore(),
            $orderFlow,
            [new FundamentalistStrategy(), new MomentumStrategy(), new IndexFundStrategy(), new MarketMakerStrategy()]
        );

        $stock = $this->stock();
        $macro = new MacroStateDTO(policyRate: 0.04, equityRiskPremium: 0.045);

        $dt = 1.0 / self::TICKS_PER_YEAR;
        $impactPhi = exp(-$dt / FinancialConstants::IMPACT_VARIANCE_EMA_YEARS);
        $momentumPhi = exp(-$dt / 0.50);

        $price = 100.0;
        $volatility = self::BASELINE_VOLATILITY;
        $impactVariance = 0.0;
        $trend = 0.0;
        $returns = [];
        $shares = [];

        for ($tick = 0; $tick < self::TICKS; $tick++) {
            $stock->setPrice((string) $price);
            $stock->setCurrentVolatility((string) $volatility);
            $stock->setPriceMomentumTrend($trend);

            $result = $engine->calculateNextPrice(new MarketPricingContext(
                currentPrice: $price,
                currentVolatility: $volatility,
                longTermVolatility: self::BASELINE_VOLATILITY,
                earningsPerShare: 5.0,
                dt: $dt,
                lambda: 2.0,
                jumpVol: 0.10,
                beta: 1.0,
                marketZ: 0.0,
                sectorZ: 0.0,
                marketJumpMultiplier: 1.0,
                marketVol: 0.15,
                macroState: $macro,
                bookValuePerShare: 40.0,
                currentRoic: 0.12,
                roicTtm: 0.12,
                liveWacc: 0.08,
                baselineIndustryPE: 15.0,
                revenuePerShare: 50.0,
                businessModel: 'none',
                liveCostOfEquity: 0.10,
                orderFlowVariance: $impactVariance
            ));

            $nextPrice = $result['price'];
            $volatility = $result['next_volatility'];

            // Last tick's agent orders arrive through the ordinary impact channel.
            $impact = 0.0;
            $drained = $orderFlow->drain();

            if ($agents && ($drained['AGT'] ?? 0.0) !== 0.0) {
                $impact = $liquidity->permanentImpact($stock, $drained['AGT']);
                $nextPrice = max(0.01, $nextPrice * exp($impact));
            }

            $impactVariance = ($impactVariance * $impactPhi) + ((($impact * $impact) / $dt) * (1.0 - $impactPhi));

            $logReturn = log($nextPrice / $price);
            $trend = max(-0.5, min(0.5, ($trend * $momentumPhi) + $logReturn));

            if ($agents) {
                $traded = $agentEngine->trade(new AgentMarketViewDTO(
                    ticker: 'AGT',
                    price: $nextPrice,
                    perceivedFairValue: $result['perceived_fair_value'],
                    momentumTrend: $trend,
                    averageDailyVolume: $liquidity->averageDailyVolume($stock),
                    logReturn: $logReturn,
                    financialConditions: 0.0,
                    dt: $dt
                ));

                if ($tick >= self::WARMUP_TICKS) {
                    $shares[] = $traded['shares']['fundamentalist'] ?? 0.5;
                }
            }

            if ($tick >= self::WARMUP_TICKS) {
                $returns[] = $logReturn;
            }

            $price = $nextPrice;
        }

        $count = count($returns);
        $mean = array_sum($returns) / $count;

        $sumSquares = 0.0;
        foreach ($returns as $return) {
            $sumSquares += ($return - $mean) ** 2;
        }

        return self::$runs[$key] = [
            'volatility' => sqrt(($sumSquares / ($count - 1)) / $dt),
            'minShare' => $shares === [] ? 0.5 : min($shares),
            'maxShare' => $shares === [] ? 0.5 : max($shares),
        ];
    }

    /**
     * The governing invariant, applied to the last source of price movement in the plan.
     */
    public function testAddingTheAgentPopulationDoesNotChangeHowVolatileTheMarketIs(): void
    {
        foreach ([11, 22, 33] as $seed) {
            $without = $this->simulate(false, $seed)['volatility'];
            $with = $this->simulate(true, $seed)['volatility'];

            $this->assertEqualsWithDelta(
                $without,
                $with,
                0.0075,
                sprintf(
                    'Seed %d: agents moved realized volatility from %.2f%% to %.2f%%.',
                    $seed,
                    $without * 100,
                    $with * 100
                )
            );
        }
    }

    /**
     * And the population is genuinely switching, not sitting where it started.
     *
     * Asserted so the invariance above cannot pass for the wrong reason. An inert population trivially
     * changes nothing about volatility, and every other test in this class would still be green.
     */
    public function testCapitalActuallyMovesBetweenBeliefsOverARun(): void
    {
        foreach ([11, 22, 33] as $seed) {
            $run = $this->simulate(true, $seed);

            $swing = $run['maxShare'] - $run['minShare'];

            $this->assertGreaterThan(
                0.15,
                $swing,
                sprintf(
                    'Seed %d: the fundamentalist share only moved between %.2f and %.2f. The switching is inert.',
                    $seed,
                    $run['minShare'],
                    $run['maxShare']
                )
            );
        }
    }

    public function testNeitherBeliefEverTakesTheWholeMarket(): void
    {
        // A belief driven to a zero share holds nothing, earns nothing and can never recover, which would
        // leave the market permanently one-sided.
        foreach ([11, 22, 33] as $seed) {
            $run = $this->simulate(true, $seed);

            $this->assertGreaterThanOrEqual(FinancialConstants::AGENT_MIN_POPULATION_SHARE, $run['minShare']);
            $this->assertLessThanOrEqual(1.0 - FinancialConstants::AGENT_MIN_POPULATION_SHARE, $run['maxShare']);
        }
    }
}
