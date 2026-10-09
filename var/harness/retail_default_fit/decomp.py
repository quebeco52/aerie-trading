"""2006-2012 decomposition under the refit channel weights, and delta-method se of the EMA-corrected kappa."""
import json, math, os
from statistics import NormalDist
H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'fit.py')).read()
exec(src[src.index('# --- linear algebra'):src.index('r = [zobs[k]')])
N = NormalDist(); RHO, PD_LRA = 0.12, 0.025
cdf = lambda z: N.cdf((N.inv_cdf(PD_LRA) - math.sqrt(RHO) * z) / math.sqrt(1 - RHO))
D = json.load(open(os.path.join(H, 'series_baa.json')))
Q, zo, ze, g, P, pdo = D['Q'], D['zobs'], D['ze'], D['g'], D['parts'], D['pdo']
n = len(Q)


def kstruct(p, lam=4.0):
    f = lambda k: (lam * math.exp(-k / 4) - k * math.exp(-lam / 4)) / (lam - k) - p
    lo, hi = 1e-6, 3.99
    if not (f(lo) > 0 > f(hi)):
        return float('nan')
    for _ in range(100):
        mid = 0.5 * (lo + hi); lo, hi = (mid, hi) if f(mid) > 0 else (lo, mid)
    return 0.5 * (lo + hi)


for name, cols in (('u,pi,spr,dsr,g', [0, 1, 2, 3, 'g']), ('u,spr,g', [0, 2, 'g']), ('u,spr', [0, 2])):
    X = [[1.0] + [g[i] if c == 'g' else P[i][c] for c in cols] for i in range(n)]
    b, se, _, e, rr = ols(zo, X)
    eng_w = {0: 40, 1: 25, 2: 15, 3: 30}
    desc = '  '.join((f"g {b[j+1]:+.2f}({se[j+1]:.2f})" if c == 'g' else f"{['u','pi','spr','dsr'][c]} {b[j+1]*eng_w[c]:+.1f}({se[j+1]*eng_w[c]:.1f})") for j, c in enumerate(cols))
    Y = e[1:]; bb, ss, so, eta, _ = ols(Y, [[1.0, x] for x in e[:-1]], lags=0)
    phi, sphi = bb[1], so[1]
    sd_eta = math.sqrt(sum(x * x for x in eta) / (len(Y) - 2)); s_obs = sd_eta / math.sqrt(1 - phi ** 2)
    ks = kstruct(phi); dk = (kstruct(phi - 0.001) - kstruct(phi + 0.001)) / 0.002
    s_st = s_obs * math.sqrt((4 + ks) / 4)
    ds = sd_eta * phi / (1 - phi ** 2) ** 1.5
    s_se = math.sqrt((ds * sphi) ** 2 + (s_obs / math.sqrt(2 * (len(Y) - 2))) ** 2) * math.sqrt((4 + ks) / 4)
    print(f"[{name}] sensitivities (engine units): c {b[0]:+.2f}  {desc}  R2 {rr:.3f}")
    print(f"    resid AR(1) phi {phi:.3f} ({sphi:.3f}): quarterly-AR kappa {-4*math.log(phi):.3f} ({4*sphi/phi:.3f}), s {s_obs:.3f}; "
          f"EMA-corrected kappa {ks:.3f} ({abs(dk)*sphi:.3f}), s {s_st:.3f} ({s_se:.3f}); Kendall phi {phi+(1+3*phi)/len(Y):.3f} -> kappa {kstruct(phi+(1+3*phi)/len(Y)):.3f}")
    if 'g' in cols:
        jg = cols.index('g') + 1
        fit = [sum(b[j] * X[i][j] for j in range(len(b))) for i in range(n)]
        nog = [fit[i] - b[jg] * g[i] for i in range(n)]
        i0 = Q.index('2006-01'); ip = Q.index('2010-01')
        print(f"    PD 2006Q1 obs {pdo[i0]:.4f} fit {cdf(fit[i0]):.4f} fit-no-g {cdf(nog[i0]):.4f} | 2010Q1 obs {pdo[ip]:.4f} fit {cdf(fit[ip]):.4f} fit-no-g {cdf(nog[ip]):.4f}")
        rise_o = pdo[ip] - pdo[i0]; rise_f = cdf(fit[ip]) - cdf(fit[i0]); rise_n = cdf(nog[ip]) - cdf(nog[i0])
        print(f"    rise 2006Q1->2010Q1: obs {rise_o:+.4f}  fit {rise_f:+.4f}  fit without g {rise_n:+.4f}  -> g share of fitted rise {(rise_f-rise_n)/rise_f:.0%}, of observed {(rise_f-rise_n)/rise_o:.0%}")
        for k in ('2007-10', '2009-10', '2011-10', '2013-10', '2015-10'):
            i = Q.index(k)
            print(f"      {k}: obs {pdo[i]:.4f} fit {cdf(fit[i]):.4f} no-g {cdf(nog[i]):.4f}")
