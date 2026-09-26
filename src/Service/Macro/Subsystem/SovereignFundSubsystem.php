<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;

/**
 * The district's sovereign reserve fund: a rule-bound investor that sits beside the central bank, not in place of it.
 *
 * Three published rules and nothing else:
 *  - It pays the budget up to half its expected long-term real return, fixed once a year (Singapore's Net
 *    Investment Returns framework, 2008). The rest stays invested, so a fund compounding at about twice potential
 *    growth keeps pace with the economy. "Long-term" is the COMPOUND rate: spending half the arithmetic mean of a
 *    volatile portfolio overspends by half its variance every year and erodes the fund it is meant to preserve.
 *  - It holds a cap-weighted slice of the whole board and a foreign reserve portfolio on GIC's 65/35 reference
 *    mix, with a fixed domestic policy weight and a deviation band (GPIF's policy asset mix).
 *  - When the domestic weight leaves its band at a month end, it trades back to the policy weight over the
 *    following months (Norges Bank's rebalancing rule).
 *
 * Nothing here reads a valuation or the cycle. The fund buys after a crash only because a crash drops its domestic
 * weight through the band, which is how a fixed-weight rebalancer behaves in the record (GPFG in 2008-09 and 2020).
 *
 * The fund incepts on the first tick that carries a board and books no trade doing so: a structural holder opens at
 * its holding. A run with no market (the simulate command, the macro harnesses, unit tests) never has a fund.
 */
class SovereignFundSubsystem
{
    // --- Spending Rule (Singapore Net Investment Returns framework, 2008) ---
    /** Share of the expected long-term real return the budget may spend each year; the rest stays invested (Singapore NIR framework). */
    public const NIR_SPENDING_SHARE = 0.50;
    /** The budget year: the draw is set once a year from the fund's value at its start. */
    public const DRAW_RESET_PERIOD_YEARS = 1.0;

    // --- Reference Portfolio (GIC, 2013) ---
    /** Global equity share of the foreign sleeve, the rest foreign sovereign paper (GIC Reference Portfolio, 65/35 since 2013). */
    public const FOREIGN_EQUITY_SHARE = 0.65;
    /** Local-currency volatility of developed ex-home equities (Ken French Developed ex US vs US, 1990-2026: 16.4% in USD, net of this engine's 8% FX noise). */
    public const FOREIGN_EQUITY_VOLATILITY = 0.144;
    /** Correlation of foreign equities with the home market factor (same sample: 0.773 in home currency, 0.885 in local currency). */
    public const FOREIGN_EQUITY_MARKET_CORRELATION = 0.885;

    // --- Domestic Mandate (GPIF) ---
    /** Share of the board's float the fund holds at inception (GPIF owned 5.8% of the Japanese market in 2016). */
    public const DOMESTIC_OWNERSHIP_OPENING = 0.058;
    /** GPIF's policy weight for domestic equities (5th medium-term period, FY2025). */
    public const GPIF_DOMESTIC_EQUITY_TARGET = 0.25;
    /** GPIF's deviation limit on that weight (±6pp in the 5th period, ±8pp in the 4th); carried to this fund's weight by the relative move that breaches it. */
    public const GPIF_DOMESTIC_EQUITY_DEVIATION_LIMIT = 0.06;
    /** Most of the float the fund may own; holding the same share of every name's float, that keeps it under 10% of any company's voting shares (GPFG mandate). */
    public const MAX_OWNERSHIP_SHARE = 0.10;

    // --- Rebalancing Execution (Norges Bank 2018) ---
    /** Month-end check; trading starts the month after the breach (NBIM rebalancing rule, 2018). */
    public const REBALANCE_CHECK_PERIOD_YEARS = 1.0 / 12.0;
    /** Months over which a breach is traded back to the policy weight; NBIM trades gradually but does not publish the pace. */
    public const REBALANCE_EXECUTION_MONTHS = 3.0;

