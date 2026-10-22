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

## 2026-10-21 Batch G (covenant-breach capex gate: plant spend limited to internal funds)
Question: does limiting a breaching non-financial firm's capex to internal cash stop penalty borrowing and missed
maturities without stunting healthy firms?
Harness: JointHarnessTest via run.sh, CREDIT=1, 8 seeds x 20y, 360 ticks/y, unpaired. Arms: fixF (= HEAD 4a81a6af, runs
reused) vs fixG (HEAD + capex gate). Analysis: `g_an.py 1-8 fixF fixG` + analyse/fragile/beat; all in `g_an.out`.

| quantity (8 seeds) | fixF | fixG |
|---|---|---|
| penalty-rate borrow events / $B | 47 / 258 (CASC 203, WADE 22 ev) | 62 / 106 (BIRD 30 ev/55, WADE 14/11) |
| missed maturities / $B | 3 / 15.7 (CASC, WADE, SINK) | 1 / 1.3 (DIPR s4) |
| shut-out forced repayments / $B | 107 / 518 | 59 / 300 |
| payment-default names per run / rescue raises per run | 0.375 / 8.5 | 0.125 / 1.6 |
| constrained non-fin quarters | 5.9% | 5.3% |
| board revenue CAGR / median per-model | 4.97% / 4.53% | 4.82% / 4.48% |
| CW index %/y | 6.67 (sd 0.89) | 7.25 (sd 0.61) |
| board EPS beat | 75.4% | 75.6% |
| SINK bust capex $B/q, peak revolver drawn/commit | 3.68, 0.82 | 3.99, 0.39 |

Caveats: unpaired, so commodity paths differ: last-2y crude averages ~130 in fixF vs ~95 in fixG, which accounts for
the lower y20 revenue of CASC (555 -> 372 $B/q), SINK (46 -> 28) and DIPR (6.7 -> 4.4); PP&E for all three holds or
rises. The fixG default is DIPR s4 (new). Penalty borrowing that remains is BIRD/LOON/SKUA/WADE, outside the gate or
not in breach. PTRL (IB, fin=false) reads 100% "constrained" by the icr<2 proxy in both arms at capex/dep 1.0.
DIPR s4: Generation Outage at y17.4, EBITDA 3.8 -> 0.5-1.1 $B/q, icr 0.15; the gate cut capex/dep 2.0 -> 0.24 (the 20%
floor) yet the revolver still filled (5.9 of 6.0 $B) and a $1.29B maturity was missed at y19.6, cured next quarter:
the shortfall there is operating and interest, not plant spend.

## 2026-10-21 Batch H (utility own-failure z -2.80, WADE exposure 0.25, utilities off Altman zones, REIT ratio undepreciated)
Arms fixG (base, on disk) vs fixH, 8 seeds x 20 y, 360 tpy, CREDIT=1, unpaired. `python3 h_an.py 1-8 fixG fixH`; full
table in h_an.out. Mean [range] over seeds.
| quantity | fixG | fixH |
|---|---|---|
| BIRD grid failures/yr | 0.063 [0..0.152] | 0.019 [0..0.051] |
| WADE grid failures/yr | 0.057 [0..0.101] | 0.025 [0..0.051] |
| BIRD quarters A / BB % | 38 / 59 | 96 / 0 |
| WADE quarters A / BB % | 73 / 20 | 88 / 0 |
| BIRD / WADE ND/EBITDA mean | 3.93 / 4.58 | 3.93 / 4.49 |
| BIRD / WADE penalty borrow n ($B) per run | 3.8 (6.9) / 1.8 (1.4) | 1.4 (2.2) / 0 (0) |
| DIPR (control) Ch11/default seeds | s4 default | s3, s4 Ch11 |
| PLZA / KITE / SHOR quarters BB-or-worse % | 26 / 15 / 43 | 0 / 4 / 1 |
| REIT end ratings | 23 BBB, 8 BB, 1 CCC | 31 BBB, 1 BB |
| REIT ND/EBITDA y20 | 4.8-6.5 | 4.98-6.44 (same) |
| board HY at end (mean names) | 10.5 | 6.4 |
| board failures / Ch11 / payment default | 0 / 0 / 0.125 | 0 / 0.25 / 0.25 (both DIPR) |
| CW index %/y | 7.25 (0.61) | 7.61 (0.55) |
| board EPS beat % | 75.6 | 75.9 (no model outside 60-85) |
Caveats: unpaired, 8 seeds; DIPR is unchanged code, its extra Ch11 is path divergence (it already sat 36% of quarters
at B). REIT leverage unchanged while ratings rose: the move is the rating basis, not the balance sheet.

## 2026-10-21 Batch I (seed only: SWAN target payout 0.20 -> 0.75, POOL 0.40 -> 0.80)
Question: does a higher payout stop SWAN/POOL out-growing their market and restore ROE (expected SWAN ~17%, yield ~4%)?
Harness: JointHarnessTest.php, `fixI` = HEAD 4b960f64 + InitialMarket patch (tree deleted). Base `fixH` (runs on disk).
`CREDIT=1 bin/harness-batch var/harness/joint/run.sh 1-4 fixI`; `i_an.py 1-4 fixH fixI`, `i_bridge.py 1-4 fixH fixI`.
4 seeds x 20y, TPY 360, unpaired. Mean [range]. Output: i_an.out.

| quantity | fixH | fixI |
|---|---|---|
| SWAN TTM ROE y2+ / y2-3 / last 2y % | 15.0 / 19.9 / 14.3 | 15.3 [11.6..17.1] / 18.0 / 14.3 [5.7..18.6] |
| SWAN equity / revenue CAGR % | 7.7 / 5.2 | 7.7 / 6.1 |
| SWAN D/E y0/5/20 | 2.51 / 2.23 / 1.50 | 2.57 / 2.69 / 2.36 |
| SWAN (div+bb)/NI, div yield % | 0.80, 1.8 | 0.72, 4.2 |
| SWAN bonds for expansion n, $B/run | 21.8, 2165 | 54.8, 3505 |
| POOL TTM ROE y2+ / y2-3 / last 2y % | 19.5 / 27.9 / 14.9 | 37.6 / 33.2 / 33.8 |
| POOL equity / revenue CAGR % | 10.4 / 9.4 | 10.1 / 12.0 |
| POOL D/E y0/5/20 | 4.11 / 4.38 / 2.82 | 4.28 / 6.05 / 6.51 |
| POOL (div+bb)/NI, div yield % | 0.61, 3.7 | 0.79, 8.6 |
| POOL bonds recap n, $B/run | 1.8, 263 | 0, 0 |
| POOL bonds for expansion n, $B/run | 23.5, 661 | 53.2, 2177 |
| board failures / Ch11 / payment default | 0 / 0.5 / 0.5 | 0 / 0 / 0 |
| CW index %/y (sd) | 7.78 (0.49) | 6.50 (0.93) |
| board EPS beat % | 76.1 | 75.7 |
Caveats: equity CAGR did not move: growth is set by the expansion plan, funded with bonds when retention falls (D/E up,
expansion issuance x2.5). SWAN's dividend rise displaced buybacks (total payout fell). SWAN equity bridge: ~40-50% of
its equity growth is not ni-div-bb (i_bridge.py). CW index gap -1.3 (se ~0.5) is price return only, 4 unpaired seeds.

