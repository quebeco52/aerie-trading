## 2026-10-25 no-change check (working tree vs cf0e6108)

Question: does the idle-capacity channel leave today's board unchanged? Harness `IdleHarnessTest.php`, `run.sh`,
replay of a working-tree macro recording, 4 seeds x 20 years, TPY 360. Probe read-only (plain vs idle seed 1 identical).

- OUT records differ on all 4 seeds (97 firms). First divergence at t=0.094 (CANV cash), relative 1e-14: float rounding
  from growth capex rewritten as plantBase*(L*(1+x)-1), not bit-identical to plantBase*x at L=1. Chaotic growth
  afterwards (ALBT bond issue timing shifts by year 4).
- Idle arm: 31,040 firm-quarters, 5 with idle_plant_share > 0, all SPRW (Grocery Stores), max 0.0062 (seeds 2, 4).
  Min industry_price_level 0.9850 (SNDR). None below 0.95.
- Paired idle - base (n 4): end board cap +1.44tn (1.52) t 0.9; median op margin +0.001 (0.001) t 1.9;
  defaults+deaths 0 both arms; share B or worse -0.000 (0.002) t -0.2. Output in `nochange.out`.
- `ovr` (growth capex in HEAD's order): seeds 1, 3 still diverge from t=2.6-5.9 at 1e-13 (WADE, CASC capex), source
  not found; seeds 2, 4 diverge where SPRW idles. Paired ovr - base: cap +0.28tn (1.08) t 0.3; op margin t 1.4;
  B-or-worse t 1.5. Output in `nochange_ovr.out`.

The committed tree uses the `ovr` order (growth capex reduces exactly to HEAD's at L=1). `basetree-cf0e6108/` was
deleted after the run; `git archive cf0e6108` rebuilds it (RUN.md).

## 2026-10-25 (b) shortage capex fix (working tree vs 3c79f9c4)

Question: does planning growth capex on the accelerator budget, gated on returnOnNewPlant, close persistent industry
shortages without harming the board? Arms `base` (git archive 3c79f9c4) and `fix` (working tree), the macro paths of
(a) replayed, 4 seeds x 20 years, TPY 360. `analyse_shortage.py`, table in `shortage_capex.out`.

Noise reference: float-only divergence (section a, idle vs base) already moved single-firm year-20 revenue by up to
+-0.25..0.35 log and p99 price level by +0.025, so per-firm movers below ~0.3 are not attributable.

- Shortage closed: price level p95 1.029 -> 1.018 (t -6.7), max 1.116 -> 1.034 (t -3.0), share > 1.05 1.5% -> 0%.
  Year 20: LOON 1.108 -> 0.997, SKUA 1.107 -> 0.998, PHIL 1.022 -> 0.999, SGRB 1.015 -> 0.999, WEAV 1.026 -> 1.014;
  GULL and HUMM unchanged inside noise; SILC 1.004 -> 1.012 (t 3.2, small).
- Overbuild: min level 0.985 -> 0.970 (LOON, 0.949 at t 16.9 in seed 2). Idle firm-quarters 1.5 -> 15.5 per seed, all
  SPRW (max 0.034).
- Spending: capex/rev 8.32% -> 8.57% (t 2.2); median ND/EBITDA y20 1.17 -> 1.18 (t 0.4); payout unchanged.
  Leverage rises where capex rose: LOON 2.24 -> 2.98 (rising through y10-20: 2.75, 2.81, 2.98), SKUA 2.19 -> 2.58,
  SGRB 0.96 -> 1.41.
- Outcomes: cap +1.39tn (1.35) t 1.0; median op margin -0.36pp (0.14) t -2.6; B-or-worse -0.08pp (0.23).
  One new default+reorganisation: FALC (Auto Manufacturers, price level ~1.00) seed 3, 7 quarters in default, reorg
  y8.92. Its capex was 6.3% of revenue vs 4.2% at y5, net debt 102 -> 123bn vs 86-98bn in base, then a weak year.
- SKYL real revenue multiple y20 5.18 -> 6.23 (0.51) t 2.0.
- Revenue movers (log fix/base): SHRK +0.54, OWLS +0.39, HARR +0.39, TSTN +0.36, ERNE +0.32; CORV -0.62, SKUA -0.34,
  PTRL -0.25.

## 2026-10-25 (c) shortage capex fix: n=16, FALC gate probe, 40-year leverage

Same arms (base = 3c79f9c4 tree in scratchpad, deleted after; fix = working tree), seeds 5-16 recorded on the working
tree, TPY 360. Tables: `shortage_capex_n16.out`, `falc_probe.out`, `leverage_y40.out`.

A) n=16, fix - base: p99 price level 1.087 -> 1.028 (t -6.5); share > 1.05 1.95% -> 0.01% (t -7.6); capex/rev
8.26% -> 8.62% (t 6.0); median ND/EBITDA y20 1.23 -> 1.28 (+0.058 (0.024) t 2.4); p90 5.58 -> 5.58 (t 0.0); payout
0.859 -> 0.846 (t -3.7); cap +1.76tn (0.55) t 3.2; median op margin -0.45pp (0.04) t -10.7; B-or-worse +0.02pp (0.09)
t 0.2; defaults+deaths per seed 0.06 -> 0.44 (+0.375 (0.272) t 1.4).
Events: base s12 TERN reorg y6.4. fix s3 FALC reorg y8.9; s6 CASC 6q default; s10 TERN and ALBT failed (both arms hit
that downturn, base ALBT ND/EBITDA 109 and TERN CCC, survived); s11 SKUA reorg y12.3 after overshooting the balance
(price 0.977-0.99, capex/rev 0.45 in y7, ND/EBITDA 3.0 -> 5.2; base SKUA A-rated at 0.8-1.2).
Also 40y seed 2 fix: TERN reorg y8.7.

B) FALC s3 fix (probe OUT == fix OUT): applied hurdle 0.064-0.082 sits 0.025-0.03 BELOW WACC (0.089-0.114, hurdle
bias). Gate passed every quarter y2-y8, margin +0.057 (y2) falling to +0.008 (y8); return on new plant was below WACC
in y4, y7, y8, y9. Growth capex = the full accelerator budget throughout (internal funds 15-65bn never bound): 5.1-7.6
bn/yr against maintenance 13-18bn. Payouts 21-23bn/yr in y5-y6 (72-81% of NI) while net debt rose 93 -> 118bn.

