<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A firm whose current return is exactly zero is priced on its baseline, however the zero was stored. The running
 * engine writes "0" and a reloaded row reads "0.0000"; tested as strings, only the first fell back, so the same firm
 * was priced differently before and after a restart.
 */
#[Group('unit')]
final class EffectiveReturnTest extends TestCase
{
    public function testAZeroCurrentReturnFallsBackToTheBaselineHoweverItWasStored(): void
    {
        foreach (['0', '0.0000'] as $zero) {
            $firm = (new Stock())->setBaselineRoic('0.0900')->setCurrentRoic($zero);
            $lender = (new Stock())->setBaselineRoe('0.1100')->setCurrentRoe($zero);

            $this->assertSame(0.09, (new StandardCorporateBusinessModel())->getEffectiveReturn($firm), "Stored as \"{$zero}\".");
            $this->assertSame(0.11, (new CommercialBankBusinessModel())->getEffectiveReturn($lender), "Stored as \"{$zero}\".");
        }
    }
}
