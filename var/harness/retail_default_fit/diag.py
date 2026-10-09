"""Diagnostics on fit.py's series: where the residual's persistence comes from, and the OU fit once the channels are refit."""
import json, math, os
H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'fit.py')).read()
exec(src[src.index('# --- linear algebra'):src.index('r = [zobs[k]')])
exec(src[src.index('def ar1('):src.index('print("\\n[2]')])
D = json.load(open(os.path.join(H, 'series_baa.json')))
Q, zo, ze, g, P = D['Q'], D['zobs'], D['ze'], D['g'], D['parts']
n = len(Q)
r = [zo[i] - ze[i] for i in range(n)]
print("residual by year (Q4): r, g, dsr_part, u_part")
for i, k in enumerate(Q):
    if k.endswith('-10') and int(k[:4]) % 2 == 1:
        print(f"  {k}  r {r[i]:+.2f}  g {g[i]:+.3f}  dsr {P[i][3]:+.2f}  u {P[i][0]:+.2f}  zobs {zo[i]:+.2f} zeng {ze[i]:+.2f}")
cg = sum((g[i]-sum(g)/n)*(P[i][3]-sum(p[3] for p in P)/n) for i in range(n))/n
sg = math.sqrt(sum((x-sum(g)/n)**2 for x in g)/n); sd = math.sqrt(sum((p[3]-sum(q[3] for q in P)/n)**2 for p in P)/n)
print(f"corr(g, dsr_part) {cg/sg/sd:+.2f}   (dsr_part = -30 DSRgap)")

print("\n[A] engine with the DSR channel removed (r' = r - dsr_part): ")
r2_ = [r[i] - P[i][3] for i in range(n)]
b, se, _, e, rr = ols(r2_, [[1.0, x] for x in g])
print(f"    beta_h {b[1]:+.3f} (NW4 {se[1]:.3f})  a {b[0]:+.3f}  R2 {rr:.3f}")
ar1(e, 'noDSR+g')

print("\n[B] unconstrained channel weights (u, pi, spr, dsr, g): residual AR(1)")
for use_g in (False, True):
    X = [[1.0] + list(P[i]) + ([g[i]] if use_g else []) for i in range(n)]
    b, se, _, e, rr = ols(zo, X)
    sd_e = math.sqrt(sum(x*x for x in e)/n)
    print(f"  g={use_g}: R2 {rr:.3f} resid sd {sd_e:.3f}")
    ar1(e, '  refit')

print("\n[C] engine coefficients kept, u channel halved (0.5 x 40 = 20) and DSR off:")
r3 = [zo[i] - (0.5*P[i][0] + P[i][1] + P[i][2]) for i in range(n)]
b, se, _, e, rr = ols(r3, [[1.0, x] for x in g])
print(f"    beta_h {b[1]:+.3f} (NW4 {se[1]:.3f})  a {b[0]:+.3f}  R2 {rr:.3f}  resid sd {math.sqrt(sum(x*x for x in e)/n):.3f}")
ar1(e, 'u20+g  ')

print("\n[D] first-difference check (robust to a unit-root residual): d r on d g")
b, se, _, e, rr = ols([r[i]-r[i-1] for i in range(1,n)], [[1.0, g[i]-g[i-1]] for i in range(1,n)])
print(f"    beta_h {b[1]:+.3f} (NW4 {se[1]:.3f})  R2 {rr:.3f}")
b, se, _, e, rr = ols([r[i]-r[i-4] for i in range(4,n)], [[1.0, g[i]-g[i-4]] for i in range(4,n)], lags=6)
print(f"    4q diffs: beta_h {b[1]:+.3f} (NW6 {se[1]:.3f})  R2 {rr:.3f}")
