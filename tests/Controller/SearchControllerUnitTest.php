<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\SearchController;
use App\Entity\Bond;
use App\Entity\Etf;
use App\Entity\Stock;
use App\Repository\BondRepository;
use App\Repository\EtfRepository;
use App\Repository\StockRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class SearchControllerUnitTest extends TestCase
{
    private StockRepository&MockObject $stockRepository;
    private EtfRepository&MockObject $etfRepository;
    private BondRepository&MockObject $bondRepository;
    private SearchController $controller;

    protected function setUp(): void
    {
        $this->stockRepository = $this->createMock(StockRepository::class);
        $this->etfRepository = $this->createMock(EtfRepository::class);
        $this->bondRepository = $this->createMock(BondRepository::class);

        $this->controller = new SearchController();
    }

    public function testAutocompleteReturnsEmptyArrayOnEmptyQuery(): void
    {
        $request = new Request(['q' => '']);
        $response = $this->controller->autocomplete(
            $request,
            $this->stockRepository,
            $this->etfRepository,
            $this->bondRepository
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], json_decode((string) $response->getContent(), true));
    }

    public function testAutocompleteAggregatesStocksEtfsAndBonds(): void
    {
        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setName('Lakeside Industrial');

        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Skein Lakebird 30');

        $bond = new Bond();
        $bond->setTicker('GB-10Y-09Q1');
        $bond->setName('10-Year District Bond');

        $this->stockRepository->method('searchByTickerOrName')->willReturn([$stock]);
        $this->etfRepository->method('searchByTickerOrName')->willReturn([$etf]);
        $this->bondRepository->method('searchByTickerOrName')->willReturn([$bond]);

        $request = new Request(['q' => 'lake']);
        $response = $this->controller->autocomplete(
            $request,
            $this->stockRepository,
            $this->etfRepository,
            $this->bondRepository
        );

        $data = json_decode((string) $response->getContent(), true);
        $this->assertCount(3, $data);

        $this->assertSame('LAKE', $data[0]['ticker']);
        $this->assertSame('Stock', $data[0]['type']);
        $this->assertSame('/stock/LAKE', $data[0]['url']);

        $this->assertSame('LBI', $data[1]['ticker']);
        $this->assertSame('Index', $data[1]['type']);
        $this->assertSame('/stock/LBI', $data[1]['url']);

        $this->assertSame('GB-10Y-09Q1', $data[2]['ticker']);
        $this->assertSame('Bond', $data[2]['type']);
        $this->assertSame('/bond/GB-10Y-09Q1', $data[2]['url']);
    }

    public function testSearchRedirectsToStockPageOnExactStockMatch(): void
    {
        $stock = new Stock();
        $stock->setTicker('LAKE');

        $this->stockRepository->method('findOneByTicker')->willReturn($stock);

        $request = new Request(['q' => 'lake']);
        $response = $this->controller->search(
            $request,
            $this->stockRepository,
            $this->etfRepository,
            $this->bondRepository
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/stock/LAKE', $response->getTargetUrl());
    }

    public function testSearchRedirectsToStockPageOnExactEtfMatch(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');

        $this->stockRepository->method('findOneByTicker')->willReturn(null);
        $this->etfRepository->method('findOneByTicker')->willReturn($etf);

        $request = new Request(['q' => 'LBI']);
        $response = $this->controller->search(
            $request,
            $this->stockRepository,
            $this->etfRepository,
            $this->bondRepository
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/stock/LBI', $response->getTargetUrl());
    }

    public function testSearchRedirectsToBondPageOnExactBondMatch(): void
    {
        $bond = new Bond();
        $bond->setTicker('GB-10Y-09Q1');
        $bond->setStatus(Bond::STATUS_ACTIVE);

        $this->stockRepository->method('findOneByTicker')->willReturn(null);
        $this->etfRepository->method('findOneByTicker')->willReturn(null);
        $this->bondRepository->method('findOneByTicker')->willReturn($bond);

        $request = new Request(['q' => 'gb-10y-09q1']);
        $response = $this->controller->search(
            $request,
            $this->stockRepository,
            $this->etfRepository,
            $this->bondRepository
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/bond/GB-10Y-09Q1', $response->getTargetUrl());
    }

    public function testSearchRedirectsHomeOnEmptyQuery(): void
    {
        $request = new Request(['q' => '   ']);
        $response = $this->controller->search(
            $request,
            $this->stockRepository,
            $this->etfRepository,
            $this->bondRepository
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/', $response->getTargetUrl());
    }
}
