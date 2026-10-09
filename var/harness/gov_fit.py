"""Government purchases as a process: log(real purchases / real potential), quarterly (FRED GCEC1 / GDPPOT).

The engine's spending index is a Schwartz (1997) log-OU with Merton jumps (geopolitical surges). The reversion speed
is the AR(1) coefficient of the detrended log share; the diffusion and the jumps are the Merton (1976) MLE on its
residuals, the method oil_fit.py uses. A lagged gap in the same regression tests for a countercyclical reaction.
NIPA purchases are quarterly AVERAGES of a flow, which reads more persistent and calmer than the process behind it
(Working 1960); the structural speed and scale are the ones whose averaged OU reproduces the measured AR(1) and residual.
"""
import json, math, os

H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'credit_fit.py')).read()
exec(src[src.index('# --- Linear algebra'):src.index('# --- 1. Premium dynamics')])
exec(src[src.index('def nelder_mead'):src.index('best = None')])
D = json.load(open(os.path.join(H, 'policy_data.json')))
# SERIES=civilian: the District's purchases, the real total scaled by the nominal non-defence share
# (SLCE + FNDEFX) / (SLCE + FNDEFX + FDEFX), FRED; the District fields no army (AssetMarketSubsystem allied defence).
import csv
if os.environ.get('SERIES') == 'civilian':
    fred = lambda i: {r['observation_date']: float(r[i]) for r in csv.DictReader(open(os.path.join(H, 'fred', i + '.csv'))) if r[i] not in ('', '.')}
    sl, nd, df = fred('SLCE'), fred('FNDEFX'), fred('FDEFX')
    D['GCEC1'] = {k: v * (sl[k] + nd[k]) / (sl[k] + nd[k] + df[k]) for k, v in D['GCEC1'].items() if k in sl and k in nd and k in df}
pot = D['GDPPOT']
gap = {k: 100 * (v / pot[k] - 1) for k, v in D['GDPC1'].items() if k in pot}
share = {k: math.log(v / pot[k]) for k, v in D['GCEC1'].items() if k in pot}
ks = sorted(k for k in share if k in gap)
out = {}
for first, last in (('1949-01', '2019-10'), ('1960-01', '2019-10'), ('1985-01', '2019-10')):
    Y, X = [], []
    for i in range(2, len(ks)):
        k = ks[i]
        if not (first <= k <= last):
            continue
        Y.append(share[k] - share[ks[i - 1]])
        X.append([1.0, i / 4.0, share[ks[i - 1]], gap[ks[i - 1]] / 100.0])
    b, se, r2, e = ols(Y, X, nw_lags=4)
    kappa = -4 * math.log(1 + b[2])
    n = len(e)
    m, sd, sk, ku = moments(e)
    print(f"{first[:4]}-{last[:4]} n {n}: persistence {b[2]:+.4f} ({se[2]:.4f}) = kappa {kappa:.3f}/yr (half-life {math.log(2) / kappa:.1f}y), "
          f"lagged gap {b[3]:+.3f} ({se[3]:.3f}) log share per unit gap; residual sd {sd:.4f}/q ({sd * 2:.4f}/sqrt(yr)) skew {sk:+.2f} exkurt {ku:.2f}")

    def nll(th):
        mu, s, lam, mj, sj = th[0], math.exp(th[1]), math.exp(th[2]), th[3], math.exp(th[4])
        tot = 0.0
        for x in e:
            acc, pn = 0.0, math.exp(-lam)
            for j in range(8):
                if j > 0:
                    pn *= lam / j
                var = s * s + j * sj * sj
                acc += pn * math.exp(-(x - mu - j * mj) ** 2 / (2 * var)) / math.sqrt(2 * math.pi * var)
            tot -= math.log(max(acc, 1e-300))
        return tot

    best = None
    for st0 in [[0.0, math.log(0.01), math.log(0.02), 0.03, math.log(0.03)], [0.0, math.log(0.008), math.log(0.05), 0.05, math.log(0.05)],
                [0.0, math.log(0.012), math.log(0.01), 0.08, math.log(0.04)], [0.0, math.log(0.01), math.log(0.1), 0.0, math.log(0.04)]]:
        th, v = nelder_mead(nll, st0)
        th, v = nelder_mead(nll, th, step=0.05)
        if best is None or v < best[1]:
            best = (th, v)
    th, v = best
    mu, s, lam, mj, sj = th[0], math.exp(th[1]), math.exp(th[2]), th[3], math.exp(th[4])
    lr_stat = 2 * (n * (0.5 * math.log(2 * math.pi * sd * sd) + 0.5) - v)
    print(f"    Merton MLE: diffusion {s:.4f}/q = {s * 2:.4f}/sqrt(yr); jumps {lam * 4:.3f}/yr of N({mj:+.3f}, {sj:.3f}^2) log; LR vs Gaussian {lr_stat:.1f} (chi2(3) 5% = 7.81)")
    big = sorted(zip(e, [ks[i] for i in range(2, len(ks)) if first <= ks[i] <= last]), key=lambda t: abs(t[0]))[-8:]
    print('    largest quarters:', sorted((k, round(x, 3)) for x, k in big))
    out[first[:4]] = {'kappa': kappa, 'persist': b[2], 'persist_se': se[2], 'gap': b[3], 'gap_se': se[3], 'resid_sd': sd, 'sigma': s * 2,
                      'jump_lambda': lam * 4, 'jump_mean': mj, 'jump_vol': sj, 'lr': lr_stat}

H_Q = 0.25
rho_bar = lambda k: (1 - math.exp(-k * H_Q)) ** 2 / (2 * (k * H_Q - 1 + math.exp(-k * H_Q)))
var_ratio = lambda k: 2 * (k * H_Q - 1 + math.exp(-k * H_Q)) / (k * H_Q) ** 2
for key, g in out.items():
    lo, hi = 0.01, 3.0
    for _ in range(100):
        lo, hi = ((lo + hi) / 2, hi) if rho_bar((lo + hi) / 2) > 1 + g['persist'] else (lo, (lo + hi) / 2)
    k = (lo + hi) / 2
    s2 = g['resid_sd'] ** 2 / (1 - rho_bar(k) ** 2) / var_ratio(k) * 2 * k
    f = s2 * H_Q / ((g['sigma'] / 2) ** 2 + g['jump_lambda'] / 4 * (g['jump_mean'] ** 2 + g['jump_vol'] ** 2))
    g['structural'] = {'kappa': k, 'sigma': g['sigma'] * math.sqrt(f), 'jump_lambda': g['jump_lambda'], 'jump_mean': g['jump_mean'] * math.sqrt(f), 'jump_vol': g['jump_vol'] * math.sqrt(f)}
    print(f"{key} structural (time-aggregation corrected): kappa {k:.3f}/yr, sigma {g['structural']['sigma']:.4f}, jumps {g['jump_lambda']:.3f}/yr of N({g['structural']['jump_mean']:+.4f}, {g['structural']['jump_vol']:.4f}^2)")
    g['structural']['gaussian_sigma'] = math.sqrt(s2)
    print(f"    all variance in the diffusion (when the jumps are not significant): sigma {math.sqrt(s2):.4f}; LR {g['lr']:.1f}")
json.dump(out, open(os.path.join(H, 'gov_fit' + ('_civilian' if os.environ.get('SERIES') == 'civilian' else '') + '.json'), 'w'), indent=1)
