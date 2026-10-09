# bank_credit quick-wins evidence

## 2026-10-06: loss rates on the loan share (0.78), RWA density from the loan mix, BANK_SEIZURE -> CAPITAL_BELOW_MINIMUM

Question: what the uncommitted quick-wins change (five src files vs HEAD 41b4bde) does to the six lenders' losses,
allowance, capital, payout stops, events, ROE and P/B, on the same seeds and the same macro path.

Harness: `runs/qw/BankCreditHarnessQwTest.php` (copy of `BankCreditHarnessTest.php` with the DebtEngine tap; adds
`capital_below_minimum` to the logged events, `req` = `capitalRequirement(macro)`, `ccyb` = CCyB EMA and `rwd` = RWA
density on each quarter row, `rwd` on the opening row) and `runs/qw/bootstrap.php` (fresh container caches under
`runs/qw/cache-*`). `runs/qw/run_qw.sh <rec|before|after> <seed>`:
- rec = working tree, live macro, records the path (`MACRO_RECORD`, scratchpad `qw_macro/path-<seed>.gz`, 313 MB)
- before = BEFORE tree replaying it: the working tree rsync'd to the scratchpad `wt_qwbefore` with ONLY the five
  files overwritten by `git show HEAD:<path>` (diff -rq src/config: exactly those five differ), vendor symlink, .env
  copied; so the user's uncommitted newswire work is in both arms
- after = working tree replaying it
`seq 1 16 | xargs -P 8 -I{} ./run_qw.sh rec {}`, then 32 before/after runs at `-P 8`. 16 seeds x 20 y, 360 tpy, same
`mt_srand(seed)`. 48/48 runs OK; wall time 791 s (110-154 s per run). Macro path identical in both arms on 16/16 seeds.
Opening CET1 reproduces the caller's table (LAKE 12.82 -> 13.06, ..., POOL 30.25 -> 36.92). Repo unchanged after:
md5 of the five files (`runs/qw/repo_five_files.md5`) OK, `git status --porcelain` identical (76 entries).
`python3 analyse_qw.py > runs/qw/quickwins_seeds1-16.out`.

Table: `runs/qw/quickwins_seeds1-16.out`. Headline, BEFORE -> AFTER, paired diff (se), n=16:

| lender | NCO % loans | NCO/TTC | allow % loans mean / peak | recession cost % book | CET1 mean | CET1 min | ROE mean | ROE p5 | P/B |
|---|---|---|---|---|---|---|---|---|---|
| LAKE | 0.82->0.64 (-0.18, 0.01) | 0.90 (+0.004) | 1.87->1.47 / 2.64->2.07 | 1.42->1.13 (-0.29, 0.05) | 13.43->13.67 (+0.24, 0.04) | 12.20->12.57 (+0.37, 0.11) | 20.1->20.1 (0.0, 0.1) | 9.9->11.2 (+1.4, 0.3) | 2.08 (0.00, 0.02) |
| RIVR | 0.90->0.70 (-0.19, 0.01) | 0.91 (+0.004) | 2.05->1.60 / 3.07->2.40 | 1.94->1.52 (-0.42, 0.08) | 17.50->15.34 (-2.16, 0.05) | 15.56->13.95 (-1.61, 0.23) | 15.2->15.3 (+0.1, 0.2) | 5.1->7.2 (+2.1, 0.8) | 1.36 (-0.01, 0.01) |
| PLVR | 0.72->0.57 (-0.16, 0.01) | 0.89 (+0.003) | 1.67->1.30 / 2.33->1.83 | 1.18->0.92 (-0.26, 0.04) | 13.26->16.68 (+3.42, 0.08) | 11.05->13.97 (+2.92, 0.18) | 12.8->13.0 (+0.2, 0.2) | 3.2->5.0 (+1.8, 0.8) | 1.23 (+0.01, 0.01) |
| TALN | 4.12->3.26 (-0.86, 0.02) | 0.91->0.93 (+0.013) | 6.82->5.37 / 8.85->6.99 | 4.24->3.40 (-0.83, 0.13) | 22.36->19.49 (-2.87, 0.58) | 18.28->16.22 (-2.05, 0.23) | 23.8->22.3 (-1.5, 0.7) | 9.1->10.3 (+1.2, 1.3) | 1.82->1.72 (-0.10, 0.04) |
| STRK | 8.55->6.82 (-1.72, 0.07) | 0.89->0.91 (+0.021) | 14.38->11.50 / 18.44->14.71 | 5.64->5.19 (-0.45, 0.19) | 20.34->19.08 (-1.26, 0.56) | 12.46->14.02 (+1.56, 0.67) | 8.5->18.0 (+9.5, 1.9) | -26.7->-6.7 (+20.0, 2.9) | 1.17->1.48 (+0.32, 0.05) |
| POOL | 0.61->0.48 (-0.13, 0.01) | 0.87 (+0.004) | 2.07->1.62 / 3.05->2.37 | 1.12->0.88 (-0.25, 0.09) | 35.95->42.25 (+6.30, 0.51) | 27.64->32.83 (+5.19, 0.43) | 21.4->21.2 (-0.2, 0.2) | 13.2->13.2 (+0.1, 0.3) | 1.61->1.62 (+0.01, 0.02) |

Payout-stop quarters / seed (CET1 < requirement + CCyB): 0 in both arms for LAKE, TALN, POOL; RIVR 0.06 -> 0, PLVR
0.19 -> 0 (-0.19, 0.14), STRK 2.12 -> 1.31 (-0.81, 0.88). BANK_SEIZURE / CAPITAL_BELOW_MINIMUM: 0 in every run, both
arms. Board: bankruptcies 0.12 -> 0.06 / seed (-0.06, 0.11; BEFORE STRK s9 y4.6 and FALC s15, AFTER FULM s1),
reorganisations 0.12 -> 0.25 (+0.12, 0.12), non-finite samples 0 in both arms.

Finding: dollar NCO, provisions and the allowance fall 21-22% on the four prime lenders, NCO/TTC is unchanged (both
scaled alike), and their ROE does not move: the bank's revenue target funds the TTC provision
(`CommercialBankBusinessModel.php:385`, `resolveThroughTheCycleCreditProvision`), so the lower loss rate is passed
through as reported NIM -0.20 (LAKE), -0.34 (RIVR), -0.31 (PLVR) pp (se 0.03-0.05). The capital changes follow the RWA
density: RIVR / TALN down ~2 pp, PLVR +3.4, POOL +6.3. STRK is the one lender whose economics change: ROE TTM +9.5
(1.9) pp, p5 +20 (2.9), P/B +0.32 (0.05); its recession cost falls only 8% (-0.45, 0.19), not 22%.

Caveats: tail cells (ROE p5, CET1 min, payout stops, failures) are indicative at n=16; they need 48 seeds. STRK seed 9
fails at y4.6 in BEFORE, so its BEFORE row for that seed covers 18 quarters (also why its window count differs). Without seed 9 (n=15) STRK
still shows ROE mean +7.7 (0.7), p5 +18.1 (2.4), P/B +0.33 (0.05), recession cost -0.55 (0.18).
Replay drops the board-cap feedback into the macro (the live rec runs show different deaths: TIER x3, FALC, FULM x2),
so these are paired arms on a fixed path, not two live games. Book = gross earning assets; loans = 0.78 x book.