## 2026-10-21 Batch J (fixI seed + POOL capital target 0.1964 + board-wide anti-dilution repurchase)
Question: does buying back the quarter's stock comp (from excess cash only) stop SWAN's book outgrowing its market, and
does POOL's capital target restore its ROE; what does the rule do board-wide?
Harness: JointHarnessTest.php, `fixJ` (tree deleted) vs `fixH` (runs on disk). `CREDIT=1 bin/harness-batch
var/harness/joint/run.sh 1-8 fixJ`; `i_an.py 1-8 fixH fixJ`, `j_an.py 1-8 fixH fixJ`, `i_bridge.py`. 8 seeds x 20y,
TPY 360, unpaired. Mean [range]. Output: j_an.out.

| quantity | fixH | fixJ |
|---|---|---|
| SWAN TTM ROE y2+ / y2-3 / last 2y % (lore 22) | 14.8 / 18.3 / 13.8 | 15.3 / 19.6 / 12.4 [6.9..15.5] |
| SWAN equity / revenue CAGR % | 7.8 / 5.1 | 7.2 / 5.1 |
| SWAN D/E y0/5/20 | 2.51 / 2.17 / 1.37 | 2.57 / 2.68 / 2.34 |
| SWAN div $B, bb $B, (div+bb)/NI, div yield % | 1217, 2002, 0.79, 1.9 | 2396, 254, 0.79, 4.3 |
| SWAN residual $B (% of d eq), shares end/start split-adj | 787 (49%), 0.68 | 691 (51%), 1.13 |
| SWAN expansion bonds n, $B/run; end ratings | 20, 1875; BBB x6 BB x2 | 49, 3226; BB x8 (worst B once) |
| POOL TTM ROE y2+ / y2-3 / last 2y % (lore 29) | 19.8 / 28.8 / 14.3 | 28.8 / 29.2 / 27.8 |
| POOL equity / revenue CAGR % | 10.4 / 9.1 | 2.1 / 2.4 |
| POOL D/E y0/5/20; (div+bb)/NI; div yield % | 4.14/4.50/2.80; 0.61; 3.9 | 4.09/4.00/3.99; 0.95; 8.0 |
| POOL recap bonds n, $B; expansion n, $B | 2.0, 296; 22.9, 615 | 0, 0; 4.8, 25 |
| no-target firms (75): median eq CAGR %, shares e/s, bb $B/run ex SWAN | 4.66, 0.67, 13388 (se 287) | 4.61, 0.67, 12510 (se 223) |
| failures / Ch11 / payment default / rescues per run | 0 / 0.25 / 0.25 / 1.75 | 0.375 / 0 / 0 / 1.88 |
| EW / CW index %/y (price) | 9.23 / 7.61 | 8.24 / 6.30 |
| board EPS beat %; models outside 60-85% | 75.9; none | 75.6; none |
| board (div+bb)/NI+; CW dividend yield % | 0.84; 2.1 | 0.86; 2.3 |
Caveats: the anti-dilution rule is capped by excess cash, which SWAN (dividend x2, expansion on bonds) and most firms
do not hold: SWAN bought back $254B against ~$900B of stock comp and its split-adjusted share count rose 13%. Board-wide
no detectable effect (share ratio, residual, equity CAGR unchanged; buybacks lower, unpaired). POOL hits its lore ROE
but returns ~95% of NI, so it grows 2%/y. Seed 6: credit gap 13.6% then crisis at y16, u 11.8%, corp default 17%,
STRK/RIVR/PLVR liquidated, TIER 6 rescue raises (also seed 4): a macro tail, not traced to the rule.

## 2026-10-21 Batch K (POOL capital target 0.1964; SWAN lore baseline_roe 0.22 -> 0.16; payouts unchanged)
Question: does the rest of the board still grow slower (fixJ: -0.9pp/y market cap, t -1.9), and where do POOL and SWAN
land? Harness: JointHarnessTest.php; base = fixH 1-8 (symlinked as baseK) + baseK 9-16 (git archive HEAD), fixK 1-16.
16 seeds x 20y, TPY 360, CREDIT=1, unpaired. `k_an.py 1-16 baseK fixK`, `i_an.py 1-16 baseK fixK`. Output: k_an.out.

