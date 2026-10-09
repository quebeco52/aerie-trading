<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\Company\Sectors;
use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Corporate\DebtEngine;
use App\Service\Model\Sector\AutoManufacturerBusinessModel;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\MiningBusinessModel;
use App\Service\Model\Sector\OilGasProducerBusinessModel;
use App\Service\Model\Sector\SteelManufacturingBusinessModel;
use App\Service\Model\Sector\UtilityBusinessModel;
use App\Service\View\EconomyExposureBuilder;
use PHPUnit\Framework\TestCase;

final class EconomyExposureBuilderTest extends TestCase
{
    public function testCostListsReadTheModelsOwnBasketLargestFirst(): void
    {
        $lists = $this->lists([
            $this->stock('AUTO', 'Auto Manufacturers'),
            $this->stock('MILL', 'Steel'),
        ]);

        $metals = $lists['metals']['Buy metals'];
        self::assertSame(['MILL', 'AUTO'], array_column($metals, 'ticker'));
        self::assertSame((new SteelManufacturingBusinessModel())->getInputCostExposures()['metals'], $metals[0]['value']);
        self::assertSame((new AutoManufacturerBusinessModel())->getInputCostExposures()['metals'], $metals[1]['value']);
    }

    public function testARefinerIsNotRankedOnItsBasketOfCostsOtherThanCrude(): void
    {
        $lists = $this->lists([
            $this->stock('REFN', 'Oil & Gas Refining & Marketing'),
            $this->stock('GRID', 'Utilities - Regulated Electric'),
        ]);

        self::assertSame(['GRID'], array_column($lists['gas']['Buy gas'], 'ticker'));
    }

    public function testProducersListTheirOwnRevenueMix(): void
    {
        $oil = $this->stock('WELL', 'Oil & Gas E&P');
        $mine = $this->stock('DIGR', 'Copper');
        $utility = $this->stock('GRID', 'Utilities - Regulated Electric');
        $lists = $this->lists([$oil, $mine, $utility]);

        $liquids = (new OilGasProducerBusinessModel())->resolveProductionProfile($oil)['liquids_share'];
        self::assertSame($liquids, $lists['oil']['Sell crude and liquids'][0]['value']);
        self::assertEqualsWithDelta(1.0 - $liquids, $lists['gas']['Sell gas or gas-priced coal'][0]['value'], 1e-12);
        self::assertSame((new MiningBusinessModel())->resolveProductMix($mine)['base_metals'], $lists['metals']['Sell metals'][0]['value']);

        $merchant = (new UtilityBusinessModel())->resolveWholesalePowerRevenueShare($utility);
        self::assertGreaterThan(0.0, $merchant);
        self::assertLessThan(1.0, $merchant);
        self::assertSame($merchant, $lists['power']['Sell power at market prices'][0]['value']);
    }

    public function testFloatingDebtIsRankedAgainstMarketValueAndLeavesLendersOut(): void
    {
        $light = $this->stock('LITE', 'Auto Manufacturers', debt: '1000000', floating: '0.5000');
        $heavy = $this->stock('HEVY', 'Steel', debt: '4000000', floating: '0.2500');
        $bank = $this->stock('BANK', 'Banks - Regional', debt: '90000000', floating: '0.9000');
        $lists = $this->lists([$light, $heavy, $bank]);

        $rows = $lists['rates']['Most floating-rate debt'];
        self::assertSame(['HEVY', 'LITE'], array_column($rows, 'ticker'));
        // 4,000,000 x 0.25 over a $100m market value.
        self::assertEqualsWithDelta(0.01, $rows[0]['value'], 1e-12);
        self::assertSame(0.25, $rows[0]['detail']);
    }

    public function testLendersAreRankedOnPropertyLoansAndBankruptFirmsAreLeftOut(): void
    {
        $bank = $this->stock('BANK', 'Banks - Regional');
        $failed = $this->stock('GONE', 'Banks - Regional');
        $failed->setIsBankrupt(true);
        $lists = $this->lists([$bank, $failed]);

        $rows = $lists['property']['Lenders with most property loans'];
        self::assertSame(['BANK'], array_column($rows, 'ticker'));
        self::assertSame((new CommercialBankBusinessModel())->resolvePropertyLoanShare($bank), $rows[0]['value']);
    }

