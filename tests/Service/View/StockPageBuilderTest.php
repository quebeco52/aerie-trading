<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\DTO\MacroStateDTO;
use App\Entity\Etf;
use App\Entity\Stock;
use App\Repository\EtfEventRepository;
use App\Repository\StockEventRepository;
use App\Service\Macro\MacroStateProvider;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\PriceChangeFeed;
use App\Service\Market\SecuritiesLendingDesk;
use App\Service\Math\MathUtility;
use App\Service\View\CompanySnapshotBuilder;
use App\Service\View\EtfCompositionBuilder;
use App\Service\View\IndustryPositionBuilder;
use App\Service\View\OptionChainBuilder;
use App\Service\View\PeerTableBuilder;
use App\Service\View\StockPageBuilder;
use App\Service\View\ViewerPositionBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The page is rendered by one Twig template for both instruments, so the payload is a contract: a
 * key the template reads and a branch does not supply is a runtime error on that instrument only,
 * which is exactly the failure a controller action of three hundred lines made easy to introduce.
 */
#[AllowMockObjectsWithoutExpectations]
class StockPageBuilderTest extends TestCase
{
    /**
     * Every key stock/index.html.twig is handed, as the page supplied them before the composition
     * was extracted from the controller. Spelled out here rather than derived, so this fails if a
     * branch quietly stops supplying one.
     *
     * @var list<string>
     */
    private const TEMPLATE_KEYS = [
        'advShares', 'allAssets', 'analystTargets', 'asset', 'availableToBorrow', 'borrowFee',
        'businessModel', 'changePercent', 'components', 'dividendYield', 'economic_cycle', 'events',
        'generalInfo', 'halfSpread', 'indexFacts', 'industry', 'investedCapital', 'isEtf', 'isFinancial', 'lifecycleStage',
        'lifecycleStages', 'macro', 'management', 'marketCap', 'marketShare', 'openOrders',
        'optionDealerGamma', 'optionDealerGammaPerPercent', 'optionExpiries', 'optionMultiplier',
        'optionOpenInterest', 'optionsListed', 'optionsReason', 'peRatio', 'peers',
        'pieData', 'pieLabels', 'quote', 'sharesMap', 'shortUtilization', 'targetPE', 'ticksPerYear',
        'userAvgCost', 'userDividendIncome', 'userQuantity', 'userTrades', 'userUnrealizedPnL',
        'userUnrealizedPnLPercent',
    ];

    private const TICKS_PER_YEAR = 14400;

    /** @var array<string, mixed> */
    private array $positionKeys = [
        'userQuantity' => 0,
        'userAvgCost' => 0.0,
        'userUnrealizedPnL' => 0.0,
        'userUnrealizedPnLPercent' => 0.0,
        'userDividendIncome' => 0.0,
        'openOrders' => [],
        'userTrades' => [],
    ];

    private function builder(): StockPageBuilder
    {
        $macroStateProvider = $this->createMock(MacroStateProvider::class);
        $macroStateProvider->method('liveState')->willReturn(new MacroStateDTO());

        $companySnapshot = $this->createMock(CompanySnapshotBuilder::class);
        $companySnapshot->method('build')->willReturn([
            'isFinancial' => false,
            'businessModel' => 'standard_corporate',
            'marketCap' => 1000.0,
            'peRatio' => 12.5,
            'targetPE' => 20.0,
            'investedCapital' => 500.0,
            'lifecycleStage' => null,
            'dividendYield' => 0.01,
            'analystTargets' => ['consensus' => 110.0],
        ]);

        $etfComposition = $this->createMock(EtfCompositionBuilder::class);
        $etfComposition->method('build')->willReturn([
            'allAssets' => [],
            'pieLabels' => [],
            'pieData' => [],
            'sharesMap' => [],
            'components' => [],
            'indexFacts' => ['ticker' => 'LBI'],
        ]);

        $peerTable = $this->createMock(PeerTableBuilder::class);
        $peerTable->method('build')->willReturn([]);

        $industryPosition = $this->createMock(IndustryPositionBuilder::class);
        $industryPosition->method('build')->willReturn(['marketShare' => 0.02, 'industry' => ['tracked' => true]]);

        $viewerPosition = $this->createMock(ViewerPositionBuilder::class);
        $viewerPosition->method('build')->willReturn($this->positionKeys);

        $optionChain = $this->createMock(OptionChainBuilder::class);
        $optionChain->method('build')->willReturn([
            'optionsListed' => false,
            'optionsReason' => 'No class open on this name.',
            'optionExpiries' => [],
            'optionDealerGamma' => 0.0,
            'optionDealerGammaPerPercent' => 0.0,
            'optionOpenInterest' => 0,
            'optionMultiplier' => 100,
        ]);

        return new StockPageBuilder(
            $macroStateProvider,
            $companySnapshot,
            $etfComposition,
            $peerTable,
            $industryPosition,
            $viewerPosition,
            $this->createMock(StockEventRepository::class),
            $this->createMock(EtfEventRepository::class),
            $this->createMock(PriceChangeFeed::class),
            // Final by design, so the real ones stand in; both are pure calculators over the entity.
            new LiquidityEngine(new MathUtility()),
            new SecuritiesLendingDesk(),
            $optionChain,
            self::TICKS_PER_YEAR,
        );
    }

