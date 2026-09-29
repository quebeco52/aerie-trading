<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\MarketEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use App\Service\View\CompanySnapshotBuilder;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Where the company page has the analysts marked. The page is a reading of the market, so its targets must be
 * the ones the market engine forms for the same firm, struck on the firm's own sector multiple.
 */
#[AllowMockObjectsWithoutExpectations]
class CompanySnapshotValuationTest extends TestCase
{
    private function builder(): CompanySnapshotBuilder
    {
        $math = new MathUtility();

        return new CompanySnapshotBuilder(
            new MarketEngine($math),
            new DebtEngine($math, CorporateMetrics::getInstance()),
            new AnchorStakeLedger(),
            $this->createMock(StockRepository::class)
        );
    }

    private function lender(): Stock
    {
        $bank = StockBuilder::create('BANK')
            ->withPrice(60.0)
            ->withSharesOutstanding(2_000_000_000)
            ->withTotalEquity(90_000_000_000.0)
            ->build()
            ->setIndustry('Banks - Diversified');
        $bank->setEarningsPerShare('5.40');
        $bank->setBaselineRoe('0.13');
        $bank->setCurrentRoe('0.12');
        $bank->setRoeTtm('0.12');

        return $bank;
    }

    public function testTheTargetMultipleIsTheSectorsOwn(): void
    {
        $snapshot = $this->builder()->build($this->lender(), new MacroStateDTO());

        $this->assertSame(11.0, $snapshot['targetPE']);
    }

    /** The page's analyst targets are the market's own, drawn on the same path from the same inputs. */
    public function testThePageMarksTheAnalystsWhereTheMarketDoes(): void
    {
        $macroState = new MacroStateDTO();

        mt_srand(11);
        $snapshot = $this->builder()->build($this->lender(), $macroState);

        mt_srand(11);
        $math = new MathUtility();
        $lender = $this->lender();
        $health = (new DebtEngine($math, CorporateMetrics::getInstance()))->analyzeDebtHealth($lender, $macroState);
        $market = (new MarketEngine($math))->calculateNextPrice(MarketPricingContext::forStock($lender, $macroState, $health, new AnchorStakeLedger()));

        foreach (['growth_analyst', 'income_analyst', 'value_analyst'] as $school) {
            $this->assertSame($market['analyst_targets'][$school], $snapshot['analystTargets'][$school], $school);
        }
    }
}
