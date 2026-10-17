# Joint sanity evidence

## 2026-10-17: main 64fd2a52 after six merges (macro step 2, corporate s1, earnings batch, price-vol budget, staples s1, valuation s3)

Question: does the whole simulation still behave sensibly? Default check, one arm.

Harness: `JointHarnessTest.php` via `run.sh live <seed>` (live macro, Diet on, real seeded board), seeds 1-4 x 20 years,
360 ticks/year. Table: `joint_4s20y.out` (split-adjusted). Prior numbers compared: rework_evidence.md step 1/2
(macro-only, 100y, TPY 48), price_vol_evidence.md "after the budget fix" (4 seeds x 10y live), valuation_evidence.md
(16 seeds x 15y replay), staples_evidence.md (4 x 20y replay).

Headlines (4-seed mean, sd across seeds):
- Macro: gap -1.01 (0.45)%, sd 2.22, ACF4 0.72; infl 2.05 / sd 0.85; policy 2.94 (0.25-7.0); 10y 3.97 (ELB 0.10%
  binds seed 3 years 16.5-17); u 5.3 mean, 7.9 max; recession 13.4% of quarters; debt/GDP 0.50 -> 0.53; hh 0.87;
  FX 88-124. All inside the earlier macro-only numbers' noise except infl sd (1.13 there; window length differs).
- Board: EW price return 8.2%/y, vol 16.2%; CW 5.9%/y, vol 15.2%; P/E 15.8 -> 14.1; ND/EBITDA 1.28; ratings stable
  (A ~52, BBB ~22, HY ~14); 2 liquidations + 3 Ch11 in 4 seeds (staples run: 0 and 0); rescue raises 2-21 per seed.
- Vol excess vs configured, 21-tick: median +0.010 (sd 0.011), mean +0.023 (seed 1 +0.054, DIPR).

Defects found:
- DIPR (Dipper Generation, IPP) seed 1: fair value decays to the 0.01 floor at years 16.9-17.3 while book is $52/sh
  and earnings are positive. Inputs: beta 0.60 -> 0.95, cost of equity 7.5 -> 10.0%, cost of debt = cost of equity
  9.95%, ROIC TTM 4.7% on invested capital $95/sh with net debt $37/sh, so equity value is EV minus debt ~0. Price
  then jumps 6.23 -> 126.02 in one tick (6241) with no event, crashes to 0.01 on report days, recovers x1.33/tick,
  and two 1:10 reverse splits follow. DIPR has 1-9 single-tick moves of >65% in every seed; Ch11 in seed 2.
- IBHI seed 1: price 155 -> 2.96 (year 4.4) with equity ~$97B, NI ~$3B/qtr, target $137: P/B 0.04 for ~2 years,
  then x135 to 399 by year 9.9. FALC and EIDR pinned the same way from year 4.3 in seed 1.

Caveats: 4 seeds, one arm, sanity level. Price-return index only. Draws do not pair with earlier runs.

## 2026-10-17 (follow-up): is the pinned-price pathology new? pre-merge d89691cc vs HEAD vs HEAD minus PR #48 / #50

Arms (seeds 1-4 x 20y, 360 tpy, POLITICS=1, live macro; draws do not pair, so counts per arm): `pre` (git archive
d89691cc), `live` (HEAD), `rev-48` (HEAD with `git diff 1a7e1e46^1 1a7e1e46 -- src config` reversed), `rev-50` (HEAD
with `git diff dae14db9 64fd2a52 -- src config` reversed). `path_an.py 1-4 pre live rev-48 rev-50` -> `path_revert.out`.

| arm | P/B<0.15 & trailing-4q NI>0 name-qtrs | names | unexplained >50% ticks | all >50% ticks | reverse splits | liq+Ch11 |
|---|---|---|---|---|---|---|
| pre | 2 | 2 | 0 | 1 | 1 | 3 |
| live | 41 | 8 | 0 | 9 | 3 | 5 |
| rev-48 | 11 | 2 | 0 | 5 | 4 | 7 |
| rev-50 | 0 | 0 | 0 | 0 | 0 | 1 |

