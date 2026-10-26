<?php

declare(strict_types=1);

namespace App\Service\Corporate\Earnings;

use App\DTO\EarningsSimulationContext;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Math\Decimal;
use App\Service\Math\FirmEconomics;

/**
 * Income tax under ASC 740: the loss carryforward and its deferred tax asset behind a valuation allowance, the
 * plant's separate tax basis and the deferred liability its accelerated depreciation opens, and the after-tax net
 * income of the quarter, of the estimate and of the run rate, with the bank levy below the tax line.
 */
final class IncomeTaxLedger
{
    /**
     * Quarters of pre-tax income the valuation-allowance test reads: three years, the cumulative-loss window ASC
     * 740-10-30-21 practice treats as significant negative evidence.
     */
    public const VALUATION_ALLOWANCE_LOOKBACK_QUARTERS = 12;

    // --- Deferred Taxes (ASC 740) ---
    /**
     * Declining-balance rate multiple for tax depreciation: the 200% method of the MACRS general depreciation system.
     */
    public const TAX_DEPRECIATION_ACCELERATION = 2.0;
    /**
     * Carried stream-state key: the tax rate the plant's deferred balance was last struck at, so a rate change
     * re-strikes it.
     */
    public const STATE_PLANT_DEFERRED_TAX_RATE = EarningsEngine::CARRIED_STATE_PREFIX . 'plant_deferred_tax_rate';

    public function __construct(
        private readonly FixedAssetLedger $plant,
    ) {}

