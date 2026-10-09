import json, math, os
H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'fit.py')).read()
exec(src[src.index('# --- linear algebra'):src.index('r = [zobs[k]')])
D = json.load(open(os.path.join(H, 'series_baa.json')))
zo, g, P = D['zobs'], D['g'], D['parts']; n = len(zo)
for L in (0, 4, 8, 12):
    idx = range(12, n)
    b, se, _, _, rr = ols([zo[i] for i in idx], [[1, P[i][0], P[i][2], P[i - L][3], g[i]] for i in idx])
    print(f"DSR lag {L:2d}q: dsr weight {b[3]*30:+.1f} ({se[3]*30:.1f}) [engine 30]  g {b[4]:+.2f}({se[4]:.2f})  R2 {rr:.3f}")