    private function stock(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setName('Lakeside Industrial');
        $stock->setPrice('100.00000000');
        $stock->setSharesOutstanding('1000000');

        return $stock;
    }

    public function testACompanyPageSuppliesEveryTemplateKey(): void
    {
        $payload = $this->builder()->build($this->stock(), 'LAKE', null);

        foreach (self::TEMPLATE_KEYS as $key) {
            $this->assertArrayHasKey($key, $payload, "The company page does not supply '{$key}'.");
        }
    }

    public function testTheFundPageSuppliesEveryTemplateKey(): void
    {
        $payload = $this->builder()->build(new Etf(), 'LBI', null);

        foreach (self::TEMPLATE_KEYS as $key) {
            $this->assertArrayHasKey($key, $payload, "The fund page does not supply '{$key}'.");
        }
    }

    /**
     * Neither branch may add a key the other lacks: the template would then read a value on one
     * instrument that is simply absent on the other.
     */
    public function testBothInstrumentsSupplyTheSameKeys(): void
    {
        $company = array_keys($this->builder()->build($this->stock(), 'LAKE', null));
        $fund = array_keys($this->builder()->build(new Etf(), 'LBI', null));

        sort($company);
        sort($fund);

        $this->assertSame($fund, $company);
        $this->assertSame(self::TEMPLATE_KEYS, $company, 'The payload gained or lost a key.');
    }

    /** Who runs the company's capital: the archetype and its strength, without the dials behind them. */
    public function testTheCompanyPageCarriesItsManagementAndTheFundDoesNot(): void
    {
        $management = $this->builder()->build($this->stock(), 'LAKE', null)['management'];

        $this->assertIsArray($management);
        $this->assertNotSame('', $management['label']);
        $this->assertNotSame('', $management['mandate']);
        $this->assertContains($management['conviction'], ['nominal', 'characteristic', 'pronounced']);
        $this->assertGreaterThanOrEqual(0.0, $management['tenureYears']);

        $this->assertNull(
            $this->builder()->build(new Etf(), 'LBI', null)['management'],
            'A fund has no board and nobody allocating its capital.'
        );
    }

    public function testTheFundIsNotTreatedAsABorrowableCompany(): void
    {
        $payload = $this->builder()->build(new Etf(), 'LBI', null);

        $this->assertTrue($payload['isEtf']);
        $this->assertNull($payload['analystTargets'], 'A fund has no company to put a target on.');
        $this->assertSame([], $payload['peers']);
        $this->assertSame(0.0, $payload['borrowFee']);
        $this->assertSame(0.0, $payload['availableToBorrow']);
    }

    public function testACompanyGetsNoFundComposition(): void
    {
        $payload = $this->builder()->build($this->stock(), 'LAKE', null);

        $this->assertFalse($payload['isEtf']);
        $this->assertSame([], $payload['components'], 'Only the fund has constituents.');
        $this->assertSame([], $payload['pieLabels']);
        $this->assertNotNull($payload['analystTargets']);
    }

    /**
     * A delisted company keeps its page, but the header must print the change as unknown rather than
     * as a flat day that did not move.
     */
    public function testADelistedCompanyReportsNoPriceChange(): void
    {
        $stock = $this->stock();
        $stock->setIsBankrupt(true);

        $payload = $this->builder()->build($stock, 'LAKE', null);

        $this->assertNull($payload['changePercent']);
    }

    public function testTheTickRateReachesTheTemplate(): void
    {
        $payload = $this->builder()->build($this->stock(), 'LAKE', null);

        $this->assertSame(self::TICKS_PER_YEAR, $payload['ticksPerYear']);
    }
}
