# analyst_anchor evidence

## 2026-11-19: public-revenue roll of the analyst anchor (uncommitted fix)

Question: does rolling last quarter's consensus forward by publicRevenue_t / publicRevenue_{t-1} (instead of the
expectedRevenueBeforeShocks ratio, which omits benchmark prices) remove the long same-sign revenue surprise runs of
price-taker firms, without upsetting the rest of the board?

Harness: `AnalystAnchorHarnessTest.php`, arms base (key stripped each tick) vs fix, seeds 1-4, 20 years, 360
ticks/year, live macro, no politics. See RUN.md. Sanity: base cleared ~8,240 keys per seed, 0 left after the clear.

Table (`anchor_4s20y.out`), Δ = fix - base (se) t n:

    SINK SD(s) %   12.47  6.02  -6.45 (0.63) t -10.3 | ac1 0.626 -0.181 -0.81 (0.09) t -9.5
    CASC SD(s) %    9.73  2.74  -6.99 (0.74) t -9.4  | ac1 0.719 -0.082 -0.80 (0.06) t -13.5
    DIPR SD(s) %    6.77  2.91  -3.86 (0.31) t -12.3 | ac1 0.693 -0.199 -0.89 (0.02) t -36.9
    GANN SD(s) %    3.32  2.36  -0.96 (0.22) t -4.3  | ac1 0.590 -0.157 -0.75 (0.04) t -17.3
    ALBT SD(s) %    7.06  5.88  -1.18 (0.39) t -3.0  | ac1 0.401 -0.101 -0.50 (0.06) t -7.9
    BOWR SD(s) %    2.41  1.32  -1.09 (0.27) t -4.0  | ac1 0.581 -0.233 -0.81 (0.04) t -22.3
    CNDR SD(s) %    7.92  6.89  -1.03 (0.36) t -2.9  | ac1 0.409 -0.176 -0.59 (0.11) t -5.3
    FNCH SD(s) %    7.73  6.82  -0.92 (0.43) t -2.2  | ac1 0.194 -0.112 -0.31 (0.15) t -2.1
    WING SD(s) %    4.01  4.22  +0.21 (0.12) t 1.8   | ac1 0.347  0.208 -0.14 (0.23) t -0.6
    board median SD(s) %  2.10 1.90 -0.20 (0.07) t -3.1 | median ac1 0.205 -0.039 -0.24 (0.01) t -21.5
    EPS beat: board 0.773 0.780 +0.007 (0.001) t 4.8; SINK 0.720 0.737 (t 1.0); CASC 0.750 0.816 +0.066 (0.016) t 4.1
    guards: gap SD 1.85 1.85 pp (t -1.0); price ret SD q 13.71 13.88 % (t 0.6); deaths 0.25 0.25

Caveats: 4 seeds (stage stopped: every target past |t| 4 for SINK/CASC/DIPR and the board median ac1). Under fix
the autocorrelation turns negative for most firms (board median -0.04, price-takers -0.1 to -0.23): the anchor now
carries last quarter's own-news component, which reverses. Base reproduces the live SINK/CASC numbers closely.

## 2026-11-19 (2): market-revenue roll (STATE_LAST_MARKET_REVENUE, firm history zeroed)

Same harness, base now strips STATE_LAST_MARKET_REVENUE. Seeds 1-4, 20 y, 360 tpy. Table: `anchor_v2_4s20y.out`
(v1 runs kept in `runs_v1/`). Base reproduces the v1 base exactly (the extra physics run draws nothing from the
firm stream).

    fix ac1 (v1 -> v2): SINK -0.18 -> -0.08, CASC -0.08 -> +0.02, DIPR -0.20 -> -0.06, BOWR -0.23 -> -0.26,
    board median ac1 -0.039 -> +0.063 (base +0.205; v2 Δ -0.142 (0.003) t -46)
    SD(s): SINK 6.02 -> 5.90, CASC 2.74 -> 2.85, DIPR 2.91 -> 2.86; board median SD 1.90 -> 1.83 (base 2.10)
    beat: board 0.784 (base 0.773, t 11.7); CASC 0.822 (base 0.750)
    guards: gap SD 1.85/1.85, ret SD 13.71/13.60 (t -0.4), deaths 0.25/0.00 (t -1.0)
    secs per 20-y run: v1 tree 131 (both arms); v2 tree base 147, fix 143 (+~10%, both arms pay it: the call
    at EarningsEngine.php:720 is unconditional)
