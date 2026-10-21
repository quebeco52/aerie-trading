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

## Politics fixes (2026-10-17), on main: one-sided committee camps, public spending priced on purchases
Arms `base` (git archive HEAD 1547e3d1, deleted after) vs `live` (working copy), 4 unpaired seeds. `pol_base_4s.out`,
`pol_live_4s.out`. Nothing |t| >= 1: policy mean 3.13 -> 2.99, inflation 2.12 -> 2.08, gap mean -0.82 -> -1.00,
debt end .54 -> .55, index and P/E flat. Pinned name-qtrs 7 -> 1.

## One-sided model terms read both ways (10-17, on main)
4 unpaired seeds, base = git archive HEAD (feb149e0; reproduces pol_live_4s exactly), live = checkout. `CREDIT=1`.
Out: onesided-base.out, onesided-live.out; per model: `model_an.py 1-4 base live`.
- Measured drivers: SLOOS ema mean +0.00..+0.07 (sd 0.06-0.13), so two-sided around 0 (the review's +0.14 predates
  the 10-16 shortfall fix); infl_ema − bei mean −0.08..+0.22pp, so escalators on the surprise carry no standing bonus
  (old max(0, π − 2%) paid 0.25-0.62pp); ERP mean 4.6-4.9% against BASE 4.5%, so the hypercar line reads equity
  wealth against its trend instead.
- Standing beat (rev/exp − 1, base → live): LawFirm +8.7 → −0.2% (t −2.3), Waste +0.9 → −0.2 (t −2.1), Defense
  +5.6 → −0.1, Heavy +0.4 → −0.1, CreditServices −1.7 → −1.2, ShadowBank +5.6 → +4.9. Unaffected models move as
  much (Mining −11.7, Custody −3.5): paths diverge from the first quarter.
- Board: nothing outside the range earlier code drew on seeds 1-4. FX min 79.9 → 87.6 looks systematic (+6..+9 on
  every seed) but base's seeds sit at the low end of history (80.8-89.2 across pol/cv/live runs). Failures
  0.25/seed both; payment defaults 0.75 → 1.5 (history 0.6-2.0); rescue raises 12.2 → 3.

## Batch A: payout cap, divestiture cash, coupon roll, arrears, bond convexity (10-17, on main)
Unpaired seeds 1-8, base = git archive HEAD (21bc0997), live = checkout. Out: batchA-{base,live}-8s.out.
- Distress: Ch11 0.63 -> 1.0/seed (5 vs 8 events, ~0.8 SE); payment defaults 1.0 -> 1.38; failures 0.125 -> 0.25
  (1 vs 2). Seeds 1-4 alone read 0.75 -> 1.75; an arm with the arrears carry disabled (wt-noarrears, seeds 1-4) gave
  the same 1.75 and identical paths in seeds 2-4, so the carry rarely binds and the 1-4 rise was path noise.
- EW index vol 21t 15.7 -> 16.8% (live higher in 7/8, unpaired t~1.9), inside the 14-19% earlier code drew.
  Inflation sd, unemployment, gap mean, P/E end, beat share: overlapping (gap -0.57 vs -0.52, infl sd 0.97 vs 1.08).

## Batch B: REIT ladder, vacancy, published prices, CU, Defence, Auto captive, primaryShockZ (10-17, on main)
Unpaired seeds 1-8, base = git archive HEAD (b487382b), live = checkout, `CREDIT=1`. Out: batchB-{base,live}-8s.out
(and -1-4 .out); per model `model_an.py 1-4 base live`.
- Board: nothing |t| >= 2 but unemployment max 6.1 -> 7.2 (t 2.4). CW index 7.66 -> 6.28 %/y (t -2.0), EW 9.20 ->
  8.05 (t -1.7), P/E end 17.2 -> 14.1 (t -1.9), gap mean +0.08 -> -0.30. Live sits on batchA-live-8s (CW 5.98, EW
  7.85, P/E 14.7, gap -0.52); base is the outlier at the benign top of every earlier run (CW 5.5-6.8, P/E 13.7-16.3).
- FALC Ch11 0/8 -> 2/8; earlier arms 1/4 (pre), 2/4 (rev-48), 3/8 (wt), 2/4 (wt-noarrears): base is the outlier.
- Per model (1-4): REIT revenue over expected -2.0pp (t -1.3), the macro default vacancy moving from cost to rent
  (~-1.1pp, EBIT-neutral) plus the roll's level; commodity beats move +4..+9pp both ways with t~1 (price paths;
  the change only touched what analysts see).

## Macro batch: default-rate trends, FCI centred, CRE trend, two-sided starts, freight, QE coupon, mainland FX (10-17)
Unpaired seeds 1-4, base = git archive HEAD (1e02187c; reproduces batchB-live 1-4 exactly), live = checkout,
`CREDIT=1` (now also corp_dr, retail_dr, starts, fci, cre, freight). Out: macroD-{base,live}.out.
- Board: EW index 7.32 -> 8.58 %/y (t 3.0), CW 5.60 -> 6.71 (t 2.5), P/E end 12.3 -> 14.9 (t 2.6). Not the FCI
  tilt alone: the passive book moves 0.15 per unit FCI, so base's +0.09 mean (live +0.03) is ~1.4% of it. Unpaired
  paths; base sits at the low end of earlier runs (EW 7.2-8.5, CW 5.5-6.8, P/E 13.7-16.3) and live inside them.
- Macro: gap -0.41 -> -0.65, unemp 4.73 -> 4.99, policy 3.46 -> 3.15 (all |t| < 1). Default EMA means corp 1.53 ->
  1.60%, retail 2.31 -> 2.51% (seed sd ~0.4pp); CRE mean 98.8 -> 104.6 (seed range 95-113); starts 98.9 -> 99.7;
  freight 102.3 -> 100.2.
- Distress: payment defaults 0.25 -> 1.5/seed (t 1.8; history 0.6-2.0), Ch11 0.25 -> 0.75, failures 0.5 -> 0.25.