C) 40y, seeds 1-2: fix LOON ND/EBITDA y20-40 2.78 -> 1.64 (s1), 2.73 -> 2.70 (s2), base 1.85 -> 1.12 and 1.66 ->
1.46. The fix price level drifts back above balance: LOON 1.000 (y20) -> 1.031 (s1) / 1.048 (s2) at y40 (base 1.24-1.30).
Board median ND/EBITDA same in both arms (1.0-1.4).

## 2026-10-25 (d) fix2: growth gates for non-lenders on WACC (ManagementProfile::growthHurdle)

`fix2` = working tree, seeds 1-16, 20y, TPY 360, same macro paths; `base` reused from (c) (3c79f9c4, same harness,
probe change read-only). Tables `shortage_capex_fix2_n16.out` and `shortage_capex_fix_n16_named.out` (old fix).

| quantity | base | fix | fix2 | fix2-base (se) t |
|---|---|---|---|---|
| defaults+failures / seed | 0.06 | 0.44 | 0.125 | +0.063 (0.143) 0.4 |
| B or worse | 0.84% | 0.86% | 0.86% | +0.01pp (0.06) 0.2 |
| median ND/EBITDA y20 | 1.23 | 1.28 | 1.28 | +0.054 (0.016) 3.4 |
| p90 ND/EBITDA y20 | 5.58 | 5.58 | 5.50 | -0.081 (0.088) -0.9 |
| board cap y20 $tn | 52.19 | 53.96 | 54.29 | +2.10 (0.54) 3.9 |
| median op margin | 22.84% | 22.38% | 22.38% | -0.46pp (0.06) -8.3 |
| capex/rev y10-20 | 8.26% | 8.62% | 8.53% | +0.27pp (0.05) 5.4 |
| p99 price level y10-20 | 1.087 | 1.028 | 1.029 | -0.058 (0.009) -6.7 |
| share > 1.05 y10-20 | 1.95% | 0.01% | 0.03% | -1.92pp (0.26) -7.5 |
| min price level | 0.983 | 0.977 | 0.978 | -0.005 (0.002) -2.2 |
| idle firm-quarters / seed | 3.0 | 15.9 | 14.6 | +11.6 (1.7) 6.9 |

Events: base s12 TERN reorg y6.4 (operator). fix2 s10 TERN reorg y4.4 and ALBT reorg y8.7 (both operator; the
downturn seed where base nearly lost both and old fix lost both). The old fix's FALC s3, SKUA s11 (both
empire_builder) and CASC s6 events are gone.
Year 20 ND/EBITDA base/fix/fix2 and price level base/fix2: LOON 2.08/2.95/2.96, 1.111/1.006; SKUA 1.93/2.56/2.66,
1.111/1.007; FALC 2.23/2.20/2.11, 1.010/1.009; SGRB 1.23/1.40/1.36, 1.015/0.998; PHIL 1.36/1.46/1.47, 1.022/0.998;
CASC 2.12/2.39/2.02, 1.005/1.005.

