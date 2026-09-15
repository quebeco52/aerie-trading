<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\MacroStateDTO;
use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Repository\OptionContractRepository;
use App\Service\Market\BondPricingEngine;
use App\Service\Market\DealerGammaEngine;
use App\Service\Market\Flow\InMemoryOrderFlowStore;
use App\Service\Market\Gamma\InMemoryDealerGammaStore;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\OptionChainService;
use App\Service\Market\OptionDemandEngine;
use App\Service\Market\OptionDeskService;
use App\Service\Market\OptionPricingEngine;
use App\Service\Market\OptionSettlementEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\User\CashLedger;
use App\Tests\Support\MacroStateBuilder;
use App\Tests\Support\StockBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * What one sweep pass sends to the database.
 *
 * The pass reads the chain as rows and writes back as data, so the whole contract with the database is
 * visible on the connection: a mark for each HELD contract, one bulk statement for the public's book, and
 * nothing at all through the unit of work. Those are the statements asserted here.
 */
class OptionDeskSweepTest extends TestCase
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $sent = [];

    private function desk(array $rows, array $heldTickers): OptionDeskService
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn($heldTickers);
        $connection->method('fetchAllAssociative')->willReturn($rows);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->sent[] = [$sql, $params];

                return 1;
            }
        );

        $repository = $this->createStub(OptionContractRepository::class);
        $repository->method('findExpiring')->willReturn([]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturn($repository);

        $math = new MathUtility();
        $liquidity = new LiquidityEngine($math);

        return new OptionDeskService(
            $em,
            new OptionChainService($em, $liquidity),
            new OptionPricingEngine($math, new BondPricingEngine($math)),
            new OptionSettlementEngine($em, new CashLedger()),
            new OptionDemandEngine($liquidity),
            new DealerGammaEngine($this->gammaStore = new InMemoryDealerGammaStore(), $liquidity),
            new InMemoryOrderFlowStore()
        );
    }

    private InMemoryDealerGammaStore $gammaStore;

    private function underlying(int $id, float $price): Stock
    {
        $stock = StockBuilder::create('TST')
            ->withPrice($price)
            ->withSharesOutstanding(500_000_000)
            ->withVolatility(0.25)
            ->withCurrentVolatility(0.25)
            ->withJumpIntensity(1.0)
            ->withJumpVol(0.05)
            ->build();
        $stock->setTurnoverRatio(LiquidityEngine::structuralTurnoverRatio(0.25));
        $stock->setImpactVarianceEma(0.0);

        $property = new \ReflectionProperty(Stock::class, 'id');
        $property->setValue($stock, $id);

        return $stock;
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $ticker, int $stockId, string $type, float $strike, float $expiresAt): array
    {
        return [
            'id' => $id,
            'ticker' => $ticker,
            'stock_id' => $stockId,
            'option_type' => $type,
            'strike' => number_format($strike, 8, '.', ''),
            'expiry_serial' => 1,
            'expires_at_time' => $expiresAt,
            'listed_at_time' => 0.0,
            'open_interest' => 0,
            'structural_open_interest' => 0,
        ];
    }

    public function testAHeldContractIsMarkedByRowAndTheBookIsWrittenInBulk(): void
    {
        $macro = MacroStateDTO::fromMacroState(MacroStateBuilder::create()->build());

        // Below the listing minimum, so the pass lists nothing and the chain is whatever the rows say.
        $stock = $this->underlying(5, FinancialConstants::OPTION_LISTING_MIN_PRICE * 0.5);
        $spot = (float) $stock->getPrice();
        $expiry = $macro->totalTime + 0.25;

        $rows = [
            $this->row(101, 'TST-1C', 5, OptionContract::TYPE_CALL, $spot, $expiry),
            $this->row(102, 'TST-1P', 5, OptionContract::TYPE_PUT, $spot, $expiry),
            $this->row(103, 'TST-1C2', 5, OptionContract::TYPE_CALL, $spot * 1.1, $expiry),
        ];

        $desk = $this->desk($rows, ['TST-1P']);
        $result = $desk->sweep([$stock], $macro, 1.0 / OptionDeskService::SWEEPS_PER_YEAR, 0);

        $this->assertSame(1, $result['marked']);

        $marks = array_values(array_filter($this->sent, static fn (array $s): bool => str_contains($s[0], 'SET price = ?')));
        $books = array_values(array_filter($this->sent, static fn (array $s): bool => str_contains($s[0], 'structural_open_interest')));

        // Exactly the held contract is marked, with the eight mark values and its own id last.
        $this->assertCount(1, $marks);
        $this->assertSame(102, end($marks[0][1]));
        $this->assertCount(8, $marks[0][1]);
        $this->assertGreaterThan(0.0, (float) $marks[0][1][0], 'An at-the-money put must carry a premium.');

        // The public's book moved on the first pass (it opens flat), and it moved in ONE statement.
        $this->assertCount(1, $books);
        $this->assertStringStartsWith('UPDATE option_contracts t JOIN (SELECT ? AS id, ? AS structural_open_interest', $books[0][0]);
        $writtenIds = [];
        for ($i = 0; $i < count($books[0][1]); $i += 2) {
            $writtenIds[] = $books[0][1][$i];
            $this->assertGreaterThan(0, $books[0][1][$i + 1], 'A book that is written has moved off zero.');
        }
        $this->assertNotEmpty($writtenIds);
        $this->assertSame([], array_diff($writtenIds, [101, 102, 103]));

        // Nothing else reached the connection: no per-contract UPDATE for the unheld chain.
        $this->assertCount(2, $this->sent);

        // And the desk's exposure was re-measured off the same pass.
        $this->assertNotNull($this->gammaStore->read('TST'));
    }

    public function testAPassWhereNothingMovesWritesNothing(): void
    {
        $macro = MacroStateDTO::fromMacroState(MacroStateBuilder::create()->build());
        $stock = $this->underlying(5, FinancialConstants::OPTION_LISTING_MIN_PRICE * 0.5);

        // No rows: nothing held, nothing to evolve, nothing to write.
        $desk = $this->desk([], []);
        $result = $desk->sweep([$stock], $macro, 1.0 / OptionDeskService::SWEEPS_PER_YEAR, 0);

        $this->assertSame(0, $result['marked']);
        $this->assertSame([], $this->sent);
    }
}
