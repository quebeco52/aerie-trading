<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Pricing;

use App\Data\Company\Sectors;
use App\DTO\MarketPricingContext;
use App\Service\Market\Pricing\FairValue;
use App\Service\Math\Valuation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Valuation of FFO-reporting trusts on AFFO (FFO less the maintenance reserve the engine replaces each year).
 */
#[Group('unit')]
class FairValueTest extends TestCase
{
    private function context(string $businessModel, float $reserve, float $targetPayoutRatio = 0.75, float $dividendPerShare = 0.0): MarketPricingContext
    {
        return new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.20,
            longTermVolatility: 0.20,
            dt: 0.0,
            beta: 0.6,
            costOfDebt: 0.05,
            bookValuePerShare: 50.0,
            currentRoic: 0.10,
            trailingReturn: 0.10,
            dividendPerShare: $dividendPerShare,
            baselineIndustryPE: 16.0,
            revenuePerShare: 20.0,
            businessModel: $businessModel,
            liveCostOfEquity: 0.08,
            netDebtPerShare: 50.0,
            secularGrowth: 0.02,
            baselineRoic: 0.10,
            baselineMargin: 0.55,
            investedCapitalPerShare: 100.0,
            targetPayoutRatio: $targetPayoutRatio,
            dividendAdjustmentSpeed: 1.0,
            longRunReturn: 0.10,
            maintenanceReservePerShare: $reserve,
        );
    }

    /**
     * Deducting a trust's maintenance reserve lowers its fair value while keeping the dividend leg's cash payout equal
     * to what its policy pays on FFO, reducing retained growth to what AFFO leaves after that dividend.
     */
    public function testReitValuationDeductsMaintenanceReserveIntoAffoWhilePreservingDividendCash(): void
    {
        $strategy = Sectors::getBusinessModelStrategy('reit');
        $book = 50.0;
        $hurdle = 0.08;
        $payout = 0.70;
        $reserve = 1.5;

        $structuralFfo = $strategy->calculateStructuralEps($book, 0.10, 20.0, 0.04, 0.05, 100.0);
        $policyDividend = $structuralFfo * $payout;
        $quarterlyDividend = $policyDividend / 4.0;

        $withoutReserve = FairValue::fundamentalStateFor($this->context('reit', 0.0, $payout, $quarterlyDividend));
        $withReserve = FairValue::fundamentalStateFor($this->context('reit', $reserve, $payout, $quarterlyDividend));

        $this->assertLessThan($withoutReserve['perceived_fair_value'], $withReserve['perceived_fair_value']);

        $affo = $structuralFfo - $reserve;
        $rescaledPayout = min(1.0, $payout * $structuralFfo / $affo);
        $this->assertEqualsWithDelta($policyDividend, $affo * $rescaledPayout, 1e-12);

        $expectedIncome = Valuation::calculateDividendDiscountModel(
            $policyDividend,
            $hurdle,
            (new \App\DTO\MacroStateDTO())->tipsBreakeven,
        );

        $this->assertEqualsWithDelta($expectedIncome, $withoutReserve['analyst_targets']['income_analyst'], 1e-9);
        $this->assertEqualsWithDelta($expectedIncome, $withReserve['analyst_targets']['income_analyst'], 1e-9);
    }

    /** An operating firm's earnings are already post-depreciation, so the reserve field has no effect on its value. */
    public function testMaintenanceReserveHasNoEffectOnOperatingFirms(): void
    {
        $withoutReserve = FairValue::fundamentalStateFor($this->context('none', 0.0, 0.40, 0.50));
        $withReserve = FairValue::fundamentalStateFor($this->context('none', 2.0, 0.40, 0.50));

        $this->assertSame($withoutReserve['perceived_fair_value'], $withReserve['perceived_fair_value']);
        $this->assertSame($withoutReserve['analyst_targets'], $withReserve['analyst_targets']);
    }
}