    // --- Numerics ---
    /** Room under the ownership ceiling smaller than this share of the float is rounding, not capacity. */
    private const ROOM_TOLERANCE = 1.0e-9;
    /** Fixed-point passes that size the opening fund; the return moves only through the variance, so a few suffice. */
    private const SIZING_PASSES = 20;
    /** Floor on the compound return the sizing divides by, so a pathological calibration cannot divide by zero. */
    private const MIN_COMPOUND_RETURN = 0.005;

    // --- Currency Bridge (World Bank) ---
    /** Listed-company capitalisation over GDP at inception (World Bank CM.MKT.LCAP.GD.ZS, US median 1975-2024; Singapore 1.56, world 0.74). */
    public const MARKET_CAP_TO_GDP = 1.15;

    public function __construct(
        private readonly MathUtility $mathUtility,
    ) {
    }

    /**
     * Advances the fund one tick: incept, mark to market, pay the budget, review the band, trade.
     *
     * Runs after nominal GDP is struck and before the fiscal accounts read the draw.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function update(MacroState $state, float $dt): void
    {
        $state->sovereignFundTrade = 0.0;

        if (!$this->isIncepted($state)) {
            if ($this->incept($state)) {
                $this->publish($state);
            }
            return;
        }

        $this->markToMarket($state, $dt);

        if (MathUtility::crossedSimulatedBoundary($state->totalTime, $dt, self::DRAW_RESET_PERIOD_YEARS)) {
            $state->sovereignFundAnnualDraw = $this->calculateAnnualDraw($state);
        }
        $this->payDraw($state, $dt);

        if (MathUtility::crossedSimulatedBoundary($state->totalTime, $dt, self::REBALANCE_CHECK_PERIOD_YEARS)) {
            $this->reviewRebalance($state);
        }
        $state->sovereignFundTrade = $this->executeRebalance($state, $dt);

        $this->publish($state);
    }

    /** Whether the fund exists yet. The currency bridge is set at inception and never otherwise. */
    public function isIncepted(MacroState $state): bool
    {
        return $state->sovereignFundDollarsPerGdp > 0.0;
    }

    /**
     * Opens the fund on the first observed board, sized so its opening draw funds the structural deficit.
     *
     * With draw = NIR x g(w) x F, where g is the portfolio's compound real return at domestic weight w = D / F, the
     * opening fund solves NIR x g(D / F) x F = d x GDP. g moves only through the portfolio variance, so the fixed
     * point F = d x GDP / (NIR x g(D / F)) settles in a handful of passes. The policy weight is whatever D / F comes to.
     *
     * @return bool Whether the fund incepted this tick.
     */
    private function incept(MacroState $state): bool
    {
        if ($state->boardFloatCap <= 0.0 || $state->equityMarketCap <= 0.0 || $state->nominalGdpIndex <= 0.0) {
            return false;
        }

        $dollarsPerGdp = $state->equityMarketCap / (self::MARKET_CAP_TO_GDP * $state->nominalGdpIndex);
        $gdpDollars = $dollarsPerGdp * $state->nominalGdpIndex;
        $domestic = self::DOMESTIC_OWNERSHIP_OPENING * $state->boardFloatCap;

        $spendable = MacroEngine::SOVEREIGN_STRUCTURAL_DEFICIT * $gdpDollars / self::NIR_SPENDING_SHARE;
        $fund = $spendable / max(self::MIN_COMPOUND_RETURN, $this->expectedCompoundRealReturn($state, 0.0));
        for ($pass = 0; $pass < self::SIZING_PASSES; ++$pass) {
            $fund = $spendable / max(self::MIN_COMPOUND_RETURN, $this->expectedCompoundRealReturn($state, $domestic / $fund));
        }
        $foreign = max(0.0, $fund - $domestic);

        $state->sovereignFundDollarsPerGdp = $dollarsPerGdp;
        $state->sovereignFundDomesticEquity = $domestic;
        $state->sovereignFundForeignAssets = $foreign * $state->exchangeRateIndex;
        $state->sovereignFundTargetWeight = $domestic / ($domestic + $foreign);
        $state->sovereignFundAnnualDraw = $this->calculateAnnualDraw($state);

        return true;
    }

