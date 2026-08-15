<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\ShadowBankBusinessModel;
use PHPUnit\Framework\TestCase;

class ShadowBankBusinessModelTest extends TestCase
{
    private ShadowBankBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new ShadowBankBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testIndependentOriginationAndDirectLendingStreams(): void
    {
        $stock = new Stock();
        $stock->setTicker('RITM');
        $stock->setBeta('1.3');
        $stock->setTotalEquity('1000000000');
        $stock->setWholesaleDebt('8000000000');

        $macro = new MacroStateDTO(
            outputGapEma: 0.01,
            policyRateEma: 0.05,
            yield30yEma: 0.065,
            macroCreditSpreadEma: 0.02
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 400_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 80_000_000.0,
            baselineVol: 0.12,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('origination_fees', $result->streamRevenue);
        $this->assertArrayHasKey('direct_lending', $result->streamRevenue);

        $this->assertArrayHasKey('origination', $result->streamZ);
        $this->assertArrayHasKey('direct_lending', $result->streamZ);
        $this->assertArrayHasKey('credit', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->actualRevenue);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['origination_fees'] + $result->streamRevenue['direct_lending'],
            1.0
        );
    }
}
