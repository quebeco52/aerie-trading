<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

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
 * The float is a set of reserve stocks: premium written and not yet earned, claims incurred and not yet paid, and a
 * life book's policy reserve. Δfloat = (written − earned) + (incurred − paid), and cash moves with it, so the
 * treasury is debited when a claim is paid rather than when it is incurred. A float that grew with the economy
 * whatever the firm wrote let a firm writing a third of its capacity keep earning on the reserves of a full book.
 */
final class InsuranceLossReserveTest extends TestCase
{
    private const ANNUAL_PREMIUM = 400_000_000_000.0;
    private const MARGIN = 0.045;
    private const FIXED_COST_RATIO = 0.20;

    /** @param array<string, float> $momentum */
    private function carrier(float $reserves, float $incurred, array $momentum = []): Stock
    {
        $stock = new Stock();
        $stock->setTicker('RSRV');
        $stock->setOperatingMargin((string) self::MARGIN);
        $stock->setFixedCostRatio(self::FIXED_COST_RATIO);
        $stock->setTotalEquity('300000000000');
        $stock->setCustomerDeposits((string) $reserves);
        $stock->setCorporateTreasury((string) $reserves);
        $stock->setEarningsMomentumZ([InsuranceBusinessModel::STATE_INCURRED_CLAIMS => $incurred] + $momentum);

        return $stock;
    }

    /** A quarter's incurred losses and LAE for a steady book on its structural loss and LAE ratio. */
    private function structuralIncurred(float $annualPremium): float
    {
        return $annualPremium * EarningsEngine::QUARTERLY_TIME_STEP * (1.0 - self::MARGIN)
            * (((1.0 - self::FIXED_COST_RATIO) * (1.0 - InsuranceBusinessModel::BASE_EXPENSE_RATIO_SHARE))
                + (self::FIXED_COST_RATIO * InsuranceBusinessModel::FIXED_COST_LAE_SHARE));
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
        $stock->setCorporateTreasury((string) $state['treasury']);

        return $state;
    }