| quantity | base | fixK | diff (se), t |
|---|---|---|---|
| board mkt-cap CAGR ex SWAN/POOL % | 4.64 (sd 1.0) | 4.45 (0.66) | -0.19 (0.30), -0.6 |
| EW / CW index %/y | 9.30 / 7.65 | 9.02 / 7.37 | CW -0.28 (0.30) |
| failures / Ch11 / pay. default / rescues per run | 0 / 0.25 / 0.31 / 1.75 | 0 / 0.31 / 0.44 / 2.94 | rescues +1.2 (1.2) |
| board EPS beat %; models outside 60-85% | 76.0; none | 75.6; none | |
| board (div+bb)/NI+; CW div yield % | 0.836; 2.13 | 0.834; 2.11 | |
| POOL TTM ROE y2+ / y2-3 / last 2y % (lore 29) | 19.8 / 29.5 / 14.3 | 28.9 / 29.4 / 28.4 | |
| POOL equity / revenue CAGR % | 10.4 / 9.2 | 4.7 / 4.8 | |
| POOL D/E y0/5/20; (div+bb)/NI; div yield % | 4.14/4.48/2.87; 0.62; 3.9 | 4.09/3.98/3.98; 0.87; 3.6 | |
| POOL div / bb $B per run; recap bonds n, $B; expansion n, $B | 432 / 91; 2.5, 359; 22, 588 | 185 / 269; 0, 0; 35.6, 250 | |
| SWAN TTM ROE y2+ / y2-3 / last 2y % | 14.7 / 18.1 / 13.6 (lore 22) | 11.2 / 13.2 / 10.5 (lore 16) | |
| SWAN equity / revenue CAGR %; D/E y0/5/20 | 7.8 / 5.0; 2.51/2.18/1.43 | 7.3 / 5.3; 2.48/2.32/1.61 | |
| SWAN (div+bb)/NI; div yield %; div / bb $B | 0.78; 1.9; 1213 / 1955 | 0.67; 1.6; 697 / 1131 | |
| SWAN expansion bonds n, $B; end ratings | 21.2, 2033; BBB x10 BB x6 | 24.6, 1991; BBB x8 BB x8 | |
| SWAN opening price / cap $B | 107 / 1720 (16e9 sh) | 291 / 1160 (4e9 sh) | |
Caveats: board effect inside noise (fixJ's -0.9pp came with SWAN payout 0.75, not reproduced). SWAN realises ~70% of
its lore ROE in both arms (14.7/22, 11.2/16): the shortfall is proportional, not a lore mismatch. Rescues: fixK SINK 18
(seeds 1, 14 mostly) and SKUA 9 vs base DIPR 8/TIER 5; unpaired tails, not traced. SWAN seeds at 4e9 shares, not 16e9.

## 2026-10-21 Batch L (SWAN closed at capacity 0.50 of SAM, fund leverage 2.38 off SWAN's D/E; POOL target 0.1964)
Question: does capacity closure bring SWAN to ~20% TTM ROE without hurting the board? Harness JointHarnessTest.php;
base baseK 1-16 (on disk), fixL 1-8; 20y, TPY 360, CREDIT=1, unpaired. `l_an.py baseK 1-16 fixL 1-8`. Output l_an.out.

| quantity | baseK (16) | fixL (8) | diff (se), t |
|---|---|---|---|
| SWAN TTM ROE y2+ / y2-3 / last 2y % (lore 22) | 14.7 / 18.1 / 13.6 | 18.5 / 19.9 / 17.5 | y2+ +3.8 (0.95), +4.0 |
| SWAN addressable share y0 / peak / end | 0.42 / 0.82 / 0.72 | 0.42 / 0.50 / 0.41 | |
| SWAN equity / revenue CAGR % | 7.9 / 5.1 | 4.6 / 2.8 | |
| SWAN D/E y0/5/20; (div+bb)/NI; cash/eq | 2.51/2.18/1.43; 0.78; 0.36 | 2.26/1.60/1.02; 0.92; 0.15 | |
| SWAN div / bb $B; div yield %; shares split-adj | 1206 / 1956; 1.86; 0.68 | 519 / 2344; 1.21; 0.43 | |
| SWAN expansion bonds n, $B; NI sd/mean; blow-up q | 21.2, 2030; 0.345; 0.56 | 0, 0; 0.274; 2.1 | blow-ups +1.6 (0.49) |
| POOL TTM ROE y2+ / last 2y %; equity CAGR % | 19.8 / 14.3; 10.4 | 28.4 / 27.4; 4.8 | |
| board mkt-cap CAGR ex SWAN/POOL % | 4.64 | 3.63 | -1.01 (0.35), -2.9 |
| EW / CW index %/y | 9.30 / 7.65 | 8.60 / 6.93 | |
| Ch11 / defaults / rescues per run; payout; CW yield | 0.25/0.31/1.75; 0.836; 2.13 | 0.5/0.5/2.1; 0.843; 2.20 | inside noise |
Caveats: board -1.0pp (t -2.9) repeats fixJ's -0.9pp, which also raised SWAN's cash payout; fixK (POOL only) was
-0.19. Needs seeds 9-16 before it is believed. SWAN seed 8: revenue flat (100->121 $B/q) while capped equity grows with
nominal GDP, ROE 12.3 (last TTM 3.9), BB. SWAN debt still rises (1080 -> 1676 $B seed 8) without expansion bonds.
SWAN equity-bridge residual $479B (73% of d eq; base 48%) untraced. Addressable share omits secular drift.

## 2026-10-21 Batch M (fixL with SWAN FundGrossLeverage 1.80: no blow-up or margin-call label possible)
Question: is fixL's board drop (-1.0pp, t -2.9) caused by SWAN directional blow-ups raising macro vol? Harness
JointHarnessTest.php; fixM 1-16 vs baseK 1-16 and fixL 1-8 (on disk); 20y, TPY 360, CREDIT=1, unpaired.
`m_an.py baseK:1-16 fixL:1-8 fixM:1-16`, `l_an.py baseK 1-16 fixM 1-16`, `l_an.py fixL 1-8 fixM 1-16`. Output m_an.out.

| quantity | baseK (16) | fixL (8) | fixM (16) | fixM-baseK (se) t | fixM-fixL (se) t |
|---|---|---|---|---|---|
| board mkt-cap CAGR ex SWAN/POOL % | 4.64 | 3.63 | 4.02 | -0.62 (0.35) -1.8 | +0.38 (0.34) +1.1 |
| macro vol mean % | 15.3 | 16.4 | 15.4 | +0.03 (0.39) +0.1 | -1.02 (0.48) -2.1 |
| macro erp mean % | 4.48 | 4.59 | 4.64 | +0.16 (0.08) +2.0 | +0.05 (0.12) +0.4 |
| SWAN blow-up / margin-call q per run | 0.56 / 0 | 2.12 / 0 | 0 / 0 | | |
| SWAN TTM ROE y2+ / last 2y % | 14.7 / 13.6 | 18.5 / 17.5 | 18.7 / 16.6 | +4.0 (0.69) +5.8 | +0.2 (1.1) |
| SWAN equity / revenue CAGR %; payout; D/E y20 | 7.9 / 5.1; 0.78; 1.43 | 4.6 / 2.8; 0.92; 1.02 | 4.4 / 2.3; 0.94; 1.04 | | |
| SWAN NI sd/mean | 0.345 | 0.274 | 0.237 | -0.11 (0.015) | -0.037 (0.013) -2.7 |
| POOL TTM ROE y2+ %; equity CAGR % | 19.8; 10.4 | 28.4; 4.8 | 29.0; 4.4 | | |
| EW / CW index %/y | 9.30 / 7.65 | 8.60 / 6.93 | 8.75 / 7.13 | | |
| Ch11 / defaults / rescues per run | 0.25/0.31/1.75 | 0.5/0.5/2.1 | 0.13/0.19/3.7 | inside noise | |
Pooled fixL+fixM vs baseK board CAGR -0.75pp (0.31), t -2.4. Pooled per seed (n=40): corr(blow-ups, mean vol) +0.44,
corr(mean vol, board CAGR) -0.44. Event time: macro vol change from the quarter before a SWAN blow-up to k=0..4 after
is -0.4..0.0pp vs +0.0 other quarters (26 events, |t| <= 0.6): no vol response.
Caveats: the blow-up is a news label (HedgeFundBusinessModel.php:454) with no path into macro vol
(AssetMarketSubsystem::calculateMarketVolatility reads gap, EBP, slope, EPU, its own draw); the corr is the label
firing in weak markets. fixL's vol +1.05 was seed noise (fixM same economics, vol back at base). Board drop persists
without blow-ups: it is in the shared SWAN-capacity/POOL-target change. SWAN end ratings BBB 15/16. Rescues: one fixM
seed has 28.

## 2026-10-21 Batch O (SWAN capacity rule on a replayed macro path, paired by seed)
Question: with the macro held fixed, does SWAN's capacity rule lower the rest of the board's market-cap growth (the
-0.6pp/y seen unpaired in fixL/fixM/fixK-type live arms)? Harness JointHarnessTest.php; recNS (noswan tree, live,
POLITICS=1) records each seed's macro, rpNS and rpSW replay it. Seeds 1-16 (stage 8 gave t +2.3, so extended), 20y,
TPY 360, CREDIT=1. `python3 var/harness/joint/o_an.py 1-16`; output o_an.out.

| quantity (rpSW - rpNS, n=16) | rpNS | rpSW | diff (se) | t | sd(diff) |
|---|---|---|---|---|---|
| board mkt-cap CAGR ex SWAN/POOL % | 4.23 | 4.30 | +0.077 (0.041) | +1.9 | 0.17 |
| board TTM NI / revenue / book-equity CAGR % | 4.45 / 4.66 / 4.81 | 4.52 / 4.71 / 4.86 | +0.06 / +0.05 / +0.06 (0.04-0.07) | <= 1.3 | |
| board P/E end / P/B end | 15.5 / 2.70 | 15.5 / 2.69 | +0.02 (0.18) / -0.005 (0.022) | ~0 | |
| SWAN TTM ROE y2+ % | 14.5 | 19.8 | +5.3 (0.5) | +10.7 | |
| SWAN D/E y20; (div+bb)/NI; expansion bonds | 1.45; 0.79; 20.6 | 1.07; 0.97; 0 | | | |
| SWAN cap share of board+SWAN end % | 8.3 | 6.1 | -2.1 (0.5) | -4.3 | |
Per firm: 48% of ~87 firms lower in rpSW (per-seed 38-56%), median diff +0.09pp. Replay sanity: live recNS board CAGR
4.19 vs replayed rpNS 4.23 (diff +0.04, se 0.04).
Caveats: the rule is active (no expansion bonds, ROE +5pp). Under replay the board drop is gone: the 2-se interval
[-0.01, +0.16] excludes -0.5 by ~14 se. The live drop either runs through board -> macro feedback (cut by replay) or
was macro noise; replay also drops politics on the replayed arms (both arms equally).

## 2026-10-21 Batch P (fee-manager family capacity rule d12 on the replayed macro, paired by seed)
Question: does closing SWAN/TIER/CROW/VULT at their opening capacity share (0.4035/0.0357/0.30/0.32), with no borrowing
to grow and no DebtEngine leverage target, bring them to lore ROE (22/24/20/23%) without board side effects?
Harness: JointHarnessTest.php, rpD12 (HEAD + d12-fix.patch) vs Batch O rpSW, both replaying recNS-<s>.macro.gz;
seeds 1-16 (stage 8 left the board at t -3.5, TIER ROE t +1.7), 20y, TPY 360, CREDIT=1. `p_an.py 1-16`; p_an.out.

| quantity (rpD12 - rpSW, n=16) | rpSW | rpD12 | diff (se) | t |
|---|---|---|---|---|
| board mkt-cap CAGR ex funds/POOL % | 4.27 | 4.10 | -0.17 (0.03) | -5.8 |
| SWAN TTM ROE y2+ % | 19.8 | 19.3 | -0.6 (0.8) | -0.7 |
| TIER TTM ROE y2+ % | 8.2 | 9.9 | +1.7 (1.1) | +1.6 |
| CROW TTM ROE y2+ % | 16.5 | 18.8 | +2.3 (0.4) | +5.8 |
| VULT TTM ROE y2+ % | 17.7 | 22.2 | +4.5 (0.5) | +9.0 |
| CROW / VULT share end | 0.73 / 0.77 | 0.29 / 0.31 | -0.44 / -0.46 | -47 / -35 |
| TIER D/E y20 | 2.24 | 1.41 | -0.83 (0.19) | -4.4 |
| TIER expansion / recap bonds | 29 / 5.6 | 0 / 0 | | |

Per firm: 51% of 84 lower in rpD12, mean of per-seed medians -0.02pp (se 0.04): the board drop is in a few names.
Board sanity unchanged inside noise (Ch11 0.25->0.06, defaults 0.31->0.13, rescues 2.5->3.3/run, EW/CW return -0.07
/ -0.10pp, vol -0.06/-0.11pp, all |t| < 2). No negative equity; no fund with TTM ROE > 40% for 8 quarters.
Caveats: the rule binds (zero expansion bonds, CROW/VULT held at their open share). TIER stays at ~10% ROE: its
ROE falls 21% (y1) -> ~5% (y16-20) in both arms as revenue/equity drifts 73% -> 48% at a flat ~42% EBIT margin, and
D/E 1.4 legacy debt carries the interest. TIER 3y (div+bb)/NI > 1.5 in 9/16 seeds (payout through loss years).

## 2026-10-22 Batch Q (DIPR: independent-power equity_limit 1.5 -> 0.50, producer book leverage)
Question: does the IPP sector norm at producer leverage end DIPR's recurring reorganisation without board side effects?
Harness: JointHarnessTest.php, rpIPP (HEAD a9cc9383 + scratchpad dipr-fix.patch) vs rpD12 (src = HEAD), both replaying
recNS-<s>.macro.gz; seeds 1-16, 20y, TPY 360, CREDIT=1. `python3 var/harness/joint/q_an.py 1-16`; q_an.out. Paired.

| quantity (rpIPP - rpD12, n=16) | rpD12 | rpIPP | diff (se) | t |
|---|---|---|---|---|
| DIPR reorganisations / events of default per run | 0.06 / 0.13 | 0 / 0 | -0.06 / -0.13 (0.06 / 0.09) | -1.0 / -1.5 |
| DIPR debt-rising q with power > 3y trend % | 63.8 | 61.1 | -2.7 (5.6) | -0.5 |
| DIPR amount-weighted power deviation % | +17.2 | +8.2 | -9.0 (5.5) | -1.6 |
| DIPR acquisitions (goodwill rises) / run | 0.25 | 1.62 | +1.38 (0.74) | +1.9 |
| DIPR ND/TTM EBITDA median / peak | 2.69 / 6.37 | 1.57 / 3.85 | -1.12 / -2.52 | -15 / -10 |
| DIPR quarters BB or below % | 60.2 | 4.9 | -55.3 (4.5) | -12 |
| DIPR TTM ROE y2+ % | 11.4 | 10.3 | -1.0 (0.4) | -2.5 |
| DIPR (div+bb)/NI / D/E y20 | 0.96 / 0.79 | 0.72 / 0.35 | -0.24 / -0.44 | -7 / -10 |
| board mkt-cap CAGR ex DIPR % | 4.11 | 4.19 | +0.08 (0.06) | +1.4 |
| 10 cyclicals: debt-rising q with EBIT > 3y mean % | 75.9 | 75.0 | -0.9 (1.3) | -0.7 |

Base: reorganisation seed 14 only (seed 2 reorganises in rpSW, not rpD12), cured default seed 11. Fix: none.
Pooled DIPR debt-rising quarters 178 -> 125, weighted power deviation +15.4% -> -2.2%.
Board: Ch11 and payment-default counts equal (0.06, 0.13/run); EW/CW return and vol |t| < 1.1; rescues -0.8 (0.7).
CASC (refiner) reorganises in rpIPP seed 5 (default seed 10 too): CASC is at the edge in seeds 5/10 in every replay arm
(rpNS seed 10 defaulted; ND/EBITDA peaks 14-216), so this is firm-path divergence after the shared draw stream shifts,
not a DIPR channel. Caveats: failure counts are rare events (2 in base), so their t stays under 2 at 16 seeds; the
mechanism (BB share, ND/EBITDA peak under the 4.0 covenant) is decisive. Acquisitions count goodwill rises (the harness
does not log M&A text).

## 2026-10-22 Batch R (TIER acquisitions + seeded debt, then financial return-state fixes, replayed macro)

Question: does tierfix (fee managers at capacity make no acquisitions; TIER wholesale_debt 37.5B -> 12.5B) restore
TIER's ROE (lore 0.24) without board damage, and what do the return-state fixes add, with (rpF0) and without (rpF) the
roeTtm pull for non-insurers? Arms rpT / rpF0 / rpF vs rpD12 (HEAD a9cc9383 src, Batch P runs reused), all replaying
runs/recNS-<s>.macro.gz, CREDIT=1, 20 y, 360 ticks/y, seeds 1-16 (stage 8 then 16: credit events were unclear at 8).
Replay held (48/48 ok). `python3 var/harness/joint/r_an.py 1-16` -> r_an.out (full tables).

| quantity (paired vs rpD12, n=16) | rpD12 | rpT | rpF0 | rpF |
|---|---|---|---|---|
| TIER ROE y2-5 / 6-10 / 11-15 / 16-20 | .169/.118/.083/.052 | .241/.233/.243/.238 | .214/.223/.231/.233 | .239/.228/.234/.232 |
| TIER D/E y20 | 1.41 | 0.24 (t-7.4) | 0.23 | 0.23 |
| TIER acquisitions / revolver draws / rescues per run | 8.8 / 4.1 / 0.38 | 0 / 0 / 0 | 0 / 0 / 0 | 0 / 0 / 0 |
| TIER rating y20 | BBB9/BB7 | A16 | A16 | A16 |
| RIVR ROE y2-20 (lore .15) | .117 | +.000 | +.021 (t8.6) | +.022 (t10.4) |
| board cap CAGR ex funds/POOL/RIVR % | 4.30 | +0.08 (0.06) | +0.05 (0.06) | +0.57 (0.07) |
| financial cap weight y20 % | 44.7 | +0.2 (0.7) | +1.2 (0.6) | +6.9 (0.6) |
| CW index return %/y | 7.47 | +0.11 (0.06) | +0.16 (0.07) | +0.49 (0.08) |
| Ch11 / payment defaults per run | .06 / .13 | +.13 / +.19 | +.19 / +.25 | +.06 / 0 |

Caveats: every Ch11/default is a non-financial (DIPR, FALC, CASC, SINK, IBHI), all |t| < 2: the changed opening
board perturbs the replay. rpF re-rates every bank and fee manager (P/B q79 +0.6..+1.5, t 3-11) with realised ROE
unchanged (|diff| < 0.01 except RIVR/ACC): the no-pull roeTtm feeds valuation, not earnings. No absurdity hits.

## 2026-10-22 Batch S (final builds of the financial batches vs the Batch R prototypes, replayed macro)

Question: do the final builds behave like Batch R's prototypes? rpX1 = HEAD 289382e9 + fin1.patch (rpF0 without the
acquisition-blend change), rpX2 = fin12.patch (rpX1 + non-insurer roeTtm a plain EWMA, like rpF). Base rpIPP 1-8 on
disk (src = HEAD). All replay runs/recNS-<s>.macro.gz, CREDIT=1, 20 y, 360 ticks/y, seeds 1-8 (16/16 ok; stopped at 8:
targets past |t| 4 or matching Batch R). `python3 var/harness/joint/s_an.py 1-8` -> s_an.out (full tables).

