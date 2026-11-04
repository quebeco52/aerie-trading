<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Company\AnchorHoldings;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;

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
 * - Net Asset Value, a sum of the parts: the stakes at market net of the tax deferred on them, the cash at
 *   face, and the subsidiaries at what their earnings are worth (resolveSubsidiaryRevaluation()), as a
 *   holding company publishes it. The intrinsic multiple on it is exactly one.
 * - The book is MARKED, which is what makes the rest true. Stakes are declared in AnchorHoldings and valued
 *   by AnchorStakeLedger at the held companies' market caps — opened at seed, onto the balance sheet at
 *   every report, into the valuation every tick. Before this a sphere compounded straight through the
 *   crashes that halve a real trust's book.
 * - Nothing about the composition is declared. Treasury is a ledger balance, the stakes are priced off the
 *   board, the consolidated sleeve is what those leave: capital employed less the stakes
 *   (getPlantCapital()), which is all the subsidiaries sell on.
 * - The Holding-Company Discount is the defining feature of the class, not a haircut on it: control blocks
 *   cannot be sold at the screen price and the foundation's control means no holder can force the break-up
 *   that would close the gap. The tax on exit and the subsidiaries' returns are in the net asset value
 *   already. Sentiment moves it, not fundamentals (Lee, Shleifer & Thaler 1991) — it gaps wide in a downturn
 *   and closes in an expansion while the assets underneath do neither.
 * - The income mix INVERTS the asset mix, as an accounting fact: a controlled subsidiary is consolidated
 *   and contributes its whole top line, a stake only the dividend its company declares. So the anchor
 *   stakes that ARE the trust barely appear on its income statement.
 * - Revenue is the subsidiaries' sales alone. The dividends received are the ones the held companies
 *   paid (AnchorStakeLedger::accrueDividends()), booked below the operating line as investment income,
 *   and the treasury earns the money market and the credit book through the parent's interest income.
 *   Neither has a cost of goods, so neither passes through the subsidiaries' margin.
 * - NOT a financial, deliberately: an investment COMPANY, not an investment trust. isFinancial() is built
 *   for a closed-end fund that consolidates nothing — it skips the fixed asset ledger, forbids organic
 *   capex, depreciates total EQUITY and drops the firm out of the industry share ledger. Here the
 *   controlled half is real factories and berths, so filing it that way would delete them.
 */
class InvestmentCompanyBusinessModel extends ConglomerateBusinessModel
{
    // --- Net Asset Value Anchoring ---
    /**
     * Intrinsic multiple on book. One, because a trust's book is a portfolio carried at value — so pbFairValue becomes
     * net asset value per share.
     */
    public const INTRINSIC_PB_MULTIPLE = 1.00;

    // --- Holding-Company Discount ---
    /**
     * Long-run discount to NAV at a neutral cycle (20%): control blocks are unsaleable at the screen price and the
     * foundation's control rules out the break-up that would close it. The exit tax and the subsidiaries' returns are
     * inside the NAV it is struck on.
     */
    public const NAV_DISCOUNT_BASE = 0.20;

    /**
     * Widening per unit of output gap below neutral. The discount is a sentiment index, so it gaps out in a contraction
     * while the assets underneath do not.
     */
    public const NAV_DISCOUNT_CYCLE_SCALAR = 4.00;

    /**
     * Narrowing per unit of RESIDUAL consumer confidence — the half the cycle above does not already explain (Lemmon &
     * Portniaguina 2006). Confidence mean-reverts slowly, which is what makes the discount persistent.
     */
    public const NAV_DISCOUNT_SENTIMENT_SCALAR = 0.60;

    /** Floor (5%): even at a cyclical peak a control block cannot be sold at the screen price. */
    public const MIN_NAV_DISCOUNT = 0.05;

    /** Ceiling (45%): past this the trust is worth more broken up than held, and the foundation's own bid arrives. */
    public const MAX_NAV_DISCOUNT = 0.45;

    // --- Industry Structure ---
    /**
     * Share of an idiosyncratic gain taken from peers. Zero: "Investment Companies" is a filing category, not a product
     * market — no rival trust could have won that dividend.
     */
    public const INDUSTRY_SUBSTITUTABILITY = 0.00;

