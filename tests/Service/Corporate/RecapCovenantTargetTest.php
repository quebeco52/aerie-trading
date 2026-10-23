<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Market\Bond\CreditRatingAgency;
use App\Service\Math\MathUtility;
use App\Service\News\MarketEventPublisher;
use PHPUnit\Framework\TestCase;

/**
 * Lenders test cash-flow leverage, so a recapitalisation stops at the manager's share of the covenant on normal EBITDA
 * as well as at the book target.
 */
final class RecapCovenantTargetTest extends TestCase
{
    /**
     * A recapitalisation stops where funded net debt reaches the manager's share of its covenant on normal EBITDA,
     * whatever book leverage would still allow: the same balance sheet is under-levered on a high long-run return
     * and at its target on a low one, and with no long-run return yet only the book target applies.
     */
    public function testARecapStopsAtTheCovenantShareOfNormalEbitda(): void
    {
        $engine = $this->engine();
        $macro = $this->macro();

        $fresh = $this->fixture();
        $h = $engine->analyzeDebtHealth($fresh, $macro);
        $this->assertTrue($h->isUnderLeveraged, 'no long-run return yet: the book target alone');
        $this->assertEqualsWithDelta(1.125, $h->leverageTarget, 1e-9, 'conglomerate 1.5 x 0.75');

        $weak = $this->fixture('0.02');
        $h = $engine->analyzeDebtHealth($weak, $macro);
        $this->assertFalse($h->isUnderLeveraged, 'net debt already past 0.75 x 3.5 x normal EBITDA');
        $this->assertLessThan($h->debtToEquity, $h->leverageTarget);

        $strong = $this->fixture('0.15');
        $h = $engine->analyzeDebtHealth($strong, $macro);
        $this->assertTrue($h->isUnderLeveraged);
        $this->assertEqualsWithDelta(1.125, $h->leverageTarget, 1e-9, 'the book target binds when earnings carry more');
    }

    /** Where the covenant binds, a repurchase sized to the target ends the quarter with net debt on it exactly. */
    public function testTheCovenantTargetIsTheRatioARepurchaseOfTheRoomEndsOn(): void
    {
        $stock = $this->fixture('0.05');
        $h = $this->engine()->analyzeDebtHealth($stock, $this->macro());
        $strategy = \App\Data\Company\Sectors::strategyFor('Conglomerates');
        $normal = $strategy->getNormalEbitda($stock, 0.20, $h->rawMetrics->depreciation);
        $room = ($strategy->getLeverageTarget(3.5) * $normal) - max(0.0, 40_000_000.0 - 10_000_000.0);
        $this->assertGreaterThan(0.0, $room);
        $this->assertLessThan(1.125, $h->leverageTarget, 'the covenant must bind in this fixture');

        $equity = (float) $stock->getTotalEquity();
        $recapDebt = $h->debtToEquity * $equity;
        $repurchase = (($h->leverageTarget * $equity) - $recapDebt) / (1.0 + $h->leverageTarget);
        $this->assertEqualsWithDelta($room, $repurchase, 1.0);
    }

    /** Normal EBITDA grosses NOPAT back up for tax and adds depreciation; a trust's NOI return is EBITDA already. */
    public function testNormalEbitdaIsStruckOnTheBasisTheReturnIsDefinedOn(): void
    {
        $corp = $this->fixture('0.10');
        $standard = \App\Data\Company\Sectors::strategyFor('Conglomerates');
        $ic = $corp->getInvestedCapital();
        $this->assertEqualsWithDelta((0.10 * $ic / 0.80) + 5.0, $standard->getNormalEbitda($corp, 0.20, 5.0), 1e-6);
        $this->assertNull($standard->getNormalEbitda($this->fixture(), 0.20, 5.0));

        $loss = $this->fixture('-0.04');
        $this->assertEqualsWithDelta((-0.04 * $loss->getInvestedCapital()) + 5.0, $standard->getNormalEbitda($loss, 0.20, 5.0), 1e-6, 'a loss is not grossed up');

        $trust = $this->fixture('0.06');
        $trust->setIndustry('REIT - Industrial');
        $reit = \App\Data\Company\Sectors::strategyFor('REIT - Industrial');
        $this->assertEqualsWithDelta(0.06 * $trust->getInvestedCapital(), $reit->getNormalEbitda($trust, 0.20, 5.0), 1e-6);
    }

    private function engine(): DebtEngine
    {
        return new DebtEngine(new MathUtility(), new CorporateMetrics(), new CreditRatingAgency(), $this->createStub(MarketEventPublisher::class));
    }

    private function macro(): MacroStateDTO
    {
        return new MacroStateDTO(policyRateEma: 0.03, corporateTaxRate: 0.20, yield5yEma: 0.03, equityRiskPremium: 0.05);
    }

    private function fixture(?string $longRunReturn = null): Stock
    {
        $stock = new Stock();
        $stock->setTicker('LEV');
        $stock->setIndustry('Conglomerates');
        $stock->setPrice('50.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalEquity('80000000');
        $stock->setWholesaleDebt('40000000');
        $stock->setCorporateTreasury('10000000');
        $stock->setTotalRevenue('100000000');
        $stock->setOperatingMargin('0.30');
        $stock->setBeta('0.5');
        $stock->setHistoricalFixedRate('0.02');
        $stock->setCreditSpread('0.01');
        $stock->setLongRunReturn($longRunReturn);

        return $stock;
    }
}