## Market layer C: option beta and market jump, dealer inventory, bond stamp, daily impact window, slicing (10-17)
The harness has no option desk, dealer or player fills; it exercises only the day-window impact reading.
- Seed 9, 2 years at TPY=3600 (the live rate, ~14 ticks a trading day), TRACE of 8 names (`iv`), year 0.5-2: measured
  impact vol sqrt(mean iv) 0.23-1.18% (base, per tick) -> 0.65-2.53% (live, day window), x2.2-2.8 (variance x5-8).
  Still under 1% of each name's variance (CASC 0.37% vs 0.05%), so the diffusion's give-back barely moves.
- Board, unpaired seeds 1-4, 5 years at TPY=3600 (marketC-{base,live}-tpy3600.out): vol excess vs configured mean
  -0.9 -> +1.3pp (t 0.9), EW vol 14.1 -> 15.0 (t 0.5); paths diverge (gap means -2.4..+1.8%), nothing |t| >= 2.

## Cleanup D: Construction rate stance, bank recession trend, volume shock, policy news in budget (10-17)
4 seeds live only, `CREDIT=1` with the new `recp` field (out: cleanupD-live.out).
- Recession probability, years 2-20: 12.3% pooled (seed means 8.8, 14.1, 16.0, 10.2%; within-run sd 17pp), so the
  13.8% the probit intercept was fitted to is inside the noise (t ~ -1); not refitted. FCI seed means -0.07..+0.16.
- Board inside earlier runs: EW 7.4%/y, CW 5.8, P/E end 13.7, gap -0.72, vol excess vs configured +1.15pp.

## Trade cycle and leases by stream; seeding after mocks (10-17)
Unpaired seeds 1-8, base = git archive HEAD (193ed81f; seeds 1-4 reproduce cleanupD-live), live = checkout. Out:
wcLease-{base,live}-8s.out, wcLease-firms-8s.out (`wc_an.py`; balances as % of annual revenue, mean over reports).
- Balances base -> live: receivables 0 -> 4.6-14.7% on all twelve negative-cycle firms (CROP 2.3 -> 6.5); inventory
  0 -> WEAV 3.4, BREW 1.0, LARK 9.0, PHIL 8.6, SGRB 14.4, PINT 11.9 (CROP 1.7 -> 10.7); JDAW, HUMM, TICK, SHRK stay 0.
- Leases: WEAV 36.2 -> 14.2, JDAW 36.5 -> 6.2, SHER 30.6 -> 12.8, staples 30 -> 5.0; HUMM, TICK, SHRK, BREW, SWFT flat.
- Credit: the freed lease room is borrowed as bonds: ND/EBITDA LARK 0.61 -> 0.99, PINT 1.02 -> 1.51, CROP 0.88 ->
  1.91; end rating CROP A -> BBB/BBB+ (+2.25 notches, SE 0.49), PINT +1.1 (0.97), PHIL +0.75 (0.75). None of the 13 fails.
- Write-downs now reach stock holders: BREW 2.2 bp of revenue, SGRB 1.2, LARK/PHIL/PINT 0.3-0.5; receivable
  provisions 0.2-1.0 bp.
- Board and macro, 8 seeds: nothing |t| >= 1.4. Gap -0.93 -> -0.86 (t 0.15; at 4 seeds it read -0.72 -> -1.52,
  t -1.4, seed noise), recession share 11.1 -> 13.6% (t 0.6), EW 8.05 -> 7.99%/y, payment defaults 1.0 -> 1.6 a seed
  (t 1.1; none among the 13), median ND/EBITDA 1.33 -> 1.44 (t 1.3).

## Receivables on the spread trend, maturing stock, EPS walk-down by measured surprise (10-17)
Unpaired seeds 1-8, base = git archive HEAD (4177a893; reproduces wcLease-live), live = checkout. Out:
items15-{base,live}-8s.out, beat-{base,live}-8s.out (`beat_an.py`), wc_an.py for PINT.
- EPS beat share, board 71.0 -> 74.7%. By model, base ran 50-100% with the flat 7.7%-of-EPS cut (ClearingHouse 99.8,
  FinancialData 94.3, Tech 90.5, Staples 89.3; Steel 50.8, Defence 49.5, Auto 51.5); live 66-81% except ClearingHouse
  95.2 and Telecom 56.5 (price-war misses persist). Board median surprise sd 2.52% of revenue sets the prior.
- PINT inventory 11.9 -> 43.8% of revenue (maturing 0.55 of branded sales), write-downs 0.44 -> 0.31 bp of revenue;
  ND/EBITDA 1.51 -> 1.63, end rating +0.75 notch (SE 0.75).
- Board: nothing |t| >= 2. Gap -0.86 -> -0.63, recession share 13.6 -> 8.4% (t -1.3), rescue equity raises 11.4 ->
  4.9 a seed (t -1.9; the same quantity read 7.5 -> 11.4 one batch earlier), payment defaults 1.6 -> 0.9 (t -1.3).

## Per-model fundamentals moments on HEAD f76d4750 (2026-10-20)
Question: how does each business model's quarterly earnings behave on the live board (feeds the earnings-engine and
sector-model review). Sanity level, one arm.
- Run: `base` arm = `git archive HEAD` in `tree-base` + vendor symlink (the checkout had an uncommitted
  FinancialRegulator/Office edit, so `live` would not have been HEAD); deleted after. Seeds 1-4 x 20 y, 360 tpy,
  POLITICS=1 HEALTH=1. `bin/harness-batch var/harness/joint/run.sh 1-4 base` (146 s wall), then
  `python3 var/harness/joint/model_moments.py 1-4 base > var/harness/joint/models-head-4s.out`. Needs
  `runs/ticker_models.json` and `runs/ticker_fin.json` (RUN.md).
- Table: `models-head-4s.out` (89 names, 56 models, 80 quarters a firm-seed). Nominal GDP 4.79%/y; real GDP y/y mean
  2.7%, sd 1.5-2.1% by seed, worst -0.5 to -2.0%: mild cycles, so revenue betas rest on little GDP variance.
