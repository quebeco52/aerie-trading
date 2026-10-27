# Industry shortage anatomy (Auto Manufacturers and the board)

Question: why do industry price levels sit in shortage (autos, live 10-26), and is it the roster's plant, the fringe,
the outlook surprise or the anchor? Probe `AutoShortHarnessTest.php` (read-only) logs per non-financial report the
ledger balance: Σ roster trend shares, share-weighted log(trend plant / plant) G, x - m, x - f, roster and fringe
supply, own capacity and trend plant, filed capacity gap; plus FALC/EIDR events. Outlook salt 1000+SEED.

    bin/harness-batch var/harness/autoshort/run.sh 1-4 head          # working tree, YEARS 25
    bin/harness-batch var/harness/autoshort/run.sh 1-8 ovr           # + ovr/ (prototype fix)
    python3 var/harness/autoshort/analyse.py head 1,2,3,4 5           # decomposition by industry, counterfactual P
    python3 var/harness/autoshort/leak.py head 1,2,3,4                # per-firm under-build and gate-shut share
    python3 var/harness/autoshort/falc.py head 1,2,3,4                # FALC/EIDR growth, margin, leverage, P/E
    python3 var/harness/autoshort/compare.py head ovr 1,...,8         # paired effects
    bin/harness-batch var/harness/autoshort/run.sh 1-16 ovr15         # + ovr15/ (speed 0.15)
    cd var/harness/autoshort && python3 compare16.py head ovr15,ovr 1,...,16 > speed_16s25y.out
        # speed check: auto bands, board spread/G, failures with glut/shortage tag, P/E, E/P quintile spread,
        # defaults, FALC/EIDR revenue less trend GDP (compare.py/analyse.py read runs/ relative to cwd too)

`ovr/` (stale once src changes): CapitalBudgetEngine copy with CAPITAL_ERROR_CORRECTION_SPEED 0.25 (shared by the
roster accelerator, the fringe, the entry offset and the valuation catch-up). `ovr15/`: the same with 0.15. A first prototype (the outlook revision
added one-for-one to the planned growth of roster and fringe) ratcheted plant up (irreversibility floor): rejected.
`base`: BASE=<git archive of 102d2f80 with vendor/composer copied, rest symlinked> (the live-read tree).

Settled 10-27: src now carries 0.25 (head runs in `runs/` are the old 0.062 tree; `ovr/` equals src, `ovr15/` is stale).
Results in `autoshort_evidence.md` and `speed_16s25y.out`.
