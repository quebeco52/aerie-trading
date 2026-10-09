"""Allied defence burden as a process: log(allied military spending / allied GDP), annual, SIPRI constant-dollar
spending of NATO members plus Japan, South Korea, Australia, New Zealand and Israel, chain-linked over the countries
reported in both years. Same method as gov_fit.py: AR(1) on a trend, Merton (1976) MLE on the residuals, then the
time-aggregation correction for an annual average of a flow (Working 1960), here with H = 1 year."""
import json, math, os
H = '/home/quebeco/Projects/Code/Private/aerie-trading/var/harness'
src = open(os.path.join(H, 'credit_fit.py')).read()
exec(src[src.index('# --- Linear algebra'):src.index('# --- 1. Premium dynamics')])
exec(src[src.index('def nelder_mead'):src.index('best = None')])

# --- Build the series: SIPRI constant-dollar milex over GDP (milex / share-of-GDP), chain-linked over the allies reported in both years ---
import sys
sys.path.insert(0, H)
from xlsx_read import load
ALLIES = ['United States of America', 'United Kingdom', 'France', 'Germany', 'Italy', 'Canada', 'Netherlands', 'Belgium', 'Norway', 'Denmark',
          'Luxembourg', 'Portugal', 'Spain', 'Greece', 'Japan', 'Korea, South', 'Australia', 'New Zealand', 'Poland', 'Israel']
def sheet(index):
    rows = load(os.path.join(H, 'sipri_milex_1949_2025.xlsx'), index)
    hdr = next(r for r in rows if (r.get('A') or '').strip() == 'Country')
    years = {k: int(v) for k, v in hdr.items() if v and v.isdigit()}
    out = {}
    for r in rows:
        n = (r.get('A') or '').strip()
        if n in ALLIES:
            s = {}
            for k, y in years.items():
                try: s[y] = float(r.get(k))
                except (TypeError, ValueError): pass
            out[n] = s
    return out
milex, share = sheet(4), sheet(6)  # 'Constant (2024) US$' and 'Share of GDP'
ok = lambda c, y: y in milex.get(c, {}) and y in share.get(c, {}) and share[c][y] > 0
lvl_m, lvl_g = {1950: 1.0}, {1950: 1.0}
for y in range(1951, 2025):
    both = [c for c in milex if ok(c, y) and ok(c, y - 1)]
    lvl_m[y] = lvl_m[y - 1] * sum(milex[c][y] for c in both) / sum(milex[c][y - 1] for c in both)
    lvl_g[y] = lvl_g[y - 1] * sum(milex[c][y] / share[c][y] for c in both) / sum(milex[c][y - 1] / share[c][y - 1] for c in both)
x = {y: math.log(lvl_m[y] / lvl_g[y]) for y in lvl_m}
out = {}
for first, last in ((1951, 2024), (1951, 2019)):
    Y, X = [], []
    for y in range(first, last + 1):
        Y.append(x[y] - x[y - 1]); X.append([1.0, float(y - 1950), x[y - 1]])
    b, se, r2, e = ols(Y, X, nw_lags=2)
    n = len(e); m, sd, sk, ku = moments(e)
    print(f"{first}-{last} n {n}: persistence {b[2]:+.4f} ({se[2]:.4f}) = kappa {-math.log(1 + b[2]):.3f}/yr; residual sd {sd:.4f}/yr skew {sk:+.2f} exkurt {ku:.2f}")
    def nll(th):
        mu, s, lam, mj, sj = th[0], math.exp(th[1]), math.exp(th[2]), th[3], math.exp(th[4])
        tot = 0.0
        for v in e:
            acc, pn = 0.0, math.exp(-lam)
            for j in range(8):
                if j > 0: pn *= lam / j
                var = s * s + j * sj * sj
                acc += pn * math.exp(-(v - mu - j * mj) ** 2 / (2 * var)) / math.sqrt(2 * math.pi * var)
            tot -= math.log(max(acc, 1e-300))
        return tot
    best = None
    for st0 in [[0.0, math.log(0.05), math.log(0.1), 0.2, math.log(0.1)], [0.0, math.log(0.04), math.log(0.05), 0.4, math.log(0.1)],
                [0.0, math.log(0.06), math.log(0.2), 0.1, math.log(0.15)], [0.0, math.log(0.05), math.log(0.02), 0.5, math.log(0.05)]]:
        th, v = nelder_mead(nll, st0); th, v = nelder_mead(nll, th, step=0.05)
        if best is None or v < best[1]: best = (th, v)
    th, v = best
    mu, s, lam, mj, sj = th[0], math.exp(th[1]), math.exp(th[2]), th[3], math.exp(th[4])
    lr = 2 * (n * (0.5 * math.log(2 * math.pi * sd * sd) + 0.5) - v)
    print(f"    Merton MLE: diffusion {s:.4f}/sqrt(yr); jumps {lam:.3f}/yr of N({mj:+.3f}, {sj:.3f}^2) log; LR vs Gaussian {lr:.1f} (chi2(3) 5% = 7.81)")
    big = sorted(zip(e, range(first, last + 1)), key=lambda t: abs(t[0]))[-6:]
    print('    largest years:', sorted((y, round(r, 3)) for r, y in big))
    out[str(first) + '-' + str(last)] = {'persist': b[2], 'resid_sd': sd, 'sigma': s, 'jump_lambda': lam, 'jump_mean': mj, 'jump_vol': sj, 'lr': lr}
H_Y = 1.0
rho_bar = lambda k: (1 - math.exp(-k * H_Y)) ** 2 / (2 * (k * H_Y - 1 + math.exp(-k * H_Y)))
var_ratio = lambda k: 2 * (k * H_Y - 1 + math.exp(-k * H_Y)) / (k * H_Y) ** 2
for key, g in out.items():
    lo, hi = 0.005, 5.0
    for _ in range(100):
        lo, hi = ((lo + hi) / 2, hi) if rho_bar((lo + hi) / 2) > 1 + g['persist'] else (lo, (lo + hi) / 2)
    k = (lo + hi) / 2
    s2 = g['resid_sd'] ** 2 / (1 - rho_bar(k) ** 2) / var_ratio(k) * 2 * k
    f = s2 * H_Y / (g['sigma'] ** 2 + g['jump_lambda'] * (g['jump_mean'] ** 2 + g['jump_vol'] ** 2))
    g['structural'] = {'kappa': k, 'sigma': g['sigma'] * math.sqrt(f), 'jump_lambda': g['jump_lambda'], 'jump_mean': g['jump_mean'] * math.sqrt(f), 'jump_vol': g['jump_vol'] * math.sqrt(f),
                       'gaussian_sigma': math.sqrt(s2), 'stationary_sd': math.sqrt(s2 / (2 * k))}
    st_ = g['structural']
    print(f"{key} structural: kappa {k:.3f}/yr (half-life {math.log(2)/k:.1f}y), sigma {st_['sigma']:.4f}, jumps {st_['jump_lambda']:.3f}/yr of N({st_['jump_mean']:+.4f}, {st_['jump_vol']:.4f}^2); stationary sd {st_['stationary_sd']:.3f}")
json.dump(out, open(os.path.join(H, 'allied_fit.json'), 'w'), indent=1)