- Flags: Steel beta 7.3 (se 0.5), Semis 5.6 (0.3) above ~2-4; SpecialtyIndustrialMachinery 0.84 (0.10) below;
  HealthInsurer 0.82 (0.38) above the defensive band; Utility margin sd 4.4pp (> 2pp). EPS beat share above 80%:
  ClearingHouse 96, CommunicationEquipment 89, DistressedDebt 83, Reit 83, ConsumerStaples 81, HealthInsurer 82,
  ShadowBank 82, PrivateEquity 81, Tech 80; below 55%: DefenseContractor 49 (rev surprise median -4.9%), Telecom 50
  (-2.7%). No ROIC/ROE, ND/EBITDA, growth or NaN flags. Board EPS beat 74.1%, revenue beat 48.2%.
- Distress: 0 liquidations, 0 Ch11, 1 payment default (SINK, OilGasProducer, seed 1), 17 rescue raises (OilGas 13,
  Mining 3, Shipping 1). Seasonal amplitude large in InternetRetail 88%, Luxury 72%, Restaurant 59% (range of mean
  q/q log change by calendar quarter). TIER (PrivateEquity) price x0.01 in seed 2 while equity rose ~5x.
- Caveats: 4 seeds; most models 1 ticker (nF 4); macro path differs by seed; beta SE clustered by firm-seed.

## Earnings-model batch A sanity, baseA (a202316c) vs fixA (a202316c + batchA.patch) (2026-10-20)
Question: does batch A (capacity drift out, sector demand factor as a level, Steel/Chemical/Railroad demand terms,
7 inflation-surprise revenue terms and 9 demand-draw margin terms deleted, cat claims on the incurred flow, AM/HF/PE
equity sleeve at 10y+ERP) keep the board sane and move the flagged per-model moments. Sanity level, 4 unpaired seeds.
- Arms: `baseA` = `git archive a202316c` in `tree-baseA`; `fixA` = same + `git apply --directory=var/harness/joint/tree-fixA
  batchA.patch` + new-files.tar; vendor symlinks; fresh caches; all deleted after. Seeds 1-4 x 20 y, 360 tpy, POLITICS=1
  HEALTH=1. `bin/harness-batch var/harness/joint/run.sh 1-4 baseA fixA` (177 s wall), then model_moments.py, analyse.py,
  beat_an.py, `ema_an.py 1-4 baseA fixA` (MACRO_OUT now carries `scp`, `vol_ema`), `ins_margin.py 1-4 baseA fixA`.
- Tables: models-batchA-{base,fix}-4s.out, analyse-batchA-{base,fix}-4s.out, ema-batchA-4s.out, ins-batchA-4s.out.
- Board: nominal GDP 4.86 -> 5.16%/y; gap mean 0.02 (sd 0.67) -> 0.79 (sd 0.10), t~2.2 unpaired, inside the -1.8..0.7
  range of past seed means; infl sd 0.93 -> 1.25. Failures 1/1 (SHOR both), Ch11 0 -> 1 (SKUA s4 y3.6), payment
  defaults 2 -> 2, rescues 14 -> 13. No NaN, no anomalies. FALC x135 (s2 fixA).
- Revenue beta to real GDP base -> fix: Steel 7.99 -> 0.91 (se 1.04), Chemical 3.60 -> 3.41, Railroad 1.03 -> 0.92,
  HeavyMfg 5.34 -> 4.55 (control, shares the demand-factor change), Semis 5.96 -> 4.89, SIM 0.95 -> 1.11.
- EPS beat: board 76.5 -> 78.1% (HEAD f76d4750 74.1). CommEquip 84 -> 96, ClearingHouse 94 -> 97, Defense 59 -> 73
  (rev surprise median -2.9 -> +7.8%), Telecom 80 -> 72, Tech 72 -> 78, Reit 80 -> 82, HealthIns 73 -> 77, Rest 80 -> 78.
- Insurers: margin sd RetailIns 5.05 -> 10.86pp, Reins 4.35 -> 10.56pp; p10/p90 -2/9 -> -9/21 (RetailIns); loss
  quarters 1.9 -> 9.4% and 2.8 -> 6.2%; worst Reins quarter -82% (s1 t0.82). Revenue beta -0.2/+0.9 -> -1.9/-1.8.
- Caveats: arms do not pair (macro paths differ by seed after the patch); 4 seeds; most models nF 4.

## Earnings-model batch A+B sanity, baseA (a202316c) vs fixAB (a202316c + batchAB.patch) (2026-10-20)
Question: does batch B plus the three A corrections (Steel PMI accelerator back at 0.25, priced and capped cat XoL
cover, two-sided GSCPI/VIX/unemployment/cat terms, mean-centred LawFirm/HedgeFund/ClearingHouse terms) keep the board
sane, and does fixA's +0.79% gap mean persist. Indicative: 8 unpaired seeds per arm.
- Arms: `baseA` seeds 1-4 reused, 5-8 new on a rebuilt `git archive a202316c` tree; `fixAB` = same archive +
  `git apply --directory=var/harness/joint/tree-fixAB batchAB.patch` (from the repo root: `git -C <tree> apply` inside
  the checkout silently applies nothing) + new-files.tar. Vendor symlinks, fresh caches, trees deleted after.
  20 y, 360 tpy, POLITICS=1 HEALTH=1. `bin/harness-batch var/harness/joint/run.sh 1-8 fixAB` (173 s), `... 5-8 baseA` (147 s).
- Tables: models-batchAB-{base,fix}-8s.out, analyse-batchAB-{base,fix}-8s.out, beat-batchAB-{base,fix}-8s.out,
  ema-batchAB-8s.out, ins-batchAB-8s.out.
- Board base -> fix: nominal GDP 4.69 (0.54) -> 4.45 (0.45)%/y; gap mean -0.07 (0.70) -> -0.14 (0.59), so fixA's +0.79
  on 4 seeds does not persist; recession quarters 6.6 (5.7) -> 11.1 (6.8)%; failures 2 -> 0, Ch11 2 -> 2, payment
  defaults 5 -> 6, rescues 34 -> 32 (sums over 8 seeds). No anomalies, no NaN. Extremes: FALC/SKUA/CNDR x20-58 both arms.
