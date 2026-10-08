<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\ReinsuranceBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use PHPUnit\Framework\TestCase;

/**
 * The float is a reserve stock: the claims an insurer has incurred and not yet paid. Δreserves = incurred − paid,
 * and cash moves with it, so the treasury is debited when a claim is paid rather than when it is incurred. A
 * float that grew with the economy whatever the firm wrote let a firm writing a third of its capacity keep
 * earning on the reserves of a full book.
 */
final class InsuranceLossReserveTest extends TestCase
{
    private const ANNUAL_PREMIUM = 400_000_000_000.0;
    private const MARGIN = 0.045;
    private const FIXED_COST_RATIO = 0.20;

    private function carrier(float $reserves, float $incurred): Stock
    {
        $stock = new Stock();
        $stock->setTicker('RSRV');
        $stock->setOperatingMargin((string) self::MARGIN);
        $stock->setFixedCostRatio(self::FIXED_COST_RATIO);
        $stock->setTotalEquity('300000000000');
        $stock->setCustomerDeposits((string) $reserves);
        $stock->setCorporateTreasury((string) $reserves);
        $stock->setEarningsMomentumZ([InsuranceBusinessModel::STATE_INCURRED_CLAIMS => $incurred]);

        return $stock;
    }

    /** A quarter's incurred losses and LAE for a steady book on its structural loss ratio: the cost line less its expense share. */
    private function structuralIncurred(float $annualPremium): float
    {
        return $annualPremium * EarningsEngine::QUARTERLY_TIME_STEP * (1.0 - self::MARGIN) * (1.0 - self::FIXED_COST_RATIO)
            * (1.0 - InsuranceBusinessModel::BASE_EXPENSE_RATIO_SHARE);
    }

    /**
     * @return array{treasury: float, customerDeposits: float, wholesaleDebt: float, events: list<array<string, mixed>>}
     */
    private function runQuarter(InsuranceBusinessModel $model, Stock $stock): array
    {
        $state = [
            'treasury' => (float) $stock->getCorporateTreasury(),
            'customerDeposits' => (float) $stock->getCustomerDeposits(),
            'wholesaleDebt' => 0.0,
            'events' => [],
        ];
        $model->processPassiveLiabilityGrowth($stock, new MacroStateDTO(), $state, new MathUtility());

        return $state;
    }

    public function testReserveChangeIsIncurredLessPaidAndCashMovesWithIt(): void
    {
        $model = new InsuranceBusinessModel();
        $reserves = 500_000_000_000.0;
        $incurred = 90_000_000_000.0;
        $stock = $this->carrier($reserves, $incurred);

        $roll = $model->rollLossReserves($reserves, $incurred, $model->resolveReserveRunoffYears($stock));
        $state = $this->runQuarter($model, $stock);
        $change = $state['customerDeposits'] - $reserves;

        $this->assertEqualsWithDelta($incurred - $roll['paid'], $change, 1.0);
        $this->assertEqualsWithDelta($change, $state['treasury'] - $reserves, 1.0, 'Cash moves one for one with the reserve stock.');
        $this->assertGreaterThan(0.0, $roll['paid']);
        $this->assertLessThan($reserves + $incurred, $roll['paid']);
        $this->assertSame((string) $state['customerDeposits'], $stock->getCustomerDeposits());
    }

    public function testASteadyBookHoldsItsFloatAtTheReserveRatio(): void
    {
        $model = new InsuranceBusinessModel();
        $settled = InsuranceBusinessModel::RESERVE_TO_PREMIUM_RATIO * self::ANNUAL_PREMIUM;
        $stock = $this->carrier($settled, $this->structuralIncurred(self::ANNUAL_PREMIUM));

        for ($quarter = 0; $quarter < 40; $quarter++) {
            $this->runQuarter($model, $stock);
        }

        $this->assertEqualsWithDelta($settled, (float) $stock->getCustomerDeposits(), $settled * 1e-9);
    }

