<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Ticker;

use App\DTO\MacroStateDTO;
use App\Entity\Etf;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\Flow\InMemoryOrderFlowStore;
use App\Service\Market\Index\AuthorizedParticipant;
use App\Service\Market\Index\EtfTracker;
use App\Service\Market\Index\IndexCommittee;
use App\Service\Market\Index\IndexFundAccountant;
use App\Service\Market\Index\InMemoryIndexMembershipStore;
use App\Service\Market\Index\MarketIndex;
use App\Service\Market\Pricing\LiquidityEngine;
use App\Service\Market\Ticker\IndexFundPhase;
use App\Service\Math\MathUtility;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The fund phase's creation basket: money put into a fund lands on its constituents as orders in index weight,
 * and a fund struck at its net asset value sends nothing.
 */
final class IndexFundPhaseTest extends TestCase
{
    private const TICKS_PER_YEAR = 14400;

    /** A tick that is neither a reconstitution nor a distribution. */
    private const QUIET_TICK = 1;

    private InMemoryOrderFlowStore $orderFlow;

    /** @param float $creationValue What the fund's strike sends to the authorized participant. */
    private function phase(float $creationValue): IndexFundPhase
    {
        $etfTracker = $this->createStub(EtfTracker::class);
        $etfTracker->method('updateIndex')->willReturn([
            'ticker' => MarketIndex::benchmark()->value,
            'price' => 100.0,
            'nav' => 100.0,
            'premium' => 0.0,
            'creation_value' => $creationValue,
            'name' => 'benchmark fund',
            'is_etf' => true,
        ]);

        $this->orderFlow = new InMemoryOrderFlowStore();

        return new IndexFundPhase(
            new IndexCommittee(new InMemoryIndexMembershipStore(), $etfTracker, new LiquidityEngine(new MathUtility())),
            new IndexFundAccountant($this->createStub(EntityManagerInterface::class)),
            $etfTracker,
            new AuthorizedParticipant(),
            $this->orderFlow,
            $this->createStub(MarketEventPublisher::class),
        );
    }

    /** @return array{updates: list<array{ticker: string, price: float}>, float_caps: array<string, float>, half_spreads: array<string, float>, fund_flow: array<string, float>, dividend_points: array<string, float>} */
    private function board(): array
    {
        return [
            'updates' => [['ticker' => 'AAA', 'price' => 10.0], ['ticker' => 'BBB', 'price' => 50.0]],
            'float_caps' => ['AAA' => 300.0, 'BBB' => 100.0],
            'half_spreads' => ['AAA' => 0.001, 'BBB' => 0.001],
            'fund_flow' => [],
            'dividend_points' => [],
        ];
    }

    /** @return array<string, Etf> */
    private function funds(): array
    {
        $fund = (new Etf())->setTicker(MarketIndex::benchmark()->value)->setName('benchmark fund')->setPrice('100');

        return [MarketIndex::benchmark()->value => $fund];
    }

    /** @return array{updates: list<array<string, mixed>>, events: list<array<string, mixed>>, log: list<string>} */
    private function strike(IndexFundPhase $phase): array
    {
        return $phase->run($this->funds(), [], $this->board(), new MacroStateDTO(), self::QUIET_TICK, self::TICKS_PER_YEAR, 1.0 / self::TICKS_PER_YEAR, false);
    }

    public function testACreationBasketLandsOnTheConstituentsInIndexWeight(): void
    {
        $result = $this->strike($this->phase(1_000_000.0));

        // Weights 3:1 by float capitalisation, so $750k buys AAA at $10 and $250k buys BBB at $50.
        $orders = $this->orderFlow->drain();
        self::assertEqualsWithDelta(75_000.0, $orders['AAA'], 1e-6);
        self::assertEqualsWithDelta(5_000.0, $orders['BBB'], 1e-6);

        self::assertCount(1, $result['updates']);
        self::assertSame([], $result['events']);
    }

    public function testARedemptionSellsTheBasket(): void
    {
        $this->strike($this->phase(-400_000.0));

        $orders = $this->orderFlow->drain();
        self::assertEqualsWithDelta(-30_000.0, $orders['AAA'], 1e-6);
        self::assertEqualsWithDelta(-2_000.0, $orders['BBB'], 1e-6);
    }

    public function testAFundAtItsNetAssetValueSendsNoOrders(): void
    {
        $this->strike($this->phase(0.0));

        self::assertSame([], $this->orderFlow->drain());
    }
}
