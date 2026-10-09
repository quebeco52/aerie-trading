"""CRE: does a commercial property price gap explain CRE delinquency (DRCRELEXFACBS) beyond the corporate cycle?
Z_obs: Vasicek inversion at rho 0.12, PD rescaled to mean 0.025 (Z units comparable to the retail fit).
Price: Z.1 BOGZ1FL075035503Q (commercial real estate price index) / CPI; gap = ln(EMA_0.25y / EMA_5y(EMA)), built like the house gap.
Corporate cycle: output gap (GDPC1/GDPPOT - 1) and the Gilchrist-Zakrajsek spread (FRED's ICE HY OAS history is truncated)."""
import csv, json, math, os
from statistics import NormalDist
H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'fit.py')).read()
exec(src[src.index('# --- linear algebra'):src.index('r = [zobs[k]')].replace('def ols', 'def ols'))
N = NormalDist(); RHO = 0.12
rd = lambda p, c: {r['observation_date'][:7]: float(r[c]) for r in csv.DictReader(open(p)) if r[c] not in ('', '.')}
crep = rd(os.path.join(H, 'f3', 'z.zip'), 'BOGZ1FL075035503Q')
dq = rd(os.path.join(H, 'f2', 'quarterly,_end_of_period.csv'), 'DRCRELEXFACBS')
gdp = rd(os.path.join(H, '..', 'us_gdpc1.csv'), 'GDPC1'); pot = rd(os.path.join(H, '..', 'us_gdppot.csv'), 'GDPPOT')
cpi = {}
for r in csv.DictReader(open(os.path.join(H, 'monthly.csv'))):
    if r['CPIAUCSL']:
        y, m = int(r['observation_date'][:4]), int(r['observation_date'][5:7]); q = f"{y}-{(m-1)//3*3+1:02d}"
        cpi.setdefault(q, []).append(float(r['CPIAUCSL']))
cpi = {k: sum(v) / len(v) for k, v in cpi.items()}
gz = {}
for r in csv.DictReader(open(os.path.join(H, '..', 'ebp.csv'))):
    m, d, y = r['date'].split('/'); q = f"{y}-{(int(m)-1)//3*3+1:02d}"
    gz.setdefault(q, []).append(float(r['gz_spread']))
gz = {k: sum(v) / len(v) / 100 for k, v in gz.items()}
wm = lambda tau: 1 - math.exp(-1 / 12 / tau)
he = ht = None; cg = {}
for k in sorted(crep):
    if k not in cpi or k < '1975-01':
        continue
    p = crep[k] / cpi[k]
    for _ in range(3):
        he = p if he is None else he + wm(0.25) * (p - he)
        ht = he if ht is None else ht + wm(5.0) * (he - ht)
    cg[k] = math.log(he / ht)
Q = sorted(k for k in dq if '1991-01' <= k <= '2019-10' and k in cg and k in gz and k in gdp and k in pot)
mu = sum(dq[k] for k in Q) / len(Q)
zo = [(N.inv_cdf(0.025) - math.sqrt(1 - RHO) * N.inv_cdf(0.025 * dq[k] / mu)) / math.sqrt(RHO) for k in Q]
og = [gdp[k] / pot[k] - 1 for k in Q]; sp = [gz[k] for k in Q]; g = [cg[k] for k in Q]
print(f"CRE sample {Q[0]}..{Q[-1]} n={len(Q)}, raw delinquency mean {mu:.2f}%, gap sd {math.sqrt(sum((x-sum(g)/len(g))**2 for x in g)/len(g)):.3f}")
for name, X in (('og+gz', [[1, og[i], sp[i]] for i in range(len(Q))]),
                ('og+gz+g', [[1, og[i], sp[i], g[i]] for i in range(len(Q))]),
                ('g only', [[1, g[i]] for i in range(len(Q))])):
    b, se, _, e, rr = ols(zo, X)
    print(f"  {name:8s}: " + '  '.join(f"{b[j]:+.3f}({se[j]:.3f})" for j in range(len(b))) + f"  R2 {rr:.3f}")
for L in (4, 8):
    idx = range(L, len(Q))
    b, se, _, e, rr = ols([zo[i] for i in idx], [[1, og[i], sp[i], g[i - L]] for i in idx])
    print(f"  og+gz+g(lag {L}q): g {b[3]:+.3f}({se[3]:.3f})  R2 {rr:.3f}")
