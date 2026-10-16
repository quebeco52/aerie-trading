# Divestiture rule instrumentation

Harness: `var/harness/divestiture/DivestitureHarnessTest.php`, a fork of `new_firms/NewFirmsHarnessTest.php`
(whole-ticker live macro, POLITICS off). It swaps StockTracker's private `maEngine` for `DivestitureProbe.php`,
a subclass of MergerAndAcquisitionEngine that replays `evaluateCorporateDivestiture()` step by step through
reflection on the parent's private methods (`isInOpenDistressEpisode` before `shouldAbortDivestiture`, `openDistressEpisode` after a distress sale): same draws,
same order, so a run is identical to the production one.

- The probe must mirror the public method exactly. After any change to `evaluateCorporateDivestiture`, update the
  probe and re-check: an arm named `np*` (or `NOPROBE=1`) keeps the production engine in the tracker, then
  `python3 -I var/harness/divestiture/check_probe.py runs/<probe>-<s>.jsonl runs/np<..>-<s>.jsonl` must print 0 differ.
  Last check (r3v6 tree): seeds 1,6,8 x 12y, 287,365 values each, 0 differ, 11-51 episode quarters per seed.
  The classification never calls `isInOpenDistressEpisode` (it closes episodes); `dEp` reads the stream state.
- Every quarterly row (`final.<tk>.q[]`) gains the engine's own classification on a fresh DivestitureContext
  (`initializeDivestitureContext` + `shouldAbortDivestiture`, on a snapshot of the stream state since the abort
  check once had a side effect): `dEp dEpHeld dTcr dRet dHur dEva dDisRaw dDyRaw dDis dDy` (after the hoarder and
  cash-buffer exemptions), `dShed` (always false since isShedding() was deleted) `dBuf dScale dHoard dAbort dPE dNNI`, plus `roic roe broic gppe`.
- `sales[]` in the record: every executed sale with the context (branch, f, multiple, price, mcap, book disposed,
  debt assumed, gain, tax, return `ret`, long-run return `tcr`, hurdle, EVA), the engine's DebtHealth before
  (`ndE0 covL0 mkt0 can0 liq0`) and just after (`ndE1 mkt1`), and `pre`/`post` balance-sheet snapshots.
- Needs HEALTH=1 for the trailing `ndE` in the quarter rows.

Run script: `var/harness/divestiture/run.sh <arm> <seed>` (YEARS=20, TPY=360 default; ~90 s per seed-20y,
8 seeds in ~90 s wall). Run a stage in the foreground with a 600 s timeout; a background batch dies with the agent.

    HEALTH=1 bin/harness-batch var/harness/divestiture/run.sh 1-8 r3v6
    ARM=r3v6 python3 -I var/harness/divestiture/analyse.py 1 2 3 4 5 6 7 8 > var/harness/divestiture/r3v6_analyse.out
    python3 -I var/harness/divestiture/compare.py r3v5 r3v6 1-8 > var/harness/divestiture/r3v6_8seed_20y.out

`analyse.py` (one arm) sections: 1 classification shares and structural (seed-ROIC) distress, 2 sales by branch,
3 cascades (retained plant < 0.40 within 3y) and time to leave the dying state, 4 WADE/BIRD paths, 5 plant sold.
`compare.py A B seeds` (two arms, unpaired): classification, sales and plant sold by branch, cascades, open
episodes, distress elsewhere (defaults, reorgs, failures, loss quarters, covenant breaches, SEOs, shut-outs, ND/EBITDA
p90), board plant and market cap, named firms.
Arms on disk: r3v4d (before the fix), r3v5 (CAPM hurdle, long-run return, one distress sale per episode),
r3v6 (r3v5 minus the shedding override and the return floor; episode check its own step).
Outputs `runs/<arm>-<seed>.jsonl|.log` (ignored; ~20 MB each).