    /**
     * Taxes the quarter as filed, as analysts expected it and at its run rate: book income less the plant's timing
     * differences, the carryforward and its allowance, and the bank levy after tax.
     */
    public function bookIncomeTax(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $expectedEbt = $ctx->expectedEbit - $ctx->expectedInterestExpense + $ctx->quarterlyInterestIncome;
        $actualEbt = $ctx->ebit - $ctx->quarterlyInterestExpense + $ctx->quarterlyInterestIncome;

        $rate = $ctx->corporateTaxRate;
        $nol = (float) $stock->getNetOperatingLoss();

        // The first report under the rule recognizes the asset a loss already carried was, against retained
        // earnings (a cumulative-effect adjustment, ASC 250) rather than as one quarter's income.
        $history = $stock->getPretaxIncomeHistory();
        $openingDta = $stock->getDeferredTaxAsset();
        if ($history === null) {
            // The years before the first report are seeded at structural pre-tax income, as the trailing net income
            // is, so a new listing's first loss quarter is not read as three years of them.
            $structuralPretax = ($ctx->structuralRevenue * $ctx->stableMargin) - $ctx->quarterlyInterestExpense + $ctx->quarterlyInterestIncome;
            $history = array_fill(0, self::VALUATION_ALLOWANCE_LOOKBACK_QUARTERS - 1, $structuralPretax);
            $openingDta = $rate * max(0.0, $nol);
            if ($openingDta > 0.0) {
                $stock->setDeferredTaxAsset($openingDta);
                $stock->setTotalEquity(\bcadd($stock->getTotalEquity(), Decimal::format($openingDta, 4), 4));
                $stock->setRetainedEarnings(\bcadd($stock->getRetainedEarnings(), Decimal::format($openingDta, 4), 4));
                $ctx->cumulativeEffectAdjustment += $openingDta;
            }
        }
        $isAllowanceRequired = static fn(float $quarter): bool => array_sum(array_slice([...$history, $quarter], -self::VALUATION_ALLOWANCE_LOOKBACK_QUARTERS)) < 0.0;
        $stock->setPretaxIncomeHistory(array_values(array_map('floatval', array_slice([...$history, $actualEbt], -self::VALUATION_ALLOWANCE_LOOKBACK_QUARTERS))));

        // Taxable income is book income less the timing differences the plant opens. Accelerated tax depreciation is
        // one. A write-down is the other: the tax books allow it only through the depreciation still to come on the
        // basis it never left, so it is added back now and carried as a deductible difference, and the plant is
        // deducted once. The liability accrues on both and the loss carryforward on the taxable loss, so a loss
        // quarter's excess tax depreciation is carried forward rather than lost.
        $plantTax = $this->rollForwardTaxDepreciation($ctx);
        $recurringTiming = $plantTax['timing'];
        $timingDifference = $recurringTiming - $ctx->plantImpairment;
        $ctx->taxDepreciation = $plantTax['taxDepreciation'];

        // The plant's deferred balance is held at today's rate (ASC 740-10-35-4): a rate change re-strikes the gap the
        // quarter opened with, a deferred charge or benefit the estimate carries too, since the law is public.
        $streamState = $stock->getEarningsMomentumZ() ?? [];
        $remeasurement = ($rate - (float) ($streamState[self::STATE_PLANT_DEFERRED_TAX_RATE] ?? $rate)) * $plantTax['openingGap'];
        if ($stock->getGrossPpe() !== null) {
            $streamState[self::STATE_PLANT_DEFERRED_TAX_RATE] = $rate;
            $stock->setEarningsMomentumZ($streamState);
        }
        $openingDtl = max(0.0, (float) $stock->getDeferredTaxLiability());
        $plantDeferred = max(-$openingDtl, ($timingDifference * $rate) + $remeasurement);
        $stock->setDeferredTaxLiability((string) ($openingDtl + $plantDeferred));

        $ctx->taxableIncome = $actualEbt - $timingDifference;
        $actual = FirmEconomics::resolveIncomeTax($ctx->taxableIncome, $nol, $openingDta, $isAllowanceRequired($actualEbt), $rate);
        $stock->setNetOperatingLoss((string) $actual['nol']);
        $stock->setDeferredTaxAsset($actual['dta']);
        $ctx->deferredTaxExpense = $plantDeferred + $actual['deferred'];
        $ctx->cashTaxPaid = $actual['current'];
        $ctx->actualQuarterlyNetIncome = $actualEbt - $actual['current'] - $ctx->deferredTaxExpense;

        // Analysts read the same disclosed schedule, carryforward and allowance, and forecast no write-down.
        $expected = FirmEconomics::resolveIncomeTax($expectedEbt - $recurringTiming, $nol, $openingDta, $isAllowanceRequired($expectedEbt), $rate);
        $expectedPlantDeferred = max(-$openingDtl, ($recurringTiming * $rate) + $remeasurement);
        $ctx->expectedQuarterlyNetIncome = $expectedEbt - $expected['current'] - $expectedPlantDeferred - $expected['deferred'];

        $ctx->preTaxIncome = $actualEbt;
        // Reported tax expense is current plus deferred; the cash figure is on the context separately.
        $ctx->taxPaid = $actualEbt - $ctx->actualQuarterlyNetIncome;

        // A bank levy is charged on the balance sheet, not on profit, and is not deductible (UK Finance Act 2011,
        // Schedule 19), so it comes off below the tax line; the rate is public, so the estimate carries it too.
        $ctx->bankLevy = $ctx->strategy->calculateAnnualBankLevy($stock, $ctx->macroState) / 4.0;
        $ctx->actualQuarterlyNetIncome -= $ctx->bankLevy;
        $ctx->expectedQuarterlyNetIncome -= $ctx->bankLevy;

        // The run rate is taxed as the quarter is, on the same carryforward, allowance and recurring timing difference,
        // with the levy below it: a loss earns its tax benefit where the books recognise one, and a lender pays its
        // levy.
        $runRateEbt = $ctx->seasonallyAdjustedEbit - $ctx->quarterlyInterestExpense + $ctx->quarterlyInterestIncome;
        $runRate = FirmEconomics::resolveIncomeTax($runRateEbt - $recurringTiming, $nol, $openingDta, $isAllowanceRequired($runRateEbt), $rate);
        $ctx->seasonallyAdjustedNetIncome = $runRateEbt - $runRate['current'] - ($recurringTiming * $rate) - $runRate['deferred'] - $ctx->bankLevy;
    }

