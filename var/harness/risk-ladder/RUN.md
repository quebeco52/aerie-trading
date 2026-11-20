# Risk-ladder & relever-volatility harness

Paired whole-board scorecard harness comparing risk-model changes over 20-year runs (`RiskLadderHarnessTest.php` extends
`var/harness/lib/BoardRun.php`; read-only probe, runs against both `BASE=<archive>` and the working tree).

## What it records

Beyond `BoardRun`'s standard `OUT` record (scorecard standards, quarterly filings, defaults, reorganisations, events):
- Split-adjusted full-run realised volatility per firm (`rvol`) and mispricing `|log(px / fv)| > 0.25` tick share (`misp`).
- Quarterly consensus EPS/revenue (`ceps`, `reps`, `crev`) for EPS beat rate, revenue surprise SD and `>10%` revenue jumps.
- Per-firm, per-year (`yearly`) split-adjusted realised vol (`rv`), leverage multiplier `L` (`l`, `l_min`, `l_max`), active
  long-run volatility (`sig`, `sig_max`), peak spot volatility (`cvol_max`) and live tick count (`n`).

## Run

Prepare a clean `BASE` archive once (with `vendor` symlinked) and warm both container caches before fanning out:

    rm -rf /tmp/aerie-base var/harness/cache var/harness/cache-aerie-base
    mkdir -p /tmp/aerie-base && git archive <commit> | tar -x -C /tmp/aerie-base
    ln -s "$PWD/vendor" /tmp/aerie-base/vendor

Run paired seeds (pass `HEALTH=1` so `icr` is recorded; delete stale `runs/<arm>-s*.jsonl` first since `BoardRun` appends):

    for s in 1 2 3 4; do
      BASE=/tmp/aerie-base HEALTH=1 YEARS=20 SEED=$s OUT=var/harness/risk-ladder/runs/rv-head-s${s}.jsonl \
        bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
        var/harness/risk-ladder/RiskLadderHarnessTest.php > var/harness/risk-ladder/logs/rv-head-s${s}.log 2>&1 &
      HEALTH=1 YEARS=20 SEED=$s OUT=var/harness/risk-ladder/runs/rv-new-s${s}.jsonl \
        bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
        var/harness/risk-ladder/RiskLadderHarnessTest.php > var/harness/risk-ladder/logs/rv-new-s${s}.log 2>&1 &
    done
    wait
    python3 var/harness/risk-ladder/analyse.py rv-head rv-new

## Runs

### 2026-11-20 — Re-lever volatility every tick (`HEAD 1e1f442c` vs working tree, 8 seeds × 20y, `HEALTH=1`)

Arms `rv-head` (`BASE=1e1f442c`) vs `rv-new`, seeds 1–8 (`TPY=360`, `YEARS=20`, `HEALTH=1`):
- **Board volatility**: median realised vol `0.2173 ± 0.0036` → `0.2145 ± 0.0022` (`-1.3%`, `t = -0.8`, within 5%);
  p10 `0.1533` → `0.1516`; p90 `0.3432` → `0.3361`; `Spearman(rvol, BusinessRisk)` `+0.849` → `+0.860`.
- **Leverage–volatility link (the point of the change)**:
  - Pooled within-firm slope of `log(rv)` on `log(L)` across `L_max/L_min > 1.25` firms (`12.4` vs `11.2` firms/run):
    `+0.328 ± 0.160` → `+1.065 ± 0.080` (`+0.737 ± 0.147`, `t = +5.0`, `n = 8`).
  - Within-firm top-tercile / bottom-tercile `L` realised vol (board median): `0.919 ± 0.028` → `0.970 ± 0.027`
    (`+0.051 ± 0.006`, `t = +7.9`).
  - Core movers (`hi-L / lo-L` realised vol): `SKUA` `1.09 → 1.92`, `TERN` `1.09 → 3.77`, `BIRD` `0.86 → 1.38`,
    `WADE` `0.87 → 1.27`, `CORM` `0.89 → 1.17`, `CASC` `0.97 → 1.33`, `IBHI` `1.08 → 1.52`.
  - BB-or-below firm-years `rv / open_vol` median: `0.999` → `0.965` overall, splitting cleanly into levered-up
    (`L > 1.05 L0`: `1.093` → `1.288`, `t = +1.9`) vs delevered (`L < 0.95 L0`: `1.008` → `0.879`, `t = -4.3`).
- **Credit & distress**: deaths `0.0 → 0.0`; reorganisations `0.62 ± 0.18` → `0.88 ± 0.61` (`t = +0.4`);
  defaulters `0.8 → 0.6` (`t = -0.3`); payment-default quarters `5.12 → 6.12` (`t = +0.2`); `icr < 1` quarters
  `101.6 → 99.2` (`t = -0.3`); drawdown `>80%` firms `6.1 → 5.4` (`t = -1.4`); ratings stable (`A 60.2→61.1%`,
  `BBB 30.6→29.8%`, `BB 7.8→7.7%`, `B 1.3→1.3%`, `CCC 0.1→0.1%`).
- **Pricing & earnings**: mispricing `>0.25` share `0.1589 → 0.1571`; EPS beat rate `0.7799 → 0.7797`;
  quarterly revenue surprise SD `0.0396 → 0.0397`.
- **Ceilings & scorecard**: `2.0 ± 0.7` firm-years/run with mean `sigma_LR > 0.6` (`4.5 ± 0.9` peak `> 0.6`,
  peak `sigma_LR = 0.738`, peak spot `cvol = 1.077`), **0 firms reaching `MAX_VOLATILITY` (1.50)**; nothing newly
  out on the board scorecard.
