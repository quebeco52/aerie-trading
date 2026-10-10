
## 2026-10-10 16 seeds x 60y, 360 tpy, net vs worth

Question: does reading net financial worth instead of GFSM net debt in the fiscal reaction, spread and reimbursement move gross debt, spread or net tax? Run: `bin/harness-batch var/harness/net_worth/run.sh 1-16 net worth` (32/32 ok, 1207 s), then `paired.py` and `lastdecade.py` on `runs/`.

```
full market, POLITICS=1, seeds paired 16, years >= 60
quantity             NET    WORTH     diff      se       t
gross debt         0.808    0.890   +0.082   0.045   +1.81
spread bp          1.018    0.172   -0.845   0.627   -1.35
net tax %         11.626   11.496   -0.130   0.041   -3.17
gfsm net debt      0.574    0.645   +0.071   0.087   +0.82
worth debt         0.170    0.234   +0.064   0.148   +0.43
fund/gdp           0.630    0.646   +0.016   0.115   +0.14
spread>0 %         4.896    2.552   -2.344   1.644   -1.43
ceiling %          0.000    0.000   +0.000   0.000    +nan
debt end           0.685    0.794   +0.108   0.079   +1.37
primary def %     -0.908   -0.864   +0.044   0.201   +0.22
gap sd %           1.582    1.600   +0.018   0.103   +0.17
tmax min net 60.00 worth 60.00, n=16
quantity            NET    WORTH     diff      se      t
gross 30-40       0.792    0.874    0.081   0.062   1.30
gross 40-50       0.739    0.861    0.123   0.069   1.77
gross 50+         0.688    0.799    0.111   0.076   1.46
worth 40-50      -0.046    0.077    0.124   0.212   0.58
worth 50+        -0.232   -0.155    0.077   0.281   0.27
fund 50+          0.915    0.941    0.025   0.225   0.11
ceiling% 50+      0.000    0.000    0.000   0.000   0.00
net   gross 50+ - gross 40-50: -0.051 (se 0.019, t -2.61), rising in 6/16
net   worth 50+ - worth 40-50: -0.185 (se 0.081, t -2.30), rising in 5/16
worth gross 50+ - gross 40-50: -0.062 (se 0.027, t -2.31), rising in 2/16
worth worth 50+ - worth 40-50: -0.232 (se 0.086, t -2.69), rising in 4/16
```

Caveats: stopped at 16 seeds by instruction. Only net tax passes |t|=2 (-0.13 pt, t -3.2). Gross debt +0.08 (t 1.8) is inside noise. Both arms fall from decade 5 to decade 6, so worth-arm gross debt is not still climbing at year 60.
