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
