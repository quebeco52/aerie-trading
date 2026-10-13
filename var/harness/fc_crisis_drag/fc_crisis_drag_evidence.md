# fc_crisis_drag evidence

## 2026-10-13 creditCrisisDrag mean on working tree (mainland AR(2) refit + Swiss trade VA uncommitted)

Question: time-average of `MacroState::creditCrisisDrag` vs `KALDOR_CRISIS_DRAG_COMPENSATOR` = 0.0155.
Harness: CreditMacroProbeTest via `fc_crisis_drag/run.sh`, arm base only, 120 y per seed, TPY 180, 5-y burn-in dropped.
Seeds 1-36 (as asked), extended to 1-48 (protocol ceiling; cost 15 s). Wall: 39 s + 15 s.

| quantity | n=36 | n=48 |
|---|---|---|
| mean drag (/yr) | 0.01272 (se 0.00108) | 0.01258 (se 0.00094) |
| net push drag - 0.0155 | -0.00278 | -0.00292 |
| t (0.0155 - mean)/se | 2.58 | 3.13 |
| crises / 100 y | 3.43 (0.24) | 3.46 (0.20) |
| impulse per crisis | 0.0470 (0.0027) | 0.0460 (0.0023) |
| creditToGdpGapEma at onset | 0.033 (0.0046) | 0.031 (0.0037) |
| renewal check rate x impulse / 0.12 | 0.0135 | 0.0134 |

Per-seed range of mean drag 0.000-0.032 (one seed had no crisis in 115 y). Tables: `drag_mean.out`, `drag_mean_48.out`.
Caveats: equity-wealth loop off (no market cap passed); container politics as wired in the test env; seed means
treated as independent (se across seeds).