    // --- Analyst Visibility & Error ---
    /**
     * A trust's holdings are published and separately listed, so its assets read more easily than a conglomerate's —
     * which is why the discount is visible enough to trade.
     */
    public const BASE_COVERAGE_VISIBILITY = 0.55;

    // --- Stream Volatility Scalars ---
    /** Volatility multiplier for consolidated subsidiary sales: real operating businesses, priced as such. */
    public const WHOLLY_OWNED_VARIANCE_SCALAR = 0.30;

    // --- Macro Sensitivities ---
    /** Output gap sensitivity of subsidiary volume. Niche manufacturers sell replacement demand, not new-build. */
    public const WHOLLY_OWNED_MACRO_SCALAR = 0.70;

    // --- Valuation ---
    /**
     * Weight of book value against earnings value in the consensus fair value (100%): a closed-end holding company is
     * priced on its net asset value alone. The book leg is NAV per share, its multiple being one; the earnings leg is
     * deliberately unread, and a distribution is paid out of NAV, so it is no claim beside it.
     */
    public const FAIR_VALUE_BOOK_WEIGHT = 1.0;

    /**
     * The volume the consolidated subsidiaries are staffed to: their sales are the whole revenue line, so their cost
     * base sees the recession their sales do. Dividends received and treasury income are returns on stakes and cash,
     * below the operating line, and no payroll or plant is sized to them.
     */
    public function resolveSectorActivityShift(Stock $stock, MacroStateDTO $macroState): float
    {
        return $this->resolveOwnedVolumeShift($stock, $macroState);
    }

    /** Consolidated subsidiary volume: the output gap at the firm's floored cyclical beta. */
    private function resolveOwnedVolumeShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $beta = max(self::MIN_CYCLICAL_BETA_FLOOR, $this->getOperatingCyclicality($stock));