Reading: new with the merges; reverting PR #50 (Valuation::sectorHurdleShift at MarketEngine.php:754) removes it on
every count, reverting PR #48 halves it. Every >50% tick lands on a tick with some StockEvent (~150 event ticks per
name per 20y), so "unexplained" is 0 in all arms. 4 seeds; counts are seed-concentrated (live: 29 of 41 in seed 1).

## Floor amplifiers fix (2026-10-17), worktree `.claude/worktrees/floor-amplifiers`, arm `wt` = `tree-wt`
Arms: `live` (main 64fd2a52) seeds 1-8, `wt` (tree-wt: worktree copy, vendor symlink) seeds 1-8; 20y, 360 tpy, POLITICS=1.
Unpaired (one stream feeds macro and firms). Output: `floor_fix_8s.out` (path_an + both health tables).
- Pinned profitable name-quarters (P/B<0.15, 4q NI>0): 42 -> 1; names 9 -> 1; >50% ticks 10 -> 2; reverse splits 3 -> 0.
- Board max log drawdown by seed, live: -0.95 -0.51 -0.64 -0.57 -0.62 -0.44 -0.82 -0.32; wt: -0.28 -0.32 -0.31 -0.30
  -0.81 -0.63 -0.37 -0.24. wt's two deep-drawdown seeds (5, 6) pin nothing.
- No health moment differs at |t|>=2 (largest: 10y max +0.41pp t 1.97, infl mean +0.08 t 1.77, gap ACF4 -0.12 t -1.5).
- FALC fails more in wt (liquidated s7 y4.4, Ch11 s5/s6) vs live (Ch11 s3, then a P/B 0.2 zombie earning $13-35B/q):
  wt s7 is an operating collapse (rev 81->53B, six loss quarters, cash 0, debt 130B, D). Failures 0.25/seed both arms.
Attribution note: the earlier rev-50 arm's "0 pinned" came with mild drawdowns (-0.30..-0.63); live seed 1 drew a
2.6-sigma market run (sum of sigma*sqrt(dt)*Z = -1.72 over 6y), which at a capped levered beta of 3-4.3 pinned IBHI,
FALC, EIDR. PR #50 was not shown to cause it.

## One-off charges as special items (2026-10-17), worktree `.claude/worktrees/special-charges`, arm `wt`
16 models (IB fine + counterparty default, tech fine, restaurant scandal onset, auto recall, mine/oil disaster,
refinery outage, waste clean-up, grid failure onset, security breach, billing clawback, conglomerate/investment
company/merchant house write-downs, construction overrun onset, defence forward-loss onset + flagship failure).
4 unpaired seeds per arm vs fresh main (807ede45). Outputs `sc_live_4s.out`, `sc_wt_4s.out`.
- No health moment |t| >= 2: gap sd 2.75 -> 1.47 (t -1.9), rescue raises 12.5 -> 7.0 (t -1.9), vol excess median
  .020 -> .002 (t -1.7); main seed 3 carried 8 of main's 10 failures/Ch11 (macro path, not paired).
- Probe: fair value falls 0.38-0.50 per $1 after-tax charge for operating firms (1.84 for the IB on tangible book);
  ~60% of that on report day. Cash is valued at P/E x after-tax yield, so a $1 charge moves price ~0.25 vs ~5x before.

## Surplus cash at face (2026-10-17), worktree `.claude/worktrees/cash-valuation`, arm `wt`
Excess cash (treasury over the model's target operating cash on its operating base; 0 for financials) valued at $1
beside the business; every leg struck on book less it; net debt for the revenue floor net of operating cash only.
8 unpaired seeds per arm, both with the user's uncommitted InitialMarket edit. Outputs `cv_live_8s.out`, `cv_wt_8s.out`.
- Unit probe: fair value falls exactly $1 per $1 of surplus paid out (was $0.38-0.50).
- No health moment |t| >= 2. Median P/E start 16.44 -> 16.85 (t 1.5), end 14.48 -> 16.69 (t 1.9).
- Buybacks 19.0T -> 19.8T/seed (sd 0.9-1.5T), dividends 13.7T -> 14.1T: management's headline-P/E tests unmoved.
- Pinned name-qtrs 8 -> 13, names 2 -> 6 (Poisson noise; the names hold 1-11% cash/equity, FALC and DIPR pinned in
  both arms). divgap_an.py's report-tick slope (-0.03 vs -0.06) does not catch the ex-dividend drop: not a valid test.
