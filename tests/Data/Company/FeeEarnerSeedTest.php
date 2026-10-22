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
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\Sector\AssetManagementBusinessModel;
use App\Service\Model\Sector\BrokerageBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fee earner's operating yield is struck once, on the balance sheet it opens with (resolveOperatingYield() on the
 * broker and the fee manager), and held. Every seeded broker and fee manager opens on its structural return at the
 * opening macro, so the board starts level and a later move in its return is the funding market's or the cycle's.
 */
class FeeEarnerSeedTest extends TestCase
{
    /** How far from its structural return a fee earner may open (0.5pp of ROE): a fee manager's coupon gap is in it. */
    private const OPENING_RETURN_TOLERANCE = 0.005;

    /**
     * The cash past its model's target a fee earner may open with, as a share of its tangible equity (2%). The strike
     * counts that cash as earning, so a firm that handed a larger surplus back would run above its target for good.
     */
    private const SURPLUS_CASH_TOLERANCE = 0.02;

    /**
     * The fee manager whose notes pay far enough under the market that it opens past the tolerance: it earns its
     * coupon gap (about 2pp) until they roll.
     */
    private const CHEAP_NOTES_MANAGER = 'SWAN';

    private DebtEngine $debtEngine;
    private OpeningBoardBuilder $builder;
    private MathUtility $math;

    protected function setUp(): void
    {
        mt_srand(20261022);
        $this->math = new MathUtility();
        $this->debtEngine = new DebtEngine($this->math, CorporateMetrics::getInstance());
        $this->builder = new OpeningBoardBuilder($this->math, $this->debtEngine, new MarketEngine($this->math), new AnchorStakeLedger());
    }

    /**
     * @return array<string, array{array<string, mixed>}> Every seeded broker, bank and fee manager's row, by ticker.
     */
    public static function feeEarnerProvider(): array
    {
        $earners = [];
        foreach (InitialMarket::STOCKS as $row) {
            $strategy = Sectors::strategyFor($row['industry']);
            if ($strategy instanceof BrokerageBusinessModel || $strategy instanceof AssetManagementBusinessModel) {
                $earners[$row['ticker']] = [$row];
            }
        }

        return $earners;
    }

    /**
     * It books its structural return at the opening macro, a fee manager plus its coupon gap: its fees are struck on
     * the coupon its notes would refinance at, so notes paying under the market earn it the difference until they roll.
     * A broker is struck on the coupon it pays and has none.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('feeEarnerProvider')]
    public function testItOpensOnItsStructuralReturn(array $row): void
    {
        [$stock, $strategy] = $this->open($row);
        $opening = new MacroStateDTO();
        $structural = (new \ReflectionMethod($strategy, 'resolveStructuralTargetRoe'))->invoke($strategy, $stock, $opening);
        $couponGap = $this->couponGap($stock, $strategy, $opening);
        $booked = $this->openingReturn($stock, $strategy, $opening);

        $this->assertEqualsWithDelta($structural + $couponGap, $booked, 1e-6, sprintf('%s books %.2f%% against %.2f%% plus a %.2fpp coupon gap.', $row['ticker'], 100 * $booked, 100 * $structural, 100 * $couponGap));
        if ($row['ticker'] === self::CHEAP_NOTES_MANAGER) {
            $this->assertGreaterThan(self::OPENING_RETURN_TOLERANCE, $couponGap, 'Its notes pay well under the market.');

            return;
        }
        $this->assertEqualsWithDelta($structural, $booked, self::OPENING_RETURN_TOLERANCE, sprintf('%s opens %.2fpp off its structural return.', $row['ticker'], 100 * ($booked - $structural)));
    }

    /**
     * It opens holding no more cash than its model targets, within the tolerance: a dealer's target is the clearing
     * backing on its wholesale book, and its prime, repo and inventory books are in the earning book, not in cash.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('feeEarnerProvider')]
    public function testItOpensHoldingOnlyTheCashItNeeds(array $row): void
    {
        [$stock, $strategy] = $this->open($row);
        $operatingBase = (new \ReflectionMethod($strategy, 'getOperatingBase'))->invoke($strategy, $stock);
        $targetCash = $strategy->calculateTargetOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $surplus = (float) $stock->getCorporateTreasury() - $targetCash;

        $this->assertLessThanOrEqual(
            self::SURPLUS_CASH_TOLERANCE * $stock->getTangibleEquity(),
            $surplus,
            sprintf('%s opens with %.1f bn of cash past the %.1f bn its model targets.', $row['ticker'], $surplus / 1e9, $targetCash / 1e9),
        );
    }

    /**
     * Its return at a macro: operating profit on its struck yield, less the interest DebtEngine charges, plus the
     * interest its books earn on the realized rate, after tax, over tangible equity.
     */
    private function openingReturn(Stock $stock, BusinessModelInterface $strategy, MacroStateDTO $macro): float
    {
        $target = $strategy->getTargetMetrics($stock, $macro, $this->math);
        $debt = $this->debtEngine->calculateInterestExpense($stock, $macro);
        $operatingProfit = $target['invested_capital'] * $target['baseline_roic'] / (1.0 - $macro->corporateTaxRate);
        $income = $strategy->calculateInterestIncome($stock, $macro, $this->math, $debt->wholesaleRate);

        return ($operatingProfit - $debt->interestExpense + $income) * (1.0 - $macro->corporateTaxRate) / $stock->getTangibleEquity();
    }

    /** What a fee manager earns over its structural return while its notes pay under the coupon they would roll at. */
    private function couponGap(Stock $stock, BusinessModelInterface $strategy, MacroStateDTO $macro): float
    {
        if (!$strategy instanceof AssetManagementBusinessModel) {
            return 0.0;
        }
        $lossGivenDefault = $strategy->getLossGivenDefault();
        $saving = DebtEngine::chargedWholesaleRate($stock, $macro, $lossGivenDefault, true) - DebtEngine::chargedWholesaleRate($stock, $macro, $lossGivenDefault);

        return (float) $stock->getTotalDebt() * $saving * (1.0 - $macro->corporateTaxRate) / $stock->getTangibleEquity();
    }

    /**
     * The fee earner opened as the seed opens it.
     *
     * @param array<string, mixed> $row
     * @return array{Stock, BusinessModelInterface}
     */
    private function open(array $row): array
    {
        $strategy = Sectors::strategyFor($row['industry']);
        $stock = (new Stock())->setTicker($row['ticker']);
        $this->builder->open($stock, $row, new MacroStateDTO());

        return [$stock, $strategy];
    }
}
