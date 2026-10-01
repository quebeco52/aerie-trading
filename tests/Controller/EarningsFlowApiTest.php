<?php

namespace App\Tests\Controller;

use App\Controller\StockController;
use App\Entity\Stock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The earnings-flow Sankey's node list: every node names its role for sankey_controller.js to colour, every link
 * runs between listed nodes, and a node nothing flows through this quarter is left out. Runs the action on a
 * stubbed report, so it needs no database.
 */
final class EarningsFlowApiTest extends KernelTestCase
{
    private const ROLES = ['income', 'profit', 'cost', 'noncash', 'payout'];

    public function testEveryNodeCarriesARoleAndEveryLinkJoinsListedNodes(): void
    {
        $flow = $this->earningsFlow(['revenue_streams' => json_encode(['equipment' => 6200.0, 'aftermarket_services' => 3300.0])]);

        $names = array_column($flow['nodes'], 'name');
        foreach ($flow['nodes'] as $node) {
            self::assertContains($node['role'] ?? null, self::ROLES, "{$node['name']} has no known role");
            self::assertArrayNotHasKey('itemStyle', $node, 'colours come from the theme, not the API');
        }
        foreach ($flow['links'] as $link) {
            self::assertContains($link['source'], $names);
            self::assertContains($link['target'], $names);
        }
        self::assertContains('Equipment revenue', $names);
        self::assertContains('Other and interest income', $names, 'the 500 the segments do not cover balances the revenue node');
    }

    public function testANodeWithNothingFlowingThroughItIsLeftOut(): void
    {
        $names = array_column($this->earningsFlow()['nodes'], 'name');

        self::assertNotContains('Goodwill impairment', $names, 'no impairment this quarter');
        self::assertNotContains('External funding', $names, 'the cash generated covers every use');
        self::assertContains('Dividends', $names);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{nodes: list<array<string, mixed>>, links: list<array<string, mixed>>}
     */
    private function earningsFlow(array $overrides = []): array
    {
        self::bootKernel();

        // An industrial in $ millions: costs, depreciation, interest and tax leave 1,540 of net income, which
        // covers capital spending, dividends and buybacks without outside money.
        $report = $overrides + [
            'revenue' => 10000.0, 'operating_costs' => 7200.0, 'ebitda' => 2800.0, 'depreciation' => 600.0,
            'ebit' => 2200.0, 'interest_expense' => 250.0, 'interest_income' => 0.0, 'pre_tax_income' => 1950.0,
            'tax_paid' => 410.0, 'goodwill_impairment' => 0.0, 'capital_expenditures' => 900.0,
            'dividend_paid' => 500.0, 'stock_buybacks' => 300.0, 'revenue_streams' => null, 'stream_details' => null,
        ];

        $stock = $this->createStub(Stock::class);
        $stock->method('getId')->willReturn(1);
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($report);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $repository = new /** @extends EntityRepository<Stock> */ class ($entityManager, new ClassMetadata(Stock::class), $stock) extends EntityRepository {
            /** @param ClassMetadata<Stock> $metadata */
            public function __construct(EntityManagerInterface $entityManager, ClassMetadata $metadata, private readonly Stock $stock)
            {
                parent::__construct($entityManager, $metadata);
            }

            public function findOneByTicker(string $ticker): Stock
            {
                return $this->stock;
            }
        };
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('getConnection')->willReturn($connection);

        $response = static::getContainer()->get(StockController::class)->earningsFlow(new Request(['ticker' => 'TEST']), $entityManager);

        /** @var array{nodes: list<array<string, mixed>>, links: list<array<string, mixed>>} */
        return json_decode((string) $response->getContent(), true);
    }
}
