<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\IndustryPayoutPolicies;
use App\Data\Company\InitialMarket;
use App\Data\Company\PayoutPolicy;
use App\Data\Company\Sectors;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The dividend policy ladder, the board's annual review on it, and the industry policies firms open on.
 */
#[Group('unit')]
class PayoutPolicyTest extends TestCase
{
    public function testTheLadderPaysMoreStepByStep(): void
    {
        $previous = -1.0;
        foreach (PayoutPolicy::cases() as $rank => $policy) {
            $this->assertSame($rank, $policy->rank());
            $this->assertGreaterThan($previous, $policy->ratio(), "{$policy->value} pays more than the step below it.");
            $this->assertSame($policy, PayoutPolicy::nearest($policy->ratio()));
            $previous = $policy->ratio();
        }
    }

    public function testAShareHalfwayBetweenTwoStepsTakesTheHigher(): void
    {
        $this->assertSame(PayoutPolicy::Low, PayoutPolicy::nearest(0.10));
        $this->assertSame(PayoutPolicy::Harvest, PayoutPolicy::nearest(0.625));
    }

    /**
     * The review moves one step, past the midpoint by the margin, and holds a payout sitting on the midpoint: between
     * balanced (35%) and income (50%) the midpoint is 42.5%, so a board moves up past 45% and back down below 40%.
     */
    public function testTheReviewMovesOneStepOncePastTheMidpointByTheMargin(): void
    {
        $this->assertSame(PayoutPolicy::Balanced, PayoutPolicy::Balanced->reviewed(0.44));
        $this->assertSame(PayoutPolicy::Income, PayoutPolicy::Balanced->reviewed(0.46));
        $this->assertSame(PayoutPolicy::Income, PayoutPolicy::Income->reviewed(0.41), 'Hysteresis: back below the midpoint is not enough to step down.');
        $this->assertSame(PayoutPolicy::Balanced, PayoutPolicy::Income->reviewed(0.39));
        $this->assertSame(PayoutPolicy::Low, PayoutPolicy::Retain->reviewed(1.0), 'One step a year, however far the implied payout sits.');
        $this->assertSame(PayoutPolicy::Harvest, PayoutPolicy::Harvest->reviewed(1.0));
        $this->assertSame(PayoutPolicy::Retain, PayoutPolicy::Retain->reviewed(0.0));
    }

    public function testEveryBoardIndustryHasAPolicyAndEveryLoreStepIsOnTheLadder(): void
    {
        foreach (InitialMarket::STOCKS as $row) {
            $this->assertArrayHasKey($row['industry'], IndustryPayoutPolicies::BY_INDUSTRY, "{$row['ticker']}: {$row['industry']} has no usual policy.");
            if (isset($row['payout'])) {
                $this->assertNotNull(PayoutPolicy::tryFrom((string) $row['payout']), "{$row['ticker']} names a policy off the ladder.");
            }
        }
        $this->assertSame(PayoutPolicy::Balanced, IndustryPayoutPolicies::forIndustry('No Such Industry'));
    }

    /** A board that holds its dividend adjusts at the Lintner speed; a variable payer follows the year's earnings. */
    public function testTheBusinessModelSetsTheDividendSpeed(): void
    {
        $this->assertSame(FinancialConstants::LINTNER_QUARTERLY_ADJUSTMENT_SPEED, Sectors::strategyFor('Banks - Regional')->getDividendAdjustmentSpeed());
        $this->assertSame(FinancialConstants::VARIABLE_PAYER_ADJUSTMENT_SPEED, Sectors::strategyFor('Copper')->getDividendAdjustmentSpeed());
    }
}
