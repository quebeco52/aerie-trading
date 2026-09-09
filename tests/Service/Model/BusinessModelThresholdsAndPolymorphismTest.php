<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Sectors;
use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\Entity\Stock;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\Sector\BaseFinancialBusinessModel;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use App\Service\Model\Sector\TechBusinessModel;
use PHPUnit\Framework\TestCase;

class BusinessModelThresholdsAndPolymorphismTest extends TestCase
{
    private Stock $corpStock;
    private Stock $finStock;

    protected function setUp(): void
    {
        $this->corpStock = new Stock();
        $this->corpStock->setTicker('CORP');
        $this->corpStock->setTotalEquity('10000');
        $this->corpStock->setWholesaleDebt('20000');
        $this->corpStock->setCorporateTreasury('5000'); // Invested capital = 10000 + 20000 - 5000 = 25000
        $this->corpStock->setRoicTtm('0.15');
        $this->corpStock->setCurrentRoic('0.14');
        $this->corpStock->setBaselineRoic('0.12');

        $this->finStock = new Stock();
        $this->finStock->setTicker('BANK');
        $this->finStock->setTotalEquity('10000');
        $this->finStock->setWholesaleDebt('20000');
        $this->finStock->setCorporateTreasury('5000');
        $this->finStock->setRoeTtm('0.18');
        $this->finStock->setCurrentRoe('0.16');
        $this->finStock->setBaselineRoe('0.14');
        $this->finStock->setCustomerDeposits('80000');
    }

    public function testAllRegisteredBusinessModelsImplementRequiredMethods(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEST');

        foreach (Sectors::INDUSTRY_METRICS as $industry => $config) {
            $modelKey = $config['business_model'] ?? 'none';
            if ($modelKey === 'none') {
                continue;
            }

            $strategy = Sectors::getBusinessModelStrategy($modelKey);
            $this->assertInstanceOf(BusinessModelInterface::class, $strategy, "Strategy for {$industry} ({$modelKey}) must implement BusinessModelInterface");

            // Verify Threshold Getters
            $this->assertGreaterThan(0.0, $strategy->getMinIcr());
            $this->assertGreaterThanOrEqual(0.0, $strategy->getBankruptEquityThreshold());
            $this->assertGreaterThanOrEqual(0.0, $strategy->getDistressEquityThreshold());
            $this->assertGreaterThanOrEqual(0.0, $strategy->getWarningEquityThreshold());
            $this->assertGreaterThanOrEqual(0.0, $strategy->getWholesaleLeverageLimit());
            $this->assertGreaterThan(0.0, $strategy->getDividendCrisisIcr());
            $this->assertGreaterThan(0.0, $strategy->getBuybackMinIcr());
            $this->assertGreaterThan(0.0, $strategy->getReversionSpeed());
            $this->assertGreaterThanOrEqual(0.0, $strategy->getMoatSpread());
            $this->assertIsFloat($strategy->getWorkingCapitalIntensity($stock));
            $this->assertGreaterThan(0.0, $strategy->getCapExCompletionRate($stock));
            // Credit-book hooks: a loss rate is a rate, and the allowance horizon is at least a year.
            $this->assertGreaterThanOrEqual(0.0, $strategy->getThroughTheCycleCreditLossRate());
            $this->assertLessThan(0.20, $strategy->getThroughTheCycleCreditLossRate());
            $this->assertGreaterThanOrEqual(1.0, $strategy->getCreditLossHorizonYears());
            $this->assertIsBool($strategy->deploysFundingIntoEarningAssets());

            // Verify Polymorphic flags and hooks
            $this->assertIsBool($strategy->isFinancial());
            $this->assertIsBool($strategy->allowsPhysicalOrganicCapex());
            $this->assertIsBool($strategy->appliesDistressPremiumToCostOfEquity());
            $this->assertIsBool($strategy->shouldForceDeleveragingOnJunkOrHoarding());
        }
    }

