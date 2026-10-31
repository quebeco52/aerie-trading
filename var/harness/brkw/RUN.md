# BRKW (Breakwater Trust): book, beta, valuation and payouts

Question family: does the investment holding company's price, beta, EVA and capital allocation behave sensibly.

- Harness: `BrkwHarnessTest.php` on `var/harness/lib/BoardRun.php` (live macro, no politics). Read-only probe (checked:
  `probe0` arm, seed 1, gives an identical OUT record). `BRKW_OUT` rows:
  - `kind q` at each quarter end: price, perceived fair value (`pfv`, the pricing anchor), shares, NAV (`eq`), listed
    stakes carrying, stake market value and marked book per share (ledger), `marked_share`, fair equity value, levered
    and asset beta, `coe` = CAPM(equityRiskFreeRate, levered beta, BASE ERP) without the debt-rate floor, currentRoic,
    roicTtm, debt, trailing NI;
  - `kind rep` per BRKW report: headline EVA, filed ROIC, NI, dividends, buybacks $, treasury, debt, NAV, price, shares;
  - `kind ev`: every BRKW event, every board DIVESTITURE and every " executed a $" deal.
- Run script: `run.sh <arm> <seed>`; arms `base` (BASE=<tree>, HEAD 907746ce), `fix` (working tree 10-30), `bb`
  (working tree 10-31, buyback fix), `pay` (10-31, bb + target_payout_ratio 0.60), `rw` (10-31, anchor-stake rework; base = HEAD 93fde30b), `rw2` (10-31, + whole holding return, record-date
  dividends, charter deals), `rw3` (10-31, rw2 + MIN_TRADABLE_FLOAT 0.10), `probe0` (working tree, no BRKW_OUT). All arms' runs are in `runs/`; a new
  working-tree arm needs a new name in the `case` line of run.sh. YEARS (25), TPY (360). Outputs `runs/<arm>-<seed>.{jsonl,brkw.jsonl,log}`. ~2.5 min per 25-year run.
- BASE tree: `git archive HEAD | tar -x -C <scratchpad>/tree-head`, `ln -s <repo>/vendor <tree>/vendor`; delete the
  tree and `var/harness/cache-tree-head` after.
- Batch: `BASE=<tree> bin/harness-batch var/harness/brkw/run.sh 1-4 base fix && python3 var/harness/brkw/analyse.py 1-4
  base fix > var/harness/brkw/<name>.out`
- Three arms: `bin/harness-batch var/harness/brkw/run.sh 1-4 bb && python3 var/harness/brkw/analyse3.py 1-4 base fix
  bb > var/harness/brkw/<name>.out` (means per arm, last arm per seed, paired C - B; imports analyse.py).
- `analyse.py <seeds> A B`: BRKW per seed and paired B - A (beta, P/B, price/anchor, ROIC, CoE, EVA, payout, debt,
  cash, split-adjusted share ratio, divestitures, acquisitions, buybacks at P/B > 1, div/NI, bb/NI, buyback firing share and median P/B at firing, cash
  at y10/y20/max and cash/NAV, debt rises in quarters with no BRKW deal (recap bond events are not recorded, so this is
  the proxy)), board cap/deaths/reorgs/defaults,
  and each firm's end-cap log ratio.
- Caveat: a change in BRKW's decisions shifts the shared firm stream, so other firms do not pair across arms (median
  firm |end-cap log ratio| 0.18 between arms); judge the board on aggregates only.
- Payout extras: `python3 var/harness/brkw/payx.py 1-4 bb pay` (split-adjusted DPS path, cuts > 2%, quarters with
  div > 2x NI, div > NI with a debt rise and no deal, acquisitions count/size/cash share, deals at output gap < -2%).
  `brkw_pay_4s25y.out` = `analyse.py 1-4 bb pay` + `payx.py 1-4 bb pay`.
- Anchor-stake rework: `BASE=<tree> bin/harness-batch var/harness/brkw/run.sh 1-4 base rw && (python3
  var/harness/brkw/analyse.py 1-4 base rw; python3 var/harness/brkw/payx.py 1-4 base rw; python3
  var/harness/brkw/anchor.py 1-4 base rw) > var/harness/brkw/brkw_rw_4s25y.out`. `anchor.py` reads the q rows' `held`
  (fraction, float, price per held firm), `nav_sop` (engine NAV), `dtax`, the rep rows' NI split (`ebit`, `int_inc`,
  `divrec`) and the `end` row (cap-return vol for BRKW/CBIL/ALCA/board, dividends collected vs paid on blocks, tenders).
  Both trees run the same harness (method_exists guards for HEAD). ~2.7 min for 8 runs.
- rw2: `bin/harness-batch var/harness/brkw/run.sh 1-4 rw2 && python3 var/harness/brkw/rw2.py 1-4 base rw rw2 >
  var/harness/brkw/brkw_rw2_4s25y.out` (means of every analyse/payx/anchor quantity per arm, paired C-B and C-A, plus
  rep-row `spread` = allocator trueReturn + holding return - hurdle and `recv/paid@record` from the end row's
  `paid_on_blocks_record`). Probe check: `run.sh probe0 1`, OUT record equal to `rw2-1` except `secs`.
- rw3: `bin/harness-batch var/harness/brkw/run.sh 1-4 rw3 && (python3 var/harness/brkw/rw2.py 1-4 rw rw2 rw3; python3
  var/harness/brkw/rw3x.py 1-4 rw2 rw3) > var/harness/brkw/brkw_rw3_4s25y.out`. `rw3x.py`: tender tax (end row
  `tender_tax`, ledger rule on the previous tick-end book; from rw3 on), ALBT tick vol, quarterly cap-return vol of
  ALBT/CBIL/ALCA from the OUT record (works on any arm), y25 lowest held float. ~2.4 min for 4 runs.
- rw4 (10-31, rw3 + held firms redeem pro rata, `AnchorHoldings::redeemsProRata()`): `bin/harness-batch
  var/harness/brkw/run.sh 1-4 rw4 && (python3 var/harness/brkw/rw2.py 1-4 rw2 rw3 rw4; python3 var/harness/brkw/rw3x.py
  1-4 rw3 rw4; python3 var/harness/brkw/rw4x.py 1-4 rw3 rw4) > var/harness/brkw/brkw_rw4_4s25y.out`. `rw4x.py`: held-firm
  quantities from the OUT record (split-adjusted EPS/DPS CAGR, TSR, price path, cash, redemption/price) and the
  redemption checks from `hrep`/`red`/`redtick` rows and end-row `red_*` (receipt vs fraction x cash, tax). ~2.3 min.