    /** A firm writing half its book loses half its float, over the runoff lag rather than overnight. */
    public function testAShrinkingBookRunsItsFloatOff(): void
    {
        $model = new InsuranceBusinessModel();
        $settled = InsuranceBusinessModel::RESERVE_TO_PREMIUM_RATIO * self::ANNUAL_PREMIUM;
        $stock = $this->carrier($settled, $this->structuralIncurred(self::ANNUAL_PREMIUM / 2.0));
        $runoffYears = $model->resolveReserveRunoffYears($stock);
        $quarters = (int) round($runoffYears / EarningsEngine::QUARTERLY_TIME_STEP);

        $previous = $settled;
        for ($quarter = 0; $quarter < $quarters; $quarter++) {
            $this->runQuarter($model, $stock);
            $float = (float) $stock->getCustomerDeposits();
            $this->assertLessThan($previous, $float);
            $previous = $float;
        }

        // After one runoff lag, 1/e of the gap to the smaller book's float remains.
        $gapRemaining = ($previous - $settled / 2.0) / ($settled / 2.0);
        $elapsed = $quarters * EarningsEngine::QUARTERLY_TIME_STEP;
        $this->assertEqualsWithDelta(exp(-$elapsed / $runoffYears), $gapRemaining, 0.001);
    }

    /** A catastrophe is charged to the quarter but paid over years: reserves jump, then run back off. */
    public function testACatastropheQuarterRaisesReservesThenRunsOff(): void
    {
        $model = new InsuranceBusinessModel();
        $settled = InsuranceBusinessModel::RESERVE_TO_PREMIUM_RATIO * self::ANNUAL_PREMIUM;
        $catastrophe = 0.40 * self::ANNUAL_PREMIUM;
        $stock = $this->carrier($settled, $this->structuralIncurred(self::ANNUAL_PREMIUM) + $catastrophe);

        $state = $this->runQuarter($model, $stock);
        $afterLoss = (float) $stock->getCustomerDeposits();
        $this->assertGreaterThan($settled + 0.9 * $catastrophe, $afterLoss, 'Most of a catastrophe is still unpaid at quarter end.');
        $this->assertGreaterThan($settled + 0.9 * $catastrophe, $state['treasury'], 'The cash leaves only as the claims are paid.');

        $stock->setEarningsMomentumZ([InsuranceBusinessModel::STATE_INCURRED_CLAIMS => $this->structuralIncurred(self::ANNUAL_PREMIUM)]);
        $previous = $afterLoss;
        for ($quarter = 0; $quarter < 40; $quarter++) {
            $stock->setCorporateTreasury($stock->getCustomerDeposits());
            $this->runQuarter($model, $stock);
            $float = (float) $stock->getCustomerDeposits();
            $this->assertLessThan($previous, $float);
            $this->assertGreaterThan($settled, $float);
            $previous = $float;
        }
        $this->assertLessThan(0.05, ($previous - $settled) / $catastrophe, 'Ten years on, the loss is almost all paid.');
    }

    /** A carrier without the cash to pay what falls due borrows it, and says so. */
    public function testPayoutsBeyondCashAreBorrowed(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = $this->carrier(500_000_000_000.0, 0.0);
        $stock->setCorporateTreasury('0');

        $state = $this->runQuarter($model, $stock);

        $this->assertSame(0.0, $state['treasury']);
        $this->assertGreaterThan(0.0, $state['wholesaleDebt']);
        $this->assertCount(1, $state['events']);
    }

    /** The reinsurer's reserves sit on its treaty book alone; a retail carrier's blend its P&C and life books. */
    public function testReserveRatiosFollowTheBooksThatCarryReserves(): void
    {
        $stock = $this->carrier(0.0, 0.0);

        $this->assertEqualsWithDelta(
            ReinsuranceBusinessModel::TREATY_RESERVE_TO_PREMIUM_RATIO * ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT,
            (new ReinsuranceBusinessModel())->resolveReserveToPremiumRatio($stock),
            1e-9
        );
        $this->assertEqualsWithDelta(
            (RetailInsuranceBusinessModel::PROPERTY_CASUALTY_WEIGHT * RetailInsuranceBusinessModel::RESERVE_TO_PREMIUM_RATIO)
                + (RetailInsuranceBusinessModel::LIFE_INSURANCE_WEIGHT * RetailInsuranceBusinessModel::LIFE_RESERVE_TO_PREMIUM_RATIO),
            (new RetailInsuranceBusinessModel())->resolveReserveToPremiumRatio($stock),
            1e-9
        );
    }

