# bank_credit evidence

## 2026-10-05: systematic commercial-bank charge-offs (LAKE, RIVR), STOPPED partway

Question: does the working tree's systematic charge-off (segment Vasicek on the macro retail/corporate default
rates plus Frye collateral LGD) give positive recession credit losses where HEAD (idiosyncratic default Z plus a
margin overlay released by the allowance convergence) gives about zero? What does it do to ROE and CET1?

Harness: `BankCreditHarnessTest.php`, a copy of `../FullMarketHarnessTest.php` (production container, in-memory
Redis, real seed, MarketOperator every tpy/24). Per bank quarter it records the gross book, allowance, provision,
NCO, TTC rate, CET1, NI and equity, the macro gap, default rates and property indices, and the sector-physics event
type (read from EarningsEngine's NarrativeEngine through a reflection tap).
- `run.sh rec|after|before <seed>`: rec records `macro/path-<seed>.gz` from the working tree. after replays it on the
  working tree. before replays it on the HEAD tree (`HEADTREE`, default the scratchpad `wt_head`, built with
  `git archive HEAD` plus a vendor symlink and the working `.env`).
- `batch.sh` (SEEDS, default 1-24) runs at most 8 processes. `analyse.py` writes the paired table. Recession is the
  gap EMA below GAP_CUT=-0.02, plus TAIL=8 quarters.
- Cost: about 130 s per 20-year run at 360 tpy.

Status: stopped at the coordinator's request before the model was revised. Macro paths 1-8 are recorded. No paired
arm was run. `partial_after_rec_seeds1-8.out` holds the unpaired live-macro recording runs of the working tree as of
22:51 (AFTER v1).

Caveat found: `getThroughTheCycleCreditLossRate()` is the Vasicek EL at Z=0, which is the median-year loss (LAKE
0.323%), not the mean PD x LGD (0.54%). Mean NCO therefore exceeds the "TTC" rate in both arms, and
(provision - TTC) is positive outside recessions too. analyse.py reports a "window cost over normal quarter" that
nets this out.

### Correction, 23:01: the kill did not reach the background batch

`pkill` from a foreground Bash call cannot see a `run_in_background` job, because each call is its own sandbox. The
batch kept running after the handback and finished at 23:01:
- rec 17-24 started about 22:53, before the model edit at 22:56:44. They wrote jsonl files but no macro paths.
- after/before 1-16 and 21-24 errored at start because their macro paths are missing.
- after/before 17-20 completed at 22:57-23:01. The after runs loaded the REVISED model (v2, the coordinator's edit),
  on a replay whose path file is missing. Treat them as invalid.
All of these are moved to `runs/stray_2301/`. Do not analyse them. `runs/` now holds only rec 1-8 (AFTER v1),
and `macro/` holds path-1..8.

Next time, stop a batch by deleting `batch.sh` or using the task-stop tool, not `pkill`.

### 23:03: v2 batch (completed 23:11, see below)

A v2 run (seeds 1-16, rec then after/before) started 22:57:19 with `SEEDS="$(seq 1 16)" batch.sh`. The v1 macro paths
and runs are in `v1_archive/`. `macro/path-1..8.gz` and `runs/rec-1..8.jsonl` written at 23:00:4x are **v2**
recordings (the model file is dated 22:56:44), not v1. rec 9-16 are running now; after/before 1-16 follow.

## 2026-10-05 23:15: v2 model (macro rho, macro baseline PDs, TTC = PD x LGD mean, 2y CECL, NCO/TTC events) vs HEAD

Question: does v2 make charge-offs average their TTC and produce a recession credit cost that HEAD lacks, and what
does it do to allowance, ROE, CET1 and the event rates?

Harness: `BankCreditHarnessTest.php` unchanged. `SEEDS="$(seq 1 16)" batch.sh` recorded `macro/path-1..16.gz` from
the v2 tree (model file 22:56:44), then replayed AFTER (v2 tree) and BEFORE (HEAD 7e7a503, scratchpad `wt_head`) on
the same paths. 16 seeds x 20 years, 360 ticks/yr, LAKE and RIVR. All 32 arm runs OK; macro default EMAs identical
across arms. `python3 analyse.py > v2_seeds1-16.out` (now also prints allowance mean/peak and the macro default-rate
means). `release_check.out` is the allowance-timing check below. v1 paths and runs are in `v1_archive/`.

Table: `v2_seeds1-16.out`. Headline (mean (se), n=16; window rows n=13, three seeds have no recession window):

| quantity | LAKE before | LAKE after | RIVR before | RIVR after |
|---|---|---|---|---|
| mean NCO % | 0.531 (0.020) | 0.708 (0.023) | 0.849 (0.036) | 0.836 (0.041) |
| TTC % | 0.323 | 0.722 | 0.516 | 0.864 |
| NCO/TTC | 1.65 (0.06) | 0.98 (0.03) | 1.64 (0.07) | 0.97 (0.05) |
| window cost net of normal qtr, % book | -0.23 (0.28) | 1.70 (0.22) | 0.12 (0.55) | 3.29 (0.44) |
| peak NCO/TTC | 10.9 (1.0) | 1.96 (0.17) | 9.7 (0.7) | 2.20 (0.26) |
| allowance % book mean / peak | 1.60 / 2.22 | 1.58 / 2.07 | 2.65 / 3.72 | 1.90 / 2.50 |
| corr(NCO, cdr) | -0.02 | 0.82 | 0.00 | 1.00 |

Macro long-run means: retail default EMA 2.58% (0.05) vs 2.5%, corporate 1.60% (0.08) vs 1.6%.

Finding (release_check.out): the allowance does not follow v2's charge-offs. The implied CECL multiplier
(allowance / 2y x TTC x book) correlates +0.77 with the recession-probability EMA and +0.28/+0.32 with the output gap,
but -0.22/-0.26 (se 0.08) with the corporate default EMA, and never above +0.05 at leads of -8..+8 quarters. The
probit recession probability peaks late in expansions and falls once the recession starts, so the reserve builds
before losses and releases while they run. 73% (LAKE) and 85% (RIVR) of quarters tagged `reserve_release`
(NCO/TTC < 0.6) actually raised the allowance (HEAD 31%/26%).

Caveats: BEFORE's "TTC" is the Vasicek median-year loss, so BEFORE's raw window cost is inflated; compare the
net-of-normal-quarter row. RIVR has no household book (StockModelTuning), so its NCO is a function of the corporate
EMA and CRE prices alone (corr 0.996 by construction). 16 seeds: ROE p5 and CET1-min differences are inside noise.

## 2026-10-05 23:25: v3 CECL starts from the current charge-off rate (ASC 326-20-30-9 reversion, tau 4.2y, H 2y)

Question: does starting the lifetime loss estimate from the current annualised charge-off rate (reverting to TTC)
make the allowance follow losses instead of the recession-probability EMA, and at what cost to ROE and CET1?

Harness: unchanged. v2 AFTER runs archived to `runs/v2/after-*.{jsonl,log}`. AFTER replayed with the v3 tree
(EarningsEngine.php md5 in `runs/v3_tree.md5`) on the same `macro/path-1..16.gz`:
`seq 1 16 | xargs -P 8 -I{} ./run.sh after {}` (4.5 min). 16 seeds x 20 years, 360 tpy. All 16 OK; the macro
corporate default EMA is identical v2 vs v3 on every seed. `python3 v3_compare.py > v3_seeds1-16.out` (HEAD, v2, v3 and
the paired v3-v2; 'release raised allowance' = allowance LEVEL up on a reserve_release-tagged quarter, per-seed mean,
pooled share also printed).

Headline (mean (se), n=16; v3-v2 paired):
- corr(allow % book, corp default EMA): LAKE -0.22 -> +0.54 (+0.76, 0.08); RIVR -0.26 -> +0.80 (+1.06, 0.07).
- corr(allow % book, recession prob): LAKE +0.77 -> +0.07; RIVR +0.80 -> -0.22.
- reserve_release quarters raising the allowance: LAKE 73% -> 36%, RIVR 85% -> 50% (HEAD 31%/26%).
- allowance % book peak: LAKE 2.07 -> 2.60 (0.18), median 2.43, range 1.72-4.62; RIVR 2.50 -> 3.29 (0.34),
  median 3.03, range 1.81-6.82. Mean level unchanged.
- tail: ROE p5 LAKE -4.9 (2.3), RIVR -5.5 (1.8) pp; CET1 min RIVR -0.99 (0.41) pp; RIVR seed 3 CET1 4.18% with 2
  BANK_SEIZURE events (v2: 8.69%, none). Window cost and NCO/TTC unchanged within noise; event rates unchanged (NCO-tagged).

Caveats: the current rate is ONE quarter's annualised NCO, so the target moves with quarterly charge-off noise; the
forward multiplier still multiplies on top of it, which may stack the recession twice (not separated here). Seeds 3, 5
and 1 drive the tail. 16 seeds: tail moments (ROE p5, CET1 min, peak) need 48.

## 2026-10-05 23:30: v4 CECL additive (outlook and current conditions add on TTC, not compounded)

Question: v4 `resolveLifetimeCreditLossRate` = max(0, TTC x mult + (current - TTC) x f) x H, f = (1-e^(-H/tau))/(H/tau),
tau 4.2, H 2 (v3 was (TTC + (current - TTC) x f) x H x mult). Does removing the compounding cut the v3 allowance and
capital tail (RIVR seed 3: allowance 6.82%, CET1 4.18%, 2 seizures) without losing the allowance-follows-losses fix?

Harness: unchanged. v3 AFTER runs archived to `runs/v3/` (with `v3_tree.md5`); v4 EarningsEngine.php md5 in
`runs/v4_tree.md5` (3a313b35..., checked unchanged at end of batch). `seq 1 16 | xargs -P 8 -I{} ./run.sh after {}`
(4.2 min), same v2 `macro/path-1..16.gz`. 16 seeds x 20 y, 360 tpy. All 16 OK; corporate default EMA identical v3
vs v4 on every seed. `python3 v4_compare.py > v4_seeds1-16.out` (HEAD, v2, v3, v4, paired v4-v3, extreme seeds).

Headline (mean (se), n=16, v4-v3 paired):
- corr(allow, corp default EMA): LAKE 0.54 -> 0.48 (-0.055, 0.011); RIVR 0.80 -> 0.69 (-0.11, 0.03).
- corr(allow, recession prob): LAKE 0.07 -> 0.14 (+0.07, 0.01); RIVR -0.22 -> -0.05 (+0.17, 0.04).
- allowance peak: LAKE 2.60 -> 2.48 (-0.12, 0.04), max 4.62 -> 4.03; RIVR 3.29 -> 3.12 (-0.17, 0.07), max 6.82 -> 5.88.
  Mean level unchanged. Seed 3 is the max in both banks and both arms.
- release-tagged quarters raising the allowance: LAKE 36 -> 39% (+2.8, 1.8), RIVR 50 -> 58% (+8.2, 4.1).
- window cost, ROE mean/p5, CET1 min: paired differences inside noise.
- RIVR seed 3: CET1 min 4.18 -> 5.99, seizures 2 -> 0. LAKE seed 3: CET1 6.33 -> 4.98, seizures 0 -> 2.

Caveat: the arms share the macro path, not the board. Firm paths decohere once a bank's numbers differ: LAKE total
revenue v4/v3 ranges 0.81-1.19 across seeds, and non-bank failures differ (v3 TIER on seeds 7, 11; v4 STRK on 2,
WING/STRK/TIER on 3). LAKE seed 3's v4 seizure follows ~25-30% lower revenue from year 5 on, with provisions at
or below v3's; CET1 sits 1-1.5 pp under v3 before the recession. It is board divergence, not the CECL rule. Seizure
counts and CET1 tails therefore carry board noise on top of seed noise: they need 48 seeds.

## 2026-10-05 23:45: Plover Savings Bank (PLVR) added; PLVR, LAKE, RIVR on re-recorded paths

Question: how does the new PLVR (Banks - Regional, $2.0T deposits, 65% residential, NimInversionSensitivity 14,
floating 0.15) behave under the v4 credit model: losses, allowance, ROE, CET1, P/B, curve sensitivity, the link
from its charge-offs to house prices? Did RIVR (same industry) move against the v4 batch?

Harness: `BankCreditHarnessTest.php` extended: BANKS default LAKE,RIVR,PLVR; per quarter it now also records price,
shares, reported NIM, NII stream, interest expense, operating margin, dividends, buybacks, cash, deposits, wholesale
debt, rating, 2y/10y yield EMAs, interbank spread, spot 2s10s, policy rate; per run the seeded and tick-1 opening of
each bank (price, shares, equity, CET1, cash, TTC) and the banks' treasury/distress event lines. v4 batch archived to
`runs/v4/` (after/before/rec plus `BankCreditHarnessTest.v4.php`), its v2 macro paths to `macro/v2/` (so
`analyse.py` and `v4_compare.py` now need those paths). Tree md5s in `runs/plvr_tree.md5` (checked unchanged after).
- `seq 1 16 | xargs -P 8 -I{} ./run.sh rec {}` re-recorded `macro/path-1..16.gz` from this tree (4.4 min), then
  `seq 1 16 | xargs -P 8 -I{} ./run.sh after {}` (4.3 min). 16 seeds x 20 years, 360 tpy. All 32 runs OK.
- `python3 plvr_analyse.py > plvr_seeds1-16.out`. Curve buckets use the yield EMAs the squeeze reads; inverted
  2s10s < 0, steep > +1%; responses are per-seed (steep - inverted) bucket means, 14 seeds have both buckets.

Headline (mean (se), n=16) PLVR / LAKE / RIVR:
- NCO/TTC 0.93 (0.02) / 0.91 (0.02) / 0.89 (0.03); recession window net of normal qtr 1.25 (0.54) / 1.44 (0.58) /
  2.68 (0.89) % book (n=14); allowance peak 3.18 (0.10) / 2.40 (0.11) / 2.90 (0.22) %.
- ROE mean 12.1 (0.4) / 19.9 (0.5) / 15.1 (0.4); p5 0.1 (2.1) / 9.9 (1.6) / 4.1 (2.9). CET1 seed 12.63 / 12.82 /
  16.87, min 10.34 (0.39) / 11.97 (0.21) / 15.25 (0.62); worst seed 16 for all (7.16 / 9.98 / 6.49). No seizures,
  failures, reorganisations or payment defaults. Board deaths: STRK 1, TIER 1.
- P/B tick 1 1.13 / 2.53 / 1.42, year 20 1.17 (0.15) / 2.01 (0.17) / 1.37 (0.12); cap $236bn -> $574bn (84).
- Curve: pre-provision margin d/d(2s10s - interbank) 2.21 (0.11) / -0.18 (0.04) / 1.04 (0.13); steep - inverted
  +5.5 (0.3) / -0.4 (0.1) / +2.9 (0.3) pp. After provisions the cycle swamps it (op. margin OLS 0.2 (0.8) /
  -2.2 (0.5) / -3.2 (0.7)). Reported NIM falls in steep quarters for all three (-1.33 (0.15) pp for PLVR).
- corr(PLVR NCO, residential EMA) -0.21 (0.08); with its 4q log change -0.28 (0.06); with retail default EMA 0.97.
- Nothing non-finite; Q1 dividends paid ($4.0bn), cash/deposits 11.4% at Q1.
- PLVR "shut out of the bond market" 2.4 (0.7) per seed on 10/16 seeds, rated A-AAA at the time: DebtEngine
  hasPrimaryMarketAccess refuses any issuer with interest coverage < 1, i.e. a loss quarter.

RIVR vs v4 (unpaired, new macro paths): NCO/TTC -0.08 (0.06), window cost -0.9 (1.1), allowance peak -0.2 (0.4),
ROE +1.2 (0.8), CET1 min +1.2 (1.0); all inside noise. Both banks' NCO/TTC dip matches the new paths' lower macro
default means (retail 2.42 vs 2.58%, corporate 1.46 vs 1.60%).

Caveats: tail moments (ROE p5, CET1 min, seizures) need 48 seeds; seed 16 drives every bank's tail. The steep and
inverted buckets are confounded with the credit cycle; the pre-provision margin strips provisions but not the NII
volume terms (SLOOS, housing starts, M2), so its slope understates the structural squeeze (PLVR 3.3, RIVR 2.7,
LAKE 0.8 pp margin per pp from the constants).

## 2026-10-06: round 2 (household default refit, segment PDs, card/POOL systematic, refi gate, NIM) vs HEAD c2f29f4

See `round2_evidence.md` and `round2_seeds1-16.out` (`run2.sh`, `analyse_r2.py`, `macro_tpy.php`; runs in `runs/r2/`).
PLVR-round runs moved to `runs/plvr/`.
