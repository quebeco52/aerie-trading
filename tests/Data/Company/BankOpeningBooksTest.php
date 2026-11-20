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
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Market\Pricing\SeedRows;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\ShadowBankBusinessModel;
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

    /**
     * Most of a lender's through-the-cycle credit charge its first, median year may spare (15%): the loss
     * distribution is right-skewed (Vasicek), so its median year loses less than its mean. Measured 8-9% on every book.
     */
    private const MEDIAN_YEAR_LOSS_SPARED = 0.15;

    private DebtEngine $debtEngine;
    private OpeningBoardBuilder $builder;

    protected function setUp(): void
    {
        mt_srand(20261119);
        $math = new MathUtility();
        $this->debtEngine = new DebtEngine($math, CorporateMetrics::getInstance());
        $this->builder = new OpeningBoardBuilder($math, $this->debtEngine, new MarketEngine($math), new AnchorStakeLedger());
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
        $this->assertGreaterThanOrEqual(8, count(self::characterProvider()), 'Every lender, card lender and shadow bank states its character.');
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('characterProvider')]
    public function testItOpensOnItsStatedRevenue(array $row): void
    {
        $stock = $this->open($row);

        $this->assertEqualsWithDelta(1.0, (float) $stock->getTotalRevenue() / $row['revenue'], self::REVENUE_TOLERANCE, $row['ticker']);
    }

    /**
     * Its equity over equity and funding is the capital ratio it steers to; a lender with none (a card lender) opens at
     * the debt and leases over equity its manager recapitalises toward, inside its covenant.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('characterProvider')]
    public function testItOpensAtItsCapitalRatioOrItsLeverageTarget(array $row): void
    {
        $books = SeedRows::for($row['ticker']);
        $target = Sectors::strategyFor($row['industry'])->getTargetCapitalRatio((new Stock())->setTicker($row['ticker']));
        if ($target !== null) {
            $funding = $books['customer_deposits'] + $books['wholesale_debt'];
            $this->assertEqualsWithDelta($target, $books['total_equity'] / ($books['total_equity'] + $funding), 1e-9, $row['ticker']);

            return;
        }

        $stock = $this->open($books);
        // The manager the target is struck for: its style at the typical intensity, not this run's draw.
        $stock->setManagementIntensity(1.0);
        $health = $this->debtEngine->analyzeDebtHealth($stock, new MacroStateDTO(), (float) $stock->getTotalRevenue(), (float) $stock->getOperatingMargin());
        $this->assertEqualsWithDelta($health->leverageTarget, $health->debtToEquity, 0.01, $row['ticker']);
        $this->assertTrue($health->hasLeverageHeadroom, "{$row['ticker']} opens inside its covenant.");
    }

    /**
     * Its wholesale share of funding is the one its row states; a shadow bank takes no deposits, so all of it.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('characterProvider')]
    public function testItOpensOnItsFundingMix(array $row): void
    {
        $books = SeedRows::for($row['ticker']);
        $expected = Sectors::strategyFor($row['industry']) instanceof ShadowBankBusinessModel ? 1.0 : $row['wholesale_funding_share'];

        $this->assertEqualsWithDelta($expected, $books['wholesale_debt'] / ($books['customer_deposits'] + $books['wholesale_debt']), 1e-9, $row['ticker']);
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

        $expectedShare = (float) $row['revenue'] / (MacroConstants::DISTRICT_GDP_USD * IndustryMarkets::BY_INDUSTRY[$row['industry']]);
        $this->assertEqualsWithDelta($costOfEquity + IndustryMoats::forRow($row)->spread(), (float) $stock->getBaselineRoe(), 1e-4, $row['ticker']);
        $this->assertEqualsWithDelta(
            $expectedShare,
            CorporateMetrics::getInstance()->calculateScaleRatio((float) $stock->getTotalEquity(), 1.0, (float) $stock->getSamRatio()),
            1e-6,
            $row['ticker'],
        );
    }

    /**
     * Its first report, with no shock in it and the economy where it opened, files the operating margin it opened at:
     * the margin is the one its cost base and through-the-cycle losses leave on its revenue, not a number typed beside
     * them. A median year loses less than the cycle's mean, so the margin may run above it by the share of that charge
     * the year spares. A shadow bank's row states its margin before credit losses, which its fee yield is struck on, so
     * its report is read against that margin less its through-the-cycle charge.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('characterProvider')]
    public function testItsFirstReportFilesTheMarginItOpenedAt(array $row): void
    {
        $stock = $this->open($row);
        $strategy = Sectors::strategyFor($row['industry']);
        self::assertInstanceOf(CommercialBankBusinessModel::class, $strategy);
        $creditCharge = $strategy->resolveThroughTheCycleCreditProvision($stock, $strategy->resolveEarningAssets($stock)) / (float) $stock->getTotalRevenue();
        $opened = (float) $stock->getOperatingMargin() - ($strategy instanceof ShadowBankBusinessModel ? $creditCharge : 0.0);
        $math = new ScriptedDraws(generateUniform: 0.5, generateUniformBetween: static fn(float $min, float $max): float => ($min + $max) / 2.0);
        $engine = self::earningsEngine($math);
        $ticksPerYear = 360;

        $report = $engine->calculate($stock, new MacroStateDTO(), EarningsEngine::resolveReportingTick($row['ticker'], $ticksPerYear), $ticksPerYear);

        $this->assertNotNull($report, "{$row['ticker']} reports on its reporting tick.");
        $filed = (float) $stock->getReportedOperatingMargin();
        $this->assertGreaterThanOrEqual($opened - self::FIRST_REPORT_MARGIN_TOLERANCE, $filed, $row['ticker']);
        $this->assertLessThanOrEqual($opened + self::FIRST_REPORT_MARGIN_TOLERANCE + (self::MEDIAN_YEAR_LOSS_SPARED * $creditCharge), $filed, $row['ticker']);
    }

    /**
     * A row it cannot build from is refused, not opened on a guess: a book stated beside the character, an input
     * missing, or one out of its range.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function refusedCharacterProvider(): array
    {
        $lake = $pool = null;
        foreach (InitialMarket::STOCKS as $row) {
            $lake = $row['ticker'] === 'LAKE' ? $row : $lake;
            $pool = $row['ticker'] === 'POOL' ? $row : $pool;
        }
        self::assertNotNull($lake);
        self::assertNotNull($pool);
        $missing = $lake;
        unset($missing['retained_share']);

        return [
            'a book beside the character' => [['total_equity' => 1.0e12] + $lake],
            'addressable_share stated on a lender' => [['addressable_share' => 0.30] + $lake],
            'an input missing' => [$missing],
            'wholesale funding past all of it' => [['wholesale_funding_share' => 1.0] + $lake],
            'retained earnings past its equity' => [['retained_share' => 1.5] + $lake],
            'no revenue' => [['revenue' => 0.0] + $lake],
            'revenue past its industry market' => [['revenue' => MacroConstants::DISTRICT_GDP_USD * IndustryMarkets::BY_INDUSTRY[$lake['industry']] * 1.1] + $lake],
            'a moat off the ladder' => [['moat' => 'impregnable'] + $lake],
            'a funding mix beside a shadow bank' => [['wholesale_funding_share' => 0.5] + $pool],
            'a shadow bank margin past its revenue' => [['operating_margin' => 1.2] + $pool],
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
