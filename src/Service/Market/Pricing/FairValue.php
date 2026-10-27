<?php

declare(strict_types=1);

namespace App\Service\Market\Pricing;

use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Service\Corporate\Earnings\CapitalBudgetEngine;
use App\Service\Math\MacroConstants;
use App\Service\Math\Valuation;

/**
 * A name's fair value: the equity legs (earnings on a P/E with its revenue floor, book on a P/B, the dividends its
 * policy pays) struck on its structural earnings and again under the laws the market expects, the analyst targets read
 * off those legs, and the pull speed toward them once funding stress has slowed it. No random draw.
 */
final class FairValue
{
    // --- Analyst Targets ---
    /** Share of the P/B leg the value analyst targets. */
    private const VALUE_ANALYST_BOOK_MULT = 0.80;

    // --- Revenue Floor (Margin-Adjusted Price-to-Sales) ---
    /**
     * Floor on the P/E anchor the price-to-sales multiple is struck from, so a distressed multiple does not erase the
     * revenue floor entirely.
     */
    private const MIN_PS_ANCHOR_PE = 10.0;
    /** Bounds on the implied net margin the price-to-sales multiple is built on. */
    private const MIN_IMPLIED_NET_MARGIN = 0.01;
    private const MAX_IMPLIED_NET_MARGIN = 0.30;

    // --- Dividend Discount Support ---
    /** Floor on the required yield used to discount the dividend stream. */
    private const MIN_DIVIDEND_REQUIRED_YIELD = 0.02;
    /** Cap on the fundamental growth a retained-earnings calculation may imply for the dividend stream. */
    private const MAX_DIVIDEND_IMPLIED_GROWTH = 0.04;

    // --- Relative Valuation Shrinkage (Vasicek 1973) ---
    /** Minimum price-to-sales multiple clamp during valuation stress. */
    public const MIN_PS_FALLBACK_MULT = 0.2;
    /** Maximum price-to-sales multiple clamp during valuation expansion. */
    public const MAX_PS_FALLBACK_MULT = 5.0;
    /** Multiplier scaling liquidity drag as macro stress rises: the recession gap plus inflation's miss of target. */
    public const FUNDING_LIQUIDITY_STRESS_FACTOR = 2.0;

    /**
     * Fair value, pull speed, policy repricing and analyst targets struck on a pricing context.
     *
     * @return array{perceived_fair_value: float, dynamic_reversion: float, policy_repricing: float, analyst_targets: array<string, float>}
     */
    public static function fundamentalStateFor(MarketPricingContext $ctx): array
    {
        return self::evaluateFundamentalState(
            $ctx->macroState->outputGap ?? 0.0,
            $ctx->macroState->inflation ?? 0.02,
            $ctx->beta,
            $ctx->costOfDebt,
            $ctx->reversionSpeed,
            $ctx->trailingReturn,
            $ctx->macroState->policyRate ?? 0.04,
            $ctx->bookValuePerShare,
            $ctx->dividendPerShare,
            $ctx->baselineIndustryPE,
            $ctx->revenuePerShare,
            $ctx->businessModel,
            $ctx->liveCostOfEquity,
            $ctx->netDebtPerShare,
            $ctx->secularGrowth,
            $ctx->baselineRoic,
            $ctx->baselineMargin,
            $ctx->accrualsRatio,
            $ctx->investedCapitalPerShare,
            $ctx->macroState,
            $ctx->tangibleBookValuePerShare,
            $ctx->targetPayoutRatio,
            $ctx->dividendAdjustmentSpeed,
            $ctx->policyBasesPerShare,
            $ctx->longRunReturn,
            $ctx->excessCashPerShare,
            $ctx->capacityGap,
            $ctx->capacitySpare,
        );
    }