- Revenue beta: Steel 8.54 -> 2.10 (se 0.51), Chemical 4.45 -> 3.20, Railroad 0.87 -> 1.23, HeavyMfg 5.17 -> 4.96,
  Semis 6.16 -> 5.96, SIM 1.01 -> 1.14. ClearingHouse -0.29 -> -1.68 (se 0.60).
- EPS beat: board 75.9 -> 75.8; CommEquip 87.2 -> 74.8, ClearingHouse 92.6 -> 88.3, Defense 64.6 -> 64.6 (rev surprise
  median -0.22 -> -0.28%), Reit 80.5 -> 82.2, Telecom 72.0 -> 69.8, HedgeFund 81.9 -> 77.0, LawFirm 78.6 -> 79.4, HealthIns
  75.2 -> 72.7, Tech 76.3 -> 77.3, Logistics 75.8 -> 74.5, Security 77.0 -> 77.0. LawFirm rev surprise sd 19.8 -> 17.6.
- Insurers base -> fix: RetailIns margin 4.9/4.5sd -> 4.7/10.1sd, p10/p90 -1.5/10.2 -> -9.0/17.4, loss qtrs 1.4 -> 10.0%,
  ROE median 15.1 -> 14.7 (p10/p90 9.6/20.5 -> 7.9/24.4), worst -21 -> -29%. Reins 4.9/3.8 -> 4.6/8.5, p10/p90 -0.3/9.8
  -> -4.4/12.1, loss 2.2 -> 6.2%, ROE 12.7 -> 12.1, worst -24 -> -38% (fixA worst was -82%).
- scp mean -0.035 -> -0.026 (sd 0.55 both); vol_ema 0.160 -> 0.154 (sd 0.036 -> 0.034).
- Not measured: cover attachment frequency (the harness keeps only treasury lines from StockEvent descriptions),
  Telecom subscriber_index and CommEquip book_to_bill (report rows carry only hedge/capture/power KPIs).

## Batch C (analyst consensus from the public-quarter replay): baseC (29782cb5) vs fixC (+ batchC.patch) (2026-10-20)
Question: do per-model EPS beat shares converge to ~75%, revenue surprise medians to the walk-down, surprise sd rise,
report-day moves grow, revenue beta stay put, and what does the replay cost. Unpaired (paths diverge at the first
report tick, tick 20: conditional draws key on consensus), 8 seeds x 20 y, 360 tpy, POLITICS=1 HEALTH=1.
- Run: `bin/harness-batch var/harness/joint/run.sh 1-8 baseC fixC`, then `c_an.py 1-8 baseC fixC`, analyse.py,
  model_moments.py per arm. Disclosure: `bin/harness-batch var/harness/disclosure/run.sh 1-4 baseC fixC` (30 y).
  JointHarnessTest gained `crev` mid-batch; baseC was rerun so both arms carry it. Trees and caches deleted.
- Tables: c-batchC-8s.out, analyse-batchC-{base,fix}-8s.out, models-batchC-{base,fix}-8s.out, beta-batchC-8s.out,
  c-timing-batch1.out, ../disclosure/batchC-4s.out.
- Board base -> fix: EPS beat 75.8 -> 75.7, rev beat (vs crev) 67.1 -> 69.8; CW return 6.73 -> 6.76, CW vol 15.2 ->
  15.7, failures 0 -> 2 (both SHOR, a REIT liquidated in earlier arms too), Ch11 2 -> 2, rescues 32 -> 40 (sums). No anomalies.
- Beat share base -> fix (se): ClearingHouse 88.3 (1.7) -> 80.4 (1.3), DistressedDebt 86.2 (2.0) -> 74.8 (0.7),
  Defense 64.6 (6.8) -> 77.0 (1.3), Telecom 69.8 (5.1) -> 79.3 (0.7), Reit 82.2 -> 78.7, MedicalCare 65.6 -> 79.4,
  Brokerage 66.1 -> 72.0. None outside 60-85 under fix; max PE 82.2, Reinsurance 82.9.
- Surprise sd (rev/crev) fell for most models (Defense 15.5 -> 2.6, CommEquip 7.1 -> 3.3, Telecom 3.5 -> 1.8, CommBank
  3.5 -> 1.9); rose for IndependentPower 6.5 -> 7.9, Refining 8.1 -> 9.5, OilGas 10.9 -> 11.8, Reinsurance 3.2 -> 3.6.
  Medians converge on +1.4..+1.7% (actual above walked-down consensus); outliers PE +8.2, HedgeFund +0.9, OilGas +0.9.
- Revenue beta: 5 of 56 models |z| > 2 (CompHW 3.45 -> 1.98, z -4.1; RetailIns 0.50 -> -1.33), inside arm-to-arm
  scatter (CompHW was 2.55 vs 3.45 across batchAB arms with no revenue change).
- Report moves: joint 2-tick window sd 3.17 (0.05) -> 3.36 (0.11)%, announcement share 6.54 (0.12) -> 6.58 (0.16)%;
  disclosure report-day sd 2.87 (0.23) -> 2.62 (0.10)%. No growth beyond noise.
- Wall: 142 vs 138 s/run (sd 11); first batch side by side 144 vs 138. No measurable cost.
- Disclosure harness records 0 warnings in both arms: its warning capture is stale since the 10-19 rework.

## 2026-10-20 Batch D1 (banks and brokers)
Question: does D1 (lender funding urgency at the deploy target, Vasicek PD-multiple losses on the gross book, opening
loan spreads, PLVR/POOL repricing tenors, ROOK yield net of interest income) behave sensibly and hit its stated targets?
Harness: JointHarnessTest.php (rows + nco/prov/allow/ii/ttc), `bin/harness-batch var/harness/joint/run.sh 1-8 baseD1 fixD1`,
then `lender_an.py 1-8 baseD1 fixD1`, `lender_open.py`, `analyse.py`, `c_an.py`. 8 seeds x 20y, TPY 360, unpaired.
Outputs: lenders-batchD1-8s.out, lenders-open-batchD1-8s.out, analyse-batchD1-{base,fix}-8s.out, c-batchD1-8s.out.
- Board: EPS beat 75.7 -> 76.2%, CW index 6.8 -> 7.2 %/y, vol 15.7 -> 15.5%, failures 0.25 -> 0, Ch11 0.25 -> 0.125,
  rescues 5.0 -> 4.9 per run, wall 143 -> 139 s/run. Macro gap sd 1.89 (0.54 sd) -> 1.43 (0.29), inside seed noise.