        return $macroState->outputGapEma * self::WHOLLY_OWNED_MACRO_SCALAR * $beta;
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility,
    ): SectorPhysicsResult {
        $pricingPower = $this->resolvePricingPower($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // The subsidiaries are the only line that sells anything; the capacity they sell on is theirs alone
        // (getPlantCapital()).
        $ownedZ = $streams->generateDemandZ('wholly_owned');
        $eventZ = $streams->generateExogenousZ('event', 0.10);

        // --- Tail risk: a subsidiary written down, which is the one event a trust reports itself ---
        $eventType = null;
        $restructuringPenalty = 0.0;
        if ($eventZ < self::SUBSIDIARY_WRITEDOWN_Z_SCORE) {
            $restructuringPenalty = self::RESTRUCTURING_DRAG_PENALTY * (1.0 - ($pricingPower * self::RESTRUCTURING_PRICING_PASS_THROUGH));
            $eventType = ShockEvent::CONGLOMERATE_SUBSIDIARY_WRITEDOWN;
        }

        // --- Consolidated subsidiaries: an operating business, responding to the cycle it is in ---
        $ownedShock = $ownedZ * ($baselineVol * self::WHOLLY_OWNED_VARIANCE_SCALAR);
        $streamRevenues = [
            'wholly_owned' => max(0.0, $expectedRevenue * (1.0 + $ownedShock + $this->resolveOwnedVolumeShift($stock, $macroState))),
        ];

        $actualRevenue = $streamRevenues['wholly_owned'];
        $streams->recordStreamShares($streamRevenues);

        $ppiCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);
        $clampedMargin = $this->clampMargin($realizedVariableMargin + $ppiCostDrag);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            specialCharge: $restructuringPenalty * $actualRevenue,
        );
    }

    /**
     * A listed stake earns the asset cost of capital it is held for: the dividends the held companies pay it
     * (AnchorStakeLedger::trailingDividends()), after the tax the trust pays on them, and its price for the rest, so a
     * company that returns its cash by buyback leaves more of the return in the price. The price gain before tax, as
     * the deferred tax on the mark is part of the capital and is not paid while the stake is held.
     */
    public function getExpectedHoldingReturn(Stock $stock, float $assetCostOfCapital): float
    {
        $investedCapital = $stock->getInvestedCapital();
        $stakes = $this->resolveListedStakeValue($stock);
        if ($investedCapital <= 0.0 || $stakes <= 0.0) {
            return 0.0;
        }

        $dividends = AnchorStakeLedger::trailingDividends($stock);

        return (($stakes * $assetCostOfCapital) - (AnchorStakeLedger::taxRate($stock) * $dividends)) / $investedCapital;
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

    /**
     * A trust depreciates the plant its subsidiaries own and nothing else. No capital-proxy fallback: here that proxy
     * IS the portfolio, so a sphere consolidating nothing would depreciate its shareholdings.
     */
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
            'import_price_level',
            'industrial_metals_index_ema',
            'macro_credit_spread_ema',
            'output_gap_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }

    /**
     * The subsidiaries at what their earnings are worth, less the capital they run on (the value-driver formula on
     * their own capital, Valuation::operatingCapitalValue()). Their return is the operating line's own, the revenue
     * line being theirs alone, faded from the trailing year toward the return the board holds them to. Repriced as the
     * market reprices listed businesses, the way a holding company marks unlisted ones off their listed peers, so its
     * whole net asset value stands at the market's prices beside its stakes.
     */
    public function resolveSubsidiaryRevaluation(Stock $stock, float $assetCostOfCapital, float $growth, float $taxRate, float $marketRepricing): float
    {
        $capital = $this->getPlantCapital($stock, $stock->getInvestedCapital());
        if ($capital <= 0.0) {
            return 0.0;
        }

        $anchor = (float) $stock->getBaselineRoic();
        $history = $stock->getQuarterlyOperatingHistory() ?? [];
        $trailing = count($history) >= EarningsEngine::TTM_QUARTERS
            ? array_sum(array_column($history, 'ebit')) * (1.0 - $taxRate) / $capital
            : $anchor;
        $return = $this->getValuationReturn($trailing, $anchor, $assetCostOfCapital);

        return (Valuation::operatingCapitalValue($capital, $return, $assetCostOfCapital, $growth) * $marketRepricing) - $capital;
    }

    /**
     * A trust's book is a portfolio carried at value, not plant earning a spread, so the multiple is one and the
     * valuation term becomes NAV per share itself.
     */
    public function getIntrinsicPbMultiple(float $structuralRoic, float $hurdleRate): float
    {
        return self::INTRINSIC_PB_MULTIPLE;
    }

    /** A trust's own news is its holdings', which its marked book already carries, not a margin it earns. */
    public function carriesFranchise(): bool
    {
        return false;
    }

    /**
     * The discount control every closed-end board runs: it buys its shares back when the discount is wider than the
     * one the structure trades at through the cycle (NAV_DISCOUNT_BASE), by how far below that price the market has
     * them, which is why a wide discount is self-correcting rather than permanent. At the normal discount a retired
     * share still lifts NAV per share, but that discount is the price of the structure, not a mispricing, and a board
     * buying there every quarter would retire its whole float, as the foundation's control block does not tender.
     */
    public function resolveRepurchaseAccretion(Stock $stock, float $currentPrice, float $netAssetValuePerShare): float
    {
        $navPerShare = $netAssetValuePerShare;

        if ($navPerShare <= 0.0 || $currentPrice <= 0.0) {
            return 0.0;
        }

        return max(0.0, 1.0 - ($currentPrice / ($navPerShare * (1.0 - self::NAV_DISCOUNT_BASE))));
    }

    /**
     * Permanent capital buys to hold, in the industries its charter names (IndustryAdjacency): it sells a business
     * only when distress forces it to.
     */
    public function investsByCharter(): bool
    {
        return true;
    }

    /**
     * A trust's assets are equity already, so its board holds the loan-to-value low and borrows for what it buys,
     * never to swap its shares for debt: a recapitalisation toward an operating company's leverage would gear a
     * portfolio that carries the market's risk in full.
     */
    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr): bool
    {
        return false;
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
     * Confidence enters as the RESIDUAL, not the level: the cycle is priced once, by the term that says so.
     */
    public function getStructuralValuationDiscount(MacroStateDTO $macroState): float
    {
        $sentimentGap = $macroState->sentimentResidual();

        return max(self::MIN_NAV_DISCOUNT, min(
            self::MAX_NAV_DISCOUNT,
            self::NAV_DISCOUNT_BASE
                - ($macroState->outputGapEma * self::NAV_DISCOUNT_CYCLE_SCALAR)
                - ($sentimentGap * self::NAV_DISCOUNT_SENTIMENT_SCALAR),
        ));
    }
}