## 2026-10-25 (e) industry outlook 2a (working tree vs 77b848e4)

Macro paths re-recorded on the 2a working tree (`TAG=-oc`), 4 seeds x 20y, TPY 360. Arms base (77b848e4 tree in
scratchpad, deleted), off (working tree), on (`ovr_on/`, drift vol 0.08, half-life 2y, salt 1000+seed). Tables
`outlook_off.out`, `outlook_on.out`, `outlook_state.out`.

1) OFF vs base: float drift only (all 4 seeds diverge from t 0.14-0.33 at the 16th digit of expected revenue).
Moments inside noise: defaults+deaths 0.50 -> 0.25 (t -1.0), B-or-worse +0.08pp (t 1.5), cap -0.63tn (0.63) t -1.0,
op margin +0.01pp (t 0.1), price level p99 y10-20 -0.002 (t -1.8); capex/rev +0.08pp (0.02) t 3.6, about 1% of the
level, the one column past 2.

2) ON vs OFF:
- exp(x) at y20: cross-industry sd 0.33-0.42 (random walk reference 0.08 x sqrt 20 = 0.36), min 0.36-0.50, max
  1.83-2.53. No mean reversion, so dispersion keeps growing as sqrt(t).
- Belief error |b - x|: mean 0.10 (all quarters and y20), max at y20 0.23-0.33. CROP s2 believed 1.35-1.38 while the
  truth was 0.89-0.99 (y4-6).
- Zero-sum: max |sum w e^x / sum w - 1| = 8.9e-16.
- Price level min 0.972 -> 0.850, p1 0.988 -> 0.895, p99 1.026 -> 1.143, max 1.038 -> 1.247; share > 1.05 in
  y10-20 0 -> 15.5%; idle firm-quarters 14.5 -> 66 per seed.
- defaults+deaths 0.25 -> 1.75 per seed (+1.5 (0.5) t 3.0); B-or-worse 0.92% -> 1.49% (t 3.1); cap -1.20tn (0.83)
  t -1.4; op margin +0.61pp (0.28) t 2.1; realised vol (quarterly, annualised) 0.349 -> 0.348 (t -0.2); median
  current vol t -1.0; median ND/EBITDA -0.13 (t -3.3).
- Events (on): s1 WEAV failed y12.1 (Internet Retail, true demand 0.53-0.64 by y8-12, belief above it); s2 CROP failed
  y9.8 (Farm Products, truth 0.75-0.81 at y8-10); s4 TERN failed y12.4 after reorg y4.7 (Airlines, truth 0.62-0.65 in
  y6-10); s4 PELI reorg y14.1 (Healthcare Plans, truth 0.70). None of the three failing firms ever idled plant
  (prices 0.86-0.93): they lost volume against a fixed cost base. No explosions.

## 2026-10-25 (f) plant follows a falling outlook (working tree vs 112fff29)

Arms on the 2a macro paths (`TAG=-oc`), 4 seeds x 20y, TPY 360: off2 / on2 (working tree, outlook off / on), onbase
(112fff29 tree + ovr_on + salt; its OUT equals the earlier `on` on all 4 seeds, so the new need/plant probe is
read-only). Tables `outlook_f.out`, `outlook_f_events.out`, `outlook_decline.out`.

- off2 vs off: bit-identical OUT moments on all 4 seeds (every delta exactly 0).
- on2 vs onbase (n 4): defaults+deaths 1.75 -> 4.0 per seed (+2.25 (1.03) t 2.2); B-or-worse 1.49% -> 1.65% (t 0.8);
  cap -3.03tn (0.24) t -12.6; median op margin 23.1% -> 20.4% (t -10.0); capex/rev +0.16pp (t 1.4); price level min
  0.850 -> 0.840, p1 0.895 -> 0.883 (t -3.9), p99 1.143 -> 1.082 (t -10.1), max 1.247 -> 1.161; idle firm-quarters
  66 -> 113 per seed (t 3.0); realised vol t -0.4.
- on2 vs off2: defaults+deaths +3.75 (0.85) t 4.4; B-or-worse +0.73pp t 7.0; cap -4.2tn t -4.1; margin -2.1pp t -22.6.
- Events on2 (16 vs onbase 7): TERN s1 reorg y2.4 (true 1.01), CROP s1 reorg y7.6 (0.71), PELI s2 failed y4.6 (true
  0.94, belief 1.30), CROP s2 failed y6.3 (true 0.91, belief 1.34), SPRW s2 failed y12.6 (0.82), SPRW s3 failed y5.1
  (0.83), TERN s3 failed y8.4 (true 0.93, belief 1.20), TERN s4 default y7.4 (0.58), PELI s4 reorg y10.3 / failed y14.3
  (0.75 / 0.62), SPRW s4 reorg y15.6 (0.95). Most fail with belief above truth, i.e. after building on optimism.
