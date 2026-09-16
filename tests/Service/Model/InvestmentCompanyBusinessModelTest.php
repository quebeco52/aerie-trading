<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ConglomerateBusinessModel;
use App\Service\Model\Sector\InvestmentCompanyBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvestmentCompanyBusinessModelTest extends TestCase
{
    private InvestmentCompanyBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new InvestmentCompanyBusinessModel();
    }

    /**
     * The multiple on a trust's book is one, so the term MarketEngine hands calculateFairValue() is net
     * asset value per share itself rather than a ROIC-scaled multiple of it. Struck across a wide range of
     * returns and hurdles because the whole point is that NEITHER moves it.
     */
    public function testBookMultipleIsAlwaysOneWhateverTheReturnOnCapital(): void
    {
        foreach ([0.01, 0.08, 0.12, 0.40] as $roic) {
            foreach ([0.04, 0.08, 0.15] as $hurdle) {
                $this->assertSame(1.0, $this->model->getIntrinsicPbMultiple($roic, $hurdle));
            }
        }

        // The parent prices the same book on what it earns over its hurdle, which is the behaviour this
        // model exists to replace.
        $this->assertGreaterThan(1.0, (new ConglomerateBusinessModel())->getIntrinsicPbMultiple(0.12, 0.08));
    }

    /**
     * The defining assertion: a trust's valuation does not move on its earnings line. Its reported profit
     * is dominated by the mark on the portfolio, which the book already carries, so pricing it through a
     * multiple would count the same information twice.
     */
    public function testFairValueIsNetAssetValueAndIgnoresTheEarningsLine(): void
    {
        $nav = 700.0;

        $boom = $this->model->calculateFairValue(earningsValue: 2_000.0, pbFairValue: $nav, normalizedEps: 90.0);
        $bust = $this->model->calculateFairValue(earningsValue: 1.0, pbFairValue: $nav, normalizedEps: -40.0);

        $this->assertSame($nav, $boom);
        $this->assertSame($nav, $bust);

        // The parent, on the same inputs, swings with earnings — 90% of its consensus is the earnings leg.
        $parent = new ConglomerateBusinessModel();
        $this->assertGreaterThan(
            $parent->calculateFairValue(1.0, $nav, -40.0),
            $parent->calculateFairValue(2_000.0, $nav, 90.0)
        );
    }

    /** The distribution is funded by dividends the trust RECEIVES, so it is a separate claim on value. */
    public function testDividendSupportBlendsIntoNetAssetValue(): void
    {
        $blended = $this->model->calculateFairValue(0.0, 700.0, 40.0, 900.0);

        $this->assertEqualsWithDelta(
            (700.0 * (1.0 - InvestmentCompanyBusinessModel::NAV_DDM_WEIGHT))
                + (900.0 * InvestmentCompanyBusinessModel::NAV_DDM_WEIGHT),
            $blended,
            1e-9
        );
    }

    /**
     * The holding-company discount is the defining feature of the class, and it is a sentiment index: it
     * gaps wide as the cycle turns down and closes in an expansion, while the assets underneath do neither.
     */
    public function testDiscountWidensIntoTheDownturnAndClampsBothEnds(): void
    {
        $neutral = $this->model->getStructuralValuationDiscount(0.0);
        $slump   = $this->model->getStructuralValuationDiscount(-0.025);
        $boom    = $this->model->getStructuralValuationDiscount(0.030);

        $this->assertSame(InvestmentCompanyBusinessModel::NAV_DISCOUNT_BASE, $neutral);
        $this->assertGreaterThan($neutral, $slump);
        $this->assertLessThan($neutral, $boom);

        // Even at a cyclical extreme the trust is neither valued at its full assets nor at half of them.
        $this->assertSame(InvestmentCompanyBusinessModel::MAX_NAV_DISCOUNT, $this->model->getStructuralValuationDiscount(-0.90));
        $this->assertSame(InvestmentCompanyBusinessModel::MIN_NAV_DISCOUNT, $this->model->getStructuralValuationDiscount(0.90));
    }

    private function neutralMacro(float $outputGapEma = 0.0, ?float $ppi = null, ?float $wage = null): MacroStateDTO
    {
        $defaults = new MacroStateDTO();

        return new MacroStateDTO(
            outputGapEma: $outputGapEma,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD,
            policyRateEma: MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION,
            producerPriceInflationEma: $ppi ?? $defaults->producerPriceInflationEma,
            wageGrowthEma: $wage ?? $defaults->wageGrowthEma,
        );
    }

    private function deterministicMath(): MathUtility
    {
        return new class extends MathUtility {
            public function generatePersistentZ(float $previousZ, float $phi, ?float $commonInnovation = null, float $commonLoading = 0.0): float
            {
                return 0.0;
            }

            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
    }

    /** The treasury share is read from the ledger now, so the fixture needs a real balance sheet. */
    private function brkw(float $treasury = 250_000_000_000.0): Stock
    {
        $stock = new Stock();
        $stock->setTicker('BRKW');
        $stock->setBeta('0.45');
        $stock->setTotalEquity('1880000000000');
        $stock->setCorporateTreasury((string) $treasury);

        return $stock;
    }

    /**
     * A trust owns no factories, so the parent's industrial stream must not appear on it — and the segments
     * it DOES report are the inverse of its asset mix: the wholly-owned minority of the portfolio is the
     * majority of the income statement, because only a controlled company is consolidated.
     */
    public function testReportsAHoldingPortfolioAndNoFactory(): void
    {
        $result = $this->model->computeActualFinancials(
            $this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath()
        );

        $this->assertSame(
            ['wholly_owned', 'listed_portfolio', 'financial_investments'],
            array_keys($result->streamRevenue)
        );
        $this->assertArrayNotHasKey('industrial_manufacturing', $result->streamRevenue);

        // BRKW holds 30% of its NAV outright and 60% as listed anchors — and reports the INVERSE, because
        // consolidation takes a subsidiary's whole top line while a listed stake contributes only its
        // dividend. The controlled 30% of the sphere is ~78% of the income statement.
        $total = array_sum($result->streamRevenue);
        $this->assertGreaterThan(
            $result->streamRevenue['listed_portfolio'],
            $result->streamRevenue['wholly_owned'],
            'The revenue mix must invert the asset mix; that inversion is the whole point of the model.'
        );
        $this->assertEqualsWithDelta(0.774, $result->streamRevenue['wholly_owned'] / $total, 0.005);
        $this->assertEqualsWithDelta(0.181, $result->streamRevenue['listed_portfolio'] / $total, 0.005);

        // BRKW's dials are deliberately not the archetype, so this also pins them as live: a dial the model
        // silently ignores is worse than no dial at all, and this block has carried one before.
        $archetypeOwned = InvestmentCompanyBusinessModel::WHOLLY_OWNED_NAV_SHARE
            * InvestmentCompanyBusinessModel::WHOLLY_OWNED_ASSET_TURNOVER;
        $archetypeTotal = $archetypeOwned
            + (InvestmentCompanyBusinessModel::LISTED_PORTFOLIO_NAV_SHARE * InvestmentCompanyBusinessModel::LISTED_DIVIDEND_YIELD)
            + (InvestmentCompanyBusinessModel::TREASURY_NAV_SHARE_FALLBACK * InvestmentCompanyBusinessModel::TREASURY_INCOME_YIELD);
        $this->assertNotEqualsWithDelta(
            $archetypeOwned / $archetypeTotal,
            $result->streamRevenue['wholly_owned'] / $total,
            0.01,
            'The archetype and the tuned value coincide, so this test can no longer tell a live dial from a dead one.'
        );

        // A calm quarter charges nothing beyond the cost base it was handed.
        $this->assertEqualsWithDelta(0.08, $result->clampedMargin, 1e-9);
    }

    /** A trust that controls nothing at all is still a trust: the stream is not drawn and leaves no hole. */
    public function testASphereThatConsolidatesNothingReportsNoOperatingStream(): void
    {
        $holdingOnly = new class extends InvestmentCompanyBusinessModel {
            /** Overlays the mix only, so every other parameter still resolves the way it normally would. */
            protected function resolveModelParameters(Stock $stock, array $defaults = []): \App\DTO\ModelParameters
            {
                $resolved = parent::resolveModelParameters($stock, $defaults);
                $overrides = [
                    \App\Data\ModelParam::WhollyOwnedNavShare->value     => 0.00,
                    \App\Data\ModelParam::ListedPortfolioNavShare->value => 0.85,
                ];

                $raw = [];
                foreach ($defaults as $key => $fallback) {
                    $raw[(string) $key] = $overrides[(string) $key] ?? $resolved->get((string) $key, (float) $fallback);
                }

                return \App\DTO\ModelParameters::from($raw);
            }
        };

        $result = $holdingOnly->computeActualFinancials(
            $this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath()
        );

        $this->assertSame(['listed_portfolio', 'financial_investments'], array_keys($result->streamRevenue));
        $this->assertArrayNotHasKey('wholly_owned', $result->streamRevenue);

        // Nothing is consolidated, so nothing buys inputs and the cost base is exactly what it was handed.
        $this->assertEqualsWithDelta(0.08, $result->clampedMargin, 1e-9);
    }

    /**
     * The hoard must be paid for once. StandardTreasuryTrait pays every corporate the front rate on its
     * excess cash, and here the same balance is already a revenue stream running the contrarian float
     * physics over it — so the trait's version has to be off, or the treasury earns twice.
     */
    public function testTheTreasuryIsNotPaidForTwice(): void
    {
        $stock = $this->brkw();
        $stock->setTotalRevenue('170000000000');
        $stock->setWholesaleDebt('140000000000');

        $macro = $this->neutralMacro();
        $math = new MathUtility();

        $this->assertSame(0.0, $this->model->calculateInterestIncome($stock, $macro, $math));

        // The parent still books it, which is what makes the override load-bearing rather than decorative.
        $this->assertGreaterThan(
            0.0,
            (new ConglomerateBusinessModel())->calculateInterestIncome($stock, $macro, $math),
            'If the parent stopped paying interest income, this override would be silently pointless.'
        );

        // And the income the stream books in its place is real.
        $result = $this->model->computeActualFinancials(
            $stock, 100_000_000.0, 0.08, 3_000_000.0, 0.0, $macro, $this->deterministicMath()
        );
        $this->assertGreaterThan(0.0, $result->streamRevenue['financial_investments']);
    }

    /**
     * The treasury stream is funded by the balance sheet, not by a dial beside it. A sphere that spends its
     * hoard on a deal stops earning on the money it no longer has — which a declared weight could not say,
     * because it would still be declaring the old number.
     */
    public function testSpendingTheHoardShrinksTheTreasuryStream(): void
    {
        $macro = $this->neutralMacro();
        $math = $this->deterministicMath();

        $full  = $this->model->computeActualFinancials($this->brkw(250_000_000_000.0), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $macro, $math);
        $spent = $this->model->computeActualFinancials($this->brkw(25_000_000_000.0), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $macro, $math);

        $fullShare  = $full->streamRevenue['financial_investments'] / array_sum($full->streamRevenue);
        $spentShare = $spent->streamRevenue['financial_investments'] / array_sum($spent->streamRevenue);

        $this->assertGreaterThan($spentShare, $fullShare, 'The float must follow the cash that funds it.');
        $this->assertEqualsWithDelta(0.046, $fullShare, 0.004);
        $this->assertEqualsWithDelta(0.005, $spentShare, 0.004);

        // And the operating half picks up the slack rather than the group simply shrinking.
        $this->assertGreaterThan(
            $full->streamRevenue['wholly_owned'] / array_sum($full->streamRevenue),
            $spent->streamRevenue['wholly_owned'] / array_sum($spent->streamRevenue)
        );
    }

    /**
     * Dividends received arrive late and damped. A slump that has only just begun has not yet reached the
     * cheque, because the payout funding it was declared out of the earnings before it.
     */
    public function testDividendsReceivedLagTheCycleTheSubsidiariesFeelNow(): void
    {
        $slump = $this->neutralMacro(outputGapEma: -0.030);

        $result = $this->model->computeActualFinancials(
            $this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $slump, $this->deterministicMath()
        );

        $calm = $this->model->computeActualFinancials(
            $this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath()
        );

        $ownedHit  = 1.0 - ($result->streamRevenue['wholly_owned'] / $calm->streamRevenue['wholly_owned']);
        $listedHit = 1.0 - ($result->streamRevenue['listed_portfolio'] / $calm->streamRevenue['listed_portfolio']);

        $this->assertGreaterThan(0.0, $ownedHit, 'Consolidated subsidiaries feel the slump immediately.');
        $this->assertGreaterThan(0.0, $listedHit, 'Dividends eventually feel it too.');
        $this->assertGreaterThan($listedHit, $ownedHit, 'The receipt line must be the quieter of the two.');
    }

    /**
     * Only the consolidated subsidiaries buy anything. A dividend received carries no cost of goods, so the
     * input basket must be charged against the operating stream's share and not against group revenue.
     */
    public function testOnlyTheConsolidatedSubsidiariesPayForInputs(): void
    {
        $costPush = $this->neutralMacro(ppi: 0.09, wage: 0.08);

        $withPush = $this->model->computeActualFinancials(
            $this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $costPush, $this->deterministicMath()
        );
        $calm = $this->model->computeActualFinancials(
            $this->brkw(), 100_000_000.0, 0.08, 3_000_000.0, 0.0, $this->neutralMacro(), $this->deterministicMath()
        );

        $charged = $withPush->clampedMargin - $calm->clampedMargin;
        $this->assertGreaterThan(0.0, $charged, 'A cost push must reach the subsidiaries.');

        // Charged at the operating stream's share of revenue, so it cannot exceed the undiscounted drag.
        $ownedShare = $calm->streamRevenue['wholly_owned'] / $calm->actualRevenue;
        $this->assertLessThan(1.0, $ownedShare);
        $this->assertLessThan(0.05, $charged, 'A trust is not a factory; the basket reaches only part of it.');
    }

    /** A trust manufactures nothing, but what its holdings earn is what compounds the book it is priced on. */
    public function testStillAConglomerateForEverythingButItsPortfolio(): void
    {
        $this->assertInstanceOf(ConglomerateBusinessModel::class, $this->model);
        $this->assertNotContains('manufacturing_pmi_ema', $this->model->getOperatingMacroFields());
    }

    public function testRegisteredAgainstItsIndustry(): void
    {
        $this->assertInstanceOf(
            InvestmentCompanyBusinessModel::class,
            Sectors::getBusinessModelStrategy('investment_company')
        );
        $this->assertSame(
            'investment_company',
            Sectors::INDUSTRY_METRICS['Investment Companies']['business_model']
        );
    }

    /**
     * The intrinsic P/B multiple moved out of MarketEngine and into the valuation strategy. Every model
     * that did not ask for its own must still compute the expression that used to be inline, or the
     * extraction silently repriced the board.
     *
     * @return array<string, array{class-string}>
     */
    public static function standardModelProvider(): array
    {
        $models = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Service/Model/Sector/*BusinessModel.php') ?: [] as $file) {
            $class = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            if ((new \ReflectionClass($class))->isAbstract() || $class === InvestmentCompanyBusinessModel::class) {
                continue;
            }
            $models[basename($file, '.php')] = [$class];
        }

        return $models;
    }

    /** @param class-string $modelClass */
    #[DataProvider('standardModelProvider')]
    public function testExtractionLeftEveryOtherModelOnTheOriginalMultiple(string $modelClass): void
    {
        $model = new $modelClass();

        foreach ([[0.12, 0.08], [0.02, 0.09], [0.90, 0.05], [0.10, 0.0001]] as [$roic, $hurdle]) {
            $this->assertSame(
                max(FinancialConstants::MIN_INTRINSIC_PB, min(FinancialConstants::MAX_INTRINSIC_PB, $roic / max(0.01, $hurdle))),
                $model->getIntrinsicPbMultiple($roic, $hurdle),
                $modelClass . ' no longer prices book the way MarketEngine did.'
            );
            $this->assertSame(0.0, $model->getStructuralValuationDiscount(-0.03));
        }
    }
}
