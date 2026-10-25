# Price-vol evidence

(10-16 sections: `git show 98549819:var/harness/price_vol/price_vol_evidence.md`.)

## 2026-10-25: "realised vol 1.5x configured" at HEAD e8537d9d

Question: the idle harness reports board median realised vol 0.36 against configured 0.21 (1.5x, 76% of names
> 1.1x). Which channel carries it, and when did it appear after the 10-16 budget fix (98549819)?
Harness: `PriceVolHarnessTest.php` + `Probe.php` (RUN.md). Live macro, no politics, 10 years x 360 ticks/yr, first
year dropped, split-adjusted. Arms do not pair (the run is not reproducible on a seed): per-arm means, unpaired se.

**1. The 1.5x is a measurement artefact.** The idle `rv` sums raw tick log returns of the unadjusted price; ~0.7 splits
per name in 20 years (mostly 4-for-1, log(4)^2 = 1.92 each) carry it. `idle_split_check.py fare 1..16`
(`idle_split_check.out`, 16 seeds x 20y): board median ratio 1.556 raw -> 1.002 with splits removed; share > 1.1x
75% -> 19%. TERN 0.35-0.96 raw -> 0.35-0.86 split-free (mean ~0.45).

**2. HEAD, split-free** (`head_16s10y.out`, 16 seeds): median realised/configured 1.074 (se 0.018) at 1 tick, 1.055
(0.018) at 21, 1.074 (0.021) at 63; share > 1.1x 32-35%. Market index vol 0.158-0.164 against anchor 0.15; macro
marketVolatility mean 0.164, rms 0.170. TERN 1.36 / 1.39 / 1.46 (0.48-0.51).
Excess variance over budget at 21 ticks, board mean +0.0071 (sigma^2 0.0565; budget exceeds sigma^2 by +0.0022 from
floored names): idio+sector +0.0028 (39%; theta x the common-idio scale, mean 1.117), announcements above their 25%
cap +0.0019 (27%; 8% of names), market diffusion +0.0012 (17%; market vol above anchor), market jump +0.0011 (16%;
sampling noise, see 4), pull +0.0006, between-tick moves +0.0007 (reorganisations), flow -0.0010.
TERN at 21 ticks, +0.101 over budget (+0.114 over sigma^2): announcements +0.040 (ann EMA 0.038 vs cap 0.011),
between-tick +0.035 (reorganisation), budget over sigma^2 +0.014 (levered beta: floored 15% of ticks), market diffusion
+0.011, pull +0.006, idio +0.006.

**3. HEAD against 98549819** (`compare_n16.out`, 89 common names, 16 seeds per arm):

| metric | 98549819 | HEAD | diff (se) | t |
|---|---|---|---|---|
| h21 median ratio, raw | 1.000 (0.017) | 1.054 (0.019) | +0.054 (0.026) | 2.1 |
| h21 median ratio, market jump at model var | 1.011 (0.016) | 1.039 (0.018) | +0.028 (0.024) | 1.2 |
| h1 median ratio, market jump at model var | 1.035 (0.016) | 1.057 (0.017) | +0.022 (0.023) | 0.9 |
| macro mean marketVolatility | 0.157 (0.004) | 0.164 (0.004) | +0.007 (0.006) | 1.2 |

At 4 seeds the raw gap was the market jump's luck (realised/model jump variance 0.49-0.92 on the old tree, 0.94-2.27
on HEAD). After that, no rise past noise; no bisect run (a 3% effect cannot be bisected at 4 seeds x 5 years).

Caveats: 16 seeds, stage 48 not run. Fund impact separated by recomputing its peak impact (permanent share only).