- Declining industries (truth < 0.7): plant/need after the crossing 1.14-1.16 -> 1.04-1.07 (the rule works there);
  margin unchanged (0.18-0.20 both).
- Margin y10-20 by truth at y20: > 1.3: 27.0% -> 21.8%; 0.7-1.3: 22.5% -> 19.6%; < 0.7: 20.4% -> 20.3%. Idle
  quarters 187 -> 259 in the middle group. The loss is overbuild where the belief rose.
- Reading: the revision term (revision / 0.25 for one quarter) builds the full belief revision at once, on a belief
  that carries reading noise, while a downward revision only stops replacing wear. Upward noise builds plant,
  downward noise unwinds it slowly: a ratchet. The trend gap already reads the belief (resolveTrendCapacityGap uses
  outlook belief), so the revision adds an immediate full step on top of the 6.2%/yr correction.

## 2026-10-25 (g) calibrated outlook table (ovr_cal) vs off, working tree = 112fff29

8 seeds x 20y, TPY 360, paths `-oc` (seeds 5-8 recorded on the working tree). Table `outlook_cal.out`.

| quantity | off | cal | cal-off (se) t |
|---|---|---|---|
| defaults+failures / seed | 0.125 | 0.50 | +0.375 (0.375) 1.0 |
| B or worse | 0.96% | 1.22% | +0.26pp (0.18) 1.5 |
| board cap y20 $tn | 48.87 | 48.78 | -0.08 (0.68) -0.1 |
| median op margin | 22.47% | 22.72% | +0.26pp (0.14) 1.8 |
| capex/rev y10-20 | 8.53% | 8.35% | -0.18pp (0.05) -3.8 |
| price level min / p1 | 0.976 / 0.989 | 0.873 / 0.912 | -0.103 (0.004) / -0.077 (0.005) |
| price level p99 / max | 1.026 / 1.041 | 1.096 / 1.150 | +0.070 (0.006) / +0.109 (0.007) |
| idle firm-quarters / seed | 16.0 | 21.8 | +5.8 (3.3) 1.7 |
| glut share of industry-quarters | 0.27% | 0.36% | +0.09pp (0.05) 1.7 |
| realised vol | 0.349 | 0.351 | +0.002 (0.004) 0.6 |

Reference: `on` (0.08 everywhere, half-life 2y) had 1.75 events/seed on seeds 1-4; cal has 1.0 on the same seeds.
By category (pooled 8 seeds): median / max |b-x| and SD exp(x) y20 (random-walk ref): staple 0.013 / 0.070, 0.118
(0.112); steady 0.020 / 0.120, 0.183 (0.179); shifting 0.047 / 0.296, 0.288 (0.268); volatile 0.057 / 0.353, 0.373
(0.402); off 0 / 0, 0.040 (rescaling moves the untracked industries too).
Events: off s4 TERN default y5.1 (true 1.00). cal s3 FALC (Auto, volatile) default y7.9, reorg y9.2 (true 0.69-0.71,
belief 0.69-0.75); cal s3 SINK (Oil & Gas E&P, outlook off) failed y12.9 (true 1.03, so not outlook-driven); cal s4
TERN (Airlines, steady) reorg y6.4 (true 0.81-0.84; off had TERN default in the same seed).

## 2026-10-25 (h) catch-up in fair value (working tree vs ac4a7a68, outlook on as committed)

Arms `val` (working tree) and `head` (ac4a7a68 tree, deleted after), salt 1000+SEED, FVX=1 (read-only: val vs
valnofvx seed 1 identical without x_ columns). 8 seeds x 20y, TPY 360, paths `-oc`. Table `val_catchup.out`.
Realised vol from every tick's log return, annualised, live names with a year of ticks.

| quantity | head | val | val-head (se) t |
|---|---|---|---|
| median realised vol | 0.359 | 0.359 | +0.000 (0.001) 0.1 |
| p90 realised vol | 0.502 | 0.492 | -0.010 (0.004) -2.4 |
| share realised > 1.1 x configured | 76.4% | 75.5% | -0.9pp (1.1) -0.8 |
| board cap y20 $tn | 48.67 | 49.07 | +0.40 (0.73) 0.5 |
| median P/E y10-20 | 16.35 | 16.29 | -0.07 (0.08) -0.8 |
| defaults+failures / seed | 0.375 | 0.375 | 0.000 (0.42) 0.0 |
| B or worse | 0.99% | 1.11% | +0.11pp (0.15) 0.8 |

- Catch-up growth y10-20 (24,611 firm-quarters): mean +0.0009, cross-sectional sd 0.0034 (per seed 0.0027-0.0041),
  p1 -0.0060, p99 +0.0125.
