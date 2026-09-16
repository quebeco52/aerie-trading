<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\AnchorHoldings;
use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Permanent-Capital Investment Companies & Industrial Holding Spheres.
 *
 * A trust is filed as a conglomerate and is not one. Its book is not plant and working capital, it is a
 * portfolio of marketable stakes carried at what they are worth — and that inverts the valuation. An
 * operating company is worth the earnings its assets produce, with book a weak second opinion. A trust is
 * the other way round: its profit is dominated by the mark on the portfolio, so a multiple on it would
 * price the same information twice. The book IS the valuation, and the earnings line is left unread.
 *
 * Financial Physics:
 * - Net Asset Value: the intrinsic multiple on book is exactly one, so the term the parent receives as a
 *   ROIC-scaled multiple arrives instead as net asset value per share.
 * - The book is MARKED, which is what makes the rest true. Stakes are declared in AnchorHoldings and valued
 *   by AnchorStakeLedger at the held companies' market caps — opened at seed, onto the balance sheet at
 *   every report, into the valuation every tick. Before this a sphere compounded straight through the
 *   crashes that halve a real trust's book.
 * - Nothing about the composition is declared. Treasury is a ledger balance, the stakes are priced off the
 *   board, the consolidated sleeve is what those leave — as BALANCES against capital employed, never as
 *   shares of NAV. Three sleeve dials have been deleted from this class, each stale within one retune.
 * - The Holding-Company Discount is the defining feature of the class, not a haircut on it: control blocks
 *   cannot be sold at the screen price and a realised stake is taxed on exit, so the parts never make the
 *   sum. Sentiment moves it, not fundamentals (Lee, Shleifer & Thaler 1991) — it gaps wide in a downturn
 *   and closes in an expansion while the assets underneath do neither.
 * - The income mix INVERTS the asset mix, as an accounting fact: a controlled subsidiary is consolidated
 *   and contributes its whole top line, a minority stake only the dividend it declares. So the anchor
 *   stakes that ARE the trust barely appear on its income statement.
 * - Dividends received are twice damped — declared out of trailing earnings, then smoothed by the board —
 *   so the receipt line is the quietest thing in the group and it arrives late.
 * - Only the consolidated subsidiaries buy anything, so the input basket is charged against that stream
 *   alone. Dividends and treasury income have no cost of goods.
 * - NOT a financial, deliberately: an investment COMPANY, not an investment trust. isFinancial() is built
 *   for a closed-end fund that consolidates nothing — it skips the fixed asset ledger, forbids organic
 *   capex, depreciates total EQUITY and drops the firm out of the industry share ledger. Here the
 *   controlled half is real factories and berths, so filing it that way would delete them.
 */
class InvestmentCompanyBusinessModel extends ConglomerateBusinessModel
{
    // --- Net Asset Value Anchoring ---
    /** Intrinsic multiple on book. One, because a trust's book is a portfolio carried at value — so pbFairValue becomes net asset value per share. */
    public const INTRINSIC_PB_MULTIPLE = 1.00;

    /** Weight on the dividend discount leg. A trust distributes what it RECEIVES, so this is a separate claim on value and not a restatement of the unread earnings line. */
    public const NAV_DDM_WEIGHT = 0.15;

    // --- Holding-Company Discount ---
    /** Long-run discount to NAV at a neutral cycle: control blocks are unsaleable at the screen price and a realised stake is taxed on exit. */
    public const NAV_DISCOUNT_BASE = 0.30;

    /** Widening per unit of output gap below neutral. The discount is a sentiment index, so it gaps out in a contraction while the assets underneath do not. */
    public const NAV_DISCOUNT_CYCLE_SCALAR = 4.00;

    /** Narrowing per unit of RESIDUAL consumer confidence — the half the cycle above does not already explain (Lemmon & Portniaguina 2006). Confidence mean-reverts slowly, which is what makes the discount persistent. */
    public const NAV_DISCOUNT_SENTIMENT_SCALAR = 0.60;

    /** Floor: even at a cyclical peak a control block cannot be sold at the screen price. */
    public const MIN_NAV_DISCOUNT = 0.15;

    /** Ceiling: past this the trust is worth more broken up than held, and the foundation's own bid arrives. */
    public const MAX_NAV_DISCOUNT = 0.50;

    // --- Industry Structure ---
    /** Share of an idiosyncratic gain taken from peers. Zero: "Investment Companies" is a filing category, not a product market — no rival trust could have won that dividend. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.00;

    // --- Analyst Visibility & Error ---
    /** A trust's holdings are published and separately listed, so its assets read more easily than a conglomerate's — which is why the discount is visible enough to trade. */
    public const BASE_COVERAGE_VISIBILITY = 0.55;