    /**
     * Carries both sleeves through the last tick.
     *
     * The domestic sleeve is a cap-weighted slice of the float, so it earns the board's float-weighted price return;
     * its dividends arrive as cash in the reserve portfolio. The foreign equity market loads on the home market
     * factor at its measured correlation, and the reserve portfolio holds it 65/35 against paper at the foreign rate.
     */
    private function markToMarket(MacroState $state, float $dt): void
    {
        $state->sovereignFundDomesticEquity = max(0.0, $state->sovereignFundDomesticEquity * (1.0 + $state->boardPriceReturn));

        $idiosyncraticVolatility = self::FOREIGN_EQUITY_VOLATILITY
            * sqrt(1.0 - (self::FOREIGN_EQUITY_MARKET_CORRELATION * self::FOREIGN_EQUITY_MARKET_CORRELATION));
        $previousIndex = $state->foreignEquityIndex;
        $state->foreignEquityIndex = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: $previousIndex,
            idiosyncraticVolatility: $idiosyncraticVolatility,
            drift: $state->foreignPolicyRate + MacroEngine::BASE_EQUITY_RISK_PREMIUM,
            gravityDrift: 0.0,
            dt: $dt,
            beta: self::FOREIGN_EQUITY_MARKET_CORRELATION,
            marketVol: self::FOREIGN_EQUITY_VOLATILITY,
            marketZ: $state->marketZ,
            w1: $this->mathUtility->generateStandardNormal()
        );
        $equityGross = $previousIndex > 0.0 ? $state->foreignEquityIndex / $previousIndex : 1.0;
        $sleeveGross = (self::FOREIGN_EQUITY_SHARE * $equityGross)
            + ((1.0 - self::FOREIGN_EQUITY_SHARE) * (1.0 + ($state->foreignPolicyRate * $dt)));
        $state->sovereignFundForeignAssets *= $sleeveGross;

