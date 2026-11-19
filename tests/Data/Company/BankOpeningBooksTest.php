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
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Market\Pricing\SeedRows;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Tests\Support\CorporateEngineFactory;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A lender whose seed row states its character has its books built by its model
 * (CommercialBankBusinessModel::resolveOpeningBooks()): it opens on the revenue, capital ratio, cash, return and market
 * share its row and its tuning state, and on the margin its own cost base leaves, so its first report files the
 * firm it opened as.
 */
#[Group('unit')]
class BankOpeningBooksTest extends TestCase
{
    use CorporateEngineFactory;

    /** Relative gap between the revenue a lender opens on and its stated revenue (1e-6). */
    private const REVENUE_TOLERANCE = 1.0e-6;

    /**
     * How far the first report's operating margin may sit from the one it opened at (1.5pp): its loan book's first
     * quarter of repricing moves it under a point.
     */
    private const FIRST_REPORT_MARGIN_TOLERANCE = 0.015;

    private OpeningBoardBuilder $builder;

    protected function setUp(): void
    {
        mt_srand(20261119);
        $math = new MathUtility();
        $this->builder = new OpeningBoardBuilder($math, new DebtEngine($math, CorporateMetrics::getInstance()), new MarketEngine($math), new AnchorStakeLedger());
    }

    /** @return array<string, array{array<string, mixed>}> Every lender whose row states its character, by ticker. */
    public static function characterProvider(): array
    {
        $lenders = [];
        foreach (InitialMarket::STOCKS as $row) {
            if (isset($row['revenue']) && Sectors::strategyFor($row['industry']) instanceof CommercialBankBusinessModel) {
                $lenders[$row['ticker']] = [$row];
            }
        }

        return $lenders;
    }

    public function testTheBoardCarriesLendersBuiltFromTheirCharacter(): void
    {
        $this->assertGreaterThanOrEqual(5, count(self::characterProvider()), 'LAKE, RIVR, PLVR, EGRT and BNTH state their character.');
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('characterProvider')]
    public function testItOpensOnItsStatedRevenue(array $row): void
    {
        $stock = $this->open($row);

        $this->assertEqualsWithDelta(1.0, (float) $stock->getTotalRevenue() / $row['revenue'], self::REVENUE_TOLERANCE, $row['ticker']);
    }

    /**
     * Its equity over equity and funding is the capital ratio it steers to, and its wholesale share of funding the one
     * its row states.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('characterProvider')]
    public function testItOpensAtItsCapitalRatioAndFundingMix(array $row): void
    {
        $books = SeedRows::for($row['ticker']);
        $funding = $books['customer_deposits'] + $books['wholesale_debt'];
        $target = Sectors::strategyFor($row['industry'])->getTargetCapitalRatio((new Stock())->setTicker($row['ticker']));

        $this->assertEqualsWithDelta($target, $books['total_equity'] / ($books['total_equity'] + $funding), 1e-9, $row['ticker']);
        $this->assertEqualsWithDelta($row['wholesale_funding_share'], $books['wholesale_debt'] / $funding, 1e-9, $row['ticker']);
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('characterProvider')]
    public function testItOpensHoldingTheCashItsTreasuryTargets(array $row): void
    {
        $stock = $this->open($row);
        $strategy = Sectors::strategyFor($row['industry']);
        $target = $strategy->calculateTargetOperatingCash(
            max((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity()),
            (float) $stock->getCustomerDeposits(),
            (float) $stock->getWholesaleDebt(),
        );

        $this->assertEqualsWithDelta(1.0, (float) $stock->getCorporateTreasury() / $target, 1e-6, $row['ticker']);
    }

    /**
     * Its return is its cost of equity at the opening plus its moat, and its equity fills the share of its market its
     * row states.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('characterProvider')]
    public function testItEarnsItsMoatOverItsCostOfEquityOnTheShareOfItsMarketItStates(array $row): void
    {
        $stock = $this->open($row);
        $opening = new MacroStateDTO();
        $costOfEquity = Valuation::calculateCAPM($opening->equityRiskFreeRate, IndustryBetas::seedBetaFor($row), MacroConstants::BASE_EQUITY_RISK_PREMIUM);

        $this->assertEqualsWithDelta($costOfEquity + IndustryMoats::forRow($row)->spread(), (float) $stock->getBaselineRoe(), 1e-4, $row['ticker']);
        $this->assertEqualsWithDelta(
            $row['addressable_share'],
            CorporateMetrics::getInstance()->calculateScaleRatio((float) $stock->getTotalEquity(), 1.0, (float) $stock->getSamRatio()),
            1e-6,
            $row['ticker'],
        );
    }

    /**
     * Its first report, with no shock in it and the economy where it opened, files the operating margin it opened at:
     * the margin is the one its cost base and through-the-cycle losses leave on its revenue, not a number typed beside
     * them.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('characterProvider')]
    public function testItsFirstReportFilesTheMarginItOpenedAt(array $row): void
    {
        $stock = $this->open($row);
        $opened = (float) $stock->getOperatingMargin();
        $math = new ScriptedDraws(generateUniform: 0.5, generateUniformBetween: static fn(float $min, float $max): float => ($min + $max) / 2.0);
        $engine = self::earningsEngine($math);
        $ticksPerYear = 360;

        $report = $engine->calculate($stock, new MacroStateDTO(), EarningsEngine::resolveReportingTick($row['ticker'], $ticksPerYear), $ticksPerYear);

        $this->assertNotNull($report, "{$row['ticker']} reports on its reporting tick.");
        $this->assertEqualsWithDelta($opened, (float) $stock->getReportedOperatingMargin(), self::FIRST_REPORT_MARGIN_TOLERANCE, $row['ticker']);
    }

    /**
     * A row it cannot build from is refused, not opened on a guess: a book stated beside the character, an input
     * missing, or one out of its range.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function refusedCharacterProvider(): array
    {
        $lake = null;
        foreach (InitialMarket::STOCKS as $row) {
            if ($row['ticker'] === 'LAKE') {
                $lake = $row;
            }
        }
        self::assertNotNull($lake);
        $missing = $lake;
        unset($missing['retained_share']);

        return [
            'a book beside the character' => [['total_equity' => 1.0e12] + $lake],
            'an input missing' => [$missing],
            'wholesale funding past all of it' => [['wholesale_funding_share' => 1.0] + $lake],
            'retained earnings past its equity' => [['retained_share' => 1.5] + $lake],
            'no revenue' => [['revenue' => 0.0] + $lake],
            'a moat off the ladder' => [['moat' => 'impregnable'] + $lake],
        ];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('refusedCharacterProvider')]
    public function testARowItCannotBuildFromIsRefused(array $row): void
    {
        $this->expectException(\DomainException::class);

        SeedRows::resolve($row);
    }

    /** A book changed on a resolved row is the book the firm opens on: resolving it again does not build over it. */
    public function testABookChangedOnAResolvedRowStands(): void
    {
        $row = SeedRows::for('LAKE');
        $row['total_equity'] *= 1.1;

        $stock = $this->open($row);

        $this->assertEqualsWithDelta($row['total_equity'], (float) $stock->getTotalEquity(), 1.0);
    }

    /** @param array<string, mixed> $row */
    private function open(array $row): Stock
    {
        $stock = (new Stock())->setTicker($row['ticker']);
        $this->builder->open($stock, $row, new MacroStateDTO());

        return $stock;
    }
}
