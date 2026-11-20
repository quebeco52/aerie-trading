<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\IndustryBetas;
use App\Data\Company\IndustryMarkets;
use App\Data\Company\IndustryMoats;
use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Market\Pricing\SeedRows;
use App\Service\Math\MacroConstants;
use App\Service\Math\Valuation;
use App\Service\Model\ModelParam;
use App\Service\Model\Sector\AssetManagementBusinessModel;
use App\Service\Model\Sector\BaseFinancialBusinessModel;
use App\Service\Model\Sector\BrokerageBusinessModel;
use App\Service\Model\Sector\ClearingHouseBusinessModel;
use App\Service\Model\Sector\HedgeFundBusinessModel;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\PrivateEquityBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Every insurer, broker, investment bank, clearinghouse and asset manager whose seed row states its character has its
 * opening books built by its model (resolveOpeningBooks()): it opens on its stated revenue, at its cost of equity plus
 * moat, with the reserves, leverage, cash and market share its model steers to.
 */
#[Group('unit')]
class InsurerAndFeeOpeningBooksTest extends TestCase
{
    /** Relative gap between the revenue a firm opens on and its stated revenue (1e-6). */
    private const REVENUE_TOLERANCE = 1.0e-6;

    private OpeningBoardBuilder $builder;

    protected function setUp(): void
    {
        // Median draws so OpeningBoardBuilder opens each manager at the typical intensity (1.0) the books are built
        // for.
        $math = new ScriptedDraws(
            generateStandardNormal: 0.0,
            generateUniform: 0.5,
            generateUniformBetween: static fn(float $min, float $max): float => ($min + $max) / 2.0,
        );
        $debtEngine = new DebtEngine($math, CorporateMetrics::getInstance());
        $this->builder = new OpeningBoardBuilder($math, $debtEngine, new MarketEngine($math), new AnchorStakeLedger());
    }

    /** @return array<string, array{array<string, mixed>}> Every insurer and fee financial stating its character. */
    public static function characterProvider(): array
    {
        $rows = [];
        foreach (InitialMarket::STOCKS as $row) {
            $strategy = Sectors::strategyFor($row['industry']);
            if ($strategy instanceof InsuranceBusinessModel
                || $strategy instanceof BrokerageBusinessModel
                || $strategy instanceof ClearingHouseBusinessModel
                || $strategy instanceof AssetManagementBusinessModel
            ) {
                $rows[$row['ticker']] = [$row];
            }
        }

        return $rows;
    }