| quantity (paired vs rpIPP, n=8) | rpIPP | rpX1 | rpX2 | Batch R rpF0 / rpF (vs rpD12, n=16) |
|---|---|---|---|---|
| TIER ROE y2-5 / 6-10 / 11-15 / 16-20 | .168/.091/.025/.048 | .235/.221/.238/.212 | .224/.226/.222/.230 | .214/.223/.231/.233 ; .239/.228/.234/.232 |
| TIER D/E y20 / acquisitions / revolver draws | 1.78 / 10.1 / 4.8 | 0.22 / 0 / 0 | 0.23 / 0 / 0 | 0.23 / 0 / 0 both |
| TIER rating y20 | BBB4/BB3 | A8 | A8 | A16 both |
| RIVR ROE y2-20 (lore .15) | .112 | +.023 (t9.4) | +.022 (t7.7) | +.021 / +.022 |
| ACC ROE y2-20 (lore .26) | .258 | -.015 (t-2.9) | -.004 (t-1.5) | +.021 / +.020 |
| board cap CAGR ex funds/POOL/RIVR % | 4.48 | -0.20 (0.06) | +0.42 (0.07) | +0.05 / +0.57 |
| financial cap weight y20 % | 43.4 | +0.7 (0.5) | +7.2 (0.8) | +1.2 / +6.9 |
| CW index return %/y | 7.52 | +0.13 (0.06) | +0.48 (0.06) | +0.16 / +0.49 |
| M&A gw steps per run | 172 | -44 (14) | -30 (13) | -20 / -23 |
| SAFE / DOVE P/B q79 | 3.23 / 1.85 | -0.43 / +0.09 | -0.38 / +0.09 | |

