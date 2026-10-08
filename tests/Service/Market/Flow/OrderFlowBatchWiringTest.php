<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Flow;

use App\DTO\AgentMarketViewDTO;
use App\Service\Market\Agent\AgentFlowEngine;
use App\Service\Market\Agent\AgentPopulation;
use App\Service\Market\Agent\FundamentalistStrategy;
use App\Service\Market\Agent\InMemoryAgentStateStore;
use App\Service\Market\Option\DealerGammaEngine;
use App\Service\Market\Flow\OrderFlowStoreInterface;
use App\Service\Market\Option\InMemoryDealerGammaStore;
use App\Service\Market\Pricing\LiquidityEngine;
use App\Service\Market\Option\OptionDeskService;
use App\Service\Math\MathUtility;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The ticker's two per-name recorders write their order flow inside a batch.
 *
 * Outside one, RedisOrderFlowStore sends every record as its own synchronous round trip, and the store
 * cannot tell a loop from a single fill. So the batch is only as good as its callers: a recorder that stops
 * opening one still works, just a round trip per name slower, and nothing but this test would notice.
 */
class OrderFlowBatchWiringTest extends TestCase
{
    public function testTheAgentPopulationRecordsInsideOneBatchPerTick(): void
    {
        $flow = $this->spy();
        $engine = new AgentFlowEngine(new AgentPopulation(), new InMemoryAgentStateStore(), $flow, [new FundamentalistStrategy()], []);

        $engine->beginTick();
        // Far enough below fair value that the fundamentalists buy.
        $engine->trade(new AgentMarketViewDTO('AAA', 100.0, 130.0, 0.0, 1000000.0, 0.0, 0.0, 1.0 / 14400.0));
        $engine->trade(new AgentMarketViewDTO('BBB', 100.0, 130.0, 0.0, 1000000.0, 0.0, 0.0, 1.0 / 14400.0));
        $engine->endTick();

        $this->assertBracketed($flow->calls, 2);
    }

    public function testTheDealerHedgeRecordsInsideOneBatch(): void
    {
        $gamma = new InMemoryDealerGammaStore();
        $gamma->record('VANE', 10_000.0, 100.0);
        $gamma->record('OWLS', -4_000.0, 40.0);

        $stocks = [
            StockBuilder::create('VANE')->withPrice(101.0)->withSharesOutstanding(500_000_000)->withPublicFloatPercentage(1.0)->build(),
            StockBuilder::create('OWLS')->withPrice(41.0)->withSharesOutstanding(500_000_000)->withPublicFloatPercentage(1.0)->build(),
        ];

        $flow = $this->spy();
        $desk = (new \ReflectionClass(OptionDeskService::class))->newInstanceWithoutConstructor();
        $this->inject($desk, 'gammaEngine', new DealerGammaEngine($gamma, new LiquidityEngine(new MathUtility())));
        $this->inject($desk, 'orderFlow', $flow);

        $desk->hedge($stocks);

        $this->assertBracketed($flow->calls, 2);
    }

    /**
     * @param list<string> $calls
     */
    private function assertBracketed(array $calls, int $records): void
    {
        $this->assertSame(
            array_merge(['begin'], array_fill(0, $records, 'record'), ['commit']),
            $calls,
            'Every record falls between one beginBatch() and one commitBatch().'
        );
    }

    private function inject(object $target, string $property, object $value): void
    {
        (new \ReflectionProperty($target, $property))->setValue($target, $value);
    }

    /**
     * @return OrderFlowStoreInterface&object{calls: list<string>}
     */
    private function spy(): OrderFlowStoreInterface
    {
        return new class () implements OrderFlowStoreInterface {
            /** @var list<string> */
            public array $calls = [];

            public function record(string $ticker, float $signedQuantity): void
            {
                $this->calls[] = 'record';
            }

            public function drain(): array
            {
                return [];
            }

            public function beginBatch(): void
            {
                $this->calls[] = 'begin';
            }

            public function commitBatch(): void
            {
                $this->calls[] = 'commit';
            }
        };
    }
}
