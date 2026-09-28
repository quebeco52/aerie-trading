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
 *  - It holds three sleeves at policy weights: a cap-weighted slice of the whole board, and foreign equities and
 *    foreign sovereign paper on GIC's 65/35 reference mix. Each drifts with its own market between rebalances; the
 *    paper is a constant-duration index on the foreign curve, so it carries, rolls down and reprices with the foreign rate.
 *  - At a month end it checks two deviation limits the way GPIF does: the domestic equity weight against its band,
 *    and the whole fund's equity share against its band. A breach of either trades every sleeve back to its policy
 *    weight, the board over the following months (Norges Bank's rebalancing rule) and the foreign sleeves at once,
 *    since the fund takes the price in a market that deep.
 *
 * Nothing here reads a valuation or the cycle. The fund buys the board after a District crash because the crash drops
 * the domestic weight through its band, and after a global one because the crash drops the equity share through its
 * band; that is how a fixed-weight rebalancer behaves in the record (GPFG in 2008-09 and 2020).
 *
 * It holds the float as an index holder does: it tenders its share into buybacks and takes up its share of issues, so
 * a company's own flow never moves its ownership. Its inflows are the District's stamp duty on share trading, paid
 * to the fund rather than the budget, the cash from the District's strategic stakes (App\Data\StrategicHoldings),
 * which the fund does not hold but is paid, and any budget surplus the sovereign debt floor leaves no debt to retire.
 * The budget spends the draw (CreditFiscalSubsystem::calculateSovereignDebt), so that last one is rare. Its performance is a time-weighted return index, nominal and real, that
 * none of that money moves. The fund incepts on the first tick that carries a board and books no
 * trade doing so: a structural holder opens at its holding. A run with no market (the simulate command, the macro
 * harnesses, unit tests) never has a fund.
 */
class SovereignFundSubsystem
{
    // --- Spending Rule (Singapore Net Investment Returns framework, 2008) ---
    /** Share of the expected long-term real return the budget may spend each year; the rest stays invested (Singapore NIR framework). */
    public const NIR_SPENDING_SHARE = 0.50;
    /** The budget year: the draw is set once a year from the fund's value at its start. */
    public const DRAW_RESET_PERIOD_YEARS = 1.0;

    // --- Reference Portfolio (GIC, 2013) ---
    /** Global equity share of the foreign sleeves, the rest foreign sovereign paper (GIC Reference Portfolio, 65/35 since 2013). */
    public const FOREIGN_EQUITY_SHARE = 0.65;
    /** Local-currency volatility of developed ex-home equities (Ken French Developed ex US vs US, 1990-2026: 16.4% in USD, net of this engine's 8% FX noise). */
    public const FOREIGN_EQUITY_VOLATILITY = 0.144;
    /** Correlation of foreign equities with the home market factor (same sample: 0.773 in home currency, 0.885 in local currency). */
    public const FOREIGN_EQUITY_MARKET_CORRELATION = 0.885;
    /** Duration of the foreign paper, held as a constant-maturity zero of that term (Bloomberg Global Aggregate effective duration 6.17y, SPDR GLAD factsheet 31 Aug 2026). */
    public const FOREIGN_BOND_DURATION = 6.17;

    // --- Deviation Limits (GPIF policy asset mix, 5th medium-term period, FY2025) ---
    /** Share of the board's float the fund holds at inception (GPIF owned 5.8% of the Japanese market in 2016). */
    public const DOMESTIC_OWNERSHIP_OPENING = 0.058;
    /** GPIF's policy weight for domestic equities. */
    public const GPIF_DOMESTIC_EQUITY_TARGET = 0.25;
    /** GPIF's deviation limit on that weight (±6pp; ±8pp in the 4th period); carried to this fund's weight by the relative move that breaches it. */
    public const GPIF_DOMESTIC_EQUITY_DEVIATION_LIMIT = 0.06;
    /** GPIF's policy weight for equities as a whole, domestic plus foreign. */
    public const GPIF_GLOBAL_EQUITY_TARGET = 0.50;
    /** GPIF's deviation limit on the whole equity share (±9pp; ±11pp in the 4th period); carried across the same way. */
    public const GPIF_GLOBAL_EQUITY_DEVIATION_LIMIT = 0.09;
    /** Most of the float the fund may own; holding the same share of every name's float, that keeps it under 10% of any company's voting shares (GPFG mandate). */
    public const MAX_OWNERSHIP_SHARE = 0.10;

    // --- Rebalancing Execution (Norges Bank 2018) ---
    /** Month-end check; trading starts the month after the breach (NBIM rebalancing rule, 2018). */
    public const REBALANCE_CHECK_PERIOD_YEARS = 1.0 / 12.0;
    /** Months over which the board is traded back to its policy weight; NBIM trades gradually but does not publish the pace. */
    public const REBALANCE_EXECUTION_MONTHS = 3.0;

    // --- Performance Reporting (GIPS time-weighted return) ---
    /** Level of the nominal and real return indices at inception. */
    public const RETURN_INDEX_BASE = 100.0;

    // --- Numerics ---
    /** Room under the ownership ceiling smaller than this share of the float is rounding, not capacity. */
    private const ROOM_TOLERANCE = 1.0e-9;

    // --- Opening Size (GPIF, FY2024) ---
    /** Fund over GDP at inception: GPIF's scale, ¥250T of assets on ¥609T of FY2024 nominal GDP (GPIF annual report FY2024; Cabinet Office). */
    public const OPENING_FUND_TO_GDP = 0.41;

    // --- Currency Bridge (World Bank) ---
    /** Listed-company capitalisation over GDP at inception: a financial centre's, Singapore's 1.56 (World Bank CM.MKT.LCAP.GD.ZS; US median 1.15, world 0.74). */
    public const MARKET_CAP_TO_GDP = 1.56;

    public function __construct(
        private readonly MathUtility $mathUtility,
    ) {
    }

    /**
     * Advances the fund one tick: incept, mark to market, chain the return index, take in the District's money, pay
     * the budget, review the bands, trade.
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
        $this->chainReturnIndices($state, $dt);
        $this->receiveInflows($state);

        if (MathUtility::crossedSimulatedBoundary($state->totalTime, $dt, self::DRAW_RESET_PERIOD_YEARS)) {
            $this->setAnnualDraw($state);
            $this->closeStampDutyYear($state);
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
     * Opens the fund on the first observed board at GPIF's size against the economy, holding its opening share of the
     * float. The policy weight is whatever D / F comes to, and the first draw is the spending rule on that fund; the
     * budget spends it (CreditFiscalSubsystem::calculateSovereignDebt), so the size sets spending, not borrowing.
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
        $foreign = max(0.0, (self::OPENING_FUND_TO_GDP * $gdpDollars) - $domestic);

        $state->sovereignFundDollarsPerGdp = $dollarsPerGdp;
        $state->sovereignFundDomesticEquity = $domestic;
        $state->sovereignFundTargetWeight = $domestic / ($domestic + $foreign);
        $this->resetForeignSplit($state, $foreign);
        $state->foreignBondYield = $this->foreignZeroYield($state, self::FOREIGN_BOND_DURATION);
        $state->sovereignFundReturnIndex = self::RETURN_INDEX_BASE;
        $state->sovereignFundRealReturnIndex = self::RETURN_INDEX_BASE;
        $this->setAnnualDraw($state);

        return true;
    }

    /**
     * Carries the sleeves through the last tick.
     *
     * The domestic sleeve is a cap-weighted slice of the float, so it earns the board's float-weighted price return;
     * its dividends arrive as cash in the paper sleeve, and it tenders into buybacks and takes up issues pro rata. The
     * foreign equity market loads on the home market factor at its measured correlation; the paper is marked on the
     * foreign curve. Neither is rebalanced here: between the fund's own reviews each sleeve drifts with its market.
     * Everything booked here is return; money paid in from outside arrives after, in receiveInflows().
     *
     * The foreign market also re-rates on its own business cycle (AssetMarketSubsystem's habit premium on the foreign
     * gap, valued Campbell-Shiller). That term moves at the cycle's frequency, so it adds well under 1% to the monthly
     * variance the diffusion's volatility was pinned to, and the diffusion keeps its measured value.
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
        $state->foreignEquityIndex *= exp($state->foreignEquityValuationChange);
        if ($previousIndex > 0.0) {
            $state->sovereignFundForeignEquity *= $state->foreignEquityIndex / $previousIndex;
        }
        $this->markForeignBonds($state, $dt);

        if ($state->boardFloatCap > 0.0) {
            // The float the fund held a share of before the companies' own issuance and buybacks landed.
            $floatBeforeIssuance = $state->boardFloatCap - $state->boardNetIssuance;
            $ownership = $floatBeforeIssuance > 0.0 ? $state->sovereignFundDomesticEquity / $floatBeforeIssuance : 0.0;

            $dividends = ($state->sovereignFundDomesticEquity / $state->boardFloatCap) * $state->boardDividendCash;
            $participation = $ownership * $state->boardNetIssuance;
            $state->sovereignFundDomesticEquity = max(0.0, $state->sovereignFundDomesticEquity + $participation);
            $state->sovereignFundForeignBonds += ($dividends - $participation) * $state->exchangeRateIndex;
        }
    }

    /**
     * One tick of the paper sleeve: a constant-maturity index of foreign sovereign zeros at the reference duration,
     * bought at the last close's yield and valued a tick later, one tick shorter, on today's curve, then rolled.
     *
     * Carry, roll-down and the rate move come out of one exact price ratio, exp(y_then x D - y_now(D - dt) x (D - dt)),
     * so the sleeve compounds the same at any tick size. A sleeve with no yield on record (a fund incepted before the
     * paper was marked) is priced here and earns that yield's carry for the tick; the foreign curve never gets near
     * zero, since the foreign rate is floored at zero and the term premium sits on top.
     */
    private function markForeignBonds(MacroState $state, float $dt): void
    {
        $boughtAt = $state->foreignBondYield;
        $state->foreignBondYield = $this->foreignZeroYield($state, self::FOREIGN_BOND_DURATION);

        if ($boughtAt <= 0.0) {
            $logReturn = $state->foreignBondYield * $dt;
        } else {
            $held = max(0.0, self::FOREIGN_BOND_DURATION - $dt);
            $logReturn = ($boughtAt * self::FOREIGN_BOND_DURATION) - ($this->foreignZeroYield($state, $held) * $held);
        }

        $state->sovereignFundForeignBonds *= exp($logReturn);
    }

    /**
     * The foreign sovereign zero-coupon yield at a maturity, on the domestic curve's own building blocks: the foreign
     * policy rate's expected path back to its neutral level at the Bliss slope decay (the Vasicek expectations loading),
     * plus the Adrian-Crump-Moench term premium scaled for duration. The foreign bloc has no curve shocks of its own,
     * so the paper reprices only as the foreign rate moves with the foreign cycle.
     */
    public function foreignZeroYield(MacroState $state, float $maturity): float
    {
        $neutral = MacroEngine::MAINLAND_NEUTRAL_RATE;
        $expectationsYield = $this->mathUtility->calculateSvenssonYield(
            level: $neutral,
            slope: $state->foreignPolicyRate - $neutral,
            curvature1: 0.0,
            curvature2: 0.0,
            tau: $maturity,
            slopeLambda: MacroEngine::SVENSSON_SLOPE_LAMBDA
        );

        return $expectationsYield
            + (MacroEngine::NS_BASE_TERM_PREMIUM * MathUtility::calculateTermPremiumDurationScale($maturity, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS));
    }

    /**
     * The premium the paper earns over the foreign short rate on a steady curve: a constant-maturity zero earns the
     * forward rate at its maturity, which is the yield plus its roll-down, so the forward term premium at the duration.
     */
    public static function foreignBondExpectedPremium(): float
    {
        return MacroEngine::NS_BASE_TERM_PREMIUM
            * MathUtility::calculateTermPremiumForwardScale(self::FOREIGN_BOND_DURATION, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
    }

    /**
     * Chains the time-weighted return indices over the tick (GIPS): the fund marked to market against its last close,
     * before any money arrives or leaves. Valued every tick, the chain is exact, so the District's payments in and the
     * budget's draw never read as performance, and a rebalance, which swaps one sleeve for another at market, cannot.
     * The real index deflates by the District's inflation over the tick. A fund with no index yet, one incepted before
     * the index existed, opens it at the base here and books no return for the tick.
     */
    private function chainReturnIndices(MacroState $state, float $dt): void
    {
        if ($state->sovereignFundReturnIndex <= 0.0 || $state->sovereignFundValueAtClose <= 0.0) {
            $state->sovereignFundReturnIndex = self::RETURN_INDEX_BASE;
            $state->sovereignFundRealReturnIndex = self::RETURN_INDEX_BASE;
            return;
        }

        $growth = $this->fundValue($state) / $state->sovereignFundValueAtClose;
        $state->sovereignFundReturnIndex *= $growth;
        $state->sovereignFundRealReturnIndex *= $growth * exp(-$state->inflation * $dt);
    }

    /** The money paid in from outside the fund over the last tick, parked in the paper sleeve like any cash it receives. */
    private function receiveInflows(MacroState $state): void
    {
        // The stamp duty on the board's trading is paid into the fund rather than the budget, as Singapore's land-sale
        // proceeds go to its reserves: new money, parked in the paper sleeve like any cash the fund receives.
        $state->sovereignFundForeignBonds += $state->boardStampDuty * $state->exchangeRateIndex;
        $state->sovereignFundStampDutyYearToDate += $state->boardStampDuty;

        // The District's strategic stakes sit outside the fund, but their cash is paid into it (and a subscription to an
        // issue paid out of it), as the dividends on Norway's 67% of Equinor reach the GPFG.
        $state->sovereignFundForeignBonds += $state->strategicStakeCash * $state->exchangeRateIndex;

        // A budget surplus with no debt left to retire above the floor is paid in, as Singapore's surpluses accrue to
        // its reserves. Taken in once: the fiscal accounts strike the next one after this update.
        $state->sovereignFundForeignBonds += $state->sovereignFundBudgetInflow * $state->exchangeRateIndex;
        $state->sovereignFundBudgetInflow = 0.0;
    }

    /** Publishes the budget year's stamp duty over GDP and starts the next year's count. */
    private function closeStampDutyYear(MacroState $state): void
    {
        $gdpDollars = $state->sovereignFundDollarsPerGdp * $state->nominalGdpIndex;
        $state->sovereignFundStampDutyToGdp = $gdpDollars > 0.0 ? $state->sovereignFundStampDutyYearToDate / $gdpDollars : 0.0;
        $state->sovereignFundStampDutyYearToDate = 0.0;
    }

    /** Sets this budget year's draw, and publishes the expected return it was set from. */
    private function setAnnualDraw(MacroState $state): void
    {
        $state->sovereignFundExpectedRealReturn = $this->currentExpectedRealReturn($state);
        $state->sovereignFundAnnualDraw = $this->calculateAnnualDraw($state);
    }

    /**
     * This budget year's draw: half the expected long-term (compound) real return on the fund at the start of the year.
     *
     * @return float Currency per year.
     */
    public function calculateAnnualDraw(MacroState $state): float
    {
        return self::NIR_SPENDING_SHARE * max(0.0, $this->currentExpectedRealReturn($state)) * $this->fundValue($state);
    }

    /** The expected compound real return on the fund at its sleeve weights today; zero for an empty fund. */
    public function currentExpectedRealReturn(MacroState $state): float
    {
        $fund = $this->fundValue($state);
        if ($fund <= 0.0) {
            return 0.0;
        }

        return $this->expectedCompoundRealReturn(
            $state,
            $state->sovereignFundDomesticEquity / $fund,
            $this->foreignEquityHomeValue($state) / $fund
        );
    }

    /**
     * The portfolio's expected compound real return at sleeve weights: its arithmetic mean less half its variance.
     *
     * The variance is the Markowitz sum over the three sleeves in home terms. Foreign equities carry their own market
     * and the currency, foreign paper the currency alone, and the two co-move with the board only through the equity
     * leg's loading on the home market factor. The paper's own rate risk is left out: at the index's 4.1% volatility
     * (GLAD, 3 years to Aug 2026), with a third of the fund in it and a stock-bond correlation of -0.3, it and its
     * covariance with the equity leg move the variance drag by under 0.1pp.
     *
     * @param float $domesticWeight      Share of the fund in the board.
     * @param float $foreignEquityWeight Share of the fund in foreign equities; foreign paper is the remainder.
     */
    public function expectedCompoundRealReturn(MacroState $state, float $domesticWeight, float $foreignEquityWeight): float
    {
        $domestic = max(0.0, min(1.0, $domesticWeight));
        $equity = max(0.0, min(1.0 - $domestic, $foreignEquityWeight));
        $paper = 1.0 - $domestic - $equity;

        $foreignRealRate = MacroEngine::MAINLAND_NEUTRAL_RATE - MacroEngine::TARGET_INFLATION;
        $arithmetic = ($domestic * $this->domesticExpectedRealReturn($state))
            + ($equity * ($foreignRealRate + MacroEngine::BASE_EQUITY_RISK_PREMIUM))
            + ($paper * ($foreignRealRate + self::foreignBondExpectedPremium()));

        $boardVol = MacroEngine::MACRO_VOL_BASE_ANCHOR;
        $equityVol = self::FOREIGN_EQUITY_VOLATILITY;
        $fxVol = MacroEngine::EXCHANGE_RATE_VOLATILITY;
        $foreignShare = $equity + $paper;

        $variance = ($domestic * $domestic * $boardVol * $boardVol)
            + ($equity * $equity * $equityVol * $equityVol)
            + ($foreignShare * $foreignShare * $fxVol * $fxVol)
            + (2.0 * $domestic * $equity * self::FOREIGN_EQUITY_MARKET_CORRELATION * $equityVol * $boardVol);

        return $arithmetic - (0.5 * $variance);
    }

    /** The compound return of the fund at its policy mix for a given domestic weight: the foreign sleeves split 65/35. */
    public function policyCompoundRealReturn(MacroState $state, float $domesticWeight): float
    {
        return $this->expectedCompoundRealReturn($state, $domesticWeight, self::FOREIGN_EQUITY_SHARE * (1.0 - $domesticWeight));
    }

    /**
     * Pays the year's draw out of the foreign sleeves in proportion to their size, at an even pace, together with the
     * budget's stabilisation (CreditFiscalSubsystem::calculateFundStabilisation): spending in a slump, and in a boom
     * a negative amount, the budget's saving paid back in.
     */
    private function payDraw(MacroState $state, float $dt): void
    {
        $stabilisation = $state->sovereignFundStabilisationToGdp * $state->sovereignFundDollarsPerGdp * $state->nominalGdpIndex;
        $this->withdrawForeign($state, ($state->sovereignFundAnnualDraw + $stabilisation) * $dt);
    }

    /**
     * The month-end review: continue a programme in flight, or start one when either deviation limit is breached.
     *
     * A programme re-measures what is left of the board's gap at every month end, so a market that keeps falling while
     * it trades is bought into rather than chased by a stale amount, and it lands on the policy weight when its months
     * run out. The foreign sleeves are put back on their policy split at each review of a programme.
     */
    private function reviewRebalance(MacroState $state): void
    {
        $fund = $this->fundValue($state);
        if ($fund <= 0.0) {
            return;
        }

        $domesticWeight = $state->sovereignFundDomesticEquity / $fund;
        $gap = min(($state->sovereignFundTargetWeight - $domesticWeight) * $fund, $this->purchaseRoom($state));

        if ($state->sovereignFundRebalanceMonthsLeft > 0.0) {
            $state->sovereignFundRebalanceMonthsLeft = max(0.0, $state->sovereignFundRebalanceMonthsLeft - 1.0);
        }

        if ($state->sovereignFundRebalanceMonthsLeft > 0.0) {
            $this->scheduleRebalance($state, $gap, $state->sovereignFundRebalanceMonthsLeft);
            $this->resetForeignSplit($state, $this->foreignHomeValue($state));
            return;
        }

        $state->sovereignFundRebalanceBacklog = 0.0;
        $state->sovereignFundRebalanceRate = 0.0;

        $equityShare = ($state->sovereignFundDomesticEquity + $this->foreignEquityHomeValue($state)) / $fund;
        $equityTarget = $this->policyEquityShare($state->sovereignFundTargetWeight);

        $domesticBreach = abs($domesticWeight - $state->sovereignFundTargetWeight) > $this->rebalanceBand($state->sovereignFundTargetWeight);
        $equityBreach = abs($equityShare - $equityTarget) > $this->equityBand($equityTarget);
        if (!$domesticBreach && !$equityBreach) {
            return;
        }

        // Back to policy: the foreign sleeves at once, the board over the programme. A breach the ownership ceiling
        // leaves no room to act on starts no programme on the board, so there is nothing to announce.
        $this->resetForeignSplit($state, $this->foreignHomeValue($state));
        if ($gap !== 0.0) {
            $this->scheduleRebalance($state, $gap, self::REBALANCE_EXECUTION_MONTHS);
            $state->lastSovereignRebalanceAt = $state->totalTime;
        }
    }

    /** Sets a programme to trade $amount of the board evenly over $months. */
    private function scheduleRebalance(MacroState $state, float $amount, float $months): void
    {
        $state->sovereignFundRebalanceMonthsLeft = $months;
        $state->sovereignFundRebalanceBacklog = $amount;
        $state->sovereignFundRebalanceRate = $amount / ($months * self::REBALANCE_CHECK_PERIOD_YEARS);
    }

    /**
     * This tick's slice of the programme, booked between the sleeves at once; the ticker executes it on the board.
     *
     * The currency comes out of (or goes back into) the foreign sleeves in proportion to their size, so the programme
     * never skews the foreign split it just put back on policy.
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
        $trade = max(-$state->sovereignFundDomesticEquity, min($this->foreignHomeValue($state), $this->purchaseRoom($state), $trade));

        $state->sovereignFundRebalanceBacklog = $backlog - $trade;
        $state->sovereignFundDomesticEquity += $trade;
        $this->withdrawForeign($state, $trade);

        return $trade;
    }

    /**
     * The deviation band on this fund's domestic weight: GPIF's domestic limit carried by the move that breaches it.
     *
     * GPIF's band is written for a 25% weight; the same number of points on a weight a fifth of that size would be a
     * different rule. What carries across is the relative move that breaches it: the sleeve falling d against the rest
     * moves a weight w by w(1 - w)d / (1 - wd), so GPIF's limit L on T trips at d* = L / (T(1 - T) + TL), about 30%,
     * and this fund's band is the move d* makes in its own weight.
     */
    public function rebalanceBand(float $targetWeight): float
    {
        return $this->breachingBand($targetWeight, self::GPIF_DOMESTIC_EQUITY_TARGET, self::GPIF_DOMESTIC_EQUITY_DEVIATION_LIMIT);
    }

    /** The deviation band on the fund's whole equity share: GPIF's global equity limit, carried the same way. */
    public function equityBand(float $targetShare): float
    {
        return $this->breachingBand($targetShare, self::GPIF_GLOBAL_EQUITY_TARGET, self::GPIF_GLOBAL_EQUITY_DEVIATION_LIMIT);
    }

    /** The fund's policy equity share: the board plus the equity share of the foreign sleeves. */
    public function policyEquityShare(float $domesticTargetWeight): float
    {
        return $domesticTargetWeight + (self::FOREIGN_EQUITY_SHARE * (1.0 - $domesticTargetWeight));
    }

    /** The relative fall of a sleeve against the rest of the fund that breaches GPIF's limit L on weight T: L / (T(1 - T) + TL). */
    public static function breachingMove(float $gpifTarget, float $gpifLimit): float
    {
        return $gpifLimit / (($gpifTarget * (1.0 - $gpifTarget)) + ($gpifTarget * $gpifLimit));
    }

    /** A weight's band: the move in it that the relative fall breaching GPIF's limit L on weight T would make. */
    private function breachingBand(float $weight, float $gpifTarget, float $gpifLimit): float
    {
        $breachingMove = self::breachingMove($gpifTarget, $gpifLimit);

        return $weight * (1.0 - $weight) * $breachingMove / (1.0 - ($weight * $breachingMove));
    }

    /** Currency the fund may still spend on the board before it reaches the ownership ceiling; never negative. */
    private function purchaseRoom(MacroState $state): float
    {
        $room = (self::MAX_OWNERSHIP_SHARE * $state->boardFloatCap) - $state->sovereignFundDomesticEquity;

        // A fund that has bought up to the ceiling sits on it to the last bit, and rounding must not read as room.
        return $room > self::ROOM_TOLERANCE * $state->boardFloatCap ? $room : 0.0;
    }

    /** Puts a home-currency amount of foreign assets back on the 65/35 policy split. */
    private function resetForeignSplit(MacroState $state, float $foreignHomeValue): void
    {
        $units = max(0.0, $foreignHomeValue) * $state->exchangeRateIndex;
        $state->sovereignFundForeignEquity = self::FOREIGN_EQUITY_SHARE * $units;
        $state->sovereignFundForeignBonds = (1.0 - self::FOREIGN_EQUITY_SHARE) * $units;
    }

    /** Takes a home-currency amount out of the foreign sleeves in proportion to their size (a negative amount puts it back). */
    private function withdrawForeign(MacroState $state, float $homeAmount): void
    {
        $units = $state->sovereignFundForeignEquity + $state->sovereignFundForeignBonds;
        if ($units <= 0.0) {
            return;
        }

        $remaining = max(0.0, 1.0 - (($homeAmount * $state->exchangeRateIndex) / $units));
        $state->sovereignFundForeignEquity *= $remaining;
        $state->sovereignFundForeignBonds *= $remaining;
    }

    /** Refreshes the stationary readings the dashboards and macro_report use. */
    private function publish(MacroState $state): void
    {
        $fund = $this->fundValue($state);
        $gdpDollars = $state->sovereignFundDollarsPerGdp * $state->nominalGdpIndex;

        $state->sovereignFundToGdp = $gdpDollars > 0.0 ? $fund / $gdpDollars : 0.0;
        $state->sovereignFundDomesticWeight = $fund > 0.0 ? $state->sovereignFundDomesticEquity / $fund : 0.0;
        $state->sovereignFundEquityShare = $fund > 0.0 ? ($state->sovereignFundDomesticEquity + $this->foreignEquityHomeValue($state)) / $fund : 0.0;
        $state->sovereignFundOwnershipShare = $state->boardFloatCap > 0.0 ? $state->sovereignFundDomesticEquity / $state->boardFloatCap : 0.0;
        $state->sovereignFundDrawToGdp = $gdpDollars > 0.0 ? $state->sovereignFundAnnualDraw / $gdpDollars : 0.0;
        $state->sovereignFundRebalanceShare = $state->boardFloatCap > 0.0 ? $state->sovereignFundRebalanceBacklog / $state->boardFloatCap : 0.0;
        $state->sovereignFundBondsToGdp = ($gdpDollars > 0.0 && $state->exchangeRateIndex > 0.0)
            ? ($state->sovereignFundForeignBonds / $state->exchangeRateIndex) / $gdpDollars
            : 0.0;
        $state->sovereignFundValueAtClose = $fund;
    }

    /** The whole fund in home currency. */
    public function fundValue(MacroState $state): float
    {
        return $state->sovereignFundDomesticEquity + $this->foreignHomeValue($state);
    }

    /** Both foreign sleeves in home currency: foreign units at today's exchange rate, a stronger home currency buying more of them. */
    public function foreignHomeValue(MacroState $state): float
    {
        return $state->exchangeRateIndex > 0.0
            ? ($state->sovereignFundForeignEquity + $state->sovereignFundForeignBonds) / $state->exchangeRateIndex
            : 0.0;
    }

    /** The foreign equity sleeve in home currency. */
    public function foreignEquityHomeValue(MacroState $state): float
    {
        return $state->exchangeRateIndex > 0.0 ? $state->sovereignFundForeignEquity / $state->exchangeRateIndex : 0.0;
    }

    /** Expected real return on the board: the natural rate plus the equity premium. */
    private function domesticExpectedRealReturn(MacroState $state): float
    {
        return $state->naturalRate + MacroEngine::BASE_EQUITY_RISK_PREMIUM;
    }
}