Direct pairing (section 5): rpX1-rpF0 12 keys past |t| 2 of ~120 (~6 by chance); real: ACC ROE -0.022 (t-4.7), payout
-0.024 (t-3.9), P/B q79 -0.48 (t-3.6), M&A -24/run (t-2.6). rpX2-rpF 7 keys, largest ACC ROE -0.006 (t-2.6), DOVE P/B
q79 -0.32 (t-2.3: insurers keep the pull in rpX2). Caveats: the blend change was what lifted ACC in Batch R; without it
ACC sits 0.015 below base in rpX1. rpX2 re-rates banks and fee managers (P/B q79 +0.7..+1.8) with realised ROE flat, as
rpF did; insurers do not re-rate. No Ch11 or absurdity hits in either arm.

Extension (10-22): rpX1 seeds 9-16 added (tree-X1 rebuilt as mk_trees_s.sh, CREDIT=1, 20y x 360 tpy), `s_an16.py` ->
s_an16.out, rpX1 paired by seed, n=16: board cap CAGR -0.11 (0.07) vs rpIPP, -0.02 (0.07) vs rpD12, -0.07 (0.07) vs
rpF0, all inside noise (seeds 9-16 alone: -0.01 / +0.14 / -0.04); M&A -33 (9)/run, t-3.5 (TIER -9.6, HUMM -5.4, ROOK
-2.8, PHIL -2.7, SHRK -2.4); SAFE cap CAGR -0.45 (0.27), P/B q79 -0.19 (0.15), both t<2 (9-16 alone ~0); HUMM
acquisitions 13.9 -> 8.4 (t-3.4), share eq/(1e12 x ngdp x 0.375) at q79 0.89 -> 0.75 (t-2.8), debt steps >10% -1.7
(0.8). The 1-8 effects on SAFE and board CAGR halve or vanish on 9-16.

