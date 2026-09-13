<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Trait;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\Model\BareStandardModel;
use PHPUnit\Framework\TestCase;

/**
 * The treasury policy every non-financial sector inherits, addressed directly.
 *
 * Exercised indirectly by 48 sector models, which means a regression here surfaces as several dozen
 * confusing sector failures rather than one clear one, and any behaviour a sector overrides is covered
 * only where some other sector happens to leave the default in place.
 */
final class StandardTreasuryTraitTest extends TestCase
{
    private BareStandardModel $model;

    protected function setUp(): void
    {
        $this->model = new BareStandardModel();
    }

    public function testOperatingCashTargetsAreFixedRatiosOfTheOperatingBase(): void
    {
        $operatingBase = 800_000_000.0;

        $this->assertSame(
            $operatingBase * FinancialConstants::TARGET_OPERATING_CASH_RATIO,
            $this->model->calculateTargetOperatingCash($operatingBase, 0.0, 0.0),
            'The cash target is a flat ratio of the operating base.'
        );
        $this->assertSame(
            $operatingBase * FinancialConstants::MIN_OPERATING_CASH_RATIO,
            $this->model->calculateMinOperatingCash($operatingBase, 0.0, 0.0),
            'The cash floor is a flat ratio of the operating base.'
        );
        $this->assertLessThan(
            $this->model->calculateTargetOperatingCash($operatingBase, 0.0, 0.0),
            $this->model->calculateMinOperatingCash($operatingBase, 0.0, 0.0),
            'The floor must sit below the target or the firm is permanently raising cash.'
        );
    }

    /**
     * A non-financial firm's cash need is driven by its own scale, not by what it owes: the liability
     * arguments exist for the bank models that override this and must not move the standard answer.
     */
    public function testOperatingCashTargetsIgnoreLiabilitiesForNonFinancials(): void
    {
        $base = 500_000_000.0;
        $unlevered = $this->model->calculateTargetOperatingCash($base, 0.0, 0.0);
        $levered = $this->model->calculateTargetOperatingCash($base, 900_000_000.0, 2_000_000_000.0);

        $this->assertSame($unlevered, $levered, 'Debt must not raise a corporate cash target; only a deposit funded balance sheet does.');
        $this->assertSame(
            $this->model->calculateMinOperatingCash($base, 0.0, 0.0),
            $this->model->calculateMinOperatingCash($base, 900_000_000.0, 2_000_000_000.0),
            'The same holds for the floor.'
        );
    }

    public function testHoardingIsGradedOnExcessCashAgainstTheOperatingBase(): void
    {
        $operatingBase = 1_000_000_000.0;
        $target = 50_000_000.0;

        $lean = $this->model->evaluateHoardingStatus(40_000_000.0, $target, $operatingBase, 0.0);
        $this->assertSame(0.0, $lean['excess_cash'], 'Cash below target is not excess; it must floor at zero rather than go negative.');
        $this->assertFalse($lean['is_hoarder'], 'A firm below its cash target is not hoarding.');
        $this->assertFalse($lean['is_mega_hoarder']);

        // Just inside the hoarder band: excess above 25% of the operating base, below 40%.
        $hoarder = $this->model->evaluateHoardingStatus($target + 300_000_000.0, $target, $operatingBase, 0.0);
        $this->assertSame(300_000_000.0, $hoarder['excess_cash'], 'Excess cash is the surplus over target.');
        $this->assertTrue($hoarder['is_hoarder'], 'Excess above the hoarder ratio must register.');
        $this->assertFalse($hoarder['is_mega_hoarder'], 'The mega threshold sits above the plain one.');

        $mega = $this->model->evaluateHoardingStatus($target + 450_000_000.0, $target, $operatingBase, 0.0);
        $this->assertTrue($mega['is_hoarder'], 'A mega hoarder is always also a hoarder.');
        $this->assertTrue($mega['is_mega_hoarder']);

        // The two thresholds are ordered, so the bands can never invert.
        $this->assertLessThan(
            FinancialConstants::MEGA_HOARDER_THRESHOLD_RATIO,
            FinancialConstants::HOARDER_THRESHOLD_RATIO,
            'The hoarder threshold must sit below the mega threshold.'
        );
    }

    /**
     * Deposit beta is a bank concept: a corporate treasury has no depositors to reprice.
     */
    public function testNonFinancialsHaveNoDepositBetaAndDoNotDeployFundingIntoEarningAssets(): void
    {
        $this->assertSame(0.0, $this->model->calculateDepositBeta(1_000_000.0, 500_000.0, 2.0, 900_000.0));
        $this->assertFalse($this->model->deploysFundingIntoEarningAssets(), 'A corporate deploys cash on its own judgement, never automatically.');
    }

    public function testCashYieldTracksThePolicyRateAndFloorsAtZero(): void
    {
        $macro = new MacroStateDTO(policyRateEma: 0.05);
        $this->assertEqualsWithDelta(0.05 - MacroEngine::CASH_YIELD_SPREAD, $this->model->calculateCashYield($macro), 0.0000001, 'Cash earns the policy rate less the intermediation spread.');

        // At the zero bound the spread would take the yield negative; a treasury is not charged to hold cash.
        $zeroBound = new MacroStateDTO(policyRateEma: 0.001);
        $this->assertSame(0.0, $this->model->calculateCashYield($zeroBound), 'The cash yield must floor at zero, never go negative.');
    }

    /**
     * Only cash above the operating target earns: working balances are committed to running the business.
     */
    public function testInterestIncomeAccruesOnlyOnCashAboveTheOperatingTarget(): void
    {
        $macro = new MacroStateDTO(policyRateEma: 0.05);
        $math = new MathUtility();
        $yield = $this->model->calculateCashYield($macro);

        $revenue = 400_000_000.0;
        $target = $revenue * FinancialConstants::TARGET_OPERATING_CASH_RATIO;

        $atTarget = (new Stock())->setTicker('CASH');
        $atTarget->setTotalRevenue((string) $revenue);
        $atTarget->setTotalEquity('0');
        $atTarget->setCorporateTreasury((string) $target);
        $this->assertSame(0.0, $this->model->calculateInterestIncome($atTarget, $macro, $math), 'A treasury at its target earns nothing.');

        $rich = (new Stock())->setTicker('CASH');
        $rich->setTotalRevenue((string) $revenue);
        $rich->setTotalEquity('0');
        $rich->setCorporateTreasury((string) ($target + 100_000_000.0));
        $this->assertEqualsWithDelta(
            100_000_000.0 * $yield,
            $this->model->calculateInterestIncome($rich, $macro, $math),
            0.01,
            'Only the surplus over target earns the cash yield.'
        );

        $poor = (new Stock())->setTicker('CASH');
        $poor->setTotalRevenue((string) $revenue);
        $poor->setTotalEquity('0');
        $poor->setCorporateTreasury('1000');
        $this->assertSame(0.0, $this->model->calculateInterestIncome($poor, $macro, $math), 'A firm below target must not accrue negative interest income.');
    }
}
