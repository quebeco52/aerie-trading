# Slump length evidence

## 2026-10-19: which of four candidates makes District slumps long and deep? (stage 8)

Question: the live 52-year run (TPY 3600) has gap < -2% in 32% of quarters, > +2% in 12%, longest run < -1.5% of
29 q, policy floor 12%. Which candidate shortens/shallows slumps, as a paired arm against HEAD 58382878?
Arms: A HEAD; B easing drag 0.5505 -> 1.2505; C rule reads today's gap; D rule sees through only a positive
productivity gap; E mainland as a 0.5-loading level part of the gap (impulse to demandShock off); F = B+C+D+E.
Harness `SlumpLengthHarnessTest.php` (see RUN.md), seeds 1-8 per arm, 52 years, TPY 360, POLITICS 1, burn 2 years.
All 48 runs completed, no non-finite values. Full table: `s8.out`.

| quantity | A level | B-A | C-A | D-A | E-A | F-A |
|---|---|---|---|---|---|---|
| share gap < -2% | 0.222 | -0.069 (0.046) | +0.006 (0.037) | -0.057 (0.034) | +0.001 (0.041) | -0.113 (0.043) t-2.6 |
| longest run < -1.5% (q) | 18.8 | -2.0 (3.8) | +3.1 (3.0) | -2.5 (2.9) | -1.3 (3.4) | -7.6 (4.1) |
| policy floor share | 0.066 | -0.027 (0.015) | +0.015 (0.023) | +0.007 (0.018) | -0.023 (0.020) | -0.036 (0.019) |
| gap mean % | -0.45 | +0.40 (0.35) | +0.10 (0.25) | +0.18 (0.26) | -0.20 (0.28) | +0.68 (0.31) |
| corr d gap, d fgap | 0.04 | | | | +0.43 (0.02) | +0.46 (0.03) |

Verdict at 8 seeds: no single arm reaches |t| 2 on the three targets. B and D lean the right way (t -1.5, -1.7 on
share < -2%); C and E are null on slump length (C raises supercore sd, t 3.3; E cuts policy sd, t -4.1, and lowers
mean inflation 0.13 pp). F is the only arm past t 2 (share < -2% -0.11, t -2.6; gap mean +0.68, t 2.2). F minus B
(best single) is inside noise on the targets (-0.044, se 0.034).
Caveats: A at TPY 360 sits below the live run (22% vs 32% below -2%, longest 19 vs 29 q, floor 7% vs 12%); seed range
of A spans 10-38%. E's level part starts from fgap's value at year 0 (not subtracted). D's policy diagnostics were
left unchanged (no test asserts their sum).

## 2026-10-19: B + D (H) and B + D + E (G) as the central bank's stance (stage 16)
Question: does B + D on its own (H) shorten/shallow District slumps against A, and what does E add (G - H)? Watch
inflation, supercore sd, policy sd/floor, recession starts, worst rgq, gap acf 13 (ringing).
Harness: SlumpLengthHarnessTest.php via run.sh, arms ovrH (MAS B, MPS D) and ovrG (MAS B+E, MPS D), built by setup.sh
(byte-identical to ovrB/ovrD/ovrF parts; sltree rebuilt identical to the s8 tree, so A 1-8 reused). Seeds 1-16, YEARS
52, TPY 360, POLITICS 1. Table: s16.out.
H-6 and H-15 crashed (NaN stock price at t=35.3 and t=14.8), so the main table pairs the 14 complete seeds; G vs A on
all 16 is appended. Paired differences, 14 seeds, mean (se):

| quantity | A level | H-A | G-A | G-H |
|---|---|---|---|---|
| share gap < -2% | 0.191 | +0.002 (0.032) | -0.040 (0.017) t-2.3 | -0.042 (0.032) |
| longest run < -1.5% (q) | 17.1 | -1.4 (2.2) | -5.0 (1.9) t-2.7 | -3.6 (2.1) |
| gap mean % | -0.26 | -0.12 (0.21) | +0.12 (0.14) | +0.24 (0.18) |
| gap sd % | 2.12 | -0.17 (0.12) | -0.40 (0.09) t-4.5 | -0.23 (0.15) |
| gap acf 13 | 0.058 | -0.001 (0.051) | -0.067 (0.050) | -0.067 (0.032) t-2.1 |
| infl mean / sd % | 2.19 / 0.86 | +0.03 / +0.04 | -0.02 / +0.00 | -0.05 / -0.04 |
| supercore sd % | 0.68 | +0.02 (0.04) | -0.05 (0.03) | -0.08 (0.035) t-2.2 |
| policy sd % / floor | 2.03 / 0.056 | -0.09 / +0.010 | -0.21 / -0.006 | -0.11 / -0.016 |
| recession starts /dec | 1.41 | +0.33 (0.22) | +0.16 (0.15) | -0.17 (0.21) |
| worst rgq % | -1.40 | -0.05 (0.14) | -0.14 (0.19) | -0.09 (0.14) |
G vs A on 16 seeds: share < -2% -0.063 (0.022) t-2.9, longest -6.3 (1.9) t-3.3, gap sd -0.47 (0.10) t-4.9.

