<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\Data\AnchorHoldings;
use App\DTO\DebtHealthDTO;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The one mapping from a listed firm to the inputs MarketEngine prices it on. The ticker and the company page both
 * price through it, so each input read here is read the same way everywhere a firm is valued.
 */
class MarketPricingContextTest extends TestCase
{
    private function health(Stock $stock, MacroStateDTO $macroState): DebtHealthDTO
    {
        return (new DebtEngine(new MathUtility(), CorporateMetrics::getInstance()))->analyzeDebtHealth($stock, $macroState);
    }

    private function firm(string $ticker, string $industry): Stock
    {
        return StockBuilder::create($ticker)
            ->withPrice(100.0)
            ->withSharesOutstanding(1_000_000_000)
            ->withTotalEquity(50_000_000_000.0)
            ->build()
            ->setIndustry($industry);
    }

    public function testAFirmIsPricedOnItsOwnSectorMultiple(): void
    {
        $macroState = new MacroStateDTO();
        $software = $this->firm('SOFT', 'Software - Infrastructure');

        $context = MarketPricingContext::forStock($software, $macroState, $this->health($software, $macroState), new AnchorStakeLedger());

        $this->assertSame(26.0, $context->baselineIndustryPE);
        $this->assertSame('tech', $context->businessModel);
    }

    /** A lender's baselineRoic column is a placeholder: it is priced on the return on equity its model measures it by. */
    public function testALenderIsPricedOnItsReturnOnEquity(): void
    {
        $macroState = new MacroStateDTO();
        $bank = $this->firm('BANK', 'Banks - Diversified');
        $bank->setBaselineRoic('0.10');
        $bank->setBaselineRoe('0.14');
        $bank->setCurrentRoe('0.16');
        $bank->setRoeTtm('0.15');

        $context = MarketPricingContext::forStock($bank, $macroState, $this->health($bank, $macroState), new AnchorStakeLedger());

        $this->assertSame(0.14, $context->baselineRoic);
        $this->assertSame(0.16, $context->currentRoic);
        $this->assertSame(0.15, $context->roicTtm);
    }

    public function testASpheresBookIsMarkedToTheBoardItHolds(): void
    {
        $macroState = new MacroStateDTO();
        $sphere = $this->firm('BRKW', 'Investment Companies');
        $sphere->setListedStakesCarrying('1118700000000');

        $board = [];
        foreach (array_keys(AnchorHoldings::forHolder('BRKW')) as $ticker) {
            $board[] = StockBuilder::create($ticker)->withPrice(350.0)->withSharesOutstanding(1_000_000_000)->build();
        }
        $ledger = new AnchorStakeLedger();
        $ledger->beginTick($board);

        $context = MarketPricingContext::forStock($sphere, $macroState, $this->health($sphere, $macroState), $ledger);

        $this->assertSame($ledger->resolveMarkedBookValuePerShare($sphere), $context->bookValuePerShare);
        $this->assertNotEqualsWithDelta((float) $sphere->getBookValuePerShare(), $context->bookValuePerShare, 1.0);
    }

    /** The step and the deal shock are the caller's; the common factors are the macro state's, the sector's by the firm's sector. */
    public function testTheStepIsTheCallersAndTheFactorsAreTheMacroStates(): void
    {
        $firm = $this->firm('SOFT', 'Software - Infrastructure');
        $macroState = new MacroStateDTO(marketVolatility: 0.22, marketZ: -1.3, marketJumpMultiplier: 0.9, sectorZ: [$firm->getSector() => 0.7]);

        $context = MarketPricingContext::forStock($firm, $macroState, $this->health($firm, $macroState), new AnchorStakeLedger(), 1.0 / 360.0, 0.05);

        $this->assertSame(1.0 / 360.0, $context->dt);
        $this->assertSame(0.05, $context->maShock);
        $this->assertSame(0.22, $context->marketVol);
        $this->assertSame(-1.3, $context->marketZ);
        $this->assertSame(0.9, $context->marketJumpMultiplier);
        $this->assertSame(0.7, $context->sectorZ);
    }
}
