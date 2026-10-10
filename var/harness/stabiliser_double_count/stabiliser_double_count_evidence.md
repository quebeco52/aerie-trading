# Stabiliser double count evidence

## 2026-10-10: demand reads netTaxStanceRate (NEW, working tree) vs netTaxRate (OLD, HEAD 4e82f2b)

Question: does dropping the lagged automatic-stabiliser swing from demand's discretionary fiscal impulse
(MacroAggregateSubsystem ~l.565) move the loop-fit moments? Harness: `loopfit_tree.sh` (loopfit.sh at Taylor 1.6/1.5,
CreditMacroProbeTest 120y + RatePathProbeTest +/-0.25, 360 tpy), 36 seeds per arm, same seeds. Table: `result.out`.

| quantity (seed mean) | OLD | NEW | NEW-OLD (se) | t | US |
|---|---|---|---|---|---|
| gap sd % | 1.930 | 2.004 | +0.074 (0.022) | +3.4 | 2.33 (0.26) / loopfit 1.63 |
| ACF 4q | .720 | .737 | +.017 (.003) | +5.6 | .57 (Bartlett .14) |
| ACF 8q | .434 | .459 | +.025 (.007) | +3.4 | .26 (.16) |
| ACF 12q | .207 | .231 | +.025 (.010) | +2.4 | .07 (.17) |
| ACF 16q | .040 | .063 | +.023 (.015) | +1.6 | .06 (.17) |
| busts, 1 per y | 17.1 | 17.2 | -0.03/century (0.22) | -0.1 | 7.7 |
| deep >4% share | 38% | 38% | | | 40% |

loopfit_rank: OLD rho .797 pi 2.55 y 1.04 sd 1.98 | J_rule 6.55 J_acf 4.08 J_sd 1.81 J_path 8.99 J_tim 1.25 | J 22.69;
NEW rho .799 pi 2.49 y 1.02 sd 2.06 | 7.07 5.19 2.74 8.80 1.33 | J 25.12.

Caveats: the effect is real but under 0.3 US se on sd and 0.15 Bartlett se on any ACF lag; J rises 2.4, mostly from
J_sd (loopfit's 1.63 target, not CBO 2.31) and J_acf. A HEAD worktree lacks the ignored policy_fit.json /
asym_fit.json, so its first rate-path run had a zero path (J_path 16.5); copied in and rerun (PATHONLY=1).
Harness bootstrap needs BASE=<tree> for a worktree, or it loads main's tests/bootstrap and vendor.
