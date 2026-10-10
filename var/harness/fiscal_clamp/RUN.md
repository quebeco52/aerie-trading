# Corporate-tax clamp probe

Question family: how often `CreditFiscalSubsystem::calculateDynamicFiscalPolicy()` clamps the corporate-tax target to
[MIN_CORPORATE_TAX_RATE, MAX_CORPORATE_TAX_RATE], and which parts of the target (law shift, 0.35 x outputGapEma,
fiscalStance = Bohn surplus - structural deficit - consolidation spending cut) push it there.

- `clamp_row.php`: recomputes the unclamped target and its parts from a MacroStateDTO after each macro step
  (fiscalStance() takes MacroState, so it composes the public static bohnSurplus/consolidationSpendingCut).
  Keep it in step with fiscalStance() if that changes.
- `FiscalClampHarnessTest.php`: fork of `var/harness/FullMarketHarnessTest.php` with one extra probe,
  `CLAMP_OUT=<jsonl>` (one clamp_row a quarter).
- `macro_politics.php <seed> <years> <out>`: macro + politics only, tpy 48, no board, no fund, no equity cap
  (equity-wealth loop off), as `polls/lever_means.php`.

## Arms (run.sh <arm> <seed>)
- `A`: full ticker, POLITICS=1, `YEARS_A` (100) at `TPY_A` (360). ~6 s per simulated year.
- `B`: macro + politics, `YEARS_B` (200) at tpy 48. ~0.7 s per simulated year.

## Run

    bin/harness-batch var/harness/fiscal_clamp/run.sh 1-16 B A && \
      python3 var/harness/fiscal_clamp/analyse.py > var/harness/fiscal_clamp/<name>.out && cat var/harness/fiscal_clamp/<name>.out

Outputs: `runs/<arm>-<seed>.jsonl`, logs in `logs/`. Delete `var/harness/cache/test` after a `src/` service change.
