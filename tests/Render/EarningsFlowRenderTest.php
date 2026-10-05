<?php

declare(strict_types=1);

namespace App\Tests\Render;

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
 * Runs StockController::earningsFlow against a stubbed report and writes its JSON (earnings-flow.json) for an offline
 * render of the earnings Sankey. Not in any suite: bin/render-pages runs it.
 */
final class EarningsFlowRenderTest extends KernelTestCase
{
    public function testRender(): void
    {
        self::bootKernel();

        // An industrial with two segments, in $ millions.
        $report = [
            'revenue' => 10000.0, 'operating_costs' => 7200.0, 'ebitda' => 2800.0, 'depreciation' => 600.0,
            'ebit' => 2200.0, 'interest_expense' => 250.0, 'interest_income' => 0.0, 'pre_tax_income' => 1950.0,
            'tax_paid' => 410.0, 'goodwill_impairment' => 0.0, 'capital_expenditures' => 900.0,
            'dividend_paid' => 500.0, 'stock_buybacks' => 300.0,
            'revenue_streams' => json_encode(['equipment' => 6200.0, 'aftermarket_services' => 3300.0]),
            'stream_details' => null,
        ];

        $stock = $this->createStub(Stock::class);
        $stock->method('getId')->willReturn(1);
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($report);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        /** @extends EntityRepository<Stock> */
        $repository = new class($entityManager, new ClassMetadata(Stock::class), $stock) extends EntityRepository {
            /** @param ClassMetadata<Stock> $meta */
            public function __construct(EntityManagerInterface $em, ClassMetadata $meta, private readonly Stock $stock)
            {
                parent::__construct($em, $meta);
            }

            public function findOneByTicker(string $ticker): Stock
            {
                return $this->stock;
            }
        };
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('getConnection')->willReturn($connection);

        $controller = static::getContainer()->get(StockController::class);
        $response = $controller->earningsFlow(new Request(['ticker' => 'TEST']), $entityManager);
        file_put_contents(OfflinePage::outDir() . '/earnings-flow.json', (string) $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
    }
}
