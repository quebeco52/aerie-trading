<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Agent;

use App\DTO\AgentMarketViewDTO;
use App\Service\Market\Agent\AttentionRetailStrategy;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Attention-driven retail (Barber & Odean 2008).
 *
 * The properties that make this a different participant rather than a second momentum book: it reacts to
 * the SIZE of a move and not its sign, it reads volume against the name's own normal rather than in
 * absolute shares, and it never goes short.
 */
final class AttentionRetailStrategyTest extends TestCase
{
    private AttentionRetailStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new AttentionRetailStrategy();
    }

    private function view(
        float $logReturn = 0.0,
        float $abnormalVolume = 1.0,
        bool $hasNews = false,
        float $annualizedVolatility = 0.25,
        float $dt = 0.004,
    ): AgentMarketViewDTO {
        return new AgentMarketViewDTO(
            ticker: 'TEST',
            price: 100.0,
            perceivedFairValue: 100.0,
            momentumTrend: 0.0,
            averageDailyVolume: 1_000_000.0,
            logReturn: $logReturn,
            financialConditions: 0.0,
            dt: $dt,
            annualizedVolatility: $annualizedVolatility,
            abnormalVolume: $abnormalVolume,
            hasNews: $hasNews,
        );
    }

    /** One tick's standard deviation, which is the unit the return leg is measured in. */
    private function tickSigma(float $annualizedVolatility = 0.25, float $dt = 0.004): float
    {
        return $annualizedVolatility * sqrt($dt);
    }

    public function testAQuietNameIsHeldAtTheBaseShare(): void
    {
        self::assertEqualsWithDelta(
            FinancialConstants::AGENT_RETAIL_BASE_SHARE,
            $this->strategy->signal($this->view(), []),
            1e-9
        );
    }

    /**
     * The whole point of the model. A crash grabs exactly as much attention as a melt-up, and retail buys
     * into both — which is what separates this from momentum rather than duplicating it.
     */
    public function testAttentionIsOnTheSizeOfTheMoveAndNotItsSign(): void
    {
        $move = $this->tickSigma() * FinancialConstants::AGENT_RETAIL_RETURN_SIGMA;

        $up = $this->strategy->signal($this->view(logReturn: $move), []);
        $down = $this->strategy->signal($this->view(logReturn: -$move), []);

        self::assertEqualsWithDelta($up, $down, 1e-9);
        self::assertGreaterThan(FinancialConstants::AGENT_RETAIL_BASE_SHARE, $down, 'A crash is attention too.');
    }

    public function testTheReturnLegSaturatesAtTheConfiguredSigma(): void
    {
        $saturating = $this->tickSigma() * FinancialConstants::AGENT_RETAIL_RETURN_SIGMA;

        $atSaturation = $this->strategy->signal($this->view(logReturn: $saturating), []);
        $beyond = $this->strategy->signal($this->view(logReturn: $saturating * 4.0), []);

        self::assertEqualsWithDelta($atSaturation, $beyond, 1e-9, 'Attention is bounded; a ten-sigma move is not ten times the story.');
        self::assertEqualsWithDelta(
            FinancialConstants::AGENT_RETAIL_BASE_SHARE + FinancialConstants::AGENT_RETAIL_MAX_ATTENTION_TILT,
            $atSaturation,
            1e-9
        );
    }

    /**
     * The move is read against the name's own volatility. A three percent tick is unremarkable for a
     * speculative small cap and front-page news for a utility.
     */
    public function testTheSameMoveIsMoreAttentionOnAQuieterName(): void
    {
        $move = 0.02;

        $volatile = $this->strategy->signal($this->view(logReturn: $move, annualizedVolatility: 0.80), []);
        $quiet = $this->strategy->signal($this->view(logReturn: $move, annualizedVolatility: 0.10), []);

        self::assertGreaterThan($volatile, $quiet);
    }

    public function testAbnormalVolumeRaisesAttentionOnItsOwn(): void
    {
        $quiet = $this->strategy->signal($this->view(), []);
        $busy = $this->strategy->signal($this->view(abnormalVolume: FinancialConstants::AGENT_RETAIL_VOLUME_MULTIPLE), []);

        self::assertGreaterThan($quiet, $busy);
        self::assertEqualsWithDelta(
            FinancialConstants::AGENT_RETAIL_BASE_SHARE + FinancialConstants::AGENT_RETAIL_MAX_ATTENTION_TILT,
            $busy,
            1e-9
        );
    }

    public function testVolumeBelowNormalIsNotNegativeAttention(): void
    {
        self::assertEqualsWithDelta(
            FinancialConstants::AGENT_RETAIL_BASE_SHARE,
            $this->strategy->signal($this->view(abnormalVolume: 0.1), []),
            1e-9
        );
    }

    public function testNewsIsAttentionWithoutAnyMoveOrVolume(): void
    {
        $silent = $this->strategy->signal($this->view(), []);
        $reported = $this->strategy->signal($this->view(hasNews: true), []);

        self::assertGreaterThan($silent, $reported);
    }

    /**
     * A big move and heavy volume are one episode seen two ways. Summing them would count it twice and
     * would make an ordinary earnings tick look like a mania.
     */
    public function testTheTwoMarketLegsDoNotStackOnEachOther(): void
    {
        $sigma = $this->tickSigma();

        $returnOnly = $this->strategy->signal($this->view(logReturn: $sigma * 1.25), []);
        $volumeOnly = $this->strategy->signal($this->view(abnormalVolume: 2.0), []);
        $both = $this->strategy->signal($this->view(logReturn: $sigma * 1.25, abnormalVolume: 2.0), []);

        self::assertEqualsWithDelta(max($returnOnly, $volumeOnly), $both, 1e-9);
    }

    /** Retail is long-only here: an individual can only sell what they already hold. */
    public function testTheBookIsNeverShort(): void
    {
        foreach ([-10.0, -1.0, 0.0, 1.0, 10.0] as $move) {
            $signal = $this->strategy->signal($this->view(logReturn: $move, abnormalVolume: 50.0, hasNews: true), []);
            self::assertGreaterThanOrEqual(0.0, $signal);
            self::assertLessThanOrEqual(1.0, $signal);
        }
    }

    /**
     * Retail is a population, not a belief. Inside the discrete choice it would be competed out of
     * existence on realized profit, which is the one thing retail flow empirically never does.
     */
    public function testItStaysOutOfTheDiscreteChoice(): void
    {
        self::assertFalse($this->strategy->competesForCapital());
    }

    public function testADegenerateViewIsNotAnAttentionEpisode(): void
    {
        self::assertEqualsWithDelta(
            FinancialConstants::AGENT_RETAIL_BASE_SHARE,
            $this->strategy->signal($this->view(logReturn: 0.5, annualizedVolatility: 0.0), []),
            1e-9,
            'A name with no volatility measure yet has no scale to call a move extreme against.'
        );
        self::assertEqualsWithDelta(
            FinancialConstants::AGENT_RETAIL_BASE_SHARE,
            $this->strategy->signal($this->view(logReturn: 0.5, dt: 0.0), []),
            1e-9
        );
    }

    /**
     * Every wither must carry every field. This has already gone wrong once on this DTO — a wither dropped
     * the index-membership flag and the gate downstream had silently been true for its whole life — so it
     * is guarded rather than remembered.
     */
    public function testEveryWitherCarriesEveryFieldOfTheView(): void
    {
        $view = new AgentMarketViewDTO(
            ticker: 'WITH',
            price: 123.0,
            perceivedFairValue: 99.0,
            momentumTrend: 0.5,
            averageDailyVolume: 7.0,
            logReturn: 0.03,
            financialConditions: -1.5,
            dt: 0.004,
            riskFreeRate: 0.02,
            annualizedVolatility: 0.4,
            splitRatio: 4.0,
            marketLogMispricing: 0.1,
            passiveOwnershipMultiple: 2.5,
            abnormalVolume: 3.5,
            hasNews: true,
        );

        $properties = (new ReflectionClass(AgentMarketViewDTO::class))->getProperties();
        self::assertNotEmpty($properties);

        foreach ([
            'withMarketLogMispricing' => $view->withMarketLogMispricing(0.9),
            'withAnnualizedVolatility' => $view->withAnnualizedVolatility(0.77),
        ] as $wither => $copy) {
            foreach ($properties as $property) {
                $name = $property->getName();

                // The one field each wither exists to replace.
                if (($wither === 'withMarketLogMispricing' && $name === 'marketLogMispricing')
                    || ($wither === 'withAnnualizedVolatility' && $name === 'annualizedVolatility')) {
                    continue;
                }

                self::assertSame(
                    $property->getValue($view),
                    $property->getValue($copy),
                    "{$wither}() dropped {$name}."
                );
            }
        }
    }
}