    /**
     * The tax basis a going concern's plant opens at. A firm investing steadily at nominal growth g, writing its plant
     * off at book rate d and tax rate 2d, carries a tax basis of (g + d)/(g + 2d) of book value, so a listing opens
     * mid-life on the tax books as on the book ones, with the deferred tax on the gap booked against retained earnings
     * (ASC 250 cumulative effect).
     */
    private function openSteadyStateTaxBasis(EarningsSimulationContext $ctx): float
    {
        $stock = $ctx->stock;
        $netPpe = max(0.0, $stock->getNetPpe());
        $bookRate = $this->plant->resolveDepreciationRate($ctx);
        $growth = max(0.0, SegmentBooks::fadedSecularGrowth($ctx->strategy, $stock, $ctx->macroState->totalTime) + max(0.0, $ctx->macroState->inflationEma));
        $denominator = $growth + ($bookRate * self::TAX_DEPRECIATION_ACCELERATION);
        $basis = $denominator > 0.0 ? $netPpe * ($growth + $bookRate) / $denominator : $netPpe;

        $openingDeferral = $ctx->corporateTaxRate * ($netPpe - $basis);
        if ($openingDeferral > 0.0) {
            $stock->setDeferredTaxLiability((string) ((float) $stock->getDeferredTaxLiability() + $openingDeferral));
            $stock->setTotalEquity(\bcsub($stock->getTotalEquity(), Decimal::format($openingDeferral, 4), 4));
            $stock->setRetainedEarnings(\bcsub($stock->getRetainedEarnings(), Decimal::format($openingDeferral, 4), 4));
            $ctx->cumulativeEffectAdjustment -= $openingDeferral;
        }

        return $basis;
    }

    /**
     * Advances the separate tax basis of PP&E: the quarter's tax depreciation, its timing difference against the book
     * charge, and the gap between the plant's book value and its tax basis as the quarter opened.
     *
     * Tax depreciation uses the 200% declining balance method (the MACRS general depreciation system): the
     * same asset is written off faster for the tax authority than for shareholders. Early in an asset's
     * life tax depreciation exceeds book, so taxable income is lower than book income and the unpaid tax
     * accumulates as a deferred liability. On an ageing asset the two cross over and the liability unwinds.
     *
     * A firm investing steadily therefore carries a permanently growing deferred balance, which is why
     * capital-hungry companies pay a cash tax rate well below the statutory one for decades at a time.
     *
     * @return array{taxDepreciation: float, timing: float, openingGap: float}
     */
    private function rollForwardTaxDepreciation(EarningsSimulationContext $ctx): array
    {
        $stock = $ctx->stock;
        if ($stock->getGrossPpe() === null) {
            // Financial balance sheets keep no plant, so there is no timing difference to track.
            return ['taxDepreciation' => $ctx->quarterlyDepreciation, 'timing' => 0.0, 'openingGap' => 0.0];
        }

        $basis = $stock->getPpeTaxBasis() !== null ? (float) $stock->getPpeTaxBasis() : $this->openSteadyStateTaxBasis($ctx);
        // The quarter's write-down is already off the book; the book the quarter opened with still carried it.
        $openingGap = max(0.0, $stock->getNetPpe()) + $ctx->plantImpairment - $basis;

        $taxRate = $this->plant->resolveDepreciationRate($ctx) * self::TAX_DEPRECIATION_ACCELERATION;
        $taxDepreciation = min($basis, max(0.0, $basis) * $taxRate / 4.0);

        // Additions join the basis in the ledger roll-forward, alongside the book ledger they also enter.
        $stock->setPpeTaxBasis((string) max(0.0, $basis - $taxDepreciation));

        return ['taxDepreciation' => $taxDepreciation, 'timing' => $taxDepreciation - $ctx->quarterlyDepreciation, 'openingGap' => $openingGap];
    }
}