- LAKE wholesale y20 4049 (532) -> 464 (109) bn; ROE run mean 0.160 -> 0.194 (target 0.21). ACC wholesale flat 8 bn both.
- EGRT ROE yr1 0.109 -> 0.125, run 0.108 -> 0.135 (target 0.13); BNTH yr1 0.132 -> 0.202, run 0.126 -> 0.196 (0.18).
- NCO / (TTC x gross) 0.90-0.97 in both arms for every lender (TALN 0.99 -> 0.94, STRK 0.97 -> 0.95).
- ROOK rev/q yr1 3.23 -> 1.39 bn (-57%), interest income 0.83 -> 0.80; ROE 0.276 -> 0.189; corr(ROE, policy) 0.42 -> 0.53;
  equity CAGR 7.9 -> 3.0%.
- Both arms: ROE fades from year 1 to the run mean for TALN/STRK (0.26 -> 0.20), POOL (0.29 -> 0.21), PLVR (0.14 -> 0.12).
Caveat: unpaired arms (macro paths diverge), se over 8 seeds.

## 2026-10-20 Batch D2 (insurers and equity sleeves)
Question: effect of batchD2.patch (earned-rate lag, cat terms on volume, own-layer cover, ILS principal index, life
crediting, DOVE/SAFE reseeds, equity sleeves at the board's realised return). Arms: baseD2 = tree-baseD2 (git archive
8e4c1454), fixD2 = same + batchD2.patch. JointHarnessTest (rows now carry hm, erl, ilsp, catb, lr, xr, evt, cdep,
sl_pi, sl_v, sl_er, tax, bpi, btri), seeds 1-8 x 20 y, TPY 360, unpaired (mean (se) over seeds).
`bin/harness-batch var/harness/joint/run.sh 1-8 baseD2 fixD2`, then analyse.py, c_an.py, lender_an.py, ins_an.py.
Outputs: analyse-batchD2-{base,fix}-8s.out, c-batchD2-8s.out, lenders-batchD2-8s.out, ins-batchD2-8s.out.

Board: CW return 7.2 (1.0) | 6.7 (0.7) %/y, vol 15.5 | 15.9, EPS beat 76.2 | 75.9%, failures 0 | 0, Ch11 1/8 | 1/8
(DIPR | CASC), payment default 1 | 4 name-runs, wall 131 | 139 s/run. Fix seed 8: TIER defaults (D/E 10.8, price ~0).

| quantity | base | fix |
|---|---|---|
| DOVE CR mean / sd | 0.934 / 0.106 | 0.955 / 0.086 |
| DOVE CR onset q1..q4 | 1.22 0.82 0.78 0.80 | 1.18 0.91 0.89 0.89 |
| DOVE hard onsets, attachments /dec | 1.27, 1.71 | 1.20, 1.52 |
| DOVE ROE (target) | 0.153 (0.15) | 0.101 (0.165) |
| DOVE float y0/5/10/20 bn | 274/292/352/575 | 650/665/701/993 |
| DOVE float / annual rev y0 -> y20 | 2.25 -> 1.89 | 5.28 -> 3.60 |
| SAFE CR onset q1..q4 | 1.40 0.77 0.78 0.78 | 1.20 0.87 0.83 0.83 |
| SAFE hard onsets, attachments /dec | 0.63, 0.70 | 1.27, 1.52 |
| SAFE ROE (target) | 0.126 (0.14) | 0.113 (0.125) |
| SAFE float y0/5/10/20 bn | 1344/1201/1449/2288 | 1002/1131/1402/2241 |
| SAFE ILS rev q+0,1,2,6 / pre-breach | 0.51 1.19 1.24 1.25 | 0.82 0.93 1.01 1.10 |
| TIER ROE, equity CAGR | 0.090, 0.075 | 0.059, 0.039 |

Caveats: CR = loss_ratio + expense_ratio KPIs; onset q1 is the shock quarter. Base sleeve value is 0 for the funds
(resolveEquitySleeve not on their HEAD classes). LAKE wholesale y20 heavy-tailed in both arms (192..3189 bn).

## 2026-10-20 Batch D2b (insurers re-run: growing-book reserve runoff, DOVE/SAFE re-anchored)
Question: does the revised batchD2.patch (runoff 1/tau = incurred/R - g, DOVE ROE 13.5%, SAFE ILS 3% of revenue,
ROE 17.5%, float 2.97x) fix DOVE's ROE and float decay and TIER's default? Arm fixD2b = tree-fixD2b (git archive
8e4c1454 + /tmp/claude-1000/c/batchD2.patch), seeds 1-8 x 20 y, TPY 360; baseD2/fixD2 runs reused. Unpaired.
`bin/harness-batch var/harness/joint/run.sh 1-8 fixD2b`, then analyse.py, ins_an.py (now N arms), ins_path.py,
lender_an.py, c_an.py. Outputs: analyse-batchD2b-fix-8s.out, ins-batchD2b-8s.out, inspath-batchD2b-8s.out,
lenders-batchD2b-8s.out, c-batchD2b-8s.out.