    // --- NAV to Revenue Conversion ---
    /** Revenue a controlled subsidiary books per unit of carrying value. Consolidation takes the whole top line, so a fifth of the book can be most of the income statement. */
    public const WHOLLY_OWNED_ASSET_TURNOVER = 0.30;

    /** Revenue a listed stake books per unit of market value: its dividend yield, and nothing else, however much the company earns. */
    public const LISTED_DIVIDEND_YIELD = 0.035;

    /** Revenue the treasury books per unit of its value: blended yield on cash, short sovereign paper and the credit book. */
    public const TREASURY_INCOME_YIELD = 0.040;

    /** An investment company owns no factories directly; the parent's industrial stream is switched off. */
    public const INDUSTRIAL_CONGLOMERATE_WEIGHT = 0.00;

    // --- Stream Volatility Scalars ---
    /** Volatility multiplier for consolidated subsidiary sales: real operating businesses, priced as such. */
    public const WHOLLY_OWNED_VARIANCE_SCALAR = 0.30;

    /** Volatility multiplier for dividends received. Set from trailing earnings then smoothed again, so this is the quietest line in the group. */
    public const LISTED_DIVIDEND_VARIANCE_SCALAR = 0.06;

    // --- Macro Sensitivities ---
    /** Output gap sensitivity of subsidiary volume. Niche manufacturers sell replacement demand, not new-build. */
    public const WHOLLY_OWNED_MACRO_SCALAR = 0.70;

    /** Sensitivity of upstreamed dividends to the LAGGED cycle. Small on purpose: the board's smoothing damps on top of the delay. */
    public const LISTED_DIVIDEND_MACRO_SCALAR = 0.25;

    /** Years between the cycle and the dividend it eventually pays, via the parent's distributed-lag helper. */
    public const DEMAND_LAG_YEARS = 1.50;

    // --- Analyst Observability ---
    /** Fraction of the dividend line visible to consensus. A holding declares publicly BEFORE the trust reports receiving it, so the receipt is nearly solved arithmetic. */
    public const LISTED_DIVIDEND_OBSERVABLE_DISCOUNT = 0.95;