    /**
     * Evaluates the fundamental fair value and dynamic reversion speed of a company
     * by combining structural ROIC, cost of capital and the structural EPS run-rate. The reversion speed is
     * the constant pull, slowed when funding liquidity dries up.
     *
     * @param float $outputGap           The macroeconomic output gap (boom vs bust).
     * @param float $inflation           The current inflation rate, read as stress; growth carries the breakeven.
     * @param float $beta                The stock's sensitivity to systemic market moves.
     * @param float|null $costOfDebt     The firm's pre-tax marginal borrowing rate; null prices it at the 10Y plus the
     *                                   IG spread.
     * @param float $reversionSpeed      The baseline speed at which the stock reverts to fair value.
     * @param float $trailingReturn      The firm's recent filed return (ROIC, or ROE for a lender).
     * @param float $riskFreeRate        The central bank's policy rate.
     * @param float $bookValuePerShare   The equity value per share.
     * @param float $dividendPerShare    The absolute quarterly dividend per share.
     * @param float $baselineIndustryPE  The standard P/E multiple for this industry.
     * @param float $revenuePerShare     The total revenue per share.
     * @param string $businessModel      The type of business (e.g., 'tech', 'bank', 'retail').
     * @param float $liveCostOfEquity    The Cost of Equity (CAPM on the 10Y), the hurdle every equity leg is discounted
     *                                   at.
     * @param float $netDebtPerShare     The net debt per share.
     * @param float $secularGrowth       The long-term growth rate of the sector/economy.
     * @param float $baselineRoic        The historical average ROIC.
     * @param float $baselineMargin      The historical average net margin.
     * @param float $accrualsRatio       The accruals-to-assets ratio.
     * @param float|null $tangibleBookValuePerShare Book equity less goodwill per share; a financial's P/B leg is struck
     *                                              on it.
     * @param float $targetPayoutRatio   The payout ratio the firm's dividend policy steers to.
     * @param float $dividendAdjustmentSpeed Share of the gap to its target dividend the firm closes each quarter.
     * @param array<string, float> $policyBasesPerShare What each law the firm's accounts answer to is charged on or
     *                                                  moves, per share, keyed by lever.
     * @param float|null $longRunReturn  The firm's measured long-run return; the trailing return's gap to it is valued
     *                                   as it fades.
     * @param float $excessCashPerShare  Cash beyond what the business needs to run, valued at face beside it.
     * @param float $capacityGap         Log of the plant the expected market calls for over the plant in use.
     * @param float $capacitySpare       A company with plant by segment: its segments' plant to spare, valued apart.
     * @return array{perceived_fair_value: float, dynamic_reversion: float, policy_repricing: float, analyst_targets: array}
     */
    private static function evaluateFundamentalState(
        float $outputGap,
        float $inflation,
        float $beta,
        ?float $costOfDebt,
        float $reversionSpeed,
        float $trailingReturn,
        float $riskFreeRate,
        float $bookValuePerShare,
        float $dividendPerShare,
        float $baselineIndustryPE,
        float $revenuePerShare,
        string $businessModel,
        float $liveCostOfEquity,
        float $netDebtPerShare,
        float $secularGrowth,
        float $baselineRoic,
        float $baselineMargin,
        float $accrualsRatio,
        float $investedCapitalPerShare,
        ?MacroStateDTO $macroState,
        ?float $tangibleBookValuePerShare,
        float $targetPayoutRatio,
        float $dividendAdjustmentSpeed,
        array $policyBasesPerShare,
        ?float $longRunReturn,
        float $excessCashPerShare,
        float $capacityGap = 0.0,
        float $capacitySpare = 0.0,
    ): array {

        $strategy = \App\Data\Company\Sectors::getBusinessModelStrategy($businessModel);
        $macro = $macroState ?? new MacroStateDTO();

        // Cash beyond what the business needs is worth its face: every leg below values the business on the book it
        // runs on, and the surplus is added back after at face.
        $bookValuePerShare = max(0.0, $bookValuePerShare - $excessCashPerShare);

        // Every leg below values EQUITY: levered earnings on a P/E, book equity on a P/B, the dividends its policy
        // pays. All are discounted at the cost of equity on the return the firm earns on its equity (Damodaran 2012,
        // ch. 18-20); WACC belongs to unlevered cash flows, which nothing here capitalises.
        $hurdleRate = $liveCostOfEquity;
        $afterTaxCostOfDebt = ($costOfDebt ?? ($macro->yield10yEma + $macro->macroCreditSpread))
            * (1.0 - $strategy->getEffectiveTaxRate($macro->corporateTaxRate));

        // Structural ROIC is determined by the business model strategy, allowing sectors like Insurance
        // to smooth out extreme catastrophic volatility and price based on through-the-cycle baseline capacity.
        $structuralRoic = $strategy->calculateStructuralRoic(
            $strategy->getValuationReturn($trailingReturn, $longRunReturn, $liveCostOfEquity),
            $baselineRoic,
            $revenuePerShare,
            $bookValuePerShare,
            $baselineMargin,
            $hurdleRate,
        );

        // Without a balance sheet there is no leverage to apply, and the operating return stands in.
        $equityReturn = $investedCapitalPerShare > 0.0
            ? $strategy->getEquityReturn($structuralRoic, $investedCapitalPerShare, $bookValuePerShare, $afterTaxCostOfDebt)
            : $structuralRoic;

        // MACROECONOMIC STRESS INDEX (MSI)
        $recessionStress = max(0.0, -$outputGap);
        $inflationStress = abs($inflation - MacroConstants::TARGET_INFLATION);
        $systemicStressIndex = $recessionStress + $inflationStress;

        $trueStructuralEps = $strategy->calculateStructuralEps(
            $bookValuePerShare,
            $structuralRoic,
            $revenuePerShare,
            $riskFreeRate,
            $afterTaxCostOfDebt,
            // Real capital employed when the caller supplied a balance sheet; the model's structural
            // approximation from revenue and book value otherwise.
            $investedCapitalPerShare > 0.0 ? $investedCapitalPerShare : null,
        );

        // The value legs struck on one set of earnings, so a law that moves the earnings moves every leg they reach,
        // floors and all.
        $strikeLegs = function (float $equityReturn, float $structuralEps) use (
            $strategy,
            $macro,
            $macroState,
            $hurdleRate,
            $outputGap,
            $beta,
            $secularGrowth,
            $baselineIndustryPE,
            $accrualsRatio,
            $revenuePerShare,
            $netDebtPerShare,
            $bookValuePerShare,
            $tangibleBookValuePerShare,
            $dividendPerShare,
            $targetPayoutRatio,
            $dividendAdjustmentSpeed,
            $capacityGap,
            $capacitySpare
        ): array {
            // 1. MACRO FORWARD GUIDANCE & FUNDAMENTAL P/E
            // The growth transmission and the Sloan accruals discount live in Valuation because the corporate
            // engines strike the same multiple when management decides on a buyback, an offering or a deal.
            // Perpetual growth carries the inflation the hurdle does: the ten-year breakeven inside the CAPM's 10Y,
            // not this quarter's print, so an oil spike does not read as faster growth forever.
            $catchUpGrowth = Valuation::capacityCatchUpGrowth($capacityGap, CapitalBudgetEngine::CAPITAL_ERROR_CORRECTION_SPEED, $hurdleRate - $macro->tipsBreakeven)
                + Valuation::capacityCatchUpGrowth($capacitySpare, CapitalBudgetEngine::CAPITAL_ERROR_CORRECTION_SPEED, $hurdleRate - $macro->tipsBreakeven);
            $expectedGrowth = Valuation::calculateFundableGrowth(
                Valuation::calculateExpectedNominalGrowth($secularGrowth, $outputGap, $beta, $macro->tipsBreakeven, $hurdleRate),
                $equityReturn,
                $targetPayoutRatio,
                $macro->tipsBreakeven,
                $catchUpGrowth,
            );

            $fairValuePE = Valuation::calculateQualityAdjustedFairValuePE(
                $hurdleRate,
                $equityReturn,
                $expectedGrowth,
                $baselineIndustryPE,
                $accrualsRatio,
                Valuation::sectorPriorYieldShift($hurdleRate, $beta, $macro->tipsBreakeven, $catchUpGrowth),
            );

            // The structural run-rate is the normalized EPS: the trailing print already reaches it once, through the
            // persistent-equivalent return (Ohlson 1995), and blending the print in again would count it twice.
            $normalizedEps = max(0.01, $structuralEps);

            $peFairValue = max(0.00, $normalizedEps * $fairValuePE);

            // THE ZOMBIE FIX: Revenue Floor (Margin-Adjusted Price-to-Sales) High-turnover physical corporations
            // (retail, grocers) have thin net margins and cannot support software-like P/S multiples.
            $trueMargin = $structuralEps > 0
                ? $structuralEps / max(0.01, $revenuePerShare)
                : self::MIN_IMPLIED_NET_MARGIN;
            $impliedMargin = max(self::MIN_IMPLIED_NET_MARGIN, min(self::MAX_IMPLIED_NET_MARGIN, $trueMargin));

            $psMultiple = max(
                self::MIN_PS_FALLBACK_MULT,
                min(self::MAX_PS_FALLBACK_MULT, max(self::MIN_PS_ANCHOR_PE, $fairValuePE) * $impliedMargin),
            );
            $revenueFloorValue = $revenuePerShare * $psMultiple;
            $revenueFloorEquityValue = max(0.01, $revenueFloorValue - $netDebtPerShare);

            // The earnings leg: the value-driver P/E value, never below what the model's revenue floor is worth.
            $earningsValue = $strategy->calculateEarningsValue($revenueFloorEquityValue, $peFairValue);

            // Dividend Discount Model (Gordon 1962) on the dividends the firm's own policy will pay: its target payout
            // of normalized earnings, growing at the rate its retained earnings compound at (Higgins 1977), plus the
            // present value of today's gap to that target while partial adjustment closes it (Lintner 1956). Struck on
            // the policy rather than the last cheque, a cut costs what is missed while the dividend recovers, and there
            // is no step at zero. Only the models that price on income read it; an operating company's value does not
            // turn on its payout.
            $dividendSupportValue = 0.0;
            if ($targetPayoutRatio > 0.0 && $normalizedEps > 0.0) {
                $requiredYield = max(self::MIN_DIVIDEND_REQUIRED_YIELD, $hurdleRate);
                $payoutRatio = min(1.0, $targetPayoutRatio);
                $returnOnEquity = $bookValuePerShare > 0.0 ? $normalizedEps / $bookValuePerShare : 0.0;
                $sustainableGrowth = max(0.0, min(self::MAX_DIVIDEND_IMPLIED_GROWTH, $returnOnEquity * (1.0 - $payoutRatio)));
                $targetDividend = $normalizedEps * $payoutRatio;

                $dividendSupportValue = max(
                    0.0,
                    Valuation::calculateDividendDiscountModel($targetDividend, $requiredYield, $sustainableGrowth)
                        + Valuation::calculateDividendAdjustmentValue(($dividendPerShare * 4.0) - $targetDividend, $requiredYield, $dividendAdjustmentSpeed),
                );
            }

            // Intrinsic Price-to-Book (P/B) Valuation
            // The multiple belongs to the business model: plant earning above its hurdle is worth more than the
            // plant, while a portfolio of marketable stakes is worth the portfolio, so a trust declares 1.0 and
            // this term hands its model net asset value per share rather than a multiple of it.
            $pbFairValue = $bookValuePerShare * $strategy->getIntrinsicPbMultiple($equityReturn, $hurdleRate);

            // A financial is valued on tangible book, since its regulator deducts goodwill from capital. The same
            // earnings over the smaller base give the same value wherever the multiple is interior; the change is
            // that the floor and ceiling then bound price to TANGIBLE book, so goodwill cannot prop up the floor.
            // A financial's equity return is its return on capital (BaseFinancialBusinessModel::getEquityReturn()).
            if ($strategy->isFinancial() && $tangibleBookValuePerShare !== null && $bookValuePerShare > 0.0) {
                $tangibleBook = max(0.0, $tangibleBookValuePerShare);
                $returnOnTangible = $tangibleBook > 0.0 ? $equityReturn * $bookValuePerShare / $tangibleBook : 0.0;
                $pbFairValue = $tangibleBook * $strategy->getIntrinsicPbMultiple($returnOnTangible, $hurdleRate);
            }

            // PERFECTED WEIGHTED CONSENSUS MODEL
            $fairValue = $strategy->calculateFairValue($earningsValue, $pbFairValue, $normalizedEps, $dividendSupportValue);

            // Closed-end structures trade below the assets they hold, by a gap that moves with the cycle. Applied
            // here rather than inside the consensus so it reaches the reversion target: the price is pulled toward
            // the discounted value, which is what makes a widening discount a fall rather than a mispricing.
            $fairValue *= (1.0 - $strategy->getStructuralValuationDiscount($macroState ?? new MacroStateDTO()));

            return [
                'fair_value' => $fairValue,
                'earnings_value' => $earningsValue,
                'dividend_value' => $dividendSupportValue,
                'book_value' => $pbFairValue,
                'expected_growth' => $expectedGrowth,
                'normalized_eps' => $normalizedEps,
            ];
        };

        $legs = $strikeLegs($equityReturn, $trueStructuralEps);

        // The laws the market expects rather than the ones the trailing earnings carry (PolicyCapitalization), struck
        // into the earnings every leg is valued on, so a firm held at its book floor loses no more than the floor gives
        // up. A forecast revised this tick moves the price with the target, as news does, rather than leaving it to
        // drift there.
        $policyRepricing = 1.0;
        if ($macroState !== null) {
            // Capitalized on the same perpetual growth the multiple is struck on: the raw outlook can sit within a few
            // basis points of the hurdle in an inflationary boom, and a policy gap spread over that would wipe out
            // value.
            $capRate = max(PolicyCapitalization::MIN_CAP_RATE, $hurdleRate - Valuation::perpetualGrowthRate($hurdleRate, $legs['expected_growth']));
            // The dividend its policy pays, as the income leg strikes it, over the value before the laws: the yield its
            // holders' tax is charged on. A dividend omitted for a quarter is still the policy's, and still taxed.
            $policyDividend = $legs['normalized_eps'] * min(1.0, $targetPayoutRatio * Valuation::dividendTaxPayoutScale($macroState->dividendTaxRate));
            $dividendYield = $policyDividend / max(0.01, $legs['fair_value']);

            $underExpectedLaws = function (bool $previous) use ($strikeLegs, $strategy, $macroState, $policyBasesPerShare, $capRate, $dividendYield, $equityReturn, $trueStructuralEps, $bookValuePerShare): array {
                $taxExpected = $strategy->getEffectiveTaxRate($macroState->corporateTaxRate + PolicyCapitalization::corporateTaxShiftGap($macroState, $capRate, $previous));
                $taxScale = PolicyCapitalization::afterTaxScale($strategy->getEffectiveTaxRate($macroState->corporateTaxRate), $taxExpected);
                $earningsGap = PolicyCapitalization::earningsGap($macroState, $policyBasesPerShare, $taxExpected, $capRate, $previous);
                $returnGap = $bookValuePerShare > 0.0 ? $earningsGap / $bookValuePerShare : 0.0;

                $legs = $strikeLegs(($equityReturn * $taxScale) + $returnGap, ($trueStructuralEps * $taxScale) + $earningsGap);
                $legs['fair_value'] = max(0.01, $legs['fair_value'])
                    * Valuation::dividendTaxValueScale($dividendYield, $capRate, PolicyCapitalization::expectedDividendTaxRate($macroState, $capRate, $previous));

                return $legs;
            };

            $legs = $underExpectedLaws(false);
            // Laws unrevised would strike the same legs again, a ratio of exactly one.
            $policyRepricing = $macroState->lawsAheadRevised()
                ? ($legs['fair_value'] + $excessCashPerShare) / ($underExpectedLaws(true)['fair_value'] + $excessCashPerShare)
                : 1.0;
        }

        $perceivedFairValue = max(0.01, $legs['fair_value'] + $excessCashPerShare);

        // Brunnermeier-Pedersen Funding Liquidity Dampener (2009)
        // During macroeconomic stress and systemic crises, funding liquidity dries up and capital-constrained
        // arbitrageurs pull back, slowing down market efficiency and price correction speed.
        $liquidityDampener = 1.0 / (1.0 + ($systemicStressIndex * self::FUNDING_LIQUIDITY_STRESS_FACTOR));
        $dynamicReversion = $reversionSpeed * $liquidityDampener;

        return [
            'perceived_fair_value' => $perceivedFairValue,
            'dynamic_reversion'    => $dynamicReversion,
            'policy_repricing'     => $policyRepricing,
            'analyst_targets'      => [
                'growth_analyst' => max(0.01, $legs['earnings_value'] + $excessCashPerShare),
                'income_analyst' => max(0.01, $legs['dividend_value'] + $excessCashPerShare),
                'value_analyst'  => max(0.01, ($legs['book_value'] * self::VALUE_ANALYST_BOOK_MULT) + $excessCashPerShare),
            ],
        ];
    }
}
