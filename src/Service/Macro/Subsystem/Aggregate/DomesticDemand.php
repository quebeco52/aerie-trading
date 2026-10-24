<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem\Aggregate;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\FixedIncome;
use App\Service\Math\MacroConstants;
use App\Service\Math\ResponseCurves;
use App\Service\Math\TimeSeries;

/**
 * The demand equation domestic final sales run on (Kaldor 1940): its momentum, capacity ceiling and automatic
 * stabiliser, every channel through which policy, credit, wealth, supply costs and household debt move spending, and
 * the capital stock overhang that is the cycle's slow half.
 */
final class DomesticDemand
{
    // --- KALDOR-KALECKI 2D LIMIT CYCLE ---
    /**
     * Demand drift per unit of purchases above baseline: with the rule's offset a purchases shock adds ~0.8 of itself
     * to output over two years, inside the 0.5-0.9 range of cumulative multipliers economies show.
     */
    public const KALDOR_GOVT_SPENDING_MULTIPLIER = 0.3214;
    /**
     * The rate the demand equation settles a sustained drive at, its automatic stabiliser less its momentum: a drive of
     * c a year holds the gap at c over this, so an effect on the level of spending enters the drift times it.
     */
    public const DEMAND_OWN_PULL = self::KALDOR_AUTOMATIC_STABILISER - self::KALDOR_MOMENTUM;
    /**
     * Marginal propensity to consume out of a dollar of housing wealth, a year (Mian, Rao & Sufi 2013: 5-7 cents; the
     * low end).
     */
    public const HOUSING_WEALTH_MPC = 0.05;
    /** Household real estate over GDP, 1985-2019 mean (Fed Z.1 via FRED, HNOREMQ027S over GDP: 1.40). */
    public const HOUSING_WEALTH_TO_GDP = 1.40;
    /**
     * Demand drift per unit of housing wealth above the level households are used to: the MPC on the stock's size, at
     * the pull that turns a level of spending into a drift.
     */
    public const KALDOR_WEALTH_EFFECT_ELASTICITY = self::DEMAND_OWN_PULL * self::HOUSING_WEALTH_MPC * self::HOUSING_WEALTH_TO_GDP;
    /**
     * Marginal propensity to consume out of a dollar of stock market wealth, a year (Chodorow-Reich, Nenov & Simsek
     * 2021: 3.2 cents).
     */
    public const EQUITY_WEALTH_MPC = 0.032;
    /**
     * Share of the board District households own themselves, directly and through funds (the holdings the MPC is
     * measured on): a financial centre whose listed giants earn abroad, the UK's 20.4% (ONS, Ownership of UK quoted
     * shares 2020: individuals 12.0%, unit trusts 7.4%, investment trusts 1.0%; the rest of the world holds 56.3%).
     */
    public const DISTRICT_HOUSEHOLD_EQUITY_SHARE = 0.204;
    /**
     * Demand drift per unit of equity wealth above the level households are used to: the MPC on the part of the board
     * households own, which is sized against District GDP already, so domestic demand's share is undone. Foreign
     * holders spend their gains abroad.
     */
    public const KALDOR_EQUITY_WEALTH_ELASTICITY = self::DEMAND_OWN_PULL * self::EQUITY_WEALTH_MPC * self::DISTRICT_HOUSEHOLD_EQUITY_SHARE * SovereignFundSubsystem::MARKET_CAP_TO_GDP / OutputGap::DOMESTIC_GAP_WEIGHT;
    /** The rate at which business investment (output gap) accumulates into the physical capital stock. */
    public const CAPITAL_ACCUMULATION_RATE = 0.25;
    /** The rate at which physical capital depreciates, organically clearing overhangs and creating pent-up demand. */
    public const CAPITAL_DECAY_RATE = 0.30;
    /** Asymptotic lower bound floor on physical capital stock contraction / overhang. */
    public const CAPITAL_OVERHANG_MIN = -0.15;
    /** Asymptotic upper bound ceiling on physical capital stock excess capacity / overhang. */
    public const CAPITAL_OVERHANG_MAX = 0.15;
    /**
     * Linear self-reinforcement of demand, kept low enough that zero is a stable point and the cycle is
     * shock-driven (Frisch-Slutsky) rather than a clockwork limit cycle, while keeping the left skew.
     */
    public const KALDOR_MOMENTUM = 0.06;
    /**
     * Cubic capacity ceiling on the UPSIDE only (Friedman 1993 plucking; Dupraz, Nakamura & Steinsson 2019): output is
     * plucked below a ceiling it cannot run above, and a slump has no floor of its own.
     */
    public const KALDOR_CAPACITY = 600.0;
    /**
     * Demand per unit of the transmitted real-rate stance, either side of neutral: tightening binds the collateral
     * firms and homebuyers borrow against (Guerrieri & Iacoviello 2017), beside the wealth, trade and debt-service
     * routes the engine carries separately. The District borrows at floating rates, so a cut reaches borrowers as
     * fully as a hike; where fixed-rate debt locks borrowers in, easing pushes on a string (Tenreyro & Thwaites 2016).
     */
    public const KALDOR_MONETARY_DRAG = 1.2505;