| quantity | base | old fix | new fix |
|---|---|---|---|
| board EPS beat, CW ret/vol | 76.2, 7.2/15.5 | 75.9, 6.7/15.9 | 75.8, 6.6/15.9 |
| Ch11 / liquidations / rescue raises per run | 0.13/0/4.9 | 0.13/0/4.1 | 0.25/0.13/7.9 |
| DOVE ROE (target) | 0.153 (0.15) | 0.101 (0.165) | 0.085 (0.135) |
| DOVE ROE y2/5/10/20 | .212/.131/.151/.106 | .135/.108/.068/.075 | .132/.093/.061/.091 |
| DOVE float/rev y0/5/10/20 | 2.21/1.97/1.88/1.89 | 5.26/5.07/4.61/3.60 | 5.26/5.93/6.87/5.86 |
| DOVE CR mean, onset q1..q4 | .934, 1.22 .82 .78 .80 | .955, 1.18 .91 .89 .89 | .974, 1.24 .92 .95 .89 |
| DOVE underwriting rev CAGR vs nGDP | 4.8 vs 4.9 | 4.3 vs 4.8 | 3.3 vs 4.6 %/y |
| SAFE ROE (target), y2/5/10/20 | 0.126 (0.14) | 0.113 (0.125) | 0.135 (0.175), .168/.142/.115/.121 |
| SAFE float/rev y0/5/10/20 | 2.30/1.76/1.70/1.71 | 1.83/1.88/1.76/1.65 | 3.01/3.14/3.28/2.99 |
| SAFE margin sd, loss q share | .094, .061 | .097, .080 | .133, .105 |
| SAFE ILS share, q+0/1/4/6 after breach | .391, .51/1.19/1.27/1.25 | .380, .82/.93/1.06/1.10 | .029, .85/.94/1.21/1.22 |
| TIER ROE, eq CAGR, defaults | .090, .075, 0/8 | .059, .039, 1/8 (s8) | .059, .033, 1/8 (s4, liquidated y18.3) |

TIER s4 (fixD2b): hurdle misses from y12.8 halve revenue, sleeve sold (2.3 bn -> 0), revolver drawn 0 -> 36 bn, full
goodwill write-off 14.8 bn at y14.1, icr < 1 from y13, D at y15.6 doubles interest, equity to 0.4 bn. Debt 36 -> 145 bn
on 24 -> 60 bn equity; base s4 reached 252 bn debt on 88 bn. TIER is fragile in every arm (icr ~1, ND/E 8-15 by y15).
Caveats: DOVE float/rev overshoot follows its premium stalling y5-y10 (rev ex-ii flat), not the runoff formula, which
checks out (R/P = (I/P)/(1/tau + g)). Seeds do not pair across trees.

## 2026-10-20 Batch D2c (insurers: life reserves in bonds, solvency-based lockout, NAIC ceiling with sleeve gains)
Question: does the third batchD2.patch fix DOVE/SAFE overcapitalisation (fixD2b: DOVE equity +35% in 2y on flat
premium, ROE .085 vs .135; SAFE +22%, ROE fading .17 -> .12)? Arm fixD2c = tree-fixD2c (git archive 8e4c1454 +
/tmp/claude-1000/c/batchD2.patch), seeds 1-8 x 20 y, TPY 360; baseD2/fixD2b reused. Unpaired (trees do not pair).
`bin/harness-batch var/harness/joint/run.sh 1-8 fixD2c`, then analyse.py, ins_an.py, ins_path.py, ins_bridge.py (new),
lender_an.py, c_an.py. Outputs: analyse-batchD2c-fix-8s.out, ins-/inspath-/insbridge-/lenders-/c-batchD2c-8s.out.

| quantity | base | fixD2b | fixD2c |
|---|---|---|---|
| board EPS beat, CW ret/vol | 76.2, 7.2/15.5 | 75.8, 6.6/15.9 | 75.8, 6.5/16.1 |
| Ch11 / liquidations / rescue raises per run | 0.13/0/4.9 | 0.25/0.13/7.9 | 0.50/0.38/6.3 (CASC x3, SHOR, WADE, DIPR, FALC) |
| DOVE ROE (target) | .153 (.15) | .085 (.135) | .149 (.14) |
| DOVE ROE y2/5/10/20 | .212/.131/.151/.106 | .132/.093/.061/.091 | .171/.156/.135/.153 |
| DOVE eq vs prem growth y0-1->y2-3 | .19 vs .20 | .35 vs .06 | .11 vs .07 |
| DOVE eq vs prem CAGR y0-20 | 5.3 vs 4.9 | 5.4 vs 3.2 | 4.8 vs 4.4 %/y |
| DOVE payout/NI by 5y block | .77/.89/.80/.83 | .69/1.17/1.18/1.18 | .83/.95/.89/.92 |
| DOVE erl y5/10/20 | n/a | .997/.898/.952 | 1.018/1.004/1.008 |
| DOVE float/rev y0/5/10/20 | 2.21/1.97/1.88/1.89 | 5.26/5.93/6.87/5.86 | 5.28/5.60/5.49/5.39 |
| DOVE sleeve/eq mean | 0 | .56 | .26 |
| DOVE CR mean, onset q1..q4 | .934, 1.22 .82 .78 .80 | .974, 1.24 .92 .95 .89 | .944, 1.18 .92 .89 .88 |
| DOVE margin sd, loss q share | .106, .084 | .099, .138 | .084, .077 |
| SAFE ROE (target), y2/5/10/20 | .126, .136/.131/.129/.098 | .135 (.175), .168/.142/.115/.121 | .148 (.175), .206/.161/.139/.143 |
| SAFE eq vs prem growth y0-1->y2-3 | .09 vs .07 | .22 vs .02 | .13 vs .07 |
| SAFE payout by block | .81/.85/.79/.84 | .89/.95/1.03/.90 | .86/1.03/.98/.92 |
| SAFE onsets, attachments /decade | .63, .70 | 1.39, 1.96 | 1.20, 1.58 |
| TIER defaults | 0/8 | 1/8 (s4 liquidated) | 0/8 |

Bridge: per 5y block the residual (dEq - (NI - payouts)) is .14-.38 of opening equity for DOVE, larger than retained
NI (.07-.14). Sleeve mark .05-.12; issuance ~.02; the rest +.04-.25 per block (se .09-.17) with corr(rest_q, d y10)
-0.57, so a bond AOCI mark quarter to quarter, but y10 is flat over the runs (4.8% -> 4.7%) and base shows the same
+.08-.11 rest per block with no sleeve: a persistent positive drift outside NI that predates the patch.
Caveats: unpaired; the extra failures are non-insurers (refiner CASC x3), not touched by the patch beyond shared draws.

