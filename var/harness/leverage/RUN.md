# Operating-firm leverage (net recap target)

Question family: do the listed operating firms reach the trade-off leverage target (Flannery & Rangan 2006 partial
adjustment toward `equity_limit × UNDERLEVERAGED_DEBT_RATIO × manager bias`), and what does reaching it move?

- Harness: `LeverageHarnessTest.php`, a copy of `var/harness/FullMarketHarnessTest.php` (live macro, whole board) whose
  `HEALTH=1` row adds `sev wpd ec cy dte lt lb wacc lbeta gc` and whose `OPENING_OUT` row adds the tick-1 health row and
  `wd0 cash0 eq0 rev0`. `dte` is the recap ratio the tree computes: gross in a pre-fix tree, net of idle cash after it.
  Never compare `dte` across such arms; `analyse.py` reads raw debt, cash and the trailing EBITDA instead.
- Snapshot: `$SNAP/levtree` = `git archive HEAD` + `rsync -rlpt src config tests bin` of the working tree WITHOUT the
  change (it carries the uncommitted industry-beta re-anchoring) + `vendor` symlink. Arm L1 = OVR `$SNAP/ovrL`:
  `Service/Corporate/DebtEngine.php`, `Service/Corporate/CapitalAllocationEngine.php`, `Service/Math/CreditRisk.php`,
  `DTO/DebtHealthDTO.php`, `Service/Math/FinancialConstants.php` (src-relative). Rebuild for a new session:

      git archive HEAD | tar -x -C <SNAP>/levtree; rsync -rlpt src config tests bin <SNAP>/levtree/
      ln -s <repo>/vendor <SNAP>/levtree/vendor
      # then copy the pre-change versions of the five files into levtree/src and the changed ones into ovrL/

  Once the change is committed, L0 needs those five files reverted in `levtree` (`git show HEAD~1:src/<F>`).
  Delete `var/harness/cache-levtree` after changing the snapshot's constructors.
- Run: `SNAP=<dir> bin/harness-batch var/harness/leverage/run.sh 1-8 L0 L1` (~130 s for 8 runs of 20 y at TPY 360 with
  the Diet). Env YEARS (20), TPY (360), POLITICS (1). Outputs `runs/<arm>-<seed>.{jsonl,open.json,log}`.
- Analysis: `python3 var/harness/leverage/analyse.py L0 L1 9 19` (paired by seed; year buckets 9 and 19 = the tenth and
  twentieth years). Population: non-financial operating firms, REITs excluded.
  - Targets: median net debt / EBITDA, net-cash share of firm-quarters, share rated BBB or below.
  - Guards: failures (bankruptcies + reorganisations) per seed, revolver draws, emergency equity raises, payment
    defaults (counted from the captured treasury news lines, which are newsworthy-filtered), median ICR, median P/E.
- Clear stale `runs/L*-*` before a staged run: the analysis globs every seed file of an arm.
- Benchmarks: S&P 500 non-financial median net debt / EBITDA 1.00x (end-2011) and 1.35x (March 2013), S&P Credit Trends;
  about 1.5x at end-2016 (The Economist). Damodaran (January 2026) US non-financials: book debt/capital 48%, gross
  lease-inclusive debt / EBITDA 3.0 (aggregates).

# Issuer credit spreads (rating-class curve)

Question family: does each firm's spread follow its current credit risk (JLT rating-class curve on the ICE BofA
multiples in `CreditRisk::IG_/HY_CLASS_SPREAD_MULTIPLES`, the seeded spread kept as the firm's multiple of its class)?

- Same harness. Quarter rows add `dspread` (live issuer spread), `rspread` (the report's), `ig` / `igT` / `hy` (macro
  IG index, its trend, HY index); the `HEALTH=1` row adds `dd` (Merton distance to default) and `hspread`.
- Arms: `C0` = `$SNAP/$TREE` as is, `C<n>` = OVR `$SNAP/ovr<n>`. Run with `TREE=<snapshot dir name>` so the container
  cache is `var/harness/cache-<TREE>`, not the leverage tree's: `SNAP=<dir> TREE=cstree bin/harness-batch
  var/harness/leverage/run.sh 1-8 C0 C1`.
- Analysis: `python3 var/harness/leverage/analyse_spread.py C0 C1 [2 19]` (paired by seed, firm-quarters in years 2-19).
  Targets: operating-firm median spread by rating against the class curve at the 130 bp IG / 429 bp HY through-the-
  cycle levels, rating-spread Spearman (operating firms; real ~0.6-0.7). Guards: rating mix, median ICR and P/E,
  failures, payment defaults, macro HY median. `analyse.py C0 C1 9 19` gives the leverage guards.
- Runs reproduce bit for bit since `var/harness/bootstrap.php` derives POLITICS_SEED from SEED (2026-10-11). Before
  that the Diet's stream (`app.politics_draws`) was seeded from entropy: the founding Council, governor and cabinet
  differed per run from tick 1, and in 5-year means it carried about all the rerun variance of the policy rate, the
  10y yield, the output gap and ICR, and about 60% of P/E's (4 seeds). Earlier C0/C<n> and L0/L1 comparisons are
  unpaired in macro and firm quantities alike; rerun them.
- `analyse_pe.py C0 C1 ...` splits a P/E move into WACC, cost of equity, levered beta, spread and the macro yield, ERP,
  gap and policy rate (rows carry `coe`, `cmr`, `y10`, `erp`, `pol`).
- `detcheck.sh <arm> <seed> [years]` runs an arm twice and diffs the outputs (timing keys ignored); `failures.py C0 C7`
  lists each bankruptcy/reorg with seed, year and last non-D rating. `analyse.py` now prints a Welch t too.
- Stage command (2026-10-11, C0 vs C7, 48 seeds ~23 min total): `SNAP=<dir> TREE=cstree2 bin/harness-batch
  var/harness/leverage/run.sh 1-8 C0 C7 && python3 var/harness/leverage/analyse_spread.py C0 C7`.
- `analyse_types.py <arm>...`: rating mix by firm type (operating, REIT, asset manager, insurer, bank/broker, other
  financial), revolver draws per seed with the top drawers, and TIER/SWAN/SAFE/FALC ratings and median spreads.
- `cs3_stage.sh <seeds> <N>` (2026-10-11): C10 vs C8 on the cs3 snapshot (SNAP pinned inside; rebuild it per the
  evidence section if the scratchpad is gone), then writes `cs3_s<N>.out` from all four analysis scripts. One
  background call per stage; 16 runs take ~7.5 min at 4 slots.