    /**
     * Time constant of each of the two Pascal stages the real-rate stance passes through before it moves demand (Solow
     * 1960): mean lag 0.8y against Rudebusch-Svensson's year average lagged a quarter (0.6y); the same fit.
     */
    public const MONETARY_TRANSMISSION_LAG_YEARS = 0.3;
    /**
     * Demand per unit of an ADVERSE excess bond premium over its decade trend, the one price of credit supply
     * (Gilchrist & Zakrajsek 2012): a 2008-sized 2pp spike costs 7pp of demand a year while it lasts.
     */
    public const KALDOR_CREDIT_PREMIUM_DRAG = 3.5;
    /**
     * Adverse premium the premium's own noise averages over its decade trend, the economy still and no crisis struck:
     * 0.145pp. The one-sided drag hands it back, as a jump's compensator does, so credit noise bends the cycle without
     * lowering its average; crises and the cycle's own spikes stay costs.
     */
    public const CREDIT_PREMIUM_NOISE_ADVERSE_MEAN = 0.00145;
    /**
     * Excess premium per unit of capital the banks must hold but have not built, here and in lending standards: 0.22,
     * so a 1pp rise phased in over four years costs the 0.17% of output the MAG (2010) median gives.
     */
    public const CAPITAL_SHORTFALL_PREMIUM_EQUIVALENT = 0.22;
    /**
     * Demand per point a tax rate is cut below its decade trend by decision, the stance in net taxes as a share of GDP
     * and the corporate rate alike; on the corporate rate it is near Mertens & Ravn's (2013) 0.4% of output on impact a
     * point. The stabilisers' swing is not a decision and is not read here.
     */
    public const KALDOR_FISCAL_MULTIPLIER = 0.50;
    /**
     * The demand gap's own pull back to trend on the CURRENT gap: automatic stabilisers (Blanchard & Perotti 2002) and
     * the permanent-income smoothing of transitory income (Friedman 1957), the level forces no channel here carries;
     * the same fit. Without it a rate move would integrate into the gap without limit.
     */
    public const KALDOR_AUTOMATIC_STABILISER = 1.5;
    /**
     * Sensitivity of the output gap to physical capital stock overhang: the slow half of the Kaldor-Kalecki phase
     * space, and the pent-up demand that ends a slump once the overhang has gone negative.
     */
    public const KALDOR_CAPITAL_DRAG = 0.25;
    /**
     * Demand response to a capital SHORTFALL, well under the drag an excess exerts: scrapped capacity does not summon
     * construction while balance sheets are still impaired (Bertola-Caballero 1994 irreversibility).
     */
    public const KALDOR_CAPITAL_REBOUND_DRAG = 0.08;
    /** Bruno-Sachs (1985) supply-side elasticity of output to energy price shock (Blanchard-Gali 2007). */
    public const KALDOR_ENERGY_SUPPLY_DRAG = 0.012;
    /** Supply-side elasticity of output to excess freight/logistics costs. */
    public const KALDOR_FREIGHT_SUPPLY_DRAG = 0.002;
    /**
     * Demand per unit of household debt-service gap (Juselius & Drehmann 2015; Drehmann, Juselius & Korinek 2018): the
     * service a stock of debt commits to takes spending back years after the borrowing; a point of income in extra
     * service costs half a point of demand a year.
     */
    public const KALDOR_HOUSEHOLD_DEBT_SERVICE = 0.50;
    /**
     * Demand per unit of new household borrowing, in debt to income a year: borrowed income is spent as it is borrowed.
     * Fitted by running Drehmann, Juselius & Korinek's (2018) local projection on the engine: GDP growth +0.128 the
     * year after a point of new borrowing to GDP (their Table 8 base, +0.126); the US lead of credit on the gap
     * follows.
     */
    public const KALDOR_HOUSEHOLD_NEW_BORROWING = 1.0;
    /**
     * Output lost per unit of catastrophe loss burden above an average year (Noy 2009; Hsiang & Jina 2014 give the
     * sign): a year at twice the average burden costs ~0.4pp of output, before the rebuild the construction stream
     * books.
     */
    public const KALDOR_CATASTROPHE_DRAG = 0.004;
    /**
     * Burden above an average year the catastrophe process averages (0.24 of a year, a heavy tail of rare large
     * seasons): potential carries the average toll, so the drag hands it back and only a worse season than that costs.
     */
    public const CATASTROPHE_ADVERSE_BURDEN_MEAN = 0.24;
    /**
     * Demand drag per log unit of policy uncertainty ABOVE baseline: a doubling costs ~0.4pp a year, so the 2006-2011
     * rise integrates to the ~1% output loss Baker, Bloom & Davis attribute to it. One-sided: spikes cost output (Bloom
     * 2009), calm does not stimulate.
     */
    public const KALDOR_EPU_DRAG = 0.006;
    /**
     * Log uncertainty over baseline the index's own noise averages on its adverse side, with no slack and no election
     * near (0.082): handed back so the noise bends the cycle without lowering its average; uncertainty that slack and
     * elections raise stays a cost.
     */
    public const EPU_NOISE_ADVERSE_LOG_MEAN = 0.082;

