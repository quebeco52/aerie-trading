<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\ReinsuranceBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The float is a set of reserve stocks: premium written and not yet earned, claims incurred and not yet paid, and a
 * life book's policy reserve. Δfloat = (written − earned) + (incurred − paid), and cash moves with it, so the
 * treasury is debited when a claim is paid rather than when it is incurred. A float that grew with the economy
 * whatever the firm wrote let a firm writing a third of its capacity keep earning on the reserves of a full book.
 */
#[Group('unit')]
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
        return $annualPremium * FinancialConstants::QUARTER_YEARS * (1.0 - self::MARGIN)
            * (((1.0 - self::FIXED_COST_RATIO) * (1.0 - InsuranceBusinessModel::BASE_EXPENSE_RATIO_SHARE))
                + (self::FIXED_COST_RATIO * InsuranceBusinessModel::FIXED_COST_LAE_SHARE));
    }

    /**
     * @return array{treasury: float, customerDeposits: float, wholesaleDebt: float, events: array<mixed>}
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
        $this->assertIsFloat($state['treasury']);
        $this->assertIsFloat($state['customerDeposits']);
        $this->assertIsFloat($state['wholesaleDebt']);
        $this->assertIsArray($state['events']);
        $stock->setCorporateTreasury((string) $state['treasury']);

        return [
            'treasury' => $state['treasury'],
            'customerDeposits' => $state['customerDeposits'],
            'wholesaleDebt' => $state['wholesaleDebt'],
            'events' => $state['events'],
        ];
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
        $quarterlyPremium = self::ANNUAL_PREMIUM * FinancialConstants::QUARTER_YEARS;

        $this->assertEqualsWithDelta(0.5 * self::ANNUAL_PREMIUM, (new InsuranceBusinessModel())->resolveUnearnedPremiumReserve($quarterlyPremium), 1.0);
    }

    /**
     * A book growing with the economy at trend holds each stock at its own ratio, as the books the ratios were measured
     * on do: half a term unearned, the NAIC loss and LAE reserve.
     */
    public function testABookGrowingAtTrendHoldsEachStockAtItsRatio(): void
    {
        $model = new InsuranceBusinessModel();
        $ratio = $model->resolveReserveToPremiumRatio($this->carrier(0.0, 0.0));
        $stock = $this->carrier($ratio * self::ANNUAL_PREMIUM, $this->structuralIncurred(self::ANNUAL_PREMIUM), [InsuranceBusinessModel::STATE_UNEARNED_PREMIUM_BASE => self::ANNUAL_PREMIUM * FinancialConstants::QUARTER_YEARS]);

        $premium = self::ANNUAL_PREMIUM;
        for ($quarter = 0; $quarter < 80; $quarter++) {
            $premium *= exp(InsuranceBusinessModel::TREND_BOOK_GROWTH * FinancialConstants::QUARTER_YEARS);
            $momentum = $stock->getEarningsMomentumZ() ?? [];
            $momentum[InsuranceBusinessModel::STATE_INCURRED_CLAIMS] = $this->structuralIncurred($premium);
            $momentum[InsuranceBusinessModel::STATE_UNEARNED_PREMIUM_BASE] = $premium * FinancialConstants::QUARTER_YEARS;
            $stock->setEarningsMomentumZ($momentum);
            $this->runQuarter($model, $stock);
        }

        $this->assertEqualsWithDelta(1.58, $ratio, 1e-9, 'NAIC 2024: 1.08 loss and LAE reserves plus half a year unearned.');
        // To the quarter's discretisation of a continuous runoff.
        $this->assertEqualsWithDelta($ratio, (float) $stock->getCustomerDeposits() / $premium, 0.01 * $ratio);
        $this->assertEqualsWithDelta(0.5 * $premium, ($stock->getEarningsMomentumZ() ?? [])[InsuranceBusinessModel::STATE_UNEARNED_PREMIUM], 1.0);
    }

    /** What a claim reserve fed at a steady rate settles at: a year's incurred losses times the runoff. */
    private static function staticReserve(float $quarterlyIncurred, float $runoffYears): float
    {
        return $quarterlyIncurred / FinancialConstants::QUARTER_YEARS * $runoffYears;
    }

    /** A growing book collects premium ahead of earning it: the unearned reserve and the cash rise together. */
    public function testWrittenPremiumAheadOfEarnedRaisesFloatAndCash(): void
    {
        $model = new InsuranceBusinessModel();
        $quarterlyPremium = self::ANNUAL_PREMIUM * FinancialConstants::QUARTER_YEARS;
        $settled = $model->resolveReserveToPremiumRatio($this->carrier(0.0, 0.0)) * self::ANNUAL_PREMIUM;
        $stock = $this->carrier($settled, $this->structuralIncurred(self::ANNUAL_PREMIUM), [InsuranceBusinessModel::STATE_UNEARNED_PREMIUM_BASE => $quarterlyPremium]);
        $this->runQuarter($model, $stock);
        // The same quarter written at the same premium, for the comparison.
        $level = clone $stock;
        $flat = $this->runQuarter($model, $level);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $momentum[InsuranceBusinessModel::STATE_UNEARNED_PREMIUM_BASE] = 1.10 * $quarterlyPremium;
        $stock->setEarningsMomentumZ($momentum);
        $state = $this->runQuarter($model, $stock);

        $unearnedRise = 0.5 * 0.10 * self::ANNUAL_PREMIUM;
        $this->assertEqualsWithDelta($flat['customerDeposits'] + $unearnedRise, $state['customerDeposits'], 1.0);
        $this->assertEqualsWithDelta($flat['treasury'] + $unearnedRise, $state['treasury'], 1.0);
    }

    /** A firm writing half its book loses half its loss reserves, over the runoff lag rather than overnight. */
    public function testAShrinkingBookRunsItsFloatOff(): void
    {
        $model = new InsuranceBusinessModel();
        $settled = InsuranceBusinessModel::LOSS_RESERVE_TO_PREMIUM_RATIO * self::ANNUAL_PREMIUM;
        $stock = $this->carrier($settled, $this->structuralIncurred(self::ANNUAL_PREMIUM / 2.0));
        $runoffYears = $model->resolveReserveRunoffYears($stock);
        $quarters = (int) round($runoffYears / FinancialConstants::QUARTER_YEARS);

        $previous = $settled;
        for ($quarter = 0; $quarter < $quarters; $quarter++) {
            $this->runQuarter($model, $stock);
            $float = (float) $stock->getCustomerDeposits();
            $this->assertLessThan($previous, $float);
            $previous = $float;
        }

        // After one runoff lag, 1/e of the gap to the smaller book's reserve remains.
        $halfBook = self::staticReserve($this->structuralIncurred(self::ANNUAL_PREMIUM / 2.0), $runoffYears);
        $gapRemaining = ($previous - $halfBook) / ($settled - $halfBook);
        $elapsed = $quarters * FinancialConstants::QUARTER_YEARS;
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
        // Without the storm the reserve would have moved toward the book's own level; the storm's part runs off above
        // it.
        $book = self::staticReserve($this->structuralIncurred(self::ANNUAL_PREMIUM), $model->resolveReserveRunoffYears($stock));
        $previous = $afterLoss;
        for ($quarter = 0; $quarter < 40; $quarter++) {
            $this->runQuarter($model, $stock);
            $float = (float) $stock->getCustomerDeposits();
            $this->assertLessThan($previous, $float);
            $this->assertGreaterThan($book, $float);
            $previous = $float;
        }
        $this->assertLessThan(0.01, ($previous - $book) / $catastrophe, 'Ten years on, the loss is all but paid.');
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

    /**
     * The reinsurer's reserves sit on its treaty book alone; a retail carrier's split between its P&C and life books.
     */
    public function testReserveRatiosFollowTheBooksThatCarryReserves(): void
    {
        $stock = $this->carrier(0.0, 0.0);
        $treatyShare = ReinsuranceBusinessModel::TREATY_REINSURANCE_WEIGHT;
        $lifeShare = RetailInsuranceBusinessModel::LIFE_INSURANCE_WEIGHT;

        $this->assertEqualsWithDelta(
            ['unearned' => $treatyShare * 0.5, 'loss' => $treatyShare * ReinsuranceBusinessModel::TREATY_LOSS_RESERVE_TO_PREMIUM_RATIO, 'life' => 0.0],
            (new ReinsuranceBusinessModel())->resolveReserveRatios($stock),
            1e-9,
        );
        $this->assertEqualsWithDelta(
            ['unearned' => (1.0 - $lifeShare) * 0.5, 'loss' => (1.0 - $lifeShare) * InsuranceBusinessModel::LOSS_RESERVE_TO_PREMIUM_RATIO, 'life' => $lifeShare * RetailInsuranceBusinessModel::LIFE_RESERVE_TO_PREMIUM_RATIO],
            (new RetailInsuranceBusinessModel())->resolveReserveRatios($stock),
            1e-9,
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
        $quarterlyPremium = self::ANNUAL_PREMIUM * FinancialConstants::QUARTER_YEARS;
        $incurred = $this->structuralIncurred(self::ANNUAL_PREMIUM);
        $steady = [
            InsuranceBusinessModel::STATE_INCURRED_LIFE_BENEFITS => $incurred * $lifeShare,
            InsuranceBusinessModel::STATE_UNEARNED_PREMIUM_BASE => $quarterlyPremium * (1.0 - $lifeShare),
        ];
        $settled = array_sum($ratios) * self::ANNUAL_PREMIUM;
        $stock = $this->carrier($settled, $incurred, $steady);

        $this->runQuarter($model, $stock);
        $this->assertGreaterThan(2.0 * $model->resolveReserveRunoffYears($probe), $model->resolveLifeReserveRunoffYears($probe));

        // The same quarter with and without a P&C catastrophe: the loss reserve takes it, the policy reserve does not.
        $calm = clone $stock;
        $this->runQuarter($model, $calm);
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $momentum[InsuranceBusinessModel::STATE_INCURRED_CLAIMS] = $incurred + 0.20 * self::ANNUAL_PREMIUM;
        $stock->setEarningsMomentumZ($momentum);
        $this->runQuarter($model, $stock);

        $life = static fn(Stock $carrier): float => (float) (($carrier->getEarningsMomentumZ() ?? [])[InsuranceBusinessModel::STATE_LIFE_RESERVES] ?? 0.0);
        $this->assertGreaterThan(0.0, $life($calm));
        $this->assertEqualsWithDelta($life($calm), $life($stock), 1.0);
        $this->assertGreaterThan((float) $calm->getCustomerDeposits() + 0.15 * self::ANNUAL_PREMIUM, (float) $stock->getCustomerDeposits());
    }

    /**
     * Only losses and LAE are reserved (SSAP 55, ASC 944-40): what the physics books as incurred is the filed loss
     * ratio less any reinstatement premium, which takes the claims-department share of the overhead with it, so
     * commissions, taxes and the rest of the overhead are paid in the quarter and never enter the float. Loss and
     * expense still add to the quarter's cost base, and the split state survives the stream map.
     */
    public function testThePhysicsReservesTheLossShareOnly(): void
    {
        $fixedCosts = 1.0e9;
        $carried = [InsuranceBusinessModel::STATE_UNEARNED_PREMIUM => 2.0e10, InsuranceBusinessModel::STATE_LIFE_RESERVES => 3.0e10];

        // Each carrier with a probe on the recovery its reinstatement is charged on.
        $probes = [
            new class extends InsuranceBusinessModel {
                public float $recovery = 0.0;

                protected function resolveReinstatementPremium(array $layer, float $recovery): float
                {
                    $this->recovery = $recovery;

                    return parent::resolveReinstatementPremium($layer, $recovery);
                }
            },
            new ReinsuranceBusinessModel(),
            new class extends RetailInsuranceBusinessModel {
                public float $recovery = 0.0;

                protected function resolveReinstatementPremium(array $layer, float $recovery): float
                {
                    $this->recovery = $recovery;

                    return parent::resolveReinstatementPremium($layer, $recovery);
                }
            },
        ];
        foreach ($probes as $model) {
            $math = MathUtility::ownStream(20261006);
            $momentum = $carried;
            $breaches = 0;
            for ($quarter = 0; $quarter < 200; $quarter++) {
                $stock = new Stock();
                $stock->setTicker('CLMS');
                $stock->setBeta('1.0');
                $stock->setTotalEquity('200000000000');
                $stock->setEarningsMomentumZ($momentum);
                // A district held at four average years of open claims: each quarter's losses run well above average.
                $macro = new MacroStateDTO(inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04, catastropheLossIndex: 4.0, catastropheLossIndexEma: 4.0, totalTime: 10.0 + (0.25 * $quarter));

                $rateLevel = $model->getMacroPhysics($stock, $macro)['pricing_power_multiplier'];
                $result = $model->computeActualFinancials($stock, 10_000_000_000.0, 0.60, $fixedCosts, 0.2, $macro, $math);
                $momentum = $result->streamZ;
                $premium = $result->actualRevenue;
                $label = $model::class . " quarter {$quarter}";

                // Reinsurance premium is not a loss: the cover's premium every quarter (the reinsurer buys its
                // collateral's share of the layer), and a reinstatement in a quarter the cover paid out, pro rata to
                // the recovery, all on the covered book's volume.
                $layer = $model::catastropheLayer($model::CATASTROPHE_Z_THRESHOLD, $model::CATASTROPHE_LOSS_SCALAR, $model::DISTRICT_CATASTROPHE_LOAD);
                $coverRate = $layer['expected_recovery'] * (1.0 + InsuranceBusinessModel::CATASTROPHE_COVER_RATE_LOADING);
                $bookRevenue = match (true) {
                    $model instanceof RetailInsuranceBusinessModel => $result->streamRevenue['property_casualty_premiums'],
                    $model instanceof ReinsuranceBusinessModel => $result->streamRevenue['treaty_reinsurance'],
                    default => $premium,
                };
                $volume = $bookRevenue / $rateLevel;
                $reinstatement = $model instanceof ReinsuranceBusinessModel
                    ? $volume * ReinsuranceBusinessModel::CAT_BOND_ATTACHMENT_SHIELD_SHARE * $coverRate
                    : $volume * ($coverRate + ($coverRate * $model->recovery / $layer['limit']));
                if ($result->eventType === ShockEvent::REINSURANCE_ATTACHMENT_BREACH) {
                    $breaches++;
                }

                $adjusting = $fixedCosts * InsuranceBusinessModel::FIXED_COST_LAE_SHARE;
                $variableExpenses = $result->kpis['expense_ratio'] * $premium - ($fixedCosts - $adjusting);
                $incurred = $momentum[InsuranceBusinessModel::STATE_INCURRED_CLAIMS];
                $tolerance = $premium * 1e-9;

                $this->assertEqualsWithDelta($result->kpis['loss_ratio'] * $premium - $reinstatement, $incurred, $tolerance, $label);
                $this->assertEqualsWithDelta($result->actualVariableCosts - $variableExpenses - $reinstatement + $adjusting, $incurred, $tolerance, $label);
                $this->assertGreaterThan(0.0, $variableExpenses, "{$label}: the expense share is paid, not reserved.");
                $this->assertEqualsWithDelta(
                    $result->clampedMargin + $fixedCosts / $premium,
                    $result->kpis['loss_ratio'] + $result->kpis['expense_ratio'],
                    1e-9,
                    "{$label}: loss and expense reconcile to the cost base.",
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
        $structuralLossRatio = $this->structuralIncurred(1.0) / FinancialConstants::QUARTER_YEARS;

        // Held at its ratio while the book grows at trend: 1/τ = incurred / R − g.
        $this->assertEqualsWithDelta(
            InsuranceBusinessModel::LOSS_RESERVE_TO_PREMIUM_RATIO / ($structuralLossRatio - (InsuranceBusinessModel::TREND_BOOK_GROWTH * InsuranceBusinessModel::LOSS_RESERVE_TO_PREMIUM_RATIO)),
            $model->resolveReserveRunoffYears($stock),
            1e-12,
        );
    }
}
