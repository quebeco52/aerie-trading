# bank_credit round 2 evidence

## 2026-10-06: household default refit, segment PDs, card/POOL systematic charge-offs, refi gate, NIM net of squeeze

Question: what do the working-tree changes (vs HEAD c2f29f4) do to the macro household default rate, its timestep
dependence, the six lenders' losses/capital/events, bond-market shut-outs, reported NIM and board failures?

Harness: `BankCreditHarnessTest.php` extended (PLVR-round copy in `runs/plvr/BankCreditHarnessTest.plvr.php`): BANKS
default LAKE,RIVR,PLVR,TALN,STRK,POOL; per-quarter `rwt`/`ugap` on bank rows; per-run `macro_q` (quarterly macro
sample independent of reports), board-wide distress-line counts (`board_lines`), `defaults_all`, and a board-wide
non-finite sweep (macro DTO, every stock's price/equity/cash). `run2.sh <after|before> <seed>`: after = working
tree, before = HEAD c2f29f4 (`git archive` into the scratchpad `wt_c2f29f4`, vendor symlink, working `.env`), BOTH on
their own live macro path (no replay: the macro changed), same `mt_srand(seed)`.
`for s in $(seq 1 16); do echo "after $s"; echo "before $s"; done | xargs -P 6 -L 1 ./run2.sh` (~12 min).
16 seeds x 20 years, 360 ticks/yr. All 32 runs OK; tree md5 checked unchanged after (`runs/r2/after_tree.md5`).
Timestep: `macro_tpy.php <tpy> 1 48 50 <out>` (BASE=<tree> for HEAD), macro loop only, no equity cap (equity-wealth
loop OFF), 48 seeds x 50 y at 90 and 360 tpy, quarterly samples after year 1. `python3 analyse_r2.py >
round2_seeds1-16.out`. PLVR-round runs archived to `runs/plvr/`; old container cache cleared.

Table: `round2_seeds1-16.out`. Headline, mean (se), n=16, BEFORE -> AFTER (diff (se) per seed):

Macro: retail default EMA mean 2.32 -> 2.15 (-0.17, 0.08) %, sd 0.71 -> 0.57 (-0.14, 0.05) pp, p95 3.72 -> 3.13
(-0.59, 0.16); corr with ln(H_ema/trend) +0.05 -> -0.58 (0.05); with u-NAIRU +0.04 -> +0.69 (0.05); corporate
default mean 1.28 -> 1.36 (+0.08, 0.08), sd 0.40 -> 0.52 (+0.12, 0.05; a variance at n=16).

Timestep (n=48): sd retail EMA, 90 vs 360 tpy: before 0.93 vs 0.99 (+0.06, 0.04); after 0.68 vs 0.75 (+0.07, 0.05).
Raw rate: before 1.25 vs 1.30, after 0.70 vs 0.77. Neither arm differs by tpy within noise: the old iid 0.35 Z per
tick adds ~0.09 pp (90) / 0.04 pp (360) of EMA sd in quadrature to ~0.93, too small to detect. The common +6-10%
at 360 shows in both arms, so it is not the retail term.

Lenders (BEFORE -> AFTER): see the table. NCO/TTC after 0.80-0.87 for all six; STRK TTC 3.5 -> 7.5%, no failures,
ROE p5 -3.9 -> -9.2 (diff -5.3, 3.1), allowance peak 13.5%; PLVR shut-outs 0.81 (0.37) -> 0; reported NIM PLVR
6.07 -> 5.47, steep - inverted -1.41 -> -1.10 (+0.30, 0.23).

Finding: the refit Z has no intercept. The fit's own data gives mean Z_obs -0.23 (sd 0.55) for a 2.5% mean PD
(`../retail_default_fit/fit.py` output), but the engine's Z averages about -0.08 (house -0.03, unemployment -0.06,
OU factor +0.01, plus spread stress), and the Vasicek PD at pdLra 2.5% only means 2.5% when Z ~ N(0,1). The macro
household default mean lands at 2.15% on the board (2.24% macro only) vs the 2.5% baseline. That shortfall is the
NCO/TTC ~0.83-0.87 of household books (corporate is also under its 1.6% baseline in both arms, 1.28-1.36%).

Caveats: arms run on different macro paths and boards, so per-lender differences carry macro-path noise (recession
windows/seed 0.88 vs 1.31). Tail moments (ROE p5, CET1 min, failures, corporate default sd) need 48 seeds.