Verdict: H alone does nothing measurable to slump depth or length (share < -2% +0.002, se 0.032; 2-se band
-0.06..+0.07, so a B-or-D-sized fall is not excluded). It trims booms (share > +2% -0.040, t-2.6) and gap persistence
(acf4 -0.11, t-2.7) and leans to more, shallower recessions (+0.33 starts/decade, t1.5). G is the arm that moves the
targets, and most of G's gain over H is E (G-H on longest -3.6, t-1.8). No ringing (acf 13 flat or lower), no
inflation cost; nothing on the watch list moves past noise for H or G.
Defect found (production, reached on H seeds 6 and 15): mid-term coalition talks (a cabinet falls, talksStartedAt !=
lastElectionAt) make ElectionForecast::sittingBudget's talks branch (src/Service/Politics/ElectionForecast.php
253-268, cabinetOdds + tally) return NaN for tariff, laborGrowth, foreignBuyerTax, greenBeltStringency, carbonPrice,
extractionStringency, reserveHomeShare, utilityRateGrant. NaN carbonPrice -> PolicyCapitalization::carbonPowerUpliftGap
-> earningsGap -> MarketEngine equity return/EPS -> price NaN (utility BIRD, H-15 t=14.75); Decimal::of throws. Found
with scratch overlay ovrHdbg via dbg.sh. Arm H only changed which seeds hit a mid-term collapse; A/B/D could hit it too.
Caveats: 14 pairs for H (dropping crashed seeds could bias if crashes correlate with slumps; H-6 and H-15 were not deep
at the crash). Stage 16 set by the caller; H's targets are neither past |t| 4 nor inside a negligible band.

## 2026-10-19: does E alone match G (B + D + E)? (stage 16, E 9-16 added)
Question: once E (mainland as a 0.5-loading level part of the gap) is in, do B + D add anything (G - E)?
Harness: SlumpLengthHarnessTest.php via run.sh, arms A, ovrE, ovrG (setup.sh, unchanged tree). E 9-16 run new; E-1
rerun on the 17:03 rebuilt tree was byte-identical to the 16:33 run, so E 1-8 reused. Seeds 1-16, YEARS 52, TPY 360,
POLITICS 1; no crashes. Table: s16e.out (`SEEDS=1-16 PERSEED=1 analyse.py A E G`). Paired, 16 seeds, mean (se):

| quantity | A | E | G | E-A | G-E |
|---|---|---|---|---|---|
| share gap < -2% | 0.207 | 0.264 | 0.144 | +0.057 (0.036) | -0.120 (0.030) t-4.0 |
| longest run < -1.5% (q) | 18.2 | 21.0 | 11.9 | +2.8 (2.3) | -9.1 (1.4) t-6.7 |
| gap sd % | 2.19 | 2.01 | 1.72 | -0.18 (0.14) | -0.29 (0.12) t-2.5 |
| gap mean % | -0.34 | -0.68 | -0.10 | -0.35 (0.21) | +0.58 (0.17) t+3.5 |
| share gap > +2% | 0.143 | 0.093 | 0.114 | -0.050 (0.020) t-2.5 | +0.021 (0.019) |
| recession starts /dec | 1.48 | 1.53 | 1.54 | +0.05 (0.18) | +0.01 (0.15) |
| infl 4q mean / sd % | 2.19 / 0.86 | 2.05 / 0.83 | 2.17 / 0.87 | -0.14 (0.05) / -0.03 | +0.12 (0.03) / +0.04 (0.05) |
| supercore sd % | 0.68 | 0.60 | 0.63 | -0.08 (0.03) t-2.6 | +0.03 (0.03) |
| policy floor share | 0.062 | 0.059 | 0.047 | -0.004 (0.017) | -0.012 (0.016) |
| gap acf 13 | 0.081 | 0.131 | 0.006 | +0.05 (0.04) | -0.12 (0.05) t-2.4 |

Verdict: E alone does not match G; it is if anything worse than A on slumps (share < -2% +0.057, t1.6; runs >= 8q
+2.0/century, t2.2) with a colder mean gap (-0.35) and inflation 0.14 pt lower. B + D on top of E is the large term
(longest -9.1 q, t-6.7), although H = B + D without E did nothing (s16). So the gain is an interaction (not isolated
here: a plausible reading is that E's colder District needs B's stronger easing and D's rule to close). Neither E nor
B + D works alone; G is the arm that moves the targets. Caveats: 16 seeds, stage stopped because both targets
in G-E pass |t| 4; E-A targets are inside noise (2-se bands include 0).