## 2026-10-22 Batch T (lender calibration fix: steady-state provision + TALN/STRK reseed, replayed macro)

Question: does fin123 (lender revenue calibration funds CreditRisk::calculateSteadyStateProvisionRate instead of the
net-book loss rate; TALN deposits 425 -> 354B, treasury 45 -> 37.5B; STRK deposits 225 -> 209.5B, treasury 22.5 ->
20.95B) lift TALN and STRK to ~0.24-0.25 ROE over years 2-20 without disturbing the board?
Harness: JointHarnessTest.php via run.sh, arms rpX2 (HEAD 289382e9 + fin12) and rpBC (HEAD + fin123), both replaying
runs/recNS-<s>.macro.gz, CREDIT=1, 20 y, 360 ticks/y. Seeds 1-16 (stage 2: board CAGR read t -1.8 at 8 seeds and one
Ch11 appeared; at 16 it is -0.02pp, se 0.07). `python3 var/harness/joint/t_an.py 1-16` -> t_an.out.

| quantity (seeds 1-16, rpBC - rpX2) | rpX2 | rpBC | diff (se) | t |
|---|---|---|---|---|
| TALN ROE y2-20 (lore .25) | .189 | .241 | +.052 (.0075) | +6.9 |
| STRK ROE y2-20 (lore .25) | .179 | .250 | +.072 (.0047) | +15.4 |
| LAKE ROE (.21) | .188 | .193 | +.005 (.0015) | +3.3 |
| PLVR (.11) / RIVR (.15) | .109 / .139 | .112 / .142 | +.0024 (.0013) / +.0030 (.0013) | +1.9 / +2.4 |
| EGRT (.13) / BNTH (.18) / POOL (.29) | .131 / .193 / .289 | .130 / .193 / .293 | -.0013 / +.0001 / +.0034 | -1.2 / +0.1 / +1.8 |
| TALN / STRK ROE y1 | .266 / .292 | .293 / .353 | +.027 (.006) / +.061 (.012) | +4.6 / +5.1 |
| TALN / STRK payout y2-20 | .81 / .82 | .86 / .89 | +.053 (.035) / +.065 (.018) | +1.5 / +3.5 |
| TALN / STRK equity CAGR % | 5.76 / 5.08 | 4.77 / 4.69 | -0.99 (.39) / -0.38 (.18) | -2.5 / -2.2 |
| TALN / STRK eq/assets y2-20 % | 14.45 / 14.14 | 14.06 / 13.91 | -0.39 (.53) / -0.23 (.39) | -0.7 / -0.6 |
| TALN / STRK prov/assets, allowance/book | 2.63 / 5.87, 3.89 / 8.30 % | unchanged | <= .015pp | |
| board mkt-cap CAGR ex funds/POOL/RIVR/TALN/STRK % | 4.907 | 4.882 | -0.025 (.072) | -0.3 |
| financial cap weight y20 % | 51.9 | 52.5 | +0.65 (.65) | +1.0 |
| EW / CW index return %/y | 9.34 / 8.01 | 9.41 / 8.02 | +.07 (.05) / +.01 (.05) | +1.4 / +0.2 |
| EW / CW vol % | 15.26 / 15.36 | 15.33 / 15.40 | +.07 / +.04 | +1.3 / +0.9 |
| Ch11 / payment-default firms / rescues per run | 0 / .125 / 2.5 | .06 / .125 / 3.1 | +.06 / 0 / +.6 (.7) | |

