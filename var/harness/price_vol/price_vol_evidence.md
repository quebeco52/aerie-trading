# Price-vol evidence

## 2026-10-16: which channel makes names realise more than their configured vol (main 86ec7eaf, one arm)

Question: realised share-price vol above configured sigma; which channel, transient or permanent.
Harness: `PriceVolHarnessTest.php` + `Probe.php` (see RUN.md). Live macro, no politics, 4 seeds (1-4) x 10 years,
360 ticks/yr, first year dropped. Components sum to the tracker's tick total return to 5e-7. Table: `price_vol_4s10y.out`.

- The staples run's LARK 0.34 (and PHIL 0.355 on seed 2) is a measurement artefact: `staples/analyse.py` takes tick
  log returns of raw `st_px`, which carries a 4-for-1 split (one -1.38 log return). Without it LARK is 0.15-0.17.
- Vol is flat across 1/5/21/63 ticks for every name (board 0.0606 vs sigma^2 0.0550 at 21 ticks): the excess is permanent.
- Excess variance at 21 ticks, board mean +0.0056: idio+sector diffusion above theta +0.0064, systemic jump above its
  capped deduction +0.0042, V_I floor lift +0.0008, announcements +0.0006, own jump +0.0002; market factor -0.0018
  (realised market vol under the anchor), pull/covariance -0.0048. Impact (permanent and transient) is ~0 in this
  harness (impact EMA ~1e-4): no agent flow worth the name reaches it.
- Diffusion excess mechanism: the idiosyncratic state is clamped to 0.2 sigma^2 every tick (MarketEngine ~342, 461)
  while theta is struck below it. QE with Feller violated puts mass near zero; the clamp turns it into floor and lifts
  the mean. `floor_sim.php` (the engine's recursion alone, 400 years) reproduces it: PHIL state 2.23x theta with the
  floor vs 1.03x without (measured 2.3x), LARK 3.35x vs 1.15x (measured 3.3x), CORM 6.7x vs 1.0x.
- Systemic jump: uncapped beta-scaled jump variance (PHIL 0.0050) exceeds its 25%-of-V_I cap (0.0023); the overflow is
  never deducted. Floored names (HUMM, CORM, FALC) are infeasible: beta^2 x (0.15^2 + market jump var) > sigma^2.
Caveats: 4 seeds, jump variances noisy (PHIL realised jS 0.0068 vs model 0.0050). Fund impact not separated from flow.

## 2026-10-16: after the budget fix (worktree price-vol-budget, uncommitted)

Fix: systemic jump paid in V_I (no 25% cap, no theta deduction), stripped state floored at 0, QE m<=0 failsafe.
Arm `fix`, same 4 seeds x 10 years x 360 ticks, live macro; draws do not pair, so per-arm means.
`compare.py live fix > price_vol_fix_4s10y.out`.
- Board 21-tick excess vol +0.014 -> -0.001; excess variance +0.0056 -> -0.0014. Staples +0.020 -> -0.003.
- Idio state / theta 2.08 -> 1.05. Diffusion excess +0.0064 -> +0.0010; systemic jump +0.0042 -> -0.0017 (realised
  below model in these seeds).
- 20 names now floored (beta^2 (anchor^2 + jump var) >= 0.8 sigma^2), excess vol +0.018; the table lists sigma_feas.
- Left over: Real Estate +0.029, Energy +0.022, Utilities +0.017, all announcement variance above its 25% cap.
- EW index +0.087 -> +0.098/y, 21-tick vol 0.150 -> 0.142; no failures, NaN or bad prices in either arm.