## 2026-10-20 capE (insurer stock comp at industry rates, extraordinary capital-target repurchase)
Question: does capE.patch (stock comp 0.41/0.44/0.23% of revenue for specialty/composite/reinsurer; capitalSurplusReturn
may pass the NAIC ordinary-distribution room) bring SAFE to its .175 ROE without hurting DOVE (.14)? Arm capE =
git archive 2f618cbc + /tmp/claude-1000/c/capE.patch; fixD2c is the HEAD arm, baseD2 reused. Seeds 1-8 x 20 y, TPY 360,
unpaired. `bin/harness-batch var/harness/joint/run.sh 1-8 capE`, then analyse.py, ins_an.py, ins_path.py, ins_capE.py
(new), lender_an.py, c_an.py. Outputs: analyse-capE-8s.out, ins-/inspath-/inscapE-/lenders-/c-capE-8s.out.

| quantity | baseD2 | fixD2c | capE |
|---|---|---|---|
| board EPS beat, CW ret/vol | 76.2, 7.2/15.5 | 75.8, 6.5/16.1 | 75.4, 7.1/14.9 |
| Ch11 / liquidations / rescue raises per run | .13/0/4.9 | .50/.38/6.3 | .50/.25/6.3 (TIER s5, SHOR s3) |
| SAFE ROE ttm (target .175) | .126 | .148 | .181 (.006) |
| SAFE NI/avg eq y2/5/10/20 | .141/.141/.111/.099 | .202/.158/.139/.145 | .194/.200/.183/.207 |
| SAFE rev/eq y0/5/10/20 | 1.32/1.26/1.22/1.25 | 1.31/1.14/1.13/1.20 | 1.41/1.25/1.19/1.30 |
| SAFE payout/NI by 5y block | .81/.85/.79/.84 | .86/1.03/.98/.92 | .87/.95/.95/.77 |
| SAFE shares (split-adj) | -30% | -32% | -45% |
| SAFE onsets, attachments /decade | .63, .70 | 1.20, 1.58 | 1.27, 2.15 |
| SAFE hard-regime share | .23 | .30 | .47 (.08) |
| DOVE ROE ttm (target .14) | .153 | .149 | .172 (.005) |
| DOVE NI/avg eq y2/5/10/20 | .210/.170/.134/.106 | .167/.142/.124/.150 | .178/.173/.147/.173 |
| DOVE rev/eq y0/5/10/20 | 1.39/1.25/1.25/1.27 | 1.33/1.29/1.17/1.44 | 1.59/1.32/1.22/1.39 |
| DOVE payout/NI by block | .77/.89/.80/.83 | .83/.95/.89/.92 | .75/.83/.94/.80 |
| DOVE onsets, attachments /decade | 1.27, 1.71 | 1.20, 1.58 | 1.71, 2.47 |

SAFE share count rises on 16 quarters in 8 runs by 2-5% (~10% of equity, P/B ~3 before): the size matches TreasuryEngine's
opportunistic secondary (min 5% mcap, 10% invested capital); fixD2c had 6. Not logged (harness event filter). Lower bound
on the churn: a board repurchasing past the ceiling then reissuing at the same P/B within a year.
Caveats: unpaired; TIER s5 liquidation untouched by the patch (fixD2b had one, base TIER often negative ROE by y20).
No firm on the board uses InsuranceBusinessModel (specialty) directly; PELI is HealthInsurer (unaffected).

## 2026-10-20 capE2 (capE + no premium-valuation raise for a firm at or above its capital target)
Question: does gating TreasuryEngine's opportunistic secondary on getTargetCapitalRatio stop SAFE's 2-5% share
raises, and what does it do to SAFE/DOVE ROE and the banks? Arm capE2 = git archive 2f618cbc +
/tmp/claude-1000/c/capE2.patch (tree deleted after); fixD2c and capE reused. Seeds 1-8 x 20y, TPY 360, unpaired.
`bin/harness-batch var/harness/joint/run.sh 1-8 capE2`, then analyse.py, ins_an.py, ins_path.py, ins_capE.py,
lender_an.py, beat_an.py and raise_an.py (new). Table: capE2.out; detail raise-/inscapE2-/ins-capE2-/inspath-capE2-/
lenders-capE2-8s.out.

SAFE raises 15 -> 10 (fixD2c 6). All 10 left sit in a quarter whose NI fell 70-100% or went negative (7 losses):
the "bubble" test reads P/E on the depressed quarter's earnings, so a cat quarter reads as a premium valuation,
and the loss has already taken eq/ta below target, so the new gate lets it through. None are rescue raises.
SAFE ROE .172 (.007) vs capE .181 (target .175); DOVE .154 (.007) vs .172 (target .14) although DOVE never
raised in any arm: the skipped uniform draw re-paths the board (recession share 8.1% vs 6.3%, both insurers'
loss-quarter share and CR sd up together), so the DOVE and SAFE ROE moves are path noise at n=8, not the gate.
Banks unchanged (ROE and payout inside 1 se of capE); one bank raise (BNTH s4 y19, above target, no loss).
Caveats: unpaired, seeds re-path; above/below target is from the prior quarter's report, the insurer target
from y0 eq/ta (the real target lives in strategy state).

