# plantgap evidence

## 2026-10-27: fundPlannedPlant (TreasuryEngine, uncommitted) vs HEAD 5766b5c4, 16 seeds x 25 y, 360 tpy

Question: does paying the unfunded planned plant from spare cash, then debt capacity, then (REITs only) shares at
P >= book close the REIT plant gap without raising failures?

Harness: `run16.sh base|fund <seed>` (autoshort probe on git-archive trees, BASE, with logging overlays
`ovr-baselog`/`ovr-fundlog` built by `mklog.py`); analysis `fund16.py base fund 1,...,16` -> `fund16_16s25y.out`.
Fund tree = HEAD + only the working-tree TreasuryEngine.php (CASC seed and StockInfo changes excluded). Outlook salt
1000+seed. Years 5+.

Key rows (fund - base, se, t): REIT mean gap 0.367 -> 0.005 (-0.362, 0.017, -21); REIT plant growth 1.86 -> 4.44%/yr
(+2.58, t 16.5); REIT shares growth +0.009 pp/yr (t 2.5); REIT DPS growth +2.47 pp/yr (t 15); REIT TSR +1.80 pp/yr
(t 10.6); REIT leverage -0.016 (KITE +0.046); board G 0.028 -> 0.006; mean |log P| -0.0005 (t -5.8); board lev
+0.0065 (t 6.1); median P/E +0.03 (t 0.7); board PP&E +0.13 pp/yr (t 2.3); plan built 68.5 -> 88.1%.
CASC/IBHI gap and growth: all |t| < 1.8, IBHI growth -0.24 (0.22) t -1.1.
Failures: deaths 2 -> 1, reorgs 5 -> 7, default tickers 8 -> 10 over 16 runs; none t > 1. The fund-only events (SKUA
s10/s14, TERN s4/s15, CASC default s5, SKYL default s2) are at firms fundPlannedPlant paid ~nothing before the event:
path divergence of the shared stream, not the mechanism.
Planned-plant equity issues: only ELDE, SHOR, KITE, PLZA (~200 small issues/run); no non-REIT issues.

Caveat: `defaults` count sweep intervals, not years (fund16.py prints them as "y<n>").