- Volatile industries: corr(quarterly FV log change, belief revision) +0.056 in val vs +0.033 in head (8,216
  firm-quarters; per seed val +0.001..+0.113); slope 0.22 vs 0.13; sd FV change 0.169 vs 0.177, sd revision 0.044.
- Events: head s3 FALC reorg y8.4, TERN reorg y9.4; s4 TERN reorg y6.2. val s1 WEAV failed; s8 FALC reorg y14.2.
- Pre-existing, both arms: median configured vol 0.21, median realised/configured 1.56 (quarterly-price measure in
  section e gave the same 0.35), so the vol budget is already exceeded at HEAD; the catch-up term does not add to it.

## 2026-10-25 (i) Phase 5: whole-board check of the industry-outlook rework at cbe58c09

HEAD dynamics = section h `val` (OUT identical on seed 1), so val seeds 1-8 + head5 seeds 9-16 are the HEAD arm.
`pre` = git archive 3c79f9c4^ (deleted after). Paths `-oc`, 20y, TPY 360; borderline items at 8 seeds took it to 16.
40y: head5 seeds 1-4 (`-y40`, seeds 3-4 recorded on HEAD). Tables `phase5_n16.out` (items 1-6), `phase5.out` (n 8),
`phase5_gap.out` (fair value's elasticity to the gap, `gapp` arm, seeds 1-4).

1. HEAD - pre, n 16: cap y20 +1.79tn (0.64) t 2.8; median P/E y0-20 +0.22 (0.05) t 4.3, y10-20 +0.21 (0.07) t 3.0;
   realised vol 0.357 -> 0.359 t 1.5 (1.56x configured, both); defaults+deaths 0.125 -> 0.50 /seed t 1.4;
   B-or-worse 0.81% -> 1.09% t 2.9; op margin -0.18pp t -1.7; ND/EBITDA y20 1.29 -> 1.36 t 2.3; capex/rev 8.08% ->
   8.34% t 6.9; payout 0.865 -> 0.860 t -1.7; median real revenue growth 2.00% -> 2.41%/yr t 9.3.
   Events HEAD: WEAV s1 failed; FALC reorg s8, s11, failed s16; TERN reorg s10; EIDR reorg s16. pre: SKUA reorg s5,
   CASC default s5.
2. Filter as designed: RMS(b-x) = mean sqrt(v) (staple .019/.019, steady .031/.031, shifting .068/.069, volatile
   .087/.088); revision SD = sigma/2 (.014/.0125, .021/.020, .030/.030, .045/.045); SD exp(x) y20 .120 .183 .282 .423
   (ref .112 .179 .268 .402). Industry level y20 min 0.287, max 2.890; y40 min 0.113, max 2.601. Stories 17.9/yr,
   headline 4.8/yr (estimate 17 and 4.4).
3. Slope on the belief change (per-seed OLS, n 16): FV 0.20 (0.03), price 0.14 (0.03); by category FV/price staple
   .30/.21, steady .15/.05, shifting .25/.17, volatile .17/.14. Trace: filed belief 1.00, capacity gap 0.985,
   catch-up growth 0.020 per unit revision, FV/EPS -0.10 (0.04). Fair value's own elasticity to the gap (gapp):
   median 0.14, p10 0.00, p90 0.25; 27% of firm-quarters < 0.05 (exactly 0 at p10). Theory k/(s+k): 0.47 short
   (k .062, s ~.07), 0.22 overbuilt (k .02).
4. Idle y10-20 (n 16): share fq > 0.5% idle staple 1.8%, steady 0, shifting 0.1%, volatile 0.8%; volatile idle up
   to 0.500 (the substitutability cap); glut episodes staple 33 (median 4q, max 24q), volatile 11 (max 19q).
   y20-40: staple max 44q; volatile 3 episodes median 24q, max 63q, all at 0.500 (BOWR s4, Residential
   Construction, y22.6-37.4 as truth fell 1.06 -> 0.37). HUMM s2 (Internet Content, truth 0.11 at y40) idle 0.46.
5. Firm log real revenue y20 on industry x 0.67 (0.05), on belief 0.71 (0.05), 1054 firms.
6. 40y decades: cap 36 -> 58 -> 94 -> 167tn; P/E 18.2 16.2 16.8 16.7; B-or-worse ~1%, 0.4% last decade; ND/EBITDA
   1.29 1.24 1.38 1.37 (p90 5.5-5.95); capex/rev 8.1 -> 8.9%; industry min 0.66 0.53 0.40 0.32 (lowest 0.113),
   max 1.52 1.70 1.92 2.08 (highest 2.60); max |f - b| 0.35 0.47 0.49 0.60 (one seed 1.17).

## 2026-10-25 (j) catch-up added after the payout cap (working tree vs HEAD cbe58c09)

Arm `fund` (working tree, salt 1000+SEED, FVX, GAPPROBE) vs HEAD (val 1-8 + head5 9-16). Borderline at 8 seeds
(P/E t 2.5, FV slope t -1.5), so 16 seeds x 20y, TPY 360, paths `-oc`. Tables `fund.out` (n 8), `fund_n16.out`.

- Revision slopes (n 16): FV 0.201 -> 0.207, paired +0.006 (0.022) t 0.3; price 0.141 -> 0.161, +0.021 (0.024) t 0.9.
  By category FV staple .30/.28, steady .15/.25, shifting .25/.20, volatile .17/.20.
- FV elasticity to the gap, y10-20: HEAD (gapp 1-4) median 0.142, p10 0, share < 0.05 0.27; fund median 0.134, p10 0,
  share < 0.05 0.28. The dead zone did not move: the payout cap was not what zeroed it.
- Board (n 16): cap -0.82tn (0.42) t -2.0; P/E y10-20 +0.02 t 0.5; realised vol median -0.0017 t -1.9, p90 +0.002
  t 0.9; defaults+deaths 0.50 -> 0.63 t 0.5; B-or-worse +0.00pp t 0.1; ND/EBITDA +0.02 t 0.9; buybacks 3.58% ->
  3.62% of cap a year t 1.0; share issuance 0.003% of cap t -0.2; M&A deals t -0.1.
- Dead zone anatomy (fund, non-REIT/utility fq): exact-zero elasticity sits at positive gaps (median gap 0.10 vs 0.00
  elsewhere) in names like FALC, SKYL, TERN, EIDR (gaps 0.2-0.4), mostly with EPS > 0 and ROIC >= CoE (1,676 of 1,872).
  17 of 77 firms are dead > 50% of the time: 7 are utilities/REITs/investment companies (legs that do not read
  growth), DIPR/LOON/SKUA/PLZA/BELL go negative (growth below the cost of capital destroys value, correct).
  Formula bounds in Valuation::calculateIntrinsicFairValuePE: perpetualGrowthRate clamps nominal g at 6%
  (MAX_PERPETUAL_GROWTH_RATE) -> exact zero for high-growth names at a positive gap; Vasicek shrinkage keeps a firm
  weight spread^2/(spread^2 + 0.03^2) ~ 0.5-0.7 of its own multiple, so the sector prior (which ignores the gap)
  takes 30-50% of any growth sensitivity. Expected elasticity from the formula: dlnPE/dg ~ 12.7 x catch-up
  0.033 (short) or 0.016 (overbuilt) x weight 0.69 = 0.29 / 0.14, consistent with the measured median 0.13-0.14.

## 2026-10-25 (k) sector prior carries the catch-up (working tree = fund + sectorPriorYieldShift)

Arm `prior` (salt, FVX, gap probe, perpetualGrowthRate log via ovr_pgr; read-only: prior == priorplain seed 1),
16 seeds x 20y, TPY 360, paths `-oc`, vs HEAD (val 1-8, head5 9-16) and vs `fund`. Tables `prior_vs_head.out`,
`prior_vs_fund.out`, `prior_clamp.out`.

- Revision slopes: FV 0.201 (HEAD) / 0.207 (fund) -> 0.349; paired vs HEAD +0.148 (0.022) t 6.7, vs fund +0.142
  (0.019) t 7.6. Price 0.141 / 0.161 -> 0.243; vs HEAD +0.103 (0.025) t 4.1, vs fund +0.082 (0.026) t 3.1.
  By category FV / price: staple .47/.28, steady .35/.24, shifting .34/.21, volatile .35/.27.
- FV elasticity to the gap, y10-20: median 0.229, p10 0.027, p90 0.410, share < 0.05 0.15 (HEAD 0.142 / 0 / 0.27).
- Board vs HEAD: cap +0.52tn (0.46) t 1.1; P/E y10-20 +0.05 t 0.9; vol median -0.001 t -1.2, p90 +0.004 t 1.2;
  defaults+deaths 0.50 -> 0.94 per seed +0.44 (0.18) t 2.4; B-or-worse +0.03pp t 0.4; ND/EBITDA +0.01 t 0.4;
  buybacks 3.58% -> 3.61% of cap t 0.9; M&A -1.25 t -0.8. Vs fund: defaults +0.31 (0.18) t 1.8, cap +1.34tn t 2.1.
- Events prior: TERN in 7 of 16 seeds (reorg s1 y2.7, s4 y4.2, s10 y3.2, s11 y10.4; defaults s2, s7, s12), FALC in 6
  (reorg s3, s8, s11; failed s16 with EIDR). HEAD: TERN 1 seed, FALC 3. TERN's fair value already collapses to ~2
  in HEAD (s1 t2.9, CCC, survives); in prior the crash comes earlier or after more buybacks (s4 bb 3.2/1.6/2.2/1.1/1.6bn
  in y1-3, net debt 21 -> 30bn vs 21 -> 26bn in HEAD) and tips into default.
- Clamp (non-utility/REIT fq, y10-20): of 452 exact-zero elasticities, 411 (91%) have the largest perpetualGrowthRate
  input above 6% and struck at 6%; 5 at the spread cap; 36 unclamped. Of positive elasticities 5% are at the 6% cap.
  Confirmed: MAX_PERPETUAL_GROWTH_RATE is the source of the exact zeros.

## 2026-10-25 (l) a firm that won't build files no catch-up (gate-fail clamps the filed gap to min(0, gap))

Arm `gate` (working tree = prior + clamp; salt, FVX, gap probe, ovr_gate log for TERN/FALC/SKYL/EIDR; read-only:
gate == gateplain seed 1), 16 seeds x 20y, TPY 360, paths `-oc`. Tables `gate_anatomy.out`, `gate_vs_head.out`,
`gate_vs_prior.out`, `gate_tern_trace.out`.

- Growth decisions with raw gap > 0.05: TERN 78% of decisions (gate failed 24%, passed but funds below budget 6.4%,
  passed and funded 47%); FALC 52% (16.5 / 2.0 / 33); SKYL 59% (13.7 / 2.1 / 43); EIDR 37% (3.2 / 0.2 / 33).
- Distress: defaults+deaths vs HEAD 0.50 -> 1.00 per seed +0.50 (0.20) t 2.4; vs prior +0.06 (0.27) t 0.2.
  B-or-worse vs HEAD -0.12pp t -1.5; vs prior -0.16pp (0.06) t -2.6. Firms with events (seeds of 16): HEAD FALC 3,
  TERN 1, WEAV 1, EIDR 1; prior TERN 7, FALC 5, EIDR 1; gate TERN 5, FALC 4, SINK 1, SKUA 1, EIDR 1.
- Slopes: FV 0.331 (vs HEAD +0.130 (0.028) t 4.6; vs prior -0.017 t -0.9); price 0.236 (vs HEAD +0.095 t 3.0; vs
  prior -0.007 t -0.3). Gap elasticity median 0.228, p10 0.027, share < 0.05 0.15.
- Board vs HEAD: cap +0.44tn t 1.1; P/E y10-20 +0.09 (0.05) t 2.0; vol median -0.002 t -1.9, p90 -0.001 t -0.4;
  ND/EBITDA +0.00 t 0.0; buybacks 3.58% of cap both.
- TERN trace (ovr_trace, seeds 1 and 4; OUT identical to gate): every leveraged buyback (s1 y0.1-1.1, 0.7-2.3bn a
  quarter; s4 y0.9-2.9, 1.1-3.2bn) has isUnderLeveraged = 1 (net book debt / book equity below the manager target
  1.34-1.42) with ND/EBITDA 1.4-2.2 against a 3.5 covenant and ICR 3.8-5.7. The economic spread was mostly < 0.02, so
  the "priced below its multiple" leg was not what fired; the management multiple with the gap zeroed equals the
  multiple with it (filed gap 0 to 0.06 at those dates). The decider is DebtEngine's under-leveraged recapitalization
  test (book leverage), not the catch-up or the prior shift. Seed 1 first default y2.9, seed 4 y6.1 (reorg y6.4).

## 2026-10-25 (m) why TERN's events rise under prior/gate: comparative trace HEAD vs gate

Arms `cmphead` (cbe58c09 tree, deleted after) and `cmpgate` (working tree), each with its own ovr copies of
CapitalBudgetEngine and CapitalAllocationEngine logging the growth gate and buyback triggers for TERN and FALC;
OUT identical to val/head5 and gate on all 8 seeds (1, 2, 3, 4, 5, 10, 13, 16). Window = up to the first event in
either arm, else year 10. Tables `cmp_summary.out`, `cmp_detail.out`, `cmp_h3h4.out`, `cmp_identity.out`.

- TERN events: HEAD seed 10 (y2.39); gate seeds 1 (y2.89), 4 (6.14), 10 (2.89), 13 (15.64), 16 (14.14). Discordant
  seeds 4 gate-only vs 0 HEAD-only (sign test p ~ 0.125).
- Trigger, every event seed both arms: "Shut out of the bond market and forced to repay maturing debt" (rating to
  CCC, fair value at its floor ~2) -> revolver draws -> "Lenders refused a draw" (ND/EBITDA past the 3.5 covenant
  after EBITDA collapses) -> "Failed to repay maturing debt" -> default. The emergency equity raise is a rescue
  gated by the going-concern test and an 80% draw, sized on market cap.
- H1 (cheaper capital -> more building): refuted. Paired gate - HEAD over 8 seeds: gate pass share +0.003 (0.041),
  WACC +0.004 (0.007), beta +0.04 (0.10), growth capex -1.27bn (0.76) t -1.7; no "bonds for expansion" in either arm.
- H2 (recap fires longer / higher target): weak. Buybacks +3.5bn (2.0) t 1.7 all seeds, +2.3 (2.2) event seeds;
  recap-trigger quarters similar; health D/E +0.04 (0.03), +0.07 (0.03) t 2.3 on event seeds.
- H3 (same downturn, more debt into it): partly. ND/EBITDA one year before the gate event: gate 3.45 / 4.83 / 3.38 /
  11.75 vs HEAD 2.27 / 2.01 / 2.89 / 3.43 (seeds 1, 4, 10, 16). In seeds 1 and 10 HEAD was also at CCC/D in the same
  downturn (fair value at its floor 3-4 quarters); in 4, 13, 16 HEAD stayed at B.
- H4 (noise): supported. TERN's price differs by > 5% between arms from t = 0.64 in 6 of 8 seeds (1.1-1.4 in the
  others): valuation feeds order flow and the arms' paths decorrelate within a year, so which seed meets a downturn
  with TERN's EBITDA weak is redrawn. "Other borrowing" +8.7bn (2.0) on event seeds is the revolver draws after being
  shut out, a consequence.