## 2026-10-21 Batch E (normalised-EPS capital tests, oil/miner dividends, housing starts, industrials order book, utilities)
Question: does batch E break the board, and do its targets move as designed? Harness JointHarnessTest.php, arms baseE
(27fe1065) / fixE (+batchE.patch), CREDIT=1, seeds 1-8, 20 y, TPY 360, POLITICS=1. Unpaired (the fix changes draws).
`bin/harness-batch var/harness/joint/run.sh 1-8 baseE fixE`; tables in batchE-8s.out (analyse, beat_an, e_an per arm)
and mm-batchE-<arm>-8s.out (model_moments). baseE seed 1 reproduced capE2-1 byte for byte (ticks and report rows).
| quantity | baseE | fixE |
|---|---|---|
| CW index %/y, vol | 7.24, 15.6 | 6.91, 15.4 |
| failures / Ch11 / rescue raises per seed | 0.13 / 0.5 / 6.4 | 0.13 / 0.38 / 5.3 |
| board EPS beat | 75.6% | 73.9% |
| non-rescue share raises (after loss) | 100 (64) | 9 (1) |
| SINK div-borrow events, $ | 47, $174B | 23, $50B |
| FNCH div-borrow | 23, $15.6B | 0 |
| IB ROE KING/PERE/CORV/PTRL | 25.7/24.4/24.4/17.6 | 25.8/22.6/22.0/17.3 |
| Heavy / Specialty / Tools / Semis beta | 5.05/1.03/2.60/5.35 | 2.74/1.79/3.31/6.41 |
| BIRD margin sd, R2(power) | 7.9pp, 0.76 | 4.3pp, 0.23 |
| WADE defaulted seeds; end BB; ndE | 3; 3; 3.5 | 1; 7; 4.8 |
| HealthInsurer beta | 0.44 | 0.33 |
| corr(starts, gap) k=0; rel sd; AR1; min | 0.18; 4.0; 0.76; 88 | 0.74; 11.5; 0.90; 62 |
Caveats: 8 seeds unpaired; SINK defaulted (cured) in fixE seed 1 at year 3.6 with ndE to the 15 cap; ALBT defaulted in 2
fixE seeds (Ch11 once); starts peak at k=-1 (lag the gap by ~1q); Semis beta rose; PERE/CORV ROE fell below lore.

## 2026-10-21 Batch E2 (fixE + EPS walk-down 0.60 sd, oil producers no longer hold dividends)
Arm fixE2 (HEAD + batchE2.patch), CREDIT=1, seeds 1-8, 20 y, TPY 360, unpaired vs baseE/fixE. `CREDIT=1 bin/harness-batch
var/harness/joint/run.sh 1-8 fixE2`; tables batchE2-8s.out (analyse, beat_an, e_an), mm-batchE2-fixE2-8s.out,
fragile-batchE2-8s.out (fragile_an.py SINK,ALBT,TIER over the three arms).
| quantity | baseE | fixE | fixE2 |
|---|---|---|---|
| CW index %/y, vol | 7.24, 15.6 | 6.91, 15.4 | 7.33, 15.5 |
| failures / Ch11 / pay-default names / rescue raises per seed | 0.13/0.5/0.88/6.4 | 0.13/0.38/0.88/5.3 | 0/0.13/0.63/6.3 |
| board EPS beat (model range) | 75.6% | 73.9% | 75.7% (67.9-82.4) |
| SINK div-borrow events, $; div peak/median $B/q | 47, $174B; 7.0/3.4 | 23, $50B; 2.8/1.6 | 0; 3.2/1.3 |
| SINK default seeds; rescue raises | 0; 2 | 1 (y3.9); 9 | 1 (s7 y8.4, crude 55-64 trough, div already 0); 6 |
| ALBT default seeds; rescue | 0; 4 | 2; 6 | 0; 2 |
| TIER default seeds; rescue | 0; 0 | 0; 4 | 1 (s2 y18.8, ND/E at 15 cap, equity wiped); 2 |
| non-rescue raises (after loss) | 100 (64) | 9 (1) | 22 (2) |
| WADE defaulted seeds | 3 | 1 | 0 |
| corr(starts,gap) k=0; rel sd | 0.18; 4.0 | 0.74; 11.5 | 0.76; 9.6 |
Caveats: 8 seeds unpaired; TIER at the ND/E cap in every arm (fragile by construction); fixE2 seed 7 had 4 payment
defaults (IBHI, DIPR, SINK, FALC), all in one macro path.

## 2026-10-21 Batch F (payout bias on the odds, covered-dividend hold, HF bonus centred, IB gamma friction two-sided)
Question: does batch F stop stewards paying >100% of earnings and borrowing for dividends without breaking the board?
Harness: JointHarnessTest via run.sh, CREDIT=1, 8 seeds x 20y, 360 ticks/y, unpaired (full-market seeds do not pair).
Arms: fixE2 (= HEAD a0786363) vs fixF (HEAD + batchF). Analysis: `f_an.py 1-8 fixE2 fixF` + analyse/beat/fragile; table
in `f_an.out`.

| quantity | fixE2 | fixF |
|---|---|---|
| borrowed-for-dividend events / $B (8 seeds) | 509 / 615 | 101 / 141 |
| of which PLZA, SHOR, CASC (fixF) | 104/160, 49/54, 7/100 | 42/49, 27/30, 4/25 |
| y1 div/NI WADE PLZA KITE CLAW PHIL | 1.06 1.13 1.04 1.08 1.00 | 0.83 0.90 0.80 0.94 0.78 |
| equity 20y change WADE PLZA KITE | +44% -30% -22% | +92% -27% +13% |
| board payout / CW div yield | 32.8% / 2.39% | 31.8% / 2.18% |
| CW index price return %/y | 7.33 (sd 0.96) | 6.67 (sd 0.89) |
| failures / Ch11 / payment-default names / rescues per run | 0 / 0.125 / 0.625 / 6.25 | 0 / 0 / 0.375 / 8.5 |
| board EPS beat | 75.7% | 75.4% |
| SWAN EBIT margin, TTM ROE (lore 22%) | 38.0%, 14.6% | 39.5%, 14.4% |
| PERE EBIT margin, TTM ROE (lore 25%) | 33.8%, 23.0% | 33.9%, 22.9% |

Caveats: unpaired 8 seeds, index difference -0.66pp is inside noise (se ~0.46). fixF defaults: WADE s1 (capex 2.7B/q
over EBITDA 2.5B, dividend already zero from y13, cured), SINK s5, CASC s5. PLZA keeps -27% equity over 20y (front-loaded
y0-y5 fall) and still borrows for dividends in fixF.
