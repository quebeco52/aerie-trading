<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

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
use PHPUnit\Framework\TestCase;

/**
 * Every seeded lender's franchise opens with its loans priced over the market, not under it. The loan spread is what
 * the lender's return target leaves over the yield its book earns at market rates; a negative one means the lore's
 * anchors disagree (a return target below what the deposit franchise already earns, or a book priced at the wrong
 * tenor), and its loans would be lent below cost.
 */
final class LenderOpeningFranchiseTest extends TestCase
{
    public function testEverySeededLenderOpensWithANonNegativeLoanSpread(): void
    {
        mt_srand(20261020);
        $math = new MathUtility();
        $builder = new OpeningBoardBuilder($math, new DebtEngine($math, CorporateMetrics::getInstance()), new MarketEngine($math), new AnchorStakeLedger());
        $opening = new MacroStateDTO();
        $lenders = 0;

        foreach (InitialMarket::STOCKS as $row) {
            $model = Sectors::getBusinessModelStrategy(Sectors::businessModelFor($row['industry'] ?? null));
            if (!$model instanceof CommercialBankBusinessModel) {
                continue;
            }
            $stock = (new Stock())->setTicker($row['ticker']);
            $builder->open($stock, $row, $opening);
            $pricing = (new \ReflectionMethod($model, 'resolveFranchisePricing'))->invoke($model, $stock);

            $this->assertGreaterThanOrEqual(0.0, $pricing['loan_spread'], sprintf('%s opens with a loan spread of %+.2f%%.', $row['ticker'], 100 * $pricing['loan_spread']));
            ++$lenders;
        }

        $this->assertGreaterThanOrEqual(8, $lenders);
    }
}