    public function testCorporateVsFinancialPolymorphicDistinction(): void
    {
        $corp = new StandardCorporateBusinessModel();
        $fin = new CommercialBankBusinessModel();

        // isFinancial
        $this->assertFalse($corp->isFinancial());
        $this->assertTrue($fin->isFinancial());

        // Organic Capex & Physical Capital
        $this->assertTrue($corp->allowsPhysicalOrganicCapex());
        $this->assertFalse($fin->allowsPhysicalOrganicCapex());
        $this->assertEquals(25000.0, $corp->getPhysicalCapital($this->corpStock));
        $this->assertEquals(10000.0, $fin->getPhysicalCapital($this->finStock));

        // Returns & Evaluation Capital
        $this->assertEquals(0.15, $corp->getTrueReturn($this->corpStock));
        $this->assertEquals(0.18, $fin->getTrueReturn($this->finStock));
        $this->assertEquals(0.14, $corp->getEffectiveReturn($this->corpStock));
        $this->assertEquals(0.16, $fin->getEffectiveReturn($this->finStock));
        $this->assertEquals(25000.0, $corp->getEvaluationCapital(10000.0, 25000.0));
        $this->assertEquals(10000.0, $fin->getEvaluationCapital(10000.0, 25000.0));

        // Return Basis Income
        $this->assertEquals(500.0, $corp->getReturnBasisIncome($this->corpStock, 500.0, 600.0));
        $this->assertEquals(600.0, $fin->getReturnBasisIncome($this->finStock, 500.0, 600.0));

        // Deleveraging & Distress Flags
        $this->assertFalse($corp->appliesDistressPremiumToCostOfEquity());
        $this->assertTrue($fin->appliesDistressPremiumToCostOfEquity());
        $this->assertTrue($corp->shouldForceDeleveragingOnJunkOrHoarding());
        $this->assertFalse($fin->shouldForceDeleveragingOnJunkOrHoarding());
    }

    public function testHurdleRatePolymorphism(): void
    {
        $corp = new StandardCorporateBusinessModel();
        $fin = new CommercialBankBusinessModel();

        $metrics = new DebtMetricsDTO(100.0, 0.05, 0.05, 0.01, 0.05, 0.05, 1000.0, 5000.0, 50.0, 1050.0);
        $health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.04,
            cashYield: 0.03,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 10.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 1.0,
            wacc: 0.08,
            costOfEquity: 0.12,
            leveredBeta: 1.0,
            rawMetrics: $metrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        $this->assertEquals(0.08, $corp->getHurdleRate($health));
        $this->assertEquals(0.12, $fin->getHurdleRate($health));
    }

    public function testStructuralEpsPolymorphism(): void
    {
        $corp = new StandardCorporateBusinessModel();
        $fin = new CommercialBankBusinessModel();

        $bookValuePerShare = 50.0;
        $structuralRoic = 0.10;
        $revenuePerShare = 100.0;
        $riskFreeRate = 0.04;

        // Financials: purely BVPS * Structural Roic = 50.0 * 0.10 = 5.0
        $finEps = $fin->calculateStructuralEps($bookValuePerShare, $structuralRoic, $revenuePerShare, $riskFreeRate);
        $this->assertEquals(5.0, $finEps);

        // Corporates: accounts for operating cash vs operating BV
        $corpEps = $corp->calculateStructuralEps($bookValuePerShare, $structuralRoic, $revenuePerShare, $riskFreeRate);
        $this->assertGreaterThan(0.0, $corpEps);
    }

    public function testDeleveragingEvaluationLimitSignature(): void
    {
        $corp = new StandardCorporateBusinessModel();
        $fin = new CommercialBankBusinessModel();

        $macroDebtTolerance = 1.5;
        // Corporate delegates to macroDebtTolerance directly
        $this->assertEquals(1.5, $corp->getDeleveragingEvaluationLimit($macroDebtTolerance));

        // CommercialBank limits by wholesale leverage limit (2.0)
        $this->assertEquals(2.0, $fin->getDeleveragingEvaluationLimit($macroDebtTolerance));
    }

    /**
     * Deposit- and float-funded institutions lend out what they do not need as reserves every quarter;
     * funds keep dry powder and commit it on their own view of the cycle. The hook is what routes the
     * treasury between those two behaviours, so its answer per model is a contract.
     */
    public function testOnlyLendersAndFloatTakersDeployFundingAutomatically(): void
    {
        foreach (['commercial_bank', 'credit_services', 'shadow_bank', 'insurance', 'reinsurance', 'retail_insurance', 'clearing_house'] as $model) {
            $this->assertTrue(Sectors::getBusinessModelStrategy($model)->deploysFundingIntoEarningAssets(), "{$model} lends its funding");
        }
        foreach (['hedge_fund', 'private_equity', 'asset_manager', 'investment_bank', 'brokerage', 'distressed_debt', 'none', 'tech'] as $model) {
            $this->assertFalse(Sectors::getBusinessModelStrategy($model)->deploysFundingIntoEarningAssets(), "{$model} keeps its cash discretionary");
        }

        // Unsecured card lending loses several times what a prime bank book does; a mortgage book, less.
        $bank = Sectors::getBusinessModelStrategy('commercial_bank')->getThroughTheCycleCreditLossRate();
        $this->assertGreaterThan($bank * 4.0, Sectors::getBusinessModelStrategy('credit_services')->getThroughTheCycleCreditLossRate());
        $this->assertGreaterThan(0.0, Sectors::getBusinessModelStrategy('shadow_bank')->getThroughTheCycleCreditLossRate());
        $this->assertEqualsWithDelta(0.0, Sectors::getBusinessModelStrategy('hedge_fund')->getThroughTheCycleCreditLossRate(), 1e-12, 'a fund holds positions, not credit');
    }
}
