## 2026-10-25 no-change check (working tree vs cf0e6108)

Question: does the idle-capacity channel leave today's board unchanged? Harness `IdleHarnessTest.php`, `run.sh`,
replay of a working-tree macro recording, 4 seeds x 20 years, TPY 360. Probe read-only (plain vs idle seed 1 identical).

- OUT records differ on all 4 seeds (97 firms). First divergence at t=0.094 (CANV cash), relative 1e-14: float rounding
  from growth capex rewritten as plantBase*(L*(1+x)-1), not bit-identical to plantBase*x at L=1. Chaotic growth
  afterwards (ALBT bond issue timing shifts by year 4).
- Idle arm: 31,040 firm-quarters, 5 with idle_plant_share > 0, all SPRW (Grocery Stores), max 0.0062 (seeds 2, 4).
  Min industry_price_level 0.9850 (SNDR). None below 0.95.
- Paired idle - base (n 4): end board cap +1.44tn (1.52) t 0.9; median op margin +0.001 (0.001) t 1.9;
  defaults+deaths 0 both arms; share B or worse -0.000 (0.002) t -0.2. Output in `nochange.out`.
- `ovr` (growth capex in HEAD's order): seeds 1, 3 still diverge from t=2.6-5.9 at 1e-13 (WADE, CASC capex), source
  not found; seeds 2, 4 diverge where SPRW idles. Paired ovr - base: cap +0.28tn (1.08) t 0.3; op margin t 1.4;
  B-or-worse t 1.5. Output in `nochange_ovr.out`.

The committed tree uses the `ovr` order (growth capex reduces exactly to HEAD's at L=1). `basetree-cf0e6108/` was
deleted after the run; `git archive cf0e6108` rebuilds it (RUN.md).
