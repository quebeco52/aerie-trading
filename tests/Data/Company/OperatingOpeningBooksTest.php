<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\IndustryBetas;
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
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * An operating firm whose seed row states its character has its books built by its model
 * (StandardCorporateBusinessModel::resolveOpeningBooks()): it opens on its stated revenue, at the leverage and cash its
 * manager targets and the return its moat holds, so the engine has nothing to recapitalise or pay out in its first
 * years.
 */
#[Group('unit')]
class OperatingOpeningBooksTest extends TestCase
{
    /** Relative gap between the revenue a firm opens on and its stated revenue (1e-6). */
    private const REVENUE_TOLERANCE = 1.0e-6;

    /** How far its debt to equity may sit from its manager's target (0.01): the books are stored to the cent. */
    private const LEVERAGE_TOLERANCE = 0.01;

    private MathUtility $math;
    private DebtEngine $debtEngine;
    private OpeningBoardBuilder $builder;

    protected function setUp(): void
    {
        mt_srand(20261119);
        $this->math = new MathUtility();
        $this->debtEngine = new DebtEngine($this->math, CorporateMetrics::getInstance());
        $this->builder = new OpeningBoardBuilder($this->math, $this->debtEngine, new MarketEngine($this->math), new AnchorStakeLedger());
    }

    /**
     * @return array<string, array{array<string, mixed>}> Every operating firm whose row states its character, by
     *                                                    ticker.
     */
    public static function characterProvider(): array
    {
        $firms = [];
        foreach (InitialMarket::STOCKS as $row) {
            if (isset($row['revenue']) && Sectors::strategyFor($row['industry']) instanceof StandardCorporateBusinessModel) {
                $firms[$row['ticker']] = [$row];
            }
        }

        return $firms;
    }

    public function testTheBoardCarriesOperatingFirmsBuiltFromTheirCharacter(): void
    {
        $this->assertGreaterThanOrEqual(77, count(self::characterProvider()), 'Every operating firm but the trusts and the holding company states its character.');
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('characterProvider')]
    public function testItOpensOnItsStatedRevenue(array $row): void
    {
        $stock = $this->open($row);

        $this->assertEqualsWithDelta(1.0, (float) $stock->getTotalRevenue() / $row['revenue'], self::REVENUE_TOLERANCE, $row['ticker']);
    }

    /**
     * Its debt and leases over its equity are the leverage its manager recapitalises toward, so it is neither
     * under-levered nor past its lenders' covenant on the day it opens.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('characterProvider')]
    public function testItOpensAtTheLeverageItsManagerTargets(array $row): void
    {
        $stock = $this->open($row);
        $opening = new MacroStateDTO();
        $health = $this->debtEngine->analyzeDebtHealth($stock, $opening, (float) $stock->getTotalRevenue(), (float) $stock->getOperatingMargin());

        $this->assertEqualsWithDelta($health->leverageTarget, $health->debtToEquity, self::LEVERAGE_TOLERANCE, $row['ticker']);
        $this->assertTrue($health->hasLeverageHeadroom, "{$row['ticker']} opens inside its covenant.");
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('characterProvider')]
    public function testItOpensHoldingTheCashItsTreasuryTargets(array $row): void
    {
        $stock = $this->open($row);
        $target = $stock->getManagementProfile()->managedTargetCash(
            Sectors::strategyFor($row['industry']),
            max((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity(), FinancialConstants::MIN_OPERATING_BASE_CASH),
            0.0,
            (float) $stock->getWholesaleDebt(),
        );

        $this->assertEqualsWithDelta(1.0, (float) $stock->getCorporateTreasury() / $target, 1e-6, $row['ticker']);
    }

    /**
     * Its return is what its business must earn at the opening plus its moat, its capital spans the share of its
     * market its row states, and half its equity is retained earnings.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('characterProvider')]
    public function testItEarnsItsMoatOverItsCostOfCapitalOnTheShareOfItsMarketItStates(array $row): void
    {
        $stock = $this->open($row);
        $opening = new MacroStateDTO();
        $costOfCapital = Valuation::calculateCAPM($opening->equityRiskFreeRate, IndustryBetas::seedBetaFor($row), MacroConstants::BASE_EQUITY_RISK_PREMIUM);

        $this->assertEqualsWithDelta($costOfCapital + IndustryMoats::forRow($row)->spread(), (float) $stock->getBaselineRoic(), 1e-4, $row['ticker']);
        $this->assertEqualsWithDelta(
            $row['addressable_share'],
            CorporateMetrics::getInstance()->calculateScaleRatio($stock->getInvestedCapital(), 1.0, (float) $stock->getSamRatio()),
            1e-6,
            $row['ticker'],
        );
        $this->assertEqualsWithDelta(StandardCorporateBusinessModel::OPENING_RETAINED_SHARE, (float) $stock->getRetainedEarnings() / (float) $stock->getTotalEquity(), 1e-6, $row['ticker']);
    }

    /**
     * A row it cannot build from is refused, not opened on a guess: a book stated beside the character, an input
     * missing, or one out of its range.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function refusedCharacterProvider(): array
    {
        $grip = null;
        foreach (InitialMarket::STOCKS as $row) {
            if ($row['ticker'] === 'GRIP') {
                $grip = $row;
            }
        }
        self::assertNotNull($grip);
        $missing = $grip;
        unset($missing['addressable_share']);

        return [
            'a book beside the character' => [['total_equity' => 3.5e10] + $grip],
            'an input missing' => [$missing],
            'no margin to earn' => [['operating_margin' => 0.0] + $grip],
            'retained earnings past its equity' => [['retained_share' => 1.5] + $grip],
            'no revenue' => [['revenue' => 0.0] + $grip],
            'a moat off the ladder' => [['moat' => 'impregnable'] + $grip],
        ];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('refusedCharacterProvider')]
    public function testARowItCannotBuildFromIsRefused(array $row): void
    {
        $this->expectException(\DomainException::class);

        SeedRows::resolve($row);
    }

    /** A share of retained earnings the row states is the one the firm opens on. */
    public function testARetainedShareTheRowStatesStands(): void
    {
        $row = ['retained_share' => 0.2] + $this->rawRow('GRIP');

        $books = SeedRows::resolve($row);

        $this->assertEqualsWithDelta(0.2 * $books['total_equity'], $books['retained_earnings'], 1.0);
    }

    /** @param array<string, mixed> $row */
    private function open(array $row): Stock
    {
        $stock = (new Stock())->setTicker($row['ticker']);
        $this->builder->open($stock, SeedRows::for($row['ticker']), new MacroStateDTO());
        // The manager the targets are struck for: its style at the typical intensity, not this run's draw.
        $stock->setManagementIntensity(1.0);

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
        $this->fail("No seed row for {$ticker}.");
    }
}
