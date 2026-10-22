# Reported-vol evidence

## 2026-10-22: reported vol gives back flow/announcement variance, deal moves in impact EMA, capped smile jumps, titan coverage boost

Question: does the uncommitted change (HEAD 13a85c10 vs working tree) move reported/realised vol toward 1, leave the
market unchanged, and shrink the 11 boosted firms' earnings surprises (expected 12-25%)?

Harness: `vol_report/FullMarketHarnessTest.php` via `run.sh base|treat`, POLITICS=1 live macro, seeds 1-8, 4 years,
360 ticks/year, warm-up 90 ticks. Output `s8y4.out` (3-seed stage `s3y4.out`).

| quantity | base | treat | diff (se) | t |
|---|---|---|---|---|
| reported/realised, median | 1.051 | 1.068 | +0.017 (0.013) | 1.3 |
| reported/realised, active decile median | 1.020 | 1.066 | +0.047 (0.028) | 1.7 |
| board index vol | 0.146 | 0.143 | -0.003 (0.008) | -0.3 |
| board index return /yr | 0.032 | 0.024 | -0.008 (0.022) | -0.4 |
| max drawdown | 0.280 | 0.248 | -0.033 (0.040) | -0.8 |
| bankruptcies / non-finite | 0 / 0 | 0 / 0 | | |
| spread bps | 1.108 | 1.107 | -0.001 (0.017) | 0.0 |
| ADV $m | 1002 | 1015 | +13 (36) | 0.4 |
| boosted mean abs surprise | 0.145 | 0.123 | -0.022 (0.006) | -4.0 |
| boosted earnings-day abs return | 0.0155 | 0.0140 | -0.0015 (0.0010) | -1.4 |
| other mean abs surprise | 0.151 | 0.146 | -0.005 (0.003) | -1.8 |
| other earnings-day abs return | 0.0177 | 0.0164 | -0.0013 (0.0005) | -2.9 |
| 30d skewness, median name | -0.351 | -0.302 | +0.049 (0.032) | 1.5 |

Caveats: live macro with the Diet, so the arms' paths diverge on a seed (pairing weak). Base median ratio is already
above 1 (a 4-year window usually realises less than the expected jump variance), so "toward 1" is not testable on the
median; the active decile moves +4.7% (t 1.7). Surprise cut for the boosted names (-15%, t -4) is the one clear effect.