    public function testEveryListedInsurerAndFeeFinancialStatesItsCharacter(): void
    {
        $rows = self::characterProvider();
        $this->assertCount(13, $rows, '3 insurers, 5 dealers/brokers, 1 clearinghouse and 4 asset managers.');
        foreach ($rows as $ticker => [$row]) {
            $this->assertArrayHasKey('revenue', $row, "{$ticker} states its revenue character.");
            $this->assertArrayNotHasKey('total_equity', $row, "{$ticker} drops hand-set equity.");
        }

        $handSet = [];
        foreach (InitialMarket::STOCKS as $row) {
            if (isset($row['total_equity'])) {
                $handSet[] = $row['ticker'];
            }
        }
        $this->assertSame(['BRKW'], $handSet, 'Only spheres still carry hand-set books.');
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('characterProvider')]
    public function testItOpensOnItsStatedRevenueAndMoatReturn(array $row): void
    {
        $stock = $this->open($row);
        $opening = new MacroStateDTO();
        $costOfEquity = Valuation::calculateCAPM($opening->equityRiskFreeRate, IndustryBetas::seedBetaFor($row), MacroConstants::BASE_EQUITY_RISK_PREMIUM);

        $this->assertEqualsWithDelta(1.0, (float) $stock->getTotalRevenue() / $row['revenue'], self::REVENUE_TOLERANCE, $row['ticker']);
        $this->assertEqualsWithDelta($costOfEquity + IndustryMoats::forRow($row)->spread(), (float) $stock->getBaselineRoe(), 1e-4, $row['ticker']);

        $expectedRetained = (float) ($row['retained_share'] ?? BaseFinancialBusinessModel::OPENING_RETAINED_SHARE) * (float) $stock->getTotalEquity();
        $this->assertEqualsWithDelta($expectedRetained, (float) $stock->getRetainedEarnings(), 1.0, $row['ticker']);
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('characterProvider')]
    public function testItOpensWithItsModelsBalanceSheetCashAndMarketShare(array $row): void
    {
        $stock = $this->open($row);
        $stock->setManagementIntensity(1.0);
        $strategy = Sectors::strategyFor($row['industry']);
        $equity = (float) $stock->getTotalEquity();
        $wholesale = (float) $stock->getWholesaleDebt();
        $deposits = (float) $stock->getCustomerDeposits();
        $revenue = (float) $stock->getTotalRevenue();
        $operatingBase = max($revenue, $equity);
        $manager = $stock->getManagementProfile();

        if ($strategy instanceof InsuranceBusinessModel) {
            $expectedFloat = $revenue * $strategy->resolveReserveToPremiumRatio($stock);
            $expectedDebt = $equity * $strategy->getLeverageTarget($strategy->getWholesaleLeverageLimit() * min(1.0, $manager->leverageBias()));
            $expectedCash = $manager->managedTargetCash($strategy, $operatingBase, $expectedFloat, $expectedDebt);
            $this->assertEqualsWithDelta($expectedFloat, $deposits, $expectedFloat * 1e-6, $row['ticker']);
            $this->assertEqualsWithDelta($expectedDebt, $wholesale, max(1.0, $expectedDebt * 1e-6), $row['ticker']);
            $this->assertEqualsWithDelta($expectedCash, (float) $stock->getCorporateTreasury(), $expectedCash * 1e-6, $row['ticker']);
            $expectedShare = (float) $row['revenue'] / (MacroConstants::DISTRICT_GDP_USD * IndustryMarkets::BY_INDUSTRY[$row['industry']]);
        } elseif ($strategy instanceof ClearingHouseBusinessModel) {
            $capitalRatio = $strategy->getTargetCapitalRatio($stock);
            $this->assertNotNull($capitalRatio);
            $this->assertSame(0.0, $wholesale, $row['ticker']);
            $this->assertEqualsWithDelta($capitalRatio, $equity / ($equity + $deposits), 1e-9, $row['ticker']);
            $expectedCash = $manager->managedTargetCash($strategy, $operatingBase, $deposits, 0.0);
            $this->assertEqualsWithDelta($expectedCash, (float) $stock->getCorporateTreasury(), $expectedCash * 1e-6, $row['ticker']);
            $expectedShare = (float) $row['revenue'] / (MacroConstants::DISTRICT_GDP_USD * IndustryMarkets::BY_INDUSTRY[$row['industry']]);
        } elseif ($strategy instanceof BrokerageBusinessModel) {
            $capitalRatio = $strategy->getTargetCapitalRatio($stock);
            $this->assertNotNull($capitalRatio);
            $this->assertSame(0.0, $deposits, $row['ticker']);
            $this->assertEqualsWithDelta($capitalRatio, $equity / ($equity + $wholesale), 1e-9, $row['ticker']);
            $expectedCash = $strategy->calculateTargetOperatingCash($operatingBase, 0.0, $wholesale);
            $this->assertEqualsWithDelta($expectedCash, (float) $stock->getCorporateTreasury(), $expectedCash * 1e-6, $row['ticker']);
            $expectedShare = (float) $row['revenue'] / (MacroConstants::DISTRICT_GDP_USD * IndustryMarkets::BY_INDUSTRY[$row['industry']]);
        } else {
            self::assertInstanceOf(AssetManagementBusinessModel::class, $strategy);
            $this->assertSame(0.0, $deposits, $row['ticker']);
            if ($strategy instanceof HedgeFundBusinessModel) {
                $expectedD2e = \App\Data\Company\StockModelTuning::get($row['ticker'], ModelParam::FundGrossLeverage, HedgeFundBusinessModel::FUND_GROSS_LEVERAGE);
            } elseif ($strategy instanceof PrivateEquityBusinessModel) {
                $expectedD2e = PrivateEquityBusinessModel::OPENING_DEBT_TO_EQUITY * min(1.0, $manager->leverageBias());
            } else {
                $limit = min(Sectors::metricsFor($row['industry'])['equity_limit'], $strategy->getWholesaleLeverageLimit());
                $expectedD2e = $strategy->getLeverageTarget($limit * min(1.0, $manager->leverageBias()));
            }
            $this->assertEqualsWithDelta($expectedD2e, $wholesale / $equity, 1e-6, $row['ticker']);
            $expectedCash = $strategy->calculateTargetOperatingCash($operatingBase, 0.0, $wholesale);
            $this->assertEqualsWithDelta($expectedCash, (float) $stock->getCorporateTreasury(), $expectedCash * 1e-6, $row['ticker']);
            $expectedShare = $strategy->getCapacityShare($stock);
            $this->assertNotNull($expectedShare);
        }

        $actualShare = CorporateMetrics::getInstance()->calculateScaleRatio($equity, 1.0, (float) $stock->getSamRatio());
        $this->assertEqualsWithDelta($expectedShare, $actualShare, 1e-6, $row['ticker']);
    }