    /**
     * NAIC Insurance Expense Exhibit, US P&C industry 2024 (% of premium): losses 61.5, DCC 3.8, A&O 5.5 (loss and LAE
     * 70.9); commissions 10.7, taxes 2.3, other acquisition 5.9, general 6.4 (expense 25.3). A book with the
     * industry's own cost structure files the industry's split.
     */
    public function testABookWithTheIndustryCostStructureFilesTheNaicSplit(): void
    {
        $combined = 0.709 + 0.253;
        $overhead = (0.055 + 0.059 + 0.064) / $combined;
        $stock = new Stock();
        $stock->setOperatingMargin((string) (1.0 - $combined));
        $stock->setFixedCostRatio($overhead);

        $lossAndLae = (new InsuranceBusinessModel())->resolveStructuralLossRatio($stock);

        $this->assertEqualsWithDelta(0.709, $lossAndLae, 0.002);
        $this->assertEqualsWithDelta(0.253, $combined - $lossAndLae, 0.002);
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

    /** Written premium is earned pro rata over an annual term, so half a year of it is always unearned. */
    public function testTheUnearnedPremiumReserveIsHalfAPolicyTerm(): void
    {
        $quarterlyPremium = self::ANNUAL_PREMIUM * EarningsEngine::QUARTERLY_TIME_STEP;

        $this->assertEqualsWithDelta(0.5 * self::ANNUAL_PREMIUM, (new InsuranceBusinessModel())->resolveUnearnedPremiumReserve($quarterlyPremium), 1.0);
    }

    /** A steady book holds each stock at its own ratio: half a term unearned, the NAIC loss and LAE reserve. */
    public function testASteadyBookHoldsEachStockAtItsRatio(): void
    {
        $model = new InsuranceBusinessModel();
        $quarterlyPremium = self::ANNUAL_PREMIUM * EarningsEngine::QUARTERLY_TIME_STEP;
        $settled = $model->resolveReserveToPremiumRatio($this->carrier(0.0, 0.0)) * self::ANNUAL_PREMIUM;
        $stock = $this->carrier($settled, $this->structuralIncurred(self::ANNUAL_PREMIUM), [InsuranceBusinessModel::STATE_UNEARNED_PREMIUM_BASE => $quarterlyPremium]);

        for ($quarter = 0; $quarter < 40; $quarter++) {
            $this->runQuarter($model, $stock);
        }

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $this->assertEqualsWithDelta(1.58, $settled / self::ANNUAL_PREMIUM, 1e-9, 'NAIC 2024: 1.08 loss and LAE reserves plus half a year unearned.');
        $this->assertEqualsWithDelta($settled, (float) $stock->getCustomerDeposits(), $settled * 1e-9);
        $this->assertEqualsWithDelta(0.5 * self::ANNUAL_PREMIUM, $momentum[InsuranceBusinessModel::STATE_UNEARNED_PREMIUM], 1.0);
        $this->assertEqualsWithDelta(1.55, $model->resolveReserveRunoffYears($stock), 0.01, 'A personal-lines loss reserve pays out over about a year and a half.');
    }

    /** A growing book collects premium ahead of earning it: the unearned reserve and the cash rise together. */
    public function testWrittenPremiumAheadOfEarnedRaisesFloatAndCash(): void
    {
        $model = new InsuranceBusinessModel();
        $quarterlyPremium = self::ANNUAL_PREMIUM * EarningsEngine::QUARTERLY_TIME_STEP;
        $settled = $model->resolveReserveToPremiumRatio($this->carrier(0.0, 0.0)) * self::ANNUAL_PREMIUM;
        $stock = $this->carrier($settled, $this->structuralIncurred(self::ANNUAL_PREMIUM), [InsuranceBusinessModel::STATE_UNEARNED_PREMIUM_BASE => $quarterlyPremium]);
        $this->runQuarter($model, $stock);
        $before = (float) $stock->getCustomerDeposits();

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $momentum[InsuranceBusinessModel::STATE_UNEARNED_PREMIUM_BASE] = 1.10 * $quarterlyPremium;
        $stock->setEarningsMomentumZ($momentum);
        $state = $this->runQuarter($model, $stock);

        $unearnedRise = 0.5 * 0.10 * self::ANNUAL_PREMIUM;
        $this->assertEqualsWithDelta($before + $unearnedRise, $state['customerDeposits'], 1.0);
        $this->assertEqualsWithDelta($before + $unearnedRise, $state['treasury'], 1.0);
    }

    /** A firm writing half its book loses half its loss reserves, over the runoff lag rather than overnight. */
    public function testAShrinkingBookRunsItsFloatOff(): void
    {
        $model = new InsuranceBusinessModel();
        $settled = InsuranceBusinessModel::LOSS_RESERVE_TO_PREMIUM_RATIO * self::ANNUAL_PREMIUM;
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
        $settled = InsuranceBusinessModel::LOSS_RESERVE_TO_PREMIUM_RATIO * self::ANNUAL_PREMIUM;
        $catastrophe = 0.40 * self::ANNUAL_PREMIUM;
        $stock = $this->carrier($settled, $this->structuralIncurred(self::ANNUAL_PREMIUM) + $catastrophe);

        $state = $this->runQuarter($model, $stock);
        $afterLoss = (float) $stock->getCustomerDeposits();
        $this->assertGreaterThan($settled + 0.8 * $catastrophe, $afterLoss, 'Most of a catastrophe is still unpaid at quarter end.');
        $this->assertGreaterThan($settled + 0.8 * $catastrophe, $state['treasury'], 'The cash leaves only as the claims are paid.');

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $momentum[InsuranceBusinessModel::STATE_INCURRED_CLAIMS] = $this->structuralIncurred(self::ANNUAL_PREMIUM);
        $stock->setEarningsMomentumZ($momentum);
        $previous = $afterLoss;
        for ($quarter = 0; $quarter < 40; $quarter++) {
            $this->runQuarter($model, $stock);
            $float = (float) $stock->getCustomerDeposits();
            $this->assertLessThan($previous, $float);
            $this->assertGreaterThan($settled, $float);
            $previous = $float;
        }
        $this->assertLessThan(0.01, ($previous - $settled) / $catastrophe, 'Ten years on, the loss is all but paid.');
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

    /** The reinsurer's reserves sit on its treaty book alone; a retail carrier's split between its P&C and life books. */
    public function testReserveRatiosFollowTheBooksThatCarryReserves(): void
    {
        $stock = $this->carrier(0.0, 0.0);
        $treatyShare = ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT;
        $lifeShare = RetailInsuranceBusinessModel::LIFE_INSURANCE_WEIGHT;

        $this->assertEqualsWithDelta(
            ['unearned' => $treatyShare * 0.5, 'loss' => $treatyShare * ReinsuranceBusinessModel::TREATY_LOSS_RESERVE_TO_PREMIUM_RATIO, 'life' => 0.0],
            (new ReinsuranceBusinessModel())->resolveReserveRatios($stock),
            1e-9
        );
        $this->assertEqualsWithDelta(
            ['unearned' => (1.0 - $lifeShare) * 0.5, 'loss' => (1.0 - $lifeShare) * InsuranceBusinessModel::LOSS_RESERVE_TO_PREMIUM_RATIO, 'life' => $lifeShare * RetailInsuranceBusinessModel::LIFE_RESERVE_TO_PREMIUM_RATIO],
            (new RetailInsuranceBusinessModel())->resolveReserveRatios($stock),
            1e-9
        );
    }

    /**
     * A life book's benefits build its policy reserve on its own clock: a P&C catastrophe runs through the loss
     * reserve and leaves the policy reserve where it was.
     */
    public function testLifeAndLossReservesRollOnTheirOwnClocks(): void
    {
        $model = new RetailInsuranceBusinessModel();
        $probe = $this->carrier(0.0, 0.0);
        $lifeShare = $model->resolveLifePremiumShare($probe);
        $ratios = $model->resolveReserveRatios($probe);
        $quarterlyPremium = self::ANNUAL_PREMIUM * EarningsEngine::QUARTERLY_TIME_STEP;
        $incurred = $this->structuralIncurred(self::ANNUAL_PREMIUM);
        $steady = [
            InsuranceBusinessModel::STATE_INCURRED_LIFE_BENEFITS => $incurred * $lifeShare,
            InsuranceBusinessModel::STATE_UNEARNED_PREMIUM_BASE => $quarterlyPremium * (1.0 - $lifeShare),
        ];
        $settled = array_sum($ratios) * self::ANNUAL_PREMIUM;
        $stock = $this->carrier($settled, $incurred, $steady);

        $this->runQuarter($model, $stock);
        $this->assertEqualsWithDelta($settled, (float) $stock->getCustomerDeposits(), $settled * 1e-9, 'A legacy float opens on its steady split.');
        $this->assertEqualsWithDelta($ratios['life'] * self::ANNUAL_PREMIUM, ($stock->getEarningsMomentumZ() ?? [])[InsuranceBusinessModel::STATE_LIFE_RESERVES], 1.0);
        $this->assertGreaterThan(2.0 * $model->resolveReserveRunoffYears($probe), $model->resolveLifeReserveRunoffYears($probe));

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $momentum[InsuranceBusinessModel::STATE_INCURRED_CLAIMS] = $incurred + 0.20 * self::ANNUAL_PREMIUM;
        $stock->setEarningsMomentumZ($momentum);
        $this->runQuarter($model, $stock);

        $this->assertEqualsWithDelta($ratios['life'] * self::ANNUAL_PREMIUM, ($stock->getEarningsMomentumZ() ?? [])[InsuranceBusinessModel::STATE_LIFE_RESERVES], 1.0);
        $this->assertGreaterThan($settled + 0.15 * self::ANNUAL_PREMIUM, (float) $stock->getCustomerDeposits());
    }

    /**
     * Only losses and LAE are reserved (SSAP 55, ASC 944-40): what the physics books as incurred is the filed loss
     * ratio less any reinstatement premium, which takes the claims-department share of the overhead with it, so
     * commissions, taxes and the rest of the overhead are paid in the quarter and never enter the float. Loss and
     * expense still add to the quarter's cost base, and the split state survives the stream map.
     */
    public function testThePhysicsReservesTheLossShareOnly(): void
    {
        $macro = new MacroStateDTO(inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04, catastropheLossIndexEma: 4.0);
        $fixedCosts = 1.0e9;
        $carried = [InsuranceBusinessModel::STATE_UNEARNED_PREMIUM => 2.0e10, InsuranceBusinessModel::STATE_LIFE_RESERVES => 3.0e10];

        foreach ([new InsuranceBusinessModel(), new ReinsuranceBusinessModel(), new RetailInsuranceBusinessModel()] as $model) {
            $math = MathUtility::ownStream(20261006);
            $momentum = $carried;
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

                $adjusting = $fixedCosts * InsuranceBusinessModel::FIXED_COST_LAE_SHARE;
                $variableExpenses = $result->kpis['expense_ratio'] * $premium - ($fixedCosts - $adjusting);
                $incurred = $momentum[InsuranceBusinessModel::STATE_INCURRED_CLAIMS];
                // The retail cover is sized on the P&C book's target weight, which the realized stream share only approximates.
                $tolerance = $model instanceof RetailInsuranceBusinessModel && $reinstatement > 0.0 ? $premium * 1e-3 : $premium * 1e-9;

                $this->assertEqualsWithDelta($result->kpis['loss_ratio'] * $premium - $reinstatement, $incurred, $tolerance, $label);
                $this->assertEqualsWithDelta($result->actualVariableCosts - $variableExpenses - $reinstatement + $adjusting, $incurred, $tolerance, $label);
                $this->assertGreaterThan(0.0, $variableExpenses, "{$label}: the expense share is paid, not reserved.");
                $this->assertEqualsWithDelta(
                    $result->clampedMargin + $fixedCosts / $premium,
                    $result->kpis['loss_ratio'] + $result->kpis['expense_ratio'],
                    1e-9,
                    "{$label}: loss and expense reconcile to the cost base."
                );
                foreach ($carried as $key => $value) {
                    $this->assertSame($value, $momentum[$key], "{$label}: {$key} is carried through the stream map.");
                }
                $this->assertGreaterThan(0.0, $momentum[InsuranceBusinessModel::STATE_UNEARNED_PREMIUM_BASE], $label);
                $this->assertLessThanOrEqual($premium, $momentum[InsuranceBusinessModel::STATE_UNEARNED_PREMIUM_BASE], $label);
                $this->assertSame($model instanceof RetailInsuranceBusinessModel, $momentum[InsuranceBusinessModel::STATE_INCURRED_LIFE_BENEFITS] > 0.0, $label);
            }
            $this->assertGreaterThan(0, $breaches, $model::class . ': the run reaches the cover, so the reinstatement leg is exercised.');
        }
    }

    /** The runoff lag is struck on the base that is reserved, so a steady book holds the pinned ratio. */
    public function testTheRunoffLagIsStruckOnTheLossShare(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = $this->carrier(0.0, 0.0);
        $structuralLossRatio = $this->structuralIncurred(1.0) / EarningsEngine::QUARTERLY_TIME_STEP;

        $this->assertEqualsWithDelta(
            InsuranceBusinessModel::LOSS_RESERVE_TO_PREMIUM_RATIO / $structuralLossRatio,
            $model->resolveReserveRunoffYears($stock),
            1e-12
        );
    }
}
