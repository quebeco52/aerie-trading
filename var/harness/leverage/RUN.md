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
