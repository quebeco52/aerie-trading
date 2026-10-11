# Leverage evidence

## 2026-10-11 Net-leverage recap target (L0 gross vs L1 net of idle cash)

Question: does netting idle cash out of the recap leverage ratio move operating firms toward the trade-off target,
without raising failures or emergency equity?
Harness: `LeverageHarnessTest.php` via `run.sh` (see RUN.md); SNAP levtree (no fix) vs ovrL (five fixed files).
48 paired seeds x 20 years, TPY 360, politics on. Staged 8 -> 16 -> 48 (stopped neither at 8 nor 16: BBB share
t 1.4/3.8 and ICR y19 guard t -3.5 at 16). All 96 runs ok. Table: `stage48.out` (also `stage8.out`, `stage16.out`).

| quantity | L0 | L1 | diff | se | t |
|---|---|---|---|---|---|
| nd/EBITDA y9 | 0.775 | 1.089 | +0.314 | 0.017 | 18.9 |
| nd/EBITDA y19 | 0.748 | 1.020 | +0.272 | 0.023 | 11.7 |
| net-cash share y9 | 0.131 | 0.040 | -0.091 | 0.007 | -12.3 |
| net-cash share y19 | 0.134 | 0.043 | -0.090 | 0.008 | -11.1 |
| BBB or below y9 | 0.270 | 0.351 | +0.081 | 0.011 | 7.1 |
| BBB or below y19 | 0.252 | 0.322 | +0.070 | 0.012 | 5.6 |
| median ICR y9 | 14.36 | 12.92 | -1.44 | 0.61 | -2.4 |
| median ICR y19 | 14.61 | 11.49 | -3.12 | 0.54 | -5.8 |
| median P/E y9 / y19 | 15.03 / 14.39 | 14.94 / 14.29 | -0.09 / -0.11 | 0.53 / 0.55 | ~0 |
| failures / seed | 0.042 | 0.042 | 0 | 0.042 | 0 |
| revolver draws / seed | 6.48 | 6.69 | +0.21 | 0.74 | 0.3 |
| emergency equity / seed | 13.17 | 14.96 | +1.79 | 1.66 | 1.1 |
| payment defaults / seed | 0.021 | 0.042 | +0.021 | 0.036 | 0.6 |

Caveats: L1 lands at ~1.0-1.1x median net debt/EBITDA, inside the S&P 500 benchmark band (1.0x 2011, 1.35x 2013,
~1.5x 2016). The ICR fall is the expected mechanical consequence of more debt (still ~11.5x median). Payment
defaults are counted from newsworthy-filtered treasury news lines.