- FALC (seeds 1, 3, gate-only events): seed 1 identical policy (no buybacks, same gate), both shut out; HEAD raised
  equity four times (2.9-9.0bn) and survived, gate raised once and failed: the rescue's draw and going-concern test.
  Seed 3: gate bought back 63bn vs 33bn (recap trigger 9 vs 7 quarters), ND/EBITDA 15.7 vs 6.5 at the event.

## 2026-10-25 (n) airline FARE_LOAD_ELASTICITY 1.5 -> 1.0 (working tree vs HEAD 314ed19a)

Arms `headn` (314ed19a tree, deleted after; OUT == gate on seeds 1-3) and `fare` (working tree), salt, FVX,
KPI log for TERN. 16 seeds unclear (events -0.25 t -1.0, CCC quarters +1.5 t 1.3), so 32 seeds x 20y, TPY 360
(seeds 17-32 recorded on the working tree). Tables `fare.out` (n 16), `fare_n32.out`.

| quantity (n 32) | HEAD | fare | fare-HEAD (se) t |
|---|---|---|---|
| TERN events / seed | 0.50 | 0.50 | 0.00 (0.20) 0.0 |
| TERN CCC-or-worse quarters | 3.44 | 3.72 | +0.28 (0.70) 0.4 |
| board defaults+deaths | 1.06 | 0.91 | -0.16 (0.17) -0.9 |
| board B-or-worse | 1.03% | 1.10% | +0.07pp (0.07) 1.0 |
| TERN mean ttm EBITDA margin | 13.64% | 14.10% | +0.46pp (0.46) 1.0 |
| TERN mean op margin | 8.91% | 9.43% | +0.51pp (0.47) 1.1 |
| TERN opening cap / P/E | 50.8bn / 10.95 | same | 0 |
| TERN median P/E y3-20 | 10.01 | 9.52 | -0.49 (0.51) -1.0 |
| TERN mean load factor | 0.839 | 0.840 | +0.0007 (0.0005) 1.4 |
| TERN realised vol | 0.508 | 0.536 | +0.028 (0.019) 1.5 |
| TERN max drawdown | 0.892 | 0.900 | +0.008 (0.012) 0.7 |
| board cap y20 $tn | 50.78 | 50.34 | -0.44 (0.35) -1.3 |
| board median vol | 0.361 | 0.361 | +0.000 (0.001) 0.5 |

TERN event seeds: HEAD 1, 4, 10, 13, 16, 23, 26, 31, 32; fare 1, 4, 11, 21, 24, 28, 29, 31.
EBITDA-margin drawdowns (> 5 pts below prior 8q peak), trough median / p10 (n), HEAD -> fare:
no oil spike, gap >= -2%: 0.096 / 0.051 (63) -> 0.095 / 0.037 (72); no oil spike, gap < -2%: 0.057 / -0.005 (40)
-> 0.085 / 0.018 (41); oil spike (crude +30% over 8q), gap >= -2%: 0.087 / -0.027 (18) -> 0.097 / 0.037 (24); oil
spike, gap < -2%: 0.082 / 0.020 (8) -> 0.042 / 0.037 (6).
