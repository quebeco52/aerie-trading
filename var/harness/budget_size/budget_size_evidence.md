## 2026-10-10: purchases 21% -> 15% of GDP

Question: does PUBLIC_PURCHASES_TO_GDP 0.15 (Kaldor multiplier scaled 0.45*share/0.21, founding net tax follows) vs 0.21 move the cycle or public debt? Arms: new = main working tree; old = HEAD worktree + main modified/untracked code, only the share line back to 0.21 (diff -r src: that one line). A = loopfit_tree.sh, 36 seeds x 120y (t>10y), 360 tpy. B = FullMarketHarnessTest live, POLITICS=1 FISCAL=1, 16 seeds x 60y, 360 tpy. Run: `bash var/harness/budget_size/all.sh` (~45 min wall with another job holding slots).

```
seeds paired: 36
quantity              OLD      NEW  NEW-OLD      se      t   US
gsd (seed mean)     1.722    1.720   -0.002   0.011  -0.16   ~2.1 (FM +0.5 for 6pp)
sd (seed mean)      2.004    2.020   +0.017   0.026  +0.64   2.33 (.26)
acf4 (seed mean)    0.737    0.739   +0.002   0.005  +0.40   .57
acf8 (seed mean)    0.459    0.462   +0.003   0.009  +0.27   .26
acf12 (seed mean)    0.231    0.232   +0.000   0.011  +0.03   .07
acf16 (seed mean)    0.063    0.057   -0.006   0.013  -0.43   .06
busts 1 per y        17.2     17.0   per century +0.08 (se 0.20, t +0.38)   7.7
deep >4% share        38%      40%   n busts 230 / 233   40%
mean depth %         3.86     3.89   4.31
B: full market, POLITICS=1, seeds paired 16, years >= 60
quantity          OLD .21  NEW .15  NEW-OLD      se       t
debt mean           0.818    0.847   +0.029   0.036   +0.81
debt end            0.714    0.760   +0.045   0.070   +0.65
share >0.90 %      42.839   38.516   -4.323   7.900   -0.55
primary def %      -0.861   -0.805   +0.057   0.181   +0.31
net tax %          17.603   11.668   -5.935   0.036 -163.01
gap sd %            1.573    1.644   +0.071   0.095   +0.75
founding pd bp      0.015   -0.003   -0.018   0.115   -0.15
```

Caveats: gsd = sd of annual (Q/Q-4) log real output pot*(1+gap_avg), quarterly averages; the US ~2.1 and the Fatas-Mihov +0.5pp are the caller's figures, not measured here. B arms drift apart over 60y (debt se 0.036), so B only bounds debt effects to about +-0.07 of GDP. Founding row is tick 1 of 360.

