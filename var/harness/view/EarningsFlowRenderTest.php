<?php

// Runs StockController::earningsFlow against a stubbed report and writes its JSON for an offline Sankey render.
// php vendor/bin/phpunit var/harness/view/EarningsFlowRenderTest.php   (OUT env var; writes $OUT/earnings-flow.json)

use App\Controller\StockController;
use App\Entity\Stock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EarningsFlowRenderTest extends KernelTestCase
{
    public function testRender(): void
    {
        $out = getenv('OUT') ?: sys_get_temp_dir();
        self::bootKernel();

        // An industrial with two segments and a sliver of other income, in $ millions.
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
        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($result);
        $em = $this->createStub(EntityManagerInterface::class);
        $repo = new class($em, new \Doctrine\ORM\Mapping\ClassMetadata(Stock::class), $stock) extends EntityRepository {
            public function __construct(EntityManagerInterface $em, \Doctrine\ORM\Mapping\ClassMetadata $meta, private readonly Stock $stock)
            {
                parent::__construct($em, $meta);
            }

            public function findOneByTicker(string $ticker): Stock
            {
                return $this->stock;
            }
        };
        $em->method('getRepository')->willReturn($repo);
        $em->method('getConnection')->willReturn($conn);

        $controller = static::getContainer()->get(StockController::class);
        $response = $controller->earningsFlow(new Request(['ticker' => 'TEST']), $em);
        file_put_contents("{$out}/earnings-flow.json", (string) $response->getContent());
        self::assertSame(200, $response->getStatusCode());
    }
}