Lender ratings: no lender rescue, revolver quarter, default or Ch11 in either arm; worst/end rating shifts all |t| <= 1.1.
The one Ch11 (CASC, seed 4, y18) was already rated B with icr 0.6 in the base arm; path divergence at a cliff.
Absurdity scan (negative equity, financial ROE > 40% over 4y, financial equity CAGR > 12%, lender eq/assets < 3%,
non-positive price, anomaly file): none in either arm.
Caveats: the extra TALN/STRK earnings go out as payout, so their equity grows ~0.4-1pp/y slower; TALN's lift varies
by seed (+0.001 on seed 16 to +0.12 on seed 9). Provisions and allowance are untouched: the fix moves revenue, not
losses. Trees and caches deleted.

## 2026-10-22 Batch U (covenant-share recap target, replayed macro)

Question: does the DebtEngine recap target = min(book D/E target, leverage where funded net debt reaches the manager's
share of the covenant on normal EBITDA) cut CASC's and FALC's covenant distress with M&A, failures and price formation
in, and leave the rest of the board alone? Architect's recursion expected CASC -7.0 +- 1.2 breach quarters per run and
FALC -42 +- 7pp BB-or-below share.
Harness: JointHarnessTest.php via run.sh, arms rpBC (HEAD 289382e9 + fin123, Batch T, on disk) and rpRC (HEAD +
fin1234), both replaying runs/recNS-<s>.macro.gz, CREDIT=1, 20 y, 360 ticks/y. Seeds 1-16 (seeds 9-16 of rpRC rerun
after an interrupted session). `python3 var/harness/joint/u_an.py 1-16` -> u_an.out; 1-8 -> u_an_1-8.out.

| quantity (seeds 1-16, rpRC - rpBC) | rpBC | rpRC | diff (se) | t |
|---|---|---|---|---|
| CASC breach quarters/run | 27.4 | 23.9 | -3.5 (1.5) | -2.4 |
| CASC median ND/EBITDA | 2.01 | 1.69 | -0.32 (0.06) | -5.1 |
| CASC recap bonds $B/run | 224 | 125 | -99 (11) | -9.0 |
| CASC ROE % | 14.7 | 13.1 | -1.6 (0.35) | -4.6 |
| CASC payout | 1.04 | 0.93 | -0.11 (0.03) | -3.2 |
| CASC Ch11 / reorgs per run | 0.06 | 0 | -0.06 (0.06) | -1.0 |
| FALC breach quarters/run | 20.4 | 13.6 | -6.8 (2.8) | -2.4 |
| FALC BB-or-below share % | 73.5 | 53.8 | -19.8 (5.7) | -3.5 |
| FALC median ND/EBITDA | 2.49 | 2.15 | -0.33 (0.11) | -3.0 |
| board mkt-cap CAGR ex funds/POOL/RIVR % | 4.89 | 4.98 | +0.09 (0.06) | +1.6 |
| board payout | 0.862 | 0.857 | -0.005 (0.002) | -2.5 |
| board recap bonds $B/run | 829 | 715 | -113 (19) | -5.9 |
| EW / CW index return %/y | 9.41 / 8.02 | 9.37 / 8.01 | -0.04 (0.04) / -0.02 (0.06) | -1.0 / -0.3 |
| EW / CW vol % | 15.33 / 15.40 | 15.25 / 15.33 | -0.07 (0.03) / -0.07 (0.06) | -2.5 / -1.2 |

Seeds 1-8 alone: CASC breach -5.5 (2.3), FALC BB share -11.7 (6.7), board CAGR +0.12 (0.08).
EIDR, WADE, REDW, DIPR: BB share, payout and ROE all within |t| 1.6 except REDW payout +0.03 (t +2.4). Ch11, defaults,
rescues: no change. Recap movers after CASC: FALC -9.5, RAVN -7.1, PINT -3.9, WADE -3.8 $B/run.
Verdict: direction confirmed, magnitude about half the recursion (CASC -3.5 vs -7.0, ~2 se apart; FALC -20pp vs -42pp,
~2.4 se apart); CASC still breaches ~24 of 77 quarters and pays for it with -1.6pp ROE. Board unchanged.
Caveats: CASC ND/TTM EBITDA > 10 in 10 of 16 base seeds and 8 of 16 rpRC seeds (peak 495 in one base run), so breaches are
driven by EBITDA troughs, not by the leverage the target sets; the absurdity list is otherwise the same in both arms
(ALBT BOWR CANV FULM IBHI SINK WEAV).

## 2026-10-22 Batch V (covenant cap on debt-funded acquisitions, replayed macro)

Question: does capping a leveraged deal so funded net debt (debt - cash + price) stays at the manager share of the
covenant on combined normal EBITDA cut FALC's and the board's acquisition debt and covenant distress without
disturbing the board?

Harness: JointHarnessTest.php via run.sh; arm rpMA = tree-MA (git archive HEAD 999d4ab1 + scratchpad macov.patch, src
only, = working-tree DebtEngine + MergerAndAcquisitionEngine diff) vs rpRC on disk (src checked identical to HEAD
999d4ab1). Both replay runs/recNS-<s>.macro.gz, CREDIT=1, 20 y, 360 ticks/y, seeds 1-16 (stage 2: FALC unclear at 8).
`CREDIT=1 bin/harness-batch var/harness/joint/run.sh 1-16 rpMA && python3 var/harness/joint/v_an.py 1-16`.
Tree and cache deleted. Output v_an.out (1-16), v_an_1-8.out.