        if ($state->boardFloatCap > 0.0 && $state->boardDividendCash > 0.0) {
            $dividends = ($state->sovereignFundDomesticEquity / $state->boardFloatCap) * $state->boardDividendCash;
            $state->sovereignFundForeignAssets += $dividends * $state->exchangeRateIndex;
        }
    }

    /**
     * This budget year's draw: half the expected long-term (compound) real return on the fund at the start of the year.
     *
     * @return float Currency per year.
     */
    public function calculateAnnualDraw(MacroState $state): float
    {
        $fund = $this->fundValue($state);
        if ($fund <= 0.0) {
            return 0.0;
        }

        $weight = $state->sovereignFundDomesticEquity / $fund;

        return self::NIR_SPENDING_SHARE * max(0.0, $this->expectedCompoundRealReturn($state, $weight)) * $fund;
    }

    /**
     * The portfolio's expected compound real return at a domestic weight: its arithmetic mean less half its variance.
     *
     * The variance is the two-asset Markowitz sum. The reserve portfolio carries its equity share of the foreign
     * market plus the whole of the currency in home terms; it co-moves with the board only through that equity leg.
     */
    public function expectedCompoundRealReturn(MacroState $state, float $domesticWeight): float
    {
        $weight = max(0.0, min(1.0, $domesticWeight));
        $arithmetic = ($weight * $this->domesticExpectedRealReturn($state))
            + ((1.0 - $weight) * $this->foreignExpectedRealReturn());

        $domesticVolatility = MacroEngine::MACRO_VOL_BASE_ANCHOR;
        $foreignEquityLeg = self::FOREIGN_EQUITY_SHARE * self::FOREIGN_EQUITY_VOLATILITY;
        $foreignVariance = ($foreignEquityLeg * $foreignEquityLeg)
            + (MacroEngine::EXCHANGE_RATE_VOLATILITY * MacroEngine::EXCHANGE_RATE_VOLATILITY);
        $covariance = self::FOREIGN_EQUITY_MARKET_CORRELATION * $foreignEquityLeg * $domesticVolatility;

        $variance = ($weight * $weight * $domesticVolatility * $domesticVolatility)
            + ((1.0 - $weight) * (1.0 - $weight) * $foreignVariance)
            + (2.0 * $weight * (1.0 - $weight) * $covariance);

        return $arithmetic - (0.5 * $variance);
    }

    /** Pays the year's draw out of the reserve portfolio at an even pace. */
    private function payDraw(MacroState $state, float $dt): void
    {
        $payment = $state->sovereignFundAnnualDraw * $dt * $state->exchangeRateIndex;
        $state->sovereignFundForeignAssets = max(0.0, $state->sovereignFundForeignAssets - $payment);
    }

    /**
     * The month-end review: continue a programme in flight, or start one when the weight has left its band.
     *
     * A programme re-measures what is left at every month end, so a market that keeps falling while it trades is
     * bought into rather than chased by a stale amount, and it lands on the policy weight when its months run out.
     */
    private function reviewRebalance(MacroState $state): void
    {
        $fund = $this->fundValue($state);
        if ($fund <= 0.0) {
            return;
        }

        $gap = min(
            ($state->sovereignFundTargetWeight - ($state->sovereignFundDomesticEquity / $fund)) * $fund,
            $this->purchaseRoom($state)
        );

        if ($state->sovereignFundRebalanceMonthsLeft > 0.0) {
            $state->sovereignFundRebalanceMonthsLeft = max(0.0, $state->sovereignFundRebalanceMonthsLeft - 1.0);
        }

        if ($state->sovereignFundRebalanceMonthsLeft > 0.0) {
            $this->scheduleRebalance($state, $gap, $state->sovereignFundRebalanceMonthsLeft);
            return;
        }

        $state->sovereignFundRebalanceBacklog = 0.0;
        $state->sovereignFundRebalanceRate = 0.0;

        // A breach the ownership ceiling leaves no room to act on starts nothing: there is no programme to announce.
        $drift = abs(($state->sovereignFundDomesticEquity / $fund) - $state->sovereignFundTargetWeight);
        if ($drift > $this->rebalanceBand($state->sovereignFundTargetWeight) && $gap !== 0.0) {
            $this->scheduleRebalance($state, $gap, self::REBALANCE_EXECUTION_MONTHS);
            $state->lastSovereignRebalanceAt = $state->totalTime;
        }
    }

    /** Sets a programme to trade $amount evenly over $months. */
    private function scheduleRebalance(MacroState $state, float $amount, float $months): void
    {
        $state->sovereignFundRebalanceMonthsLeft = $months;
        $state->sovereignFundRebalanceBacklog = $amount;
        $state->sovereignFundRebalanceRate = $amount / ($months * self::REBALANCE_CHECK_PERIOD_YEARS);
    }

    /**
     * This tick's slice of the programme, booked between the sleeves at once; the ticker executes it on the board.
     *
     * @return float Currency traded this tick, positive for a purchase of domestic equity.
     */
    private function executeRebalance(MacroState $state, float $dt): float
    {
        $backlog = $state->sovereignFundRebalanceBacklog;
        if ($backlog === 0.0 || $state->sovereignFundRebalanceRate === 0.0 || $dt <= 0.0) {
            return 0.0;
        }

        $slice = $state->sovereignFundRebalanceRate * $dt;
        $trade = $backlog > 0.0 ? min($backlog, $slice) : max($backlog, $slice);

        $foreignHome = $this->foreignHomeValue($state);
        $trade = max(-$state->sovereignFundDomesticEquity, min($foreignHome, $this->purchaseRoom($state), $trade));

        $state->sovereignFundRebalanceBacklog = $backlog - $trade;
        $state->sovereignFundDomesticEquity += $trade;
        $state->sovereignFundForeignAssets -= $trade * $state->exchangeRateIndex;

        return $trade;
    }

    /**
     * The deviation band on this fund's domestic weight.
     *
     * GPIF's band is written for a 25% weight; the same number of points on a weight a fifth of that size would be a
     * different rule. What carries across is the relative move that breaches it: the domestic sleeve falling d against
     * the rest moves a weight w by w(1 - w)d / (1 - wd), so GPIF's limit L on T trips at d* = L / (T(1 - T) + TL), about
     * 30%, and this fund's band is the move d* makes in its own weight.
     */
    public function rebalanceBand(float $targetWeight): float
    {
        $target = self::GPIF_DOMESTIC_EQUITY_TARGET;
        $limit = self::GPIF_DOMESTIC_EQUITY_DEVIATION_LIMIT;
        $breachingMove = $limit / (($target * (1.0 - $target)) + ($target * $limit));

        return $targetWeight * (1.0 - $targetWeight) * $breachingMove / (1.0 - ($targetWeight * $breachingMove));
    }

    /** Currency the fund may still spend on the board before it reaches the ownership ceiling; never negative. */
    private function purchaseRoom(MacroState $state): float
    {
        $room = (self::MAX_OWNERSHIP_SHARE * $state->boardFloatCap) - $state->sovereignFundDomesticEquity;

        // A fund that has bought up to the ceiling sits on it to the last bit, and rounding must not read as room.
        return $room > self::ROOM_TOLERANCE * $state->boardFloatCap ? $room : 0.0;
    }

    /** Refreshes the stationary readings the dashboards and macro_report use. */
    private function publish(MacroState $state): void
    {
        $fund = $this->fundValue($state);
        $gdpDollars = $state->sovereignFundDollarsPerGdp * $state->nominalGdpIndex;

        $state->sovereignFundToGdp = $gdpDollars > 0.0 ? $fund / $gdpDollars : 0.0;
        $state->sovereignFundDomesticWeight = $fund > 0.0 ? $state->sovereignFundDomesticEquity / $fund : 0.0;
        $state->sovereignFundOwnershipShare = $state->boardFloatCap > 0.0 ? $state->sovereignFundDomesticEquity / $state->boardFloatCap : 0.0;
        $state->sovereignFundDrawToGdp = $gdpDollars > 0.0 ? $state->sovereignFundAnnualDraw / $gdpDollars : 0.0;
        $state->sovereignFundRebalanceShare = $state->boardFloatCap > 0.0 ? $state->sovereignFundRebalanceBacklog / $state->boardFloatCap : 0.0;
    }

    /** The whole fund in home currency. */
    public function fundValue(MacroState $state): float
    {
        return $state->sovereignFundDomesticEquity + $this->foreignHomeValue($state);
    }

    /** The reserve portfolio in home currency: foreign units at today's exchange rate, a stronger home currency buying more of them. */
    public function foreignHomeValue(MacroState $state): float
    {
        return $state->exchangeRateIndex > 0.0 ? $state->sovereignFundForeignAssets / $state->exchangeRateIndex : 0.0;
    }

    /** Expected real return on the board: the natural rate plus the equity premium. */
    private function domesticExpectedRealReturn(MacroState $state): float
    {
        return $state->naturalRate + MacroEngine::BASE_EQUITY_RISK_PREMIUM;
    }

    /** Expected real return on the reserve portfolio: the foreign bloc's neutral real rate, plus the equity premium on its equity share. */
    private function foreignExpectedRealReturn(): float
    {
        $foreignRealRate = MacroEngine::GLOBAL_BASELINE_RATE - MacroEngine::TARGET_INFLATION;

        return $foreignRealRate + (self::FOREIGN_EQUITY_SHARE * MacroEngine::BASE_EQUITY_RISK_PREMIUM);
    }
}