    /** Untuned brokers and clearinghouses without a TargetCapitalRatio still resolve cleanly from their character. */
    public function testUntunedBrokerAndClearingHouseFallback(): void
    {
        $finc = $this->rawRow('FINC');
        $untunedBroker = ['ticker' => 'BRKX'] + $finc;
        $brokerStock = $this->open($untunedBroker);
        $this->assertEqualsWithDelta(1.0, (float) $brokerStock->getTotalRevenue() / $finc['revenue'], self::REVENUE_TOLERANCE);
        $this->assertGreaterThan(0.0, (float) $brokerStock->getWholesaleDebt());

        $acc = $this->rawRow('ACC');
        $untunedCcp = ['ticker' => 'CCPX'] + $acc;
        $ccpStock = $this->open($untunedCcp);
        $this->assertEqualsWithDelta(1.0, (float) $ccpStock->getTotalRevenue() / $acc['revenue'], self::REVENUE_TOLERANCE);
        $this->assertSame(0.0, (float) $ccpStock->getCustomerDeposits());
        $this->assertSame(0.0, (float) $ccpStock->getWholesaleDebt());
    }

    /**
     * A character row the model cannot build from is refused: a book stated beside it, a required key missing, an
     * input out of range, or a duplicate addressable_share on a capacity-capped asset manager.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function refusedCharacterProvider(): array
    {
        $byTicker = array_column(InitialMarket::STOCKS, null, 'ticker');
        $safe = $byTicker['SAFE'];
        $king = $byTicker['KING'];
        $acc = $byTicker['ACC'];
        $crow = $byTicker['CROW'];

        $missingSafe = $safe;
        unset($missingSafe['operating_margin']);
        $missingKing = $king;
        unset($missingKing['operating_margin']);

        return [
            'insurer with hand-set equity' => [['total_equity' => 1.0e11] + $safe],
            'insurer missing operating_margin' => [$missingSafe],
            'insurer stating addressable_share' => [['addressable_share' => 0.30] + $safe],
            'insurer retained share above 1' => [['retained_share' => 1.2] + $safe],
            'broker with hand-set debt' => [['wholesale_debt' => 1.0e11] + $king],
            'broker missing operating_margin' => [$missingKing],
            'broker stating addressable_share' => [['addressable_share' => 0.30] + $king],
            'broker zero revenue' => [['revenue' => 0.0] + $king],
            'clearinghouse with hand-set deposits' => [['customer_deposits' => 1.0e11] + $acc],
            'clearinghouse margin above 1' => [['operating_margin' => 1.0] + $acc],
            'clearinghouse stating addressable_share' => [['addressable_share' => 0.30] + $acc],
            'asset manager with hand-set roe' => [['baseline_roe' => 0.20] + $crow],
            'asset manager stating addressable_share' => [['addressable_share' => 0.30] + $crow],
            'asset manager moat off the ladder' => [['moat' => 'unassailable'] + $crow],
        ];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('refusedCharacterProvider')]
    public function testARowItCannotBuildFromIsRefused(array $row): void
    {
        $this->expectException(\DomainException::class);

        SeedRows::resolve($row);
    }

    /** A book changed on a resolved row stands: resolving it again does not overwrite it. */
    public function testABookChangedOnAResolvedRowStands(): void
    {
        foreach (['SAFE', 'KING', 'ACC', 'CROW'] as $ticker) {
            $row = SeedRows::for($ticker);
            $row['total_equity'] *= 1.1;

            $stock = $this->open($row);

            $this->assertEqualsWithDelta($row['total_equity'], (float) $stock->getTotalEquity(), 1.0, $ticker);
        }
    }

    /** @param array<string, mixed> $row */
    private function open(array $row): Stock
    {
        $stock = (new Stock())->setTicker($row['ticker']);
        $this->builder->open($stock, $row, new MacroStateDTO());

        return $stock;
    }

    /** @return array<string, mixed> */
    private function rawRow(string $ticker): array
    {
        foreach (InitialMarket::STOCKS as $row) {
            if ($row['ticker'] === $ticker) {
                return $row;
            }
        }

        self::fail("No seed row for {$ticker}.");
    }
}