```
Batch V, seeds 1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16: rpMA - rpRC, paired by seed (replay of recNS macros)

## 1a. board acquisitions per run
| quantity | rpRC | rpMA | diff (se) t | diff range | n |
|---|---|---|---|---|---|
| board acquisitions | 157.2 | 162.1 | +4.94 (6) t+0.8 | -42..+37 | 16 |
| board debt-funded acquisitions | 135.8 | 141.2 | +5.44 (5.6) t+1.0 | -42..+42 | 16 |
| board debt-funded acq $B | 2139 | 1421 | -718 (3.9e+02) t-1.9 | -5.31e+03..+300 | 16 |
| board ex-fin debt-funded acq $B | 1217 | 1017 | -200 (64) t-3.1 | -768..+210 | 16 |
| board goodwill added $B | 707.1 | 580.4 | -127 (70) t-1.8 | -907..+210 | 16 |

## 1b. acquisitions per run, five largest drops
| firm | rpRC | rpMA | diff (se) t |
|---|---|---|---|
| CHUF | 3.56 | 1.50 | -2.06 (1.55) t-1.3 |
| GULL | 10.50 | 8.50 | -2.00 (1.68) t-1.2 |
| GANN | 2.88 | 1.06 | -1.81 (1.15) t-1.6 |
| LAKE | 1.69 | 0.19 | -1.50 (1.01) t-1.5 |
| WREN | 10.69 | 9.19 | -1.50 (1.89) t-0.8 |

## 1c. debt-funded acquisitions per run (n, $B debt rise)
| quantity | rpRC | rpMA | diff (se) t | diff range | n |
|---|---|---|---|---|---|
| FALC dfa n | 4.188 | 5.25 | +1.06 (0.91) t+1.2 | -4..+8 | 16 |
| FALC dfa $B | 111 | 58.43 | -52.6 (20) t-2.7 | -188..+63.7 | 16 |
| CASC dfa n | 1.438 | 1.25 | -0.188 (0.68) t-0.3 | -6..+5 | 16 |
| CASC dfa $B | 123.3 | 34.73 | -88.5 (43) t-2.0 | -501..+67.3 | 16 |
| SINK dfa n | 0.75 | 0.875 | +0.125 (0.58) t+0.2 | -4..+7 | 16 |
| SINK dfa $B | 7.381 | 5.62 | -1.76 (5.4) t-0.3 | -56.6..+48.8 | 16 |
| HUMM dfa n | 7.125 | 7.812 | +0.688 (1) t+0.7 | -7..+10 | 16 |
| HUMM dfa $B | 126.3 | 141.7 | +15.3 (22) t+0.7 | -159..+160 | 16 |
| IBHI dfa n | 0.625 | 1.5 | +0.875 (0.74) t+1.2 | -4..+8 | 16 |
| IBHI dfa $B | 16.19 | 28.91 | +12.7 (17) t+0.7 | -139..+148 | 16 |

## 2. FALC / CASC covenant distress
| quantity | rpRC | rpMA | diff (se) t | diff range | n |
|---|---|---|---|---|---|
| FALC breach quarters | 13.62 | 11.44 | -2.19 (1.3) t-1.7 | -10..+7 | 16 |
| FALC BB-or-below share % | 53.75 | 44.84 | -8.91 (3.6) t-2.5 | -37.5..+13.8 | 16 |
| FALC reorganisations | 0 | 0 | +0 (0) t=0 | +0..+0 | 16 |
| FALC Ch11 lines | 0 | 0 | +0 (0) t=0 | +0..+0 | 16 |
| FALC payment defaults | 0 | 0 | +0 (0) t=0 | +0..+0 | 16 |
| FALC peak ND/EBITDA | 6.646 | 4.547 | -2.1 (0.92) t-2.3 | -12.9..+0.501 | 16 |
| FALC median ND/EBITDA | 2.153 | 2.105 | -0.0479 (0.064) t-0.7 | -0.722..+0.498 | 16 |
| FALC ROE % | 13.87 | 15.08 | +1.21 (0.65) t+1.8 | -3.37..+5.82 | 16 |
| FALC payout (div+bb)/NI | 0.6872 | 0.7518 | +0.0646 (0.022) t+2.9 | -0.054..+0.217 | 16 |
| CASC breach quarters | 23.94 | 19.69 | -4.25 (1.4) t-3.1 | -19..+3 | 16 |
| CASC BB-or-below share % | 2.188 | 1.484 | -0.703 (1.2) t-0.6 | -12.5..+5 | 16 |
| CASC reorganisations | 0 | 0 | +0 (0) t=0 | +0..+0 | 16 |
| CASC Ch11 lines | 0 | 0 | +0 (0) t=0 | +0..+0 | 16 |
| CASC payment defaults | 0.375 | 0 | -0.375 (0.38) t-1.0 | -6..+0 | 16 |
| CASC peak ND/EBITDA | 21.73 | 120.6 | +98.9 (56) t+1.8 | -67.4..+736 | 16 |
| CASC median ND/EBITDA | 1.689 | 1.58 | -0.109 (0.06) t-1.8 | -0.712..+0.214 | 16 |
| CASC ROE % | 13.09 | 13.76 | +0.674 (0.39) t+1.7 | -1.61..+3.44 | 16 |
| CASC payout (div+bb)/NI | 0.9341 | 0.9915 | +0.0574 (0.024) t+2.4 | -0.0633..+0.362 | 16 |

## 3. board
| quantity | rpRC | rpMA | diff (se) t | diff range | n |
|---|---|---|---|---|---|
| board mkt-cap CAGR ex funds/POOL/RIVR % | 4.976 | 4.895 | -0.0806 (0.061) t-1.3 | -0.521..+0.434 | 16 |
| Ch11/run | 0 | 0.0625 | +0.0625 (0.062) t+1.0 | +0..+1 | 16 |
| payment-default firms/run | 0.1875 | 0.125 | -0.0625 (0.062) t-1.0 | -1..+0 | 16 |
| rescues/run | 2.938 | 2.5 | -0.438 (0.36) t-1.2 | -4..+1 | 16 |
| board BB-or-below share % | 8.669 | 8.817 | +0.148 (0.19) t+0.8 | -1.67..+1.55 | 16 |
| EW index return %/y | 9.372 | 9.341 | -0.0315 (0.039) t-0.8 | -0.311..+0.332 | 16 |
| EW index vol % | 15.25 | 15.2 | -0.0568 (0.039) t-1.5 | -0.31..+0.223 | 16 |
| CW index return %/y | 8.008 | 8.042 | +0.034 (0.056) t+0.6 | -0.378..+0.329 | 16 |
| CW index vol % | 15.33 | 15.36 | +0.0288 (0.072) t+0.4 | -0.523..+0.652 | 16 |

## 4. firms with >= 2 acquisitions/run in A and none in B
  none
  largest rises: SILC (11.2 -> 14.7), TICK (11.2 -> 12.7), BUZT (0.2 -> 1.6)

## absurdity scan (firm@seed)
  rpRC: none
  rpMA: none
```

Caveats: debt-funded acquisition $ is the debt rise in goodwill-rise quarters (no deal records in the output), so it
includes same-quarter recap and revolver draws; the board total includes banks' funding moves (use the ex-fin row).
CASC peak ND/EBITDA +99 is a ratio artefact: on seeds 1, 3, 5 its TTM EBITDA fell to $0.6-2B in one quarter (net
debt unchanged at ~$300-430B); median ND/EBITDA and breach quarters both fall. Board Ch11 +1 is FULM seed 11, which
already had 6 payment defaults in rpRC (7 in rpMA). No firm that made >= 2 deals/run stops; deal counts are unchanged,
sizes fall.
