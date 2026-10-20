<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\ReinsuranceBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A carrier's structural margin is its through-the-cycle margin, catastrophe load included.
 *
 * The claim shock is therefore a pure risk around it: over the stationary district loss process the realized combined
 * ratio averages the structural one plus what the catastrophe cover costs over what it pays back, the reinsurer's
 * loading and the reinstatements. Charging the large-loss layer on top of a margin that already carries it was a
 * standing loss of ~2pp of revenue on the reinsurer's book; a cover that charged nothing paid ~2pp a quarter back.
 */
final class InsuranceClaimPricingInvariantTest extends TestCase
{
    private const QUARTERS = 8_000;
    private const STRUCTURAL_COST_RATIO = 0.60;
    private const TICKS_PER_QUARTER = 63;

    /**
     * @return iterable<string, array{InsuranceBusinessModel, float, bool}> Model, its covered share of the layer,
     *                                                                      whether it pays reinstatements.
     */
    public static function carriers(): iterable
    {
        yield 'specialty' => [new InsuranceBusinessModel(), 1.0, true];
        yield 'retail' => [new RetailInsuranceBusinessModel(), 1.0, true];
        yield 'reinsurance' => [new ReinsuranceBusinessModel(), ReinsuranceBusinessModel::CAT_BOND_ATTACHMENT_SHIELD_SHARE, false];
    }

    #[DataProvider('carriers')]
    public function testClaimShockAveragesToTheStructuralCostRatioPlusTheCoversMargin(InsuranceBusinessModel $model, float $coveredShare, bool $paysReinstatements): void
    {
        $math = MathUtility::ownStream(20261006);
        $district = new CommodityLogisticsSubsystem(MathUtility::ownStream(424242));
        $state = new MacroState();
        $dt = FinancialConstants::QUARTER_YEARS / self::TICKS_PER_QUARTER;
        $momentum = [];
        $sum = 0.0;
        $sumSq = 0.0;
        $coverCost = 0.0;
        $structuralCost = 0.0;

        for ($i = 0; $i < self::QUARTERS; $i++) {
            for ($tick = 0; $tick < self::TICKS_PER_QUARTER; ++$tick) {
                $district->calculateCatastropheLosses($state, $dt);
                $state->totalTime += $dt;
            }
            $macro = new MacroStateDTO(inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04, catastropheLossIndex: $state->catastropheLossIndex, totalTime: $state->totalTime);

            $stock = new Stock();
            $stock->setTicker('MEAN');
            $stock->setBeta('1.0');
            // Three years of the book in surplus: no capital trigger, so nothing but claims moves the ratio.
            $stock->setTotalEquity('200000000000');
            $stock->setEarningsMomentumZ($momentum);

            // The catastrophe terms are per unit of premium at the rate the book was written at.
            $rateLevel = $model->getMacroPhysics($stock, $macro)['pricing_power_multiplier'];
            // Revenue volatility off, so the expense ratio stays on its baseline and only claims vary.
            $result = $model->computeActualFinancials($stock, 10_000_000_000.0, self::STRUCTURAL_COST_RATIO, 1.0e9, 0.0, $macro, $math);
            $momentum = $result->streamZ;
            $sum += $result->clampedMargin;
            $sumSq += $result->clampedMargin ** 2;

            // The book the cover sits on: the whole book, or the P&C book of a retail carrier, or the treaty book.
            $book = match (true) {
                $model instanceof RetailInsuranceBusinessModel => $result->streamRevenue['property_casualty_premiums'] / $result->actualRevenue,
                $model instanceof ReinsuranceBusinessModel => $result->streamRevenue['treaty_reinsurance'] / $result->actualRevenue,
                default => 1.0,
            };
            // What the cover costs over what it pays back on average: the loading, and the reinstatements its
            // recoveries buy, pro rata as to amount.
            // The structural cost line, in full on the book that writes risk; a reinsurer's ILS fees carry its expense
            // share only.
            $structuralCost += self::STRUCTURAL_COST_RATIO * ($model instanceof ReinsuranceBusinessModel ? $book + ((1.0 - $book) * InsuranceBusinessModel::BASE_EXPENSE_RATIO_SHARE) : 1.0);
            $layer = $model::catastropheLayer($model::CATASTROPHE_Z_THRESHOLD, $model::CATASTROPHE_LOSS_SCALAR, $model::DISTRICT_CATASTROPHE_LOAD);
            $price = $layer['expected_recovery'] * (1.0 + InsuranceBusinessModel::CATASTROPHE_COVER_RATE_LOADING);
            $coverCost += $book / $rateLevel * (($coveredShare * $layer['expected_recovery'] * InsuranceBusinessModel::CATASTROPHE_COVER_RATE_LOADING)
                + ($paysReinstatements ? $price * $layer['expected_recovery'] / $layer['limit'] : 0.0));
        }

        $mean = $sum / self::QUARTERS;
        $standardError = sqrt(max(0.0, ($sumSq / self::QUARTERS) - ($mean ** 2)) / self::QUARTERS);
        $expected = ($structuralCost + $coverCost) / self::QUARTERS;

        $this->assertEqualsWithDelta(
            $expected,
            $mean,
            4.0 * $standardError,
            sprintf('%s: mean cost ratio %.4f (se %.5f) must sit on the structural cost line plus the cover\'s margin, %.4f.', $model::class, $mean, $standardError, $expected),
        );
    }
}