    /** Fraction of subsidiary performance visible ahead of the filing; these are unlisted and report only through the group. */
    public const WHOLLY_OWNED_OBSERVABLE_DISCOUNT = 0.45;

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility
    ): SectorPhysicsResult {
        // THE PORTFOLIO SPLIT IS READ, NEVER DECLARED. Subsidiaries, listed stakes and treasury are the
        // whole book, so any two settle the third and a dial for it can only drift. Both that used to sit
        // here did — one claimed 60% of book against stakes worth 51%, the other left 5% in no sleeve.
        //
        // Two are measured: the treasury is a ledger balance the engines rewrite every quarter, and the
        // listed stakes are priced off the board by AnchorStakeLedger. The consolidated sleeve is the one
        // thing nothing measures, so it is the residual — the honest way round.
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value => self::PRICING_POWER_INDEX,
        ]);

        // BALANCES, never shares of NAV. These three are assets and assets sum to equity PLUS what is owed,
        // so a residual struck over equity is short by exactly the leverage — and it decays, until around
        // 4x the portfolio the consolidated stream vanishes outright. Invested capital already carries the
        // debt and the deferred tax, so a mark moves it and the stakes identically and the residual is
        // invariant, which is correct: the subsidiaries did not change. Same base getPlantCapital() uses.
        $treasuryValue = max(0.0, (float) $stock->getCorporateTreasury());
        $listedValue = $this->resolveListedStakeValue($stock);
        $consolidatedValue = max(0.0, $stock->getInvestedCapital() - $listedValue);

        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta = max(self::MIN_CYCLICAL_BETA_FLOOR, $this->getOperatingCyclicality($stock));

        // Assets to income, each at the rate its KIND of ownership permits. The simplex normalises these,
        // so the revenue mix inverts the asset mix as a consequence rather than by being entered backwards.
        // A stream left at zero is never drawn and never reported.
        $activeWeights = $streams->resolveActiveStreamWeights([
            'wholly_owned'          => $consolidatedValue * self::WHOLLY_OWNED_ASSET_TURNOVER,
            'listed_portfolio'      => $listedValue * self::LISTED_DIVIDEND_YIELD,
            'financial_investments' => $treasuryValue * self::TREASURY_INCOME_YIELD,
        ]);

        $ownedWeight  = $activeWeights['wholly_owned'];
        $listedWeight = $activeWeights['listed_portfolio'];
        $floatWeight  = $activeWeights['financial_investments'];

        $ownedZ  = $ownedWeight > 0.0 ? $streams->generateZ('wholly_owned', 0.30) : 0.0;
        $listedZ = $listedWeight > 0.0 ? $streams->generateZ('listed_portfolio', 0.55) : 0.0;
        $floatZ  = $floatWeight > 0.0 ? $streams->generateZ('financial_investments', 0.15) : 0.0;
        $eventZ  = $streams->generateExogenousZ('event', 0.10);

        // --- Consolidated subsidiaries: an operating business, responding to the cycle it is in ---
        $ownedMacroBoost = $macroState->outputGapEma * self::WHOLLY_OWNED_MACRO_SCALAR * $beta;

        // --- Dividends received: the cycle as it was, not as it is ---
        // Declared out of trailing earnings, then smoothed by the board: delay and damping are separate.
        $laggedGap = $this->resolveLaggedOutputGap($stock, $macroState);
        $listedMacroBoost = $laggedGap * self::LISTED_DIVIDEND_MACRO_SCALAR;

        // --- Treasury: the parent's contrarian float book, unchanged ---
        $dealActivityShift = ($macroState->dealActivityIndexEma - MacroEngine::DEAL_ACTIVITY_BASELINE) / MacroEngine::DEAL_ACTIVITY_BASELINE;
        $contrarianSurge = $this->resolveContrarianFloatSurge(
            $macroState,
            $mathUtility,
            $macroState->outputGapEma,
            $macroState->macroCreditSpreadEma,
            $macroState->macroCreditSpread - $macroState->macroCreditSpreadEma,
            max(0.0, $dealActivityShift) * self::DEAL_ACTIVITY_DIVESTITURE_SCALAR
        );

        // --- Tail risk: a subsidiary written down, which is the one event a trust reports itself ---
        $eventType = null;
        $restructuringPenalty = 0.0;
        if ($eventZ < self::SUBSIDIARY_WRITEDOWN_Z_SCORE) {
            $restructuringPenalty = self::RESTRUCTURING_DRAG_PENALTY * (1.0 - ($pricingPower * 0.50));
            $eventType = ShockEvent::CONGLOMERATE_SUBSIDIARY_WRITEDOWN;
        }

        $ownedShock  = $ownedZ  * ($baselineVol * self::WHOLLY_OWNED_VARIANCE_SCALAR);
        $listedShock = $listedZ * ($baselineVol * self::LISTED_DIVIDEND_VARIANCE_SCALAR);
        $floatShock  = $floatZ  * ($baselineVol * self::FLOAT_VARIANCE_SCALAR);

        $streamRevenues = [];
        if ($ownedWeight > 0.0) {
            $streamRevenues['wholly_owned'] = max(0.0, $expectedRevenue * $ownedWeight * (1.0 + $ownedShock + $ownedMacroBoost));
        }
        if ($listedWeight > 0.0) {
            $streamRevenues['listed_portfolio'] = max(0.0, $expectedRevenue * $listedWeight * (1.0 + $listedShock + $listedMacroBoost));
        }
        if ($floatWeight > 0.0) {
            $streamRevenues['financial_investments'] = max(0.0, $expectedRevenue * $floatWeight * (1.0 + $floatShock + $contrarianSurge));
        }

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Only the consolidated subsidiaries buy anything. A dividend received and a coupon clipped have no
        // cost of goods behind them, so the basket is charged against the operating stream's realized share
        // rather than against the group's revenue.
        $ownedShare = $actualRevenue > 0.0 ? ($streamRevenues['wholly_owned'] ?? 0.0) / $actualRevenue : 0.0;
        $ppiCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin)
            * max(0.0, $ownedShare);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $restructuringPenalty + $ppiCostDrag);

        $observableShockZ = ($ownedShock * $ownedWeight * self::WHOLLY_OWNED_OBSERVABLE_DISCOUNT) +
            ($listedShock * $listedWeight * self::LISTED_DIVIDEND_OBSERVABLE_DISCOUNT) +
            ($floatShock * $floatWeight * self::FLOAT_OBSERVABLE_DISCOUNT) +
            ($ownedMacroBoost * $ownedWeight * self::WHOLLY_OWNED_OBSERVABLE_DISCOUNT) +
            ($listedMacroBoost * $listedWeight * self::LISTED_DIVIDEND_OBSERVABLE_DISCOUNT) +
            ($contrarianSurge * $floatWeight * self::CONTRARIAN_OBSERVABLE_DISCOUNT);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $streams->resolveDominantShockZ([$ownedZ, $listedZ, $floatZ], $eventZ),
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    /**
     * What the listed sleeve is worth: the marked carrying value, and nothing when there is no mark.
     *
     * No fallback share of book, deliberately. Three composition dials have rotted here, each within one
     * retune — the last claimed 60% of book against stakes worth 51.5%, a gap of 160 billion. Seed and
     * report both mark, so an unmarked book means no measurement exists, and zero is what that is worth.
     */
    protected function resolveListedStakeValue(Stock $stock): float
    {
        return max(0.0, (float) ($stock->getListedStakesCarrying() ?? 0.0));
    }

    /**
     * The value the sphere's investment line opens at, or NULL while its stakes have no price yet.
     *
     * Null is a refusal, not a zero: plant is whatever the portfolio leaves, so striking it first would
     * hand the fixed-asset ledger the shareholdings and depreciate them as machinery. The caller waits a
     * quarter instead. Seed and reset mark against the finished board, so this rarely fires.
     *
     * A trust declaring no holdings returns zero rather than refusing — it has no portfolio to wait for.
     */
    public function getOpeningInvestmentAssets(Stock $stock): ?float
    {
        $carrying = $stock->getListedStakesCarrying();

        if ($carrying !== null) {
            return max(0.0, (float) $carrying);
        }

        return AnchorHoldings::forHolder($stock->getTicker()) === [] ? 0.0 : null;
    }

    /** A trust depreciates the plant its subsidiaries own and nothing else. No capital-proxy fallback: here that proxy IS the portfolio, so a sphere consolidating nothing would depreciate its shareholdings. */
    public function getDepreciableBase(Stock $stock): float
    {
        return max(0.0, $stock->getNetPpe());
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'corporate_default_rate_ema',
            'deal_activity_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'industrial_metals_index_ema',
            'macro_credit_spread',
            'macro_credit_spread_ema',
            'output_gap_ema',
            'perceived_neutral_rate',
            'policy_rate_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }

    /**
     * The treasury's income is already in the revenue line and must not be booked twice.
     *
     * The trait's version pays the policy rate less a spread on surplus cash — right for a manufacturer
     * whose cash is a buffer. Here the same balance IS a business line: financial_investments draws its
     * weight from Stock::corporateTreasury and runs the contrarian float physics over it. Idle cash at the
     * front rate is precisely what a sphere's dry powder is not.
     */
    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        return 0.0;
    }

    /** A trust's book is a portfolio carried at value, not plant earning a spread, so the multiple is one and the valuation term becomes NAV per share itself. */
    public function getIntrinsicPbMultiple(float $structuralRoic, float $hurdleRate): float
    {
        return self::INTRINSIC_PB_MULTIPLE;
    }

    /**
     * Net asset value, blended with the dividend it distributes out of what it receives. $pbFairValue IS
     * NAV per share here, because the multiple above is one; the earnings leg is deliberately unread.
     */
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        return $dividendSupportValue > 0.0
            ? ($pbFairValue * (1.0 - self::NAV_DDM_WEIGHT)) + ($dividendSupportValue * self::NAV_DDM_WEIGHT)
            : $pbFairValue;
    }

    /**
     * The discount control mechanism every closed-end board has and most of them use.
     *
     * Buying a share at 0.70x NAV and cancelling it hands the remaining holders 30 cents of assets for
     * every 70 they spend — the one deployment whose return does not depend on the portfolio doing
     * anything, and why a wide discount is self-correcting rather than permanent.
     */
    public function resolveRepurchaseAccretion(Stock $stock, float $currentPrice): float
    {
        $navPerShare = (float) $stock->getBookValuePerShare();

        if ($navPerShare <= 0.0 || $currentPrice <= 0.0) {
            return 0.0;
        }

        return max(0.0, 1.0 - ($currentPrice / $navPerShare));
    }

    /**
     * The holding-company discount: the cycle, and the mood.
     *
     * Lee, Shleifer & Thaler (1991) made the closed-end discount a sentiment index rather than a valuation
     * residual; Lemmon & Portniaguina (2006) found its measurable half is consumer confidence, which
     * predicts the discount where fundamentals do not. Confidence is slow mean-reverting, so the discount
     * inherits its persistence.
     *
     * Both inputs are exogenous to any one listing, deliberately: a discount driven by the firm's own
     * realised volatility would raise the volatility that widened it, with no damping in the loop.
     *
     * Confidence enters as the RESIDUAL, not the level. Read against the index's construction constant it
     * delivered a further 2.3 of widening per unit of gap on top of the 4.0 declared above, and a standing
     * 0.07 on the base: the discount sat on MAX_NAV_DISCOUNT through every ordinary downturn and BRKW
     * quoted at a flat half of net asset value. The cycle is priced once, by the term that says so.
     */
    public function getStructuralValuationDiscount(MacroStateDTO $macroState): float
    {
        $sentimentGap = $macroState->sentimentResidual();

        return max(self::MIN_NAV_DISCOUNT, min(
            self::MAX_NAV_DISCOUNT,
            self::NAV_DISCOUNT_BASE
                - ($macroState->outputGapEma * self::NAV_DISCOUNT_CYCLE_SCALAR)
                - ($sentimentGap * self::NAV_DISCOUNT_SENTIMENT_SCALAR)
        ));
    }
}
