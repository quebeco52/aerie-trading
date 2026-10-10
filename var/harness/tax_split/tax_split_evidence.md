# Tax split evidence

## 2026-10-10: corporate rate on the Diet law only, stabiliser + stance on netTaxRate

Question: NEW (working tree: stabiliser and debt stance ride `netTaxRate`, corporate rate follows only the Diet's
shift) vs OLD (`patch_old.py`: stabiliser and stance back on the corporate rate, old revenue lines). Targets named
before the run: (1) gap mean/sd, debt mean; (2) slope and correlation of YoY board trailing-NI growth on the gap,
corp-rate sd; (3) annualised quarterly log vol of float cap and total cap.

Harness: FullMarketHarnessTest.php, live macro, POLITICS=1, BOARD_NI=1, 60 years, TPY 360, first year dropped.
Run: see RUN.md. Stage 8 then 16 seeds per arm; stopped at 16 (the cap set by the question).

Stage 8:
```
n=8 paired seeds, 60 years, first 1y dropped
quantity               NEW       OLD   NEW-OLD       se      t  range(diff)
gap mean           -0.0017   -0.0040   +0.0024   0.0025   0.95  [-0.0083, +0.0102]
gap sd              0.0158    0.0168   -0.0010   0.0016  -0.61  [-0.0103, +0.0037]
debt mean           0.8057    0.8176   -0.0119   0.0536  -0.22  [-0.2271, +0.2053]
corp rate sd        0.0058    0.0086   -0.0028   0.0008  -3.38  [-0.0056, +0.0019]
corp rate mean      0.1706    0.1698   +0.0008   0.0012   0.66  [-0.0032, +0.0072]
net tax sd          0.0042    0.0000   +0.0042   0.0002  24.27  [+0.0036, +0.0051]
NI yoy beta         3.4074    2.8976   +0.5098   0.3597   1.42  [-1.3566, +2.0889]
NI yoy corr         0.4182    0.3398   +0.0785   0.0310   2.53  [-0.0132, +0.2269]
NI yoy sd           0.1300    0.1421   -0.0121   0.0133  -0.91  [-0.0690, +0.0454]
float vol ann       0.1513    0.1513   +0.0001   0.0026   0.03  [-0.0100, +0.0090]
cap vol ann         0.1581    0.1578   +0.0004   0.0024   0.16  [-0.0112, +0.0071]
```
Stage 16 (reported):
```
n=16 paired seeds, 60 years, first 1y dropped
quantity               NEW       OLD   NEW-OLD       se      t  range(diff)
gap mean           -0.0021   -0.0024   +0.0002   0.0014   0.17  [-0.0083, +0.0102]
gap sd              0.0157    0.0158   -0.0002   0.0010  -0.16  [-0.0103, +0.0059]
debt mean           0.8245    0.7852   +0.0393   0.0346   1.14  [-0.2271, +0.2211]
corp rate sd        0.0053    0.0079   -0.0026   0.0006  -4.41  [-0.0056, +0.0019]
corp rate mean      0.1694    0.1698   -0.0004   0.0010  -0.36  [-0.0092, +0.0072]
net tax sd          0.0042    0.0000   +0.0042   0.0002  23.79  [+0.0033, +0.0055]
NI yoy beta         3.1731    2.7034   +0.4697   0.2041   2.30  [-1.3566, +2.0889]
NI yoy corr         0.4030    0.3332   +0.0698   0.0204   3.42  [-0.0845, +0.2269]
NI yoy sd           0.1243    0.1280   -0.0037   0.0082  -0.46  [-0.0690, +0.0454]
float vol ann       0.1535    0.1506   +0.0029   0.0025   1.15  [-0.0100, +0.0250]
cap vol ann         0.1601    0.1566   +0.0035   0.0023   1.52  [-0.0112, +0.0214]
```
Caveats: the arms diverge on the same seed within the first year (the macro path is not identical), so pairing buys
little on the gap and debt. NI growth is nominal TTM, YoY; quarters where a firm died in the window are dropped.