    /**
     * Only losses and LAE are reserved (SSAP 55, ASC 944-40): what the physics books as incurred is the filed loss
     * ratio less any reinstatement premium, so the acquisition and underwriting expense share of the cost line is
     * paid in the quarter and never enters the float. Loss and expense still add to the quarter's cost base.
     */
    public function testThePhysicsReservesTheLossShareOnly(): void
    {
        $macro = new MacroStateDTO(inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04, catastropheLossIndexEma: 4.0);
        $fixedCosts = 1.0e9;

        foreach ([new InsuranceBusinessModel(), new ReinsuranceBusinessModel(), new RetailInsuranceBusinessModel()] as $model) {
            $math = MathUtility::ownStream(20261006);
            $momentum = [];
            $breaches = 0;
            for ($quarter = 0; $quarter < 200; $quarter++) {
                $stock = new Stock();
                $stock->setTicker('CLMS');
                $stock->setBeta('1.0');
                $stock->setTotalEquity('200000000000');
                $stock->setEarningsMomentumZ($momentum);

                $result = $model->computeActualFinancials($stock, 10_000_000_000.0, 0.60, $fixedCosts, 0.2, $macro, $math);
                $momentum = $result->streamZ;
                $premium = $result->actualRevenue;
                $label = $model::class . " quarter {$quarter}";

                $reinstatement = 0.0;
                if ($result->eventType === ShockEvent::REINSURANCE_ATTACHMENT_BREACH) {
                    $breaches++;
                    if (!$model instanceof ReinsuranceBusinessModel) {
                        $bookWeight = $model instanceof RetailInsuranceBusinessModel
                            ? $result->streamRevenue['property_casualty_premiums'] / max(1.0, $premium) : 1.0;
                        $reinstatement = $premium * InsuranceBusinessModel::REINSTATEMENT_PREMIUM_RATE * $bookWeight;
                    }
                }

                $variableExpenses = $result->kpis['expense_ratio'] * $premium - $fixedCosts;
                $incurred = $momentum[InsuranceBusinessModel::STATE_INCURRED_CLAIMS];
                // The retail cover is sized on the P&C book's target weight, which the realized stream share only approximates.
                $tolerance = $model instanceof RetailInsuranceBusinessModel && $reinstatement > 0.0 ? $premium * 1e-3 : $premium * 1e-9;

                $this->assertEqualsWithDelta($result->kpis['loss_ratio'] * $premium - $reinstatement, $incurred, $tolerance, $label);
                $this->assertEqualsWithDelta($result->actualVariableCosts - $variableExpenses - $reinstatement, $incurred, $tolerance, $label);
                $this->assertGreaterThan(0.0, $variableExpenses, "{$label}: the expense share is paid, not reserved.");
                $this->assertEqualsWithDelta(
                    $result->clampedMargin + $fixedCosts / $premium,
                    $result->kpis['loss_ratio'] + $result->kpis['expense_ratio'],
                    1e-9,
                    "{$label}: loss and expense reconcile to the cost base."
                );
            }
            $this->assertGreaterThan(0, $breaches, $model::class . ': the run reaches the cover, so the reinstatement leg is exercised.');
        }
    }

    /** The runoff lag is struck on the base that is reserved, so a steady book holds the pinned ratio. */
    public function testTheRunoffLagIsStruckOnTheLossShare(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = $this->carrier(0.0, 0.0);
        $structuralLossRatio = (1.0 - self::MARGIN) * (1.0 - self::FIXED_COST_RATIO) * (1.0 - InsuranceBusinessModel::BASE_EXPENSE_RATIO_SHARE);

        $this->assertEqualsWithDelta(
            InsuranceBusinessModel::RESERVE_TO_PREMIUM_RATIO / $structuralLossRatio,
            $model->resolveReserveRunoffYears($stock),
            1e-12
        );
    }
}
