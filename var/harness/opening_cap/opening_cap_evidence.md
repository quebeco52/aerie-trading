# opening_cap evidence

## 2026-10-05: SovereignFundSubsystem::BOARD_OPENING_CAP_USD re-measured after adding PLVR

Question: the board's total market value (USD) over the first quarter, as the constant's docblock says
(FullMarketHarnessTest, first quarter, seeds 11-12), with Plover Savings Bank (PLVR) on the board and without it.
The constant is 19.9e12.

Harness: `OpeningCapTest.php`, a cut-down copy of `../FullMarketHarnessTest.php` (same container, in-memory Redis,
real `app:market-seed`, live macro fed the previous tick's board cap, MarketOperator every tpy/24 ticks). It runs the
first quarter (90 ticks at 360 tpy) and records `StockTracker::updateStocks()['total_cap']` every tick, plus each
firm's price x shares at tick 1 and at the quarter's end. The no-PLVR arm seeds from `ovr_noplvr/Data/InitialMarket.php`
(the working tree's file with the PLVR entry cut, loaded through the bootstrap's `OVR` autoloader; 78 stocks vs 79).
- `run.sh with|without <seed>`, about 2 s per run. `python3 analyse.py > opening_cap.out`.

Seeds 11-12 (the docblock's) and 1-16 for a standard error. Not paired: removing a firm shifts the order of the
random draws, so every other firm's path decorrelates from tick 1. The with-without board difference is therefore
PLVR's value plus board noise; PLVR's own price x shares is the clean delta.

Table: `opening_cap.out`. Headline (USD T):

| quantity | with PLVR | without | difference (se) | n |
|---|---|---|---|---|
| Q1 mean, seeds 11-12 | 20.63 (11: 20.25, 12: 21.00) | 21.23 (21.38, 21.08) | -0.60 (0.52) | 2 |
| tick 1, seeds 11-12 | 21.13 (21.09, 21.17) | 20.66 (20.61, 20.72) | +0.47 (0.01) | 2 |
| Q1 end, seeds 11-12 | 20.67 | 21.79 | -1.12 (1.70) | 2 |
| Q1 mean, seeds 1-16 | 21.24 (0.17) | 21.60 (0.24) | -0.36 (0.30) | 16 |
| tick 1, seeds 1-16 | 20.98 (0.03) | 20.77 (0.03) | +0.21 (0.04) | 16 |
| PLVR price x shares, tick 1 | 0.236 (0.001) | | | 16 |

Caveats: the Q1 mean has a seed sd of ~0.7T, so two seeds cannot resolve a 0.24T firm; the tick-1 value is the
steadiest reading (sd 0.12T). Both arms sit above 19.9 by about 1T even without PLVR, so the board has grown since
the constant was set (other working-tree and committed changes), not only by PLVR.

## 2026-10-13: titan cut (LAKE ×0.55, SWAN ×0.5, SAFE ×0.6)

The harness had rotted: StockTracker moved to `App\Service\Market\Pricing` and MarketOperator is gone. Fixed the
import and dropped the operator call (FullMarketHarnessTest runs without it too); cleared `cache/test`.
Arms `base1013` (tree before the cut) and `cut1013`, seeds 1-16, same 83 firms, so the seeds pair.

| quantity (USD T) | base | cut | paired diff (se) |
|---|---|---|---|
| tick 1 | 23.656 (0.048) | 18.871 (0.033) | -4.785 (0.019) |
| Q1 mean | 23.628 (0.261) | 18.844 (0.187) | -4.784 (0.093) |

Q1 end, mean of 16: LAKE 5.549 -> 3.073 (x0.554), SWAN 3.139 -> 1.606 (x0.511), SAFE 1.726 -> 1.040 (x0.602);
BRKW and WEAV move <1.5%. The constant was 21.2 before the cut, already 2.4T stale (EGRT, PELI and others added
since 10-05). Set to 18.8e12.

## 2026-10-13: Storm Petrel (PTRL) and Nightjar Private Bank (NJAR) added

Arm `newfirms1013` (85 firms), seeds 1-16, OVR=`ovr_newfirms`: the working tree's roster with RIVR held at its
committed size (an uncommitted RIVR resize was in the tree and is not part of this change). Not paired with
`cut1013`: two more firms shift every firm's draws from tick 2.

| quantity (USD T) | cut1013 (83) | newfirms1013 (85) |
|---|---|---|
| tick 1 | 18.871 (0.033) | 19.153 (0.042) |
| Q1 mean | 18.844 (0.187) | 19.631 (0.196) |

Q1 end, mean of 16 ($B): PTRL 215.2 (6.9), NJAR 52.2 (1.6). Tick 1 moves +0.28T, the two firms' value; the Q1-mean
gap (+0.79T) is that plus unpaired board noise (~2 se). Constant set to 19.6e12.

## 2026-10-14: WEAV cut ×0.29 and Jackdaw Bazaar (JDAW) added

Arm `jdaw1014` (86 firms), seeds 1-16, no OVR: the main working tree as is (also holds the uncommitted FICC desk and
TIPS fields, which do not touch seed caps). `run.sh` now takes any arm name and launches through `bin/php-slot`:
`bin/harness-batch var/harness/opening_cap/run.sh 1-16 jdaw1014 && python3 var/harness/opening_cap/analyse_1014.py`.
Not paired with `newfirms1013`: the roster differs. Table: `opening_cap_1014.out`.

| quantity (USD T) | newfirms1013 (85) | jdaw1014 (86) | diff (se) |
|---|---|---|---|
| tick 1 | 19.153 (0.042) | 19.226 (0.033) | +0.074 (0.053) |
| Q1 mean | 19.631 (0.196) | 19.402 (0.206) | -0.229 (0.284) |

Q1 end, mean of 16 ($B): WEAV 573.2 -> 189.1 (4.6), JDAW 231.3 (6.8). Seed values WEAV 553.9 -> 177.1, JDAW 222.6:
the two firms net -0.15T at seed, but tick 1 moves +0.07T, so ~0.2T of board drift came from other changes since
10-13 (committed 948aa08 and the tree). The Q1-mean difference is inside its noise. Constant 19.6e12 -> 19.4e12.

## 2026-10-14: JDAW asset-light retune (roic 0.35, capex_ratio 0.45, payout 0.10, capital ~$28B)

Arm `jdaw2x1014`, seeds 1-16, unpaired against `jdaw1014` (`analyse_1014b.py`, `opening_cap_1014b.out`). Board Q1 mean
19.418T (0.132) vs 19.402T (0.206); JDAW Q1 end 242B (vs 231B), WEAV 184B. Constant stays 19.4e12.

## 2026-10-16: Skua Mobile (SKUA), Dipper Generation (DIPR), Kittiwake Harbour (KTWK) added

Arm `r3_1016` (89 firms): the working tree as is. Arm `head1016` (86 firms): `git archive HEAD` (0da5644b) at
`base/head0da5644b` with a vendor symlink, same seeds 1-16, TPY 360. Seed caps of the 86 shared firms are identical
in both arms. `bin/harness-batch var/harness/opening_cap/run.sh 1-16 r3_1016 && BASE=<abs path>/var/harness/opening_cap/base/head0da5644b
bin/harness-batch var/harness/opening_cap/run.sh 1-16 head1016 && python3 -I var/harness/opening_cap/analyse_1016.py`.
Table: `opening_cap_1016.out`.

| quantity (USD T) | head1016 (86) | r3_1016 (89) | paired diff (se) |
|---|---|---|---|
| tick 1 | 20.001 (0.032) | 20.145 (0.040) | +0.144 (0.034) |
| Q1 mean | 20.005 (0.219) | 20.300 (0.203) | +0.295 (0.321) |

Q1 end, mean of 16 ($B): SKUA 20.1 (0.5), DIPR 35.3 (1.0, seed 30.5: +16% in the first quarter), KTWK 42.2 (1.4),
LOON 151.4 (seed 156.8). Tick-1 gain net of the three firms: +0.05T (0.03). HEAD alone already sits at 20.0T, so the
0.6T over the 19.4e12 constant came from commits after 10-14, not from this tree. Recommend 20.3e12.

## 2026-10-16 (b): fixed tree, arm `r3v2_1016`, seeds 1-16

`bin/harness-batch var/harness/opening_cap/run.sh 1-16 r3v2_1016 && B=r3v2_1016 python3 -I var/harness/opening_cap/analyse_1016.py`
(table `opening_cap_1016b.out`; HEAD arm `head1016` reused). Board tick 1 20.176T (0.024), Q1 mean 20.332T (0.215);
vs HEAD +0.175T (0.040) and +0.327T (0.307); vs r3_1016 +0.03T. Q1 end ($B): SKUA 22.4 (0.6, seed 23.4), DIPR 35.8 (1.2,
seed 33.0), KTWK 43.3 (1.4), LOON 153.2. Recommend 20.3e12 (unchanged from (a)).

The OVR seed copies (`ovr_newfirms/`, `ovr_noplvr/`) were deleted 2026-10-22: they froze InitialMarket as of 10-18.
`git checkout 2f8bc547 -- var/harness/opening_cap/ovr_newfirms var/harness/opening_cap/ovr_noplvr` restores them.

## 2026-10-25: AVCT added, GDWT x2.5 (working tree) vs HEAD 301da484

Arm `r4_1025` (92 firms): working tree. Arm `head1025` (91 firms): `git archive HEAD` at `base/head301da484` with a vendor *symlink* (a copied vendor fails: Composer's autoloader class is declared twice, since phpunit loads main's vendor first; the bootstrap prepends BASE's src, so the symlink is safe here). Seeds 1-16, TPY 360, one quarter. `bin/harness-batch var/harness/opening_cap/run.sh 1-16 r4_1025 && BASE=<abs>/var/harness/opening_cap/base/head301da484 bin/harness-batch var/harness/opening_cap/run.sh 1-16 head1025 && python3 -I var/harness/opening_cap/analyse_1025.py` (paired by seed). Table `opening_cap_1025.out`:

```
quantity                         head1025          r4_1025   paired diff (se)
board tick 1, $T           21.567 (0.023)   21.786 (0.029)    +0.218 (0.040)
board Q1 mean, $T          22.166 (0.186)   22.026 (0.194)    -0.140 (0.314)
board Q1 end, $T           22.620 (0.396)   22.132 (0.378)    -0.488 (0.623)
GDWT $B: seed 61.8 -> 154.5 (0.0); tick1 62.0 -> 154.7 (0.3); q1_end 64.3 -> 157.4 (3.3)
TSTN $B: seed 16.0 -> 16.0 (0.0); tick1 15.9 -> 16.0 (0.1); q1_end 15.4 -> 15.7 (0.4)
AVCT $B: seed - -> 122.8 (0.0); tick1 - -> 122.6 (0.2); q1_end - -> 123.2 (2.8)
n_stocks 91 -> 92, n=16 per arm
```

Tick-1 gain +0.218T (0.040) = AVCT 122.6B + GDWT's +92.7B. HEAD alone already opens at 21.57T and averages 22.17T over Q1, so most of the gap to the 20.3e12 constant came from commits after 10-16. Q1 mean: working tree 22.03T (0.19); HEAD Q1 mean + paired tick-1 gain 22.38T. Recommend 22.0e12 (a 22.2e12 midpoint is inside the noise).

## 2026-10-25 (b): BELL (Bellbird Media, Broadcasting) added (working tree) vs HEAD 3bf3f53b

Arm `bell_1025b` (93 firms): working tree. Arm `head1025b` (92 firms): `git archive HEAD` at `base/head3bf3f53b` with a
vendor symlink. Seeds 1-16, TPY 360, one quarter, paired by seed. `cache/` deleted first (new model class).
`bin/harness-batch var/harness/opening_cap/run.sh 1-16 bell_1025b && BASE=<abs>/var/harness/opening_cap/base/head3bf3f53b
bin/harness-batch var/harness/opening_cap/run.sh 1-16 head1025b && python3 -I var/harness/opening_cap/analyse_1025b.py`
(about 10 s per arm). Table `opening_cap_1025b.out`:

```
quantity                        head1025b       bell_1025b   paired diff (se)
board tick 1, $T           21.786 (0.029)   22.058 (0.029)    +0.272 (0.045)
board Q1 mean, $T          22.174 (0.197)   21.884 (0.183)    -0.290 (0.179)
board Q1 end, $T           22.481 (0.349)   21.768 (0.221)    -0.713 (0.323)
BELL $B: seed - -> 285.7 (0.0); tick1 - -> 285.3 (0.9); q1_end - -> 269.1 (6.5)
LYRE $B: seed 78.6 -> 78.6 (0.0); tick1 78.6 -> 78.5 (0.2); q1_end 81.2 -> 78.9 (1.8)
n_stocks 92 -> 93, n=16 per arm
```

HEAD tick 1 (21.786T) equals 10-25's working-tree arm `r4_1025` exactly, so the base is the board measured then. Tick-1
gain +0.272T = BELL's 285B less ~13B elsewhere. The Q1 mean and end fall, but the arms diverge within the quarter (the new
firm shifts the shared draw stream): per-seed Q1-mean differences run -1.5T to +0.8T, so pairing buys little after tick 1
and -0.29T (t -1.6) is noise. BELL opens at 286B against the ~195B it was sized for (+46%); see `media/media_evidence.md`.
Estimators of the Q1 mean: working tree direct 21.88T (0.18); HEAD Q1 mean + paired tick-1 gain 22.45T (0.20).
Recommend keeping 22.0e12: inside the noise of both, and if BELL is re-sized to ~195B the tick-1 gain falls to ~0.18T.
