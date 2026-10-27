# Plant under-build anatomy (accelerator plan vs what is built)

Question (10-27): why do some firms (REITs, CASC, IBHI) sit persistently short of trend plant at speed 0.25, and why
does EarningsEngineTest's growth-plant fixture file a ~5% gap right after its anchor?

- The fixture's ~5% gap was a test artefact (recorded revenue seeding working capital, frozen macro); fixed in
  EarningsEngineTest itself, so its probe was deleted.
- `run.sh <arm> <seed>`: autoshort's board probe with logging copies of CapitalBudgetEngine and TreasuryEngine
  (`ovrlog/`, behaviour-neutral): one JSON line per non-financial report (plan, internal build, treasury build, debt,
  maintenance, CIP, capital base, CASC's crude/crack/margin). Arms: `log` (working tree), `peck` (ovr-peck: the plan's
  remainder paid from spare cash, then borrowed within debt capacity), `peckeq` (+ a pass-through issues shares for the
  rest at P >= book).

    bin/harness-batch var/harness/plantgap/run.sh 1-4 log peck peckeq
    cd var/harness/plantgap && python3 leak2.py log 1,2,3,4 top      # where the plan goes, per firm
    python3 compare.py log peck 1,2,3,4                               # paired effects

Results: see the caller's report (10-27); ovr-* copies go stale once src changes.

Built into src 10-27 (TreasuryEngine::fundPlannedPlant, with organic capex reported as the sum of both paths);
`ovr-*` copies are now stale.

10-27 src check (fund vs HEAD): `run16.sh base|fund <seed>` runs the probe on git-archive trees in TREES (default the
session scratchpad: `pgbase` = HEAD, `pgfund` = HEAD + the new TreasuryEngine; vendor/composer copied, rest symlinked)
with logging overlays built by `mklog.py <TreasuryEngine.php> ovr-<arm>log` (adds org0, eqr). Rebuild trees first.

    bin/harness-batch var/harness/plantgap/run16.sh 1-16 base fund
    cd var/harness/plantgap && python3 fund16.py base fund 1,...,16 > fund16_16s25y.out

Results in `plantgap_evidence.md`. Delete `var/harness/cache-pg*` and the trees afterwards.
