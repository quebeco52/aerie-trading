<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

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
 * A trust is filed as a conglomerate and is not one. It manufactures nothing, and the single fact that
 * governs how it is PRICED has no analogue in the parent: its book is not plant and working capital, it is
 * a portfolio of marketable stakes carried at what they are worth. That inverts the valuation.
 *
 * An operating company is worth the earnings its assets produce, and its book is a weak second opinion —
 * which is why the standard consensus reads book at a tenth of the weight. A trust is the other way round.
 * Its reported profit is dominated by the mark on the portfolio, so a multiple applied to it would price
 * the same information the book already carries, twice, and would swing the valuation on an accounting
 * artefact: the quarters a trust reports a loss are the quarters its holdings fell, which the book has
 * already said. So the book IS the valuation here, and the earnings line is left unread.
 *
 * Financial Physics:
 * - Net Asset Value: the intrinsic multiple on book is exactly one (getIntrinsicPbMultiple), so the
 *   valuation term the parent receives as a ROIC-scaled multiple of retained earnings arrives instead as
 *   net asset value per share.
 * - The Holding-Company Discount: a trust's shares persistently trade BELOW the assets they represent.
 *   Control blocks cannot be sold into the market at the screen price, the unlisted holdings are illiquid,
 *   and a realised stake is taxed on the way out, so the parts are never worth the sum. The discount is
 *   the defining feature of the class, not a haircut on it.
 * - Sentiment, not fundamentals, moves the discount. It is an investor-sentiment index (Lee, Shleifer &
 *   Thaler 1991): it gaps wide in a downturn, when the holder is least able to be told that the assets are
 *   illiquid, and closes again in an expansion — while the assets underneath it did nothing of the kind.
 * - The income mix is the INVERSE of the asset mix, and that is an accounting fact rather than a quirk: a
 *   controlled subsidiary is consolidated line by line and contributes its whole top line, while a minority
 *   stake — however large the holding and however decisive the votes — contributes only the dividend it
 *   declares. So the smaller half of the portfolio is the larger half of the income statement, and the
 *   anchor stakes that ARE the trust barely appear on it.
 * - Dividends received are twice damped. A board sets its payout out of trailing earnings and then smooths
 *   the result deliberately, so two rounds of delay sit between the cycle and the cheque. The receipt line
 *   is the quietest thing in the group and it arrives late.
 * - Only the consolidated subsidiaries buy anything. Dividends and treasury income have no cost of goods,
 *   so the input basket is charged against the wholly-owned stream alone.
 * - NOT a financial, deliberately, and the name says why: an investment COMPANY, not an investment trust.
 *   A closed-end fund holds securities and consolidates nothing, and Sectors::isFinancial() is built for
 *   exactly that balance-sheet shape — it skips the fixed asset ledger (EarningsEngine::
 *   seedFixedAssetLedgerIfNeeded), forbids organic capex, charges depreciation against total EQUITY rather
 *   than plant, and drops the firm out of the industry share ledger. Here the controlled half is real
 *   factories, berths and grid equipment: it owns plant, spends capex on it and competes in a product
 *   market. Filing that as a financial would delete the half of the group that manufactures.
 */
class InvestmentCompanyBusinessModel extends ConglomerateBusinessModel
{
    // --- Net Asset Value Anchoring ---
    /** Intrinsic multiple on book. Exactly one: a trust's book is a portfolio carried at value, so the honest multiple on it is unity and pbFairValue becomes net asset value per share. */
    public const INTRINSIC_PB_MULTIPLE = 1.00;

    /** Weight on the dividend discount leg. A trust's distribution is funded by dividends it RECEIVES rather than by operations of its own, so it is a genuinely separate claim on value and not a restatement of the earnings line this model declines to read. */
    public const NAV_DDM_WEIGHT = 0.15;

    // --- Holding-Company Discount ---
    /** Long-run discount to net asset value at a neutral cycle. Control blocks are unsaleable at the screen price, the unlisted holdings are illiquid, and a realised stake is taxed on exit. */
    public const NAV_DISCOUNT_BASE = 0.30;

    /** Widening of the discount per unit of output gap BELOW neutral. The closed-end discount is a sentiment index, so it gaps out in a contraction and closes in an expansion while the assets underneath do neither. */
    public const NAV_DISCOUNT_CYCLE_SCALAR = 4.00;