    // --- Effective Corporate Borrowing Cost Weights ---
    /**
     * Floating-rate share of private borrowing priced off the policy rate (mortgages, bank and leveraged loans,
     * revolvers, cards): three quarters, the District's homes being bought on variable loans.
     */
    public const BORROWING_POLICY_WEIGHT = 0.75;
    /** Fixed-rate share (corporate bonds, auto and term loans) priced off the 5-year benchmark. */
    public const BORROWING_YIELD5Y_WEIGHT = 0.25;

    /**
     * The demand equation's drift, channel by channel, at domestic demand's own scale, stepping the real-rate stance
     * through its transmission lag on the way. Every channel is signed as it acts on demand, so a drag reads negative
     * wherever it is looked at.
     *
     * @param MacroState $state             Current macroeconomic state.
     * @param float      $y                 The domestic demand gap the tick opened on (OutputGap::domesticDemandGap()).
     * @param float      $yield5y           5-Year Treasury yield benchmark for business borrowing.
     * @param float      $naturalRate       Dynamic natural real rate of interest (r*).
     * @param float      $expectedInflation Short-horizon expected inflation, the Taylor rule's own measure
     *                                      (MonetaryPolicySubsystem::calculateExpectedInflation).
     * @param float      $dt                Time increment in years.
     * @return array<string, float> Drift per channel, a year.
     */
    public static function driftChannels(MacroState $state, float $y, float $yield5y, float $naturalRate, float $expectedInflation, float $dt): array
    {
        // Curdia & Woodford (2010) ex-ante real borrowing cost, the 5-year leg deflated by the market's breakeven.
        $expectedInflation5y = $state->tipsBreakeven;
        $realRate = (self::BORROWING_POLICY_WEIGHT * ($state->policyRate - $expectedInflation))
            + (self::BORROWING_YIELD5Y_WEIGHT * ($yield5y - $expectedInflation5y));

        $neutral5yDurationScale = FixedIncome::calculateTermPremiumDurationScale(5.0, MacroConstants::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        // Bernanke (2006) neutral borrowing benchmark incorporating structural term premium.
        $neutral5yYield = $naturalRate + MacroConstants::TARGET_INFLATION + (MacroConstants::NS_BASE_TERM_PREMIUM * $neutral5yDurationScale);

        $neutralBorrowingPolicy = (self::BORROWING_POLICY_WEIGHT * ($naturalRate + MacroConstants::TARGET_INFLATION))
            + (self::BORROWING_YIELD5Y_WEIGHT * $neutral5yYield);
        // Woodford (2003) neutral real rate with expectations anchored at inflation target.
        $neutralRealRate = $neutralBorrowingPolicy - MacroConstants::TARGET_INFLATION;

        // Curdia & Woodford (2010) risk-free real stance, reaching spending through a Pascal lag (Solow 1960;
        // Rudebusch & Svensson 1999 lag the real rate a year): investment is planned, ordered and built.
        $state->monetaryStanceStage1 = TimeSeries::calculateDistributedLag($state->monetaryStanceStage1, $realRate - $neutralRealRate, $dt, self::MONETARY_TRANSMISSION_LAG_YEARS);
        $state->monetaryStanceTransmitted = TimeSeries::calculateDistributedLag($state->monetaryStanceTransmitted, $state->monetaryStanceStage1, $dt, self::MONETARY_TRANSMISSION_LAG_YEARS);
        $monetaryDrag = self::KALDOR_MONETARY_DRAG * $state->monetaryStanceTransmitted;

        // Gilchrist & Zakrajsek (2012): the excess bond premium is the price of credit supply, and spending pays it on
        // its adverse side only (Barnichon, Matthes & Ziegenbein 2022: easy credit does not lift output). Read against
        // its decade trend, with capital the banks have yet to build counted as premium (Macroeconomic Assessment Group
        // 2010). Like the uncertainty and catastrophe drags it hands back the average its input's own noise puts on it,
        // as a jump's compensator does: the noise bends the cycle's shape without lowering its average.
        $creditPremiumDrag = self::KALDOR_CREDIT_PREMIUM_DRAG * (max(0.0, self::excessCreditPremium($state)) - self::CREDIT_PREMIUM_NOISE_ADVERSE_MEAN);

        $momentum = self::KALDOR_MOMENTUM * $y;
        // Kaldor (1940) non-linear asymmetric capacity ceiling constraint.
        $cubicConstraint = $y > 0.0 ? self::KALDOR_CAPACITY * pow($y, 3) : 0.0;
        // Blanchard & Perotti (2002) DISCRETIONARY fiscal impulse: a statutory rate and an appropriated outlay both
        // clear a legislative lag, so this leg reaches demand late by construction. Both are read against their own
        // decade trends: a stance held that long is in potential (Blanchard 1990 fiscal impulse), not the gap.
        $spendingShift = CreditFiscalSubsystem::discretionarySpendingShift($state);
        $discretionaryFiscal = (self::KALDOR_FISCAL_MULTIPLIER * (($state->corporateTaxRateTrend - $state->corporateTaxRate) + ($state->netTaxStanceRateTrend - $state->netTaxStanceRate)))
            + (self::KALDOR_GOVT_SPENDING_MULTIPLIER * $spendingShift);
        // The part of the fund's draw above the founding share reaches demand through the purchases channel: the
        // spending a budget drawing more of the fund's return, as in a recession, adds to the baseline the founding
        // draw is in.
        $drawAboveFounding = $state->sovereignFundDrawShare > 0.0
            ? $state->sovereignFundDrawToGdp * ($state->sovereignFundDrawShare - MacroConstants::RESERVE_DRAW_CEILING) / $state->sovereignFundDrawShare
            : 0.0;
        $fundDraw = self::KALDOR_GOVT_SPENDING_MULTIPLIER * $drawAboveFounding / MacroConstants::PUBLIC_PURCHASES_TO_GDP;

        // Blanchard & Perotti (2002) AUTOMATIC stabilisers and Friedman (1957) income smoothing, on the contemporaneous
        // gap: nobody decides them, so they carry no lag and act as a spring rather than an anti-damper.
        $automaticStabiliser = -self::KALDOR_AUTOMATIC_STABILISER * $y;

        // Bertola & Caballero (1994) asymmetric capital overhang drag reflecting investment irreversibility.
        $capitalDrag = ResponseCurves::calculateAsymmetricResponse($state->capitalStockOverhang, self::KALDOR_CAPITAL_DRAG, self::KALDOR_CAPITAL_REBOUND_DRAG);

        // Case, Quigley & Shiller (2005) housing wealth effect relative to persistent trend.
        $housingWealthEffect = $state->residentialWealthTrend > 0.0
            ? max(-0.60, min(0.60, ($state->residentialPropertyIndexEma / $state->residentialWealthTrend) - 1.0)) * self::KALDOR_WEALTH_EFFECT_ELASTICITY
            : 0.0;

        // Lettau & Ludvigson (2001) equity wealth effect from equity-to-GDP valuation trend deviations.
        $equityWealthRatio = self::equityWealthRatio($state);
        $equityWealthEffect = ($equityWealthRatio > 0.0 && $state->equityWealthTrend > 0.0)
            ? max(-0.60, min(0.60, ($equityWealthRatio / $state->equityWealthTrend) - 1.0)) * self::KALDOR_EQUITY_WEALTH_ELASTICITY
            : 0.0;
        // Bruno & Sachs (1985) and Blanchard & Gali (2007) symmetric energy supply shock drag, and its freight
        // counterpart, each against the price's own decade trend: where a price settles is no shock.
        $energySupplyDrag = self::supplyCostShift($state->energyPriceIndexEma, $state->energyPriceIndexDecadeTrend) * self::KALDOR_ENERGY_SUPPLY_DRAG;
        $freightSupplyDrag = self::supplyCostShift($state->freightRateIndexEma, $state->freightRateIndexDecadeTrend) * self::KALDOR_FREIGHT_SUPPLY_DRAG;

        // Drehmann, Juselius & Korinek (2018): new borrowing lifts spending now, and the debt service it commits to
        // drags it later. Service peaks about four years after the borrowing, which is the credit boom's reversal.
        $householdNewBorrowing = self::KALDOR_HOUSEHOLD_NEW_BORROWING * $state->householdNewBorrowing;
        $householdDeleveragingDrag = self::KALDOR_HOUSEHOLD_DEBT_SERVICE * $state->householdDebtServiceGap;

        // Hallegatte et al. (2007) physical capital destruction supply drag, past the average season's toll.
        $catastropheSupplyDrag = self::KALDOR_CATASTROPHE_DRAG * (max(0.0, $state->catastropheLossIndexEma - 1.0) - self::CATASTROPHE_ADVERSE_BURDEN_MEAN);

        // Jordà, Schularick & Taylor (2013) post-crisis deleveraging by firms and banks; households' share repays their
        // debt and reaches demand through the new-borrowing channel.
        $crisisDeleveragingDrag = CreditFiscalSubsystem::crisisDirectDrag($state->creditCrisisDrag);

        // Baker, Bloom & Davis (2016) real options investment deferral under policy uncertainty, past its noise's toll.
        $policyUncertaintyDrag = self::KALDOR_EPU_DRAG * (max(0.0, log(max(1.0, $state->policyUncertaintyIndexEma) / MacroConstants::EPU_BASELINE)) - self::EPU_NOISE_ADVERSE_LOG_MEAN);

        return [
            'momentum' => $momentum,
            'cubicConstraint' => -$cubicConstraint,
            'monetaryDrag' => -$monetaryDrag,
            'creditPremiumDrag' => -$creditPremiumDrag,
            'fiscalStimulus' => $discretionaryFiscal,
            'fundDraw' => $fundDraw,
            'automaticStabiliser' => $automaticStabiliser,
            'capitalDrag' => -$capitalDrag,
            'housingWealthEffect' => $housingWealthEffect,
            'equityWealthEffect' => $equityWealthEffect,
            'energySupplyDrag' => -$energySupplyDrag,
            'freightSupplyDrag' => -$freightSupplyDrag,
            'policyUncertaintyDrag' => -$policyUncertaintyDrag,
            'catastropheSupplyDrag' => -$catastropheSupplyDrag,
            'householdNewBorrowing' => $householdNewBorrowing,
            'householdDeleveragingDrag' => -$householdDeleveragingDrag,
            'crisisDeleveragingDrag' => -$crisisDeleveragingDrag,
        ];
    }

    /**
     * The capital half of the 2D Kaldor-Kalecki phase space, whose other half is the output gap
     * (OutputGap::calculateOutputGap()).
     *
     * Stepped BEFORE the gap on each tick, because the gap's own capitalDrag term reads the level this
     * leaves behind; running it after would drag the gap with an overhang one tick out of date.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public static function updateCapitalStockOverhang(MacroState $state, float $dt): void
    {
        // Kaldor (1940) capital stock accumulation and physical depreciation dynamics.
        $state->capitalStockOverhang += (($state->outputGap * self::CAPITAL_ACCUMULATION_RATE) - (self::CAPITAL_DECAY_RATE * $state->capitalStockOverhang)) * $dt;
        $state->capitalStockOverhang = max(self::CAPITAL_OVERHANG_MIN, min(self::CAPITAL_OVERHANG_MAX, $state->capitalStockOverhang));
    }

    /** A supply cost's relative distance from its own trend; none before the trend has opened. */
    private static function supplyCostShift(float $level, float $trend): float
    {
        return $trend > 0.0 ? ($level - $trend) / $trend : 0.0;
    }

    /**
     * The excess bond premium over its decade trend, plus the capital the banks must hold and have not yet built, read
     * as the premium it is worth: the credit-supply price the demand drag answers when positive.
     */
    public static function excessCreditPremium(MacroState $state): float
    {
        return $state->excessBondPremium - $state->excessBondPremiumTrend
            + (self::CAPITAL_SHORTFALL_PREMIUM_EQUIVALENT * CreditFiscalSubsystem::capitalShortfall($state));
    }

    /**
     * Household equity wealth as a share of income: the whole board's capitalisation over nominal GDP, the
     * ratio the MPC-out-of-wealth literature is estimated on.
     *
     * Carries an arbitrary scale, because capitalisation is currency and nominal GDP is an index. That is
     * deliberate and costs nothing: the effect reads this against its own trend, and a constant factor
     * divides straight out of that comparison. Zero means no market has been reported.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public static function equityWealthRatio(MacroState $state): float
    {
        // Piketty & Zucman (2014) equity market capitalization to nominal GDP valuation ratio.
        if ($state->equityMarketCapEma <= 0.0) {
            return 0.0;
        }

        return $state->equityMarketCapEma / max(0.01, $state->nominalGdpIndex);
    }
}