    public function testEqualFiguresFallBackToMarketValueAndEachListIsCapped(): void
    {
        $stocks = [];
        foreach (range(1, EconomyExposureBuilder::FIRMS_PER_LIST + 2) as $i) {
            $stocks[] = $this->stock('CAR' . $i, 'Auto Manufacturers', price: (string) (10 * $i));
        }
        $rows = $this->lists($stocks)['currency']['Sales that compete on the exchange rate'];

        self::assertCount(EconomyExposureBuilder::FIRMS_PER_LIST, $rows);
        self::assertSame('CAR' . (EconomyExposureBuilder::FIRMS_PER_LIST + 2), $rows[0]['ticker']);
    }

    /**
     * Each list is only an exposure if the earnings or financing path reads the figure it ranks on: a basket nobody
     * applies, or a currency share no demand line uses, would put a firm on the page for a risk it does not carry.
     */
    public function testEveryRankedFigureIsReadWhereTheFirmEarnsOrBorrows(): void
    {
        foreach (array_keys(Sectors::BUSINESS_MODELS) as $id) {
            $model = Sectors::getBusinessModelStrategy($id);
            $source = self::classSource($model::class);

            if (defined($model::class . '::INPUT_COST_EXPOSURES')) {
                self::assertStringContainsString('->resolveInputCostDrag(', $source, $id . ' declares a cost basket it never applies');
            }
            if (defined($model::class . '::FX_REVENUE_EXPOSURE')) {
                self::assertStringContainsString('->resolveFxDemandShift(', $source, $id . ' declares a currency share no demand line reads');
            }
            if (!$model->isFinancial()) {
                self::assertStringContainsString('->getOperatingCyclicality(', $source, $id . ' is ranked on a cyclicality it never reads');
                $interest = new \ReflectionMethod($model, 'calculateInterestExpenseAndWholesaleRate');
                self::assertStringContainsString('->getFloatingDebtRatio()', self::methodSource($interest->getDeclaringClass()->getName(), $interest->getName()), $id . ' does not price its floating-rate debt');
            }
        }

        self::assertStringContainsString('->calculateInterestExpenseAndWholesaleRate(', self::methodSource(DebtEngine::class, 'calculateInterestExpense'));
        self::assertStringContainsString('->resolveLoanBookMix(', self::methodSource(CommercialBankBusinessModel::class, 'resolveConditionalCreditLossRate'));
        self::assertStringContainsString('->resolveProductionProfile(', self::methodSource(OilGasProducerBusinessModel::class, 'calculateSectorPhysics'));
        self::assertStringContainsString('->resolveProductMix(', self::methodSource(MiningBusinessModel::class, 'calculateSectorPhysics'));
        self::assertStringContainsString('->resolveRevenueMixParameters(', self::methodSource(UtilityBusinessModel::class, 'calculateSectorPhysics'));
    }

    /**
     * @param list<Stock> $stocks
     *
     * @return array<string, array<string, list<array{ticker: string, name: string, value: float, detail: ?float}>>> card key => list label => rows
     */
    private function lists(array $stocks): array
    {
        $builder = new EconomyExposureBuilder($this->createStub(StockRepository::class));
        $byCard = [];
        foreach ($builder->buildFromStocks($stocks) as $card) {
            foreach ($card['lists'] as $list) {
                $byCard[$card['key']][$list['label']] = $list['rows'];
            }
        }

        return $byCard;
    }

    private function stock(string $ticker, string $industry, string $debt = '0', string $floating = '0.3000', string $price = '100.00'): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker . ' Holdings');
        $stock->setIndustry($industry);
        $stock->setPrice($price);
        $stock->setSharesOutstanding('1000000');
        $stock->setWholesaleDebt($debt);
        $stock->setFloatingDebtRatio($floating);

        return $stock;
    }

    /**
     * The class, its parents and every trait they use: where an inherited call would live.
     *
     * @param class-string $class
     */
    private static function classSource(string $class): string
    {
        $source = '';
        $seen = [];
        $pending = [new \ReflectionClass($class)];
        while ($pending !== []) {
            $current = array_pop($pending);
            if (isset($seen[$current->getName()])) {
                continue;
            }
            $seen[$current->getName()] = true;
            $file = $current->getFileName();
            $source .= $file !== false ? (string) file_get_contents($file) : '';
            $parent = $current->getParentClass();
            if ($parent !== false) {
                $pending[] = $parent;
            }
            foreach ($current->getTraits() as $trait) {
                $pending[] = $trait;
            }
        }

        return $source;
    }

    /** @param class-string $class */
    private static function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file((string) $reflection->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }
}