    /** Floor on the discount: even at a cyclical peak a control block cannot be sold into the market at the screen price. */
    public const MIN_NAV_DISCOUNT = 0.15;

    /** Ceiling on the discount: below this the trust is worth more broken up than held, and the foundation's own bid puts a floor under it. */
    public const MAX_NAV_DISCOUNT = 0.50;

    // --- Analyst Visibility & Error ---
    /** A trust publishes its holdings and they are separately listed, so its assets are read more easily than an operating conglomerate's unlisted subsidiaries — which is exactly why the discount is visible enough to be traded. */
    public const BASE_COVERAGE_VISIBILITY = 0.55;

    // --- Portfolio Composition (shares of NET ASSET VALUE) ---
    /** Baseline share of NAV held as wholly-owned subsidiaries: the controlled minority of the portfolio. */
    public const WHOLLY_OWNED_NAV_SHARE = 0.23;

    /** Baseline share of NAV held as listed anchor stakes. The bulk of the sphere, and the part it is known for. */
    public const LISTED_PORTFOLIO_NAV_SHARE = 0.64;

    /** Fallback share of NAV held as treasury, used ONLY when the balance sheet cannot answer — the real figure is read from the ledger, which moves every quarter as the company hoards or deploys. */
    public const TREASURY_NAV_SHARE_FALLBACK = 0.13;

    // --- NAV to Revenue Conversion ---
    /** Revenue a controlled subsidiary books per unit of the value it is carried at. Consolidation takes the whole top line, so a holding worth a fifth of the sphere can be most of its income statement. */
    public const WHOLLY_OWNED_ASSET_TURNOVER = 0.30;

    /** Revenue a listed stake books per unit of its market value: its dividend yield, and nothing else. A 20% holding in a company earning a fortune contributes the declared dividend and not one krona more. */
    public const LISTED_DIVIDEND_YIELD = 0.035;

    /** Revenue the treasury books per unit of its value: the blended yield on cash, short sovereign paper and the credit book. */
    public const TREASURY_INCOME_YIELD = 0.040;

    /** An investment company owns no factories directly; the parent's industrial stream is switched off entirely. */
    public const INDUSTRIAL_CONGLOMERATE_WEIGHT = 0.00;

    // --- Stream Volatility Scalars ---
    /** Volatility multiplier for consolidated subsidiary sales: real operating businesses, priced as such. */
    public const WHOLLY_OWNED_VARIANCE_SCALAR = 0.30;

    /** Volatility multiplier for dividends received. A payout is set from trailing earnings and then smoothed again on purpose, so the receipt line is the quietest in the group. */
    public const LISTED_DIVIDEND_VARIANCE_SCALAR = 0.06;

    // --- Macro Sensitivities ---
    /** Output gap sensitivity of consolidated subsidiary volume. Mission-critical niche manufacturers sell replacement and consumable demand, not new-build demand. */
    public const WHOLLY_OWNED_MACRO_SCALAR = 0.70;

    /** Sensitivity of upstreamed dividends to the LAGGED cycle. Deliberately small: the board's smoothing is a second damping on top of the delay. */
    public const LISTED_DIVIDEND_MACRO_SCALAR = 0.25;

    /** Years between the cycle and the dividend it eventually pays, applied through the parent's distributed-lag helper. */
    public const DEMAND_LAG_YEARS = 1.50;

    // --- Analyst Observability ---
    /** Fraction of the dividend line visible to consensus. A listed holding declares its dividend publicly BEFORE the trust reports receiving it, so the receipt is very nearly solved arithmetic and pretending otherwise would manufacture a standing surprise out of a press release. */
    public const LISTED_DIVIDEND_OBSERVABLE_DISCOUNT = 0.95;

    /** Fraction of consolidated subsidiary performance visible ahead of the filing; these are unlisted and report only through the group. */
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
        // The portfolio split is what varies between two spheres running the same kind of book — one with no
        // consolidated subsidiaries at all is still an investment company — and it is declared the way a
        // sphere actually publishes it: as shares of NET ASSET VALUE.
        $params = $this->resolveModelParameters($stock, [
            ModelParam::WhollyOwnedNavShare->value     => self::WHOLLY_OWNED_NAV_SHARE,
            ModelParam::ListedPortfolioNavShare->value => self::LISTED_PORTFOLIO_NAV_SHARE,
            ModelParam::PricingPowerIndex->value       => self::PRICING_POWER_INDEX,
        ]);

