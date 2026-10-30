<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\ManagementValuation;
use App\Service\Math\TimeSeries;
use App\Service\Model\Sector\BaseFinancialBusinessModel;
use App\Tests\Support\DebtHealthBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ManagementValuationTest extends TestCase
{
    /**
     * The board values its stock on the returns the market does: what it filed recently and the level its filed returns
     * revert to, not its operating return state, so two firms that filed alike are valued alike whatever that state,
     * and one whose filed returns settle higher earns more on its book.
     */
    public function testTheBoardValuesItsStockOnTheReturnsItFiled(): void
    {
        $eps = static function (float $state, float $anchor): float {
            $stock = (new Stock())->setTicker('MGMT')->setIndustry('Specialty Industrial Machinery');
            $stock->setTotalEquity('1000000000');
            $stock->setSharesOutstanding('10000000');
            $stock->setRoicTtm((string) $state);
            $stock->setCurrentRoic('0.20');
            $stock->setEarningsMomentumZ([EarningsEngine::STATE_TRAILING_RETURN => 0.20, EarningsEngine::STATE_ANCHOR_RETURN => $anchor]);

            return ManagementValuation::normalizedEps(Sectors::strategyFor('Specialty Industrial Machinery'), $stock, DebtHealthBuilder::healthy()->build(), new MacroStateDTO(corporateTaxRate: 0.21));
        };

        $this->assertEqualsWithDelta($eps(0.05, 0.15), $eps(0.40, 0.15), 1e-9, 'not its operating return state');
        $this->assertGreaterThan($eps(0.05, 0.15), $eps(0.05, 0.25));
    }

    /**
     * The board's multiple carries the market factor's repricing at the stock's beta, as the market's fair value does,
     * so a market-wide fall leaves the stock no cheaper against the board's own multiple.
     */
    public function testTheBoardsMultipleMovesWithTheMarketsRepricing(): void
    {
        $stock = (new Stock())->setTicker('MGMT')->setIndustry('Specialty Industrial Machinery');
        $stock->setTotalEquity('1000000000');
        $stock->setSharesOutstanding('10000000');
        $stock->setEarningsMomentumZ([EarningsEngine::STATE_TRAILING_RETURN => 0.15, EarningsEngine::STATE_ANCHOR_RETURN => 0.15]);
        $strategy = Sectors::strategyFor('Specialty Industrial Machinery');
        $health = DebtHealthBuilder::healthy()->withLeveredBeta(1.3)->build();
        $pe = static fn(float $shift): float => ManagementValuation::fairValuePE($strategy, $stock, $health, new MacroStateDTO(corporateTaxRate: 0.21, equityValuationShift: $shift));

        foreach ([-0.5, 0.5] as $shift) {
            $this->assertEqualsWithDelta(exp(1.3 * $shift), $pe($shift) / $pe(0.0), 1e-9);
        }
    }

    /** A lender's good or bad year fades at a lender's persistence, an operating firm's at its own. */
    public function testEachModelFadesARecentYearAtItsOwnPersistence(): void
    {
        $this->assertEqualsWithDelta(
            TimeSeries::persistentEquivalentReturn(0.20, 0.12, 0.10, BaseFinancialBusinessModel::RETURN_PERSISTENCE),
            Sectors::strategyFor('Banks - Regional')->getValuationReturn(0.20, 0.12, 0.10),
            1e-12,
        );
        $this->assertEqualsWithDelta(
            TimeSeries::persistentEquivalentReturn(0.20, 0.12, 0.10, 0.62),
            Sectors::strategyFor('Specialty Industrial Machinery')->getValuationReturn(0.20, 0.12, 0.10),
            1e-12,
        );
        $this->assertLessThan(0.62, BaseFinancialBusinessModel::RETURN_PERSISTENCE);
    }
}
