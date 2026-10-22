<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A lender's franchise pricing is struck once, on the balance sheet and rating it opens with
 * (CommercialBankBusinessModel::strikeFranchisePricing()). A lender seeded outside the engine's own funding and capital
 * rules has that balance sheet changed under it within a year, as the treasury sweeps its excess wholesale debt and the
 * agency lifts the rating its capital capped, and it earns past its return from then on. Every lender opens inside
 * them.
 */
class LenderSeedTest extends TestCase
{
    private DebtEngine $debtEngine;
    private OpeningBoardBuilder $builder;

    protected function setUp(): void
    {
        mt_srand(20261022);
        $math = new MathUtility();
        $this->debtEngine = new DebtEngine($math, CorporateMetrics::getInstance());
        $this->builder = new OpeningBoardBuilder($math, $this->debtEngine, new MarketEngine($math), new AnchorStakeLedger());
    }

    /** @return array<string, array{array<string, mixed>}> Every seeded lender's row, by ticker. */
    public static function lenderProvider(): array
    {
        $lenders = [];
        foreach (InitialMarket::STOCKS as $row) {
            if (Sectors::strategyFor($row['industry']) instanceof CommercialBankBusinessModel) {
                $lenders[$row['ticker']] = [$row];
            }
        }

        return $lenders;
    }

    /**
     * Its wholesale debt within the leverage limit its model sets on equity, the line TreasuryEngine's deleveraging
     * sweep pays it down to: a shadow bank's is its own, wider one.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('lenderProvider')]
    public function testItsWholesaleDebtOpensInsideItsLeverageLimit(array $row): void
    {
        [$stock, $strategy] = $this->open($row);
        $limit = $strategy->getWholesaleLeverageLimit();

        $this->assertLessThanOrEqual(
            $limit * $stock->getTangibleEquity(),
            (float) $stock->getWholesaleDebt(),
            sprintf('%s opens with wholesale debt at %.2fx tangible equity, past the %.1fx the treasury sweeps it down to.', $row['ticker'], (float) $stock->getWholesaleDebt() / $stock->getTangibleEquity(), $limit),
        );
    }

    /**
     * Its tangible capital over assets, as the rating agency measures it, above the watch line that caps a lender's
     * rating at BB.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('lenderProvider')]
    public function testItsTangibleCapitalOpensAboveTheWatchLine(array $row): void
    {
        [$stock, $strategy] = $this->open($row);
        $base = $this->debtEngine->resolveTangibleCapitalBase($stock, (float) $stock->getTotalRevenue());
        $capitalRatio = 100.0 * $base['capital'] / $base['assets'];

        $this->assertGreaterThan(
            $strategy->getWarningEquityThreshold(),
            $capitalRatio,
            sprintf('%s opens with tangible capital at %.3f%% of assets, under the %.1f%% watch line.', $row['ticker'], $capitalRatio, $strategy->getWarningEquityThreshold()),
        );
    }

    /**
     * The capital ratio it steers to above the same watch line, or its capital management walks it back onto the
     * rating cap.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('lenderProvider')]
    public function testItsCapitalTargetSitsAboveTheWatchLine(array $row): void
    {
        [$stock, $strategy] = $this->open($row);
        $target = $strategy->getTargetCapitalRatio($stock);
        if ($target === null) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertGreaterThan(
            $strategy->getWarningEquityThreshold(),
            100.0 * $target,
            sprintf('%s targets a capital ratio of %.2f%%, not above the %.1f%% watch line.', $row['ticker'], 100.0 * $target, $strategy->getWarningEquityThreshold()),
        );
    }

    /**
     * The lender opened as the seed opens it.
     *
     * @param array<string, mixed> $row
     * @return array{Stock, CommercialBankBusinessModel}
     */
    private function open(array $row): array
    {
        $strategy = Sectors::strategyFor($row['industry']);
        $this->assertInstanceOf(CommercialBankBusinessModel::class, $strategy);
        $stock = (new Stock())->setTicker($row['ticker']);
        $this->builder->open($stock, $row, new MacroStateDTO());

        return [$stock, $strategy];
    }
}