        // The treasury is the one part of the portfolio the balance sheet already knows, so it is READ
        // rather than declared. A dial beside it would be a second copy of a number the ledger rewrites
        // every quarter — TreasuryEngine on the way in, MergerAndAcquisitionEngine on the way out — and the
        // two would part company the moment the company spent anything. Reading it means the hoard funding
        // the float is the hoard the firm actually holds: deploy it into a deal and the income goes with it.
        $totalEquity = (float) $stock->getTotalEquity();
        $treasuryNavShare = $totalEquity > 0.0
            ? max(0.0, (float) $stock->getCorporateTreasury()) / $totalEquity
            : self::TREASURY_NAV_SHARE_FALLBACK;

        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta = max(self::MIN_CYCLICAL_BETA_FLOOR, $this->getOperatingCyclicality($stock));

        // Assets to income. Each holding books revenue at the rate its KIND of ownership permits: a
        // consolidated subsidiary at its asset turnover, a listed stake at its dividend yield alone, the
        // treasury at what it earns. The simplex normalises these, so the inversion is a consequence of the
        // conversion rather than something anyone has to remember to enter backwards. A stream left at zero
        // is never drawn and never reported.
        $activeWeights = $streams->resolveActiveStreamWeights([
            'wholly_owned'          => $params[ModelParam::WhollyOwnedNavShare] * self::WHOLLY_OWNED_ASSET_TURNOVER,
            'listed_portfolio'      => $params[ModelParam::ListedPortfolioNavShare] * self::LISTED_DIVIDEND_YIELD,
            'financial_investments' => $treasuryNavShare * self::TREASURY_INCOME_YIELD,
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
        // A payout is declared out of trailing earnings and then smoothed again by the board, so the delay
        // and the damping are two separate effects and both belong here.
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
     * The treasury's income is already in the revenue line, so it must not be booked a second time here.
     *
     * StandardTreasuryTrait pays every corporate the policy rate less a spread on whatever cash sits above
     * its operating needs, which is the right default for a manufacturer whose cash is a buffer. Here the
     * same balance IS a business line: the financial_investments stream draws its weight straight from
     * Stock::corporateTreasury and runs the contrarian float physics over it — rate carry, deployment alpha
     * at distressed spreads, mark-to-market and credit losses. Leaving the trait's version on top would pay
     * for the hoard twice, and would do it with the poorer of the two descriptions, since idle cash at the
     * front rate is precisely what a permanent-capital sphere's dry powder is not.
     */
    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        return 0.0;
    }

    /**
     * A trust's book is a portfolio carried at value, not plant earning a spread over its hurdle, so the
     * multiple on it is one and the valuation term becomes net asset value per share itself.
     */
    public function getIntrinsicPbMultiple(float $structuralRoic, float $hurdleRate): float
    {
        return self::INTRINSIC_PB_MULTIPLE;
    }

    /**
     * Net asset value, blended with the dividend it distributes out of what it receives.
     *
     * $pbFairValue IS net asset value per share here, because the multiple above is one. The earnings leg
     * is deliberately unread — see the class docblock for why pricing a trust's reported profit through a
     * multiple counts the portfolio mark twice.
     */
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        return $dividendSupportValue > 0.0
            ? ($pbFairValue * (1.0 - self::NAV_DDM_WEIGHT)) + ($dividendSupportValue * self::NAV_DDM_WEIGHT)
            : $pbFairValue;
    }

    /**
     * The holding-company discount, widening as the cycle turns down.
     *
     * Driven by the output gap rather than by the trust's own volatility on purpose: a discount that
     * widened on the firm's realised volatility would raise the volatility that widened it, and the loop
     * has no damping in it. The cycle is exogenous to any one listing, which is what makes it safe here.
     */
    public function getStructuralValuationDiscount(float $outputGap): float
    {
        return max(self::MIN_NAV_DISCOUNT, min(
            self::MAX_NAV_DISCOUNT,
            self::NAV_DISCOUNT_BASE - ($outputGap * self::NAV_DISCOUNT_CYCLE_SCALAR)
        ));
    }
}
