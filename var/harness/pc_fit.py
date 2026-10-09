"""Rudebusch & Svensson (1999) Phillips curve on US data and on engine output, the same regression on both:

    pi_t = c + a1 pi_{t-1} + a2 pi_{t-2} + a3 pi_{t-3} + a4 pi_{t-4} + b gap_{t-1}

pi is core inflation, quarterly, annualized (US: core PCE; engine: the supercore/goods blend the rule reads).
    python3 pc_fit.py                 # US, writes pc_fit.json
    python3 pc_fit.py <dir> <arm>     # engine arm from CreditMacroProbeTest
"""
import glob, json, math, os, sys

H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'credit_fit.py')).read()
exec(src[src.index('# --- Linear algebra'):src.index('# --- 1. Premium dynamics')])


def fit(pi, gap, keys):
    Y, X = [], []
    for i in range(4, len(keys)):
        k = keys[i]
        Y.append(pi[k])
        X.append([1.0] + [pi[keys[i - j]] for j in range(1, 5)] + [gap[keys[i - 1]]])
    b, se, r2, e = ols(Y, X, nw_lags=4)
    return b, se, len(Y)


if len(sys.argv) < 3:
    D = json.load(open(os.path.join(H, 'policy_data.json')))
    q_of = lambda k: f"{k[:4]}-{(int(k[5:7]) - 1) // 3 * 3 + 1:02d}"
    acc = {}
    for k, v in D['PCEPILFE'].items():
        acc.setdefault(q_of(k), []).append(v)
    core = {k: sum(v) / 3 for k, v in acc.items() if len(v) == 3}
    qs = sorted(core)
    pi = {qs[i]: 400 * math.log(core[qs[i]] / core[qs[i - 1]]) for i in range(1, len(qs))}
    gap = {q_of(k): 100 * (v / D['GDPPOT'][k] - 1) for k, v in D['GDPC1'].items() if k in D['GDPPOT']}
    out = {}
    for first, last in (('1961-01', '2008-10'), ('1985-01', '2019-10')):
        keys = [k for k in sorted(pi) if first <= k <= last and k in gap]
        b, se, n = fit(pi, gap, keys)
        s = sum(b[1:5])
        print(f"US {first[:4]}-{last[:4]} n {n}: sum of lags {s:.3f}, gap slope {b[5]:+.3f} ({se[5]:.3f}) pp of annualized inflation per pp of gap, long-run {b[5] / (1 - s) if s < 1 else float('inf'):.2f}")
        out[first[:4]] = {'sum': s, 'slope': b[5], 'slope_se': se[5]}
    json.dump(out, open(os.path.join(H, 'pc_fit.json'), 'w'), indent=1)
else:
    US = json.load(open(os.path.join(H, 'pc_fit.json')))
    pis, gaps, keysets = {}, {}, []
    for f in sorted(glob.glob(f'{sys.argv[1]}/{sys.argv[2]}-*.json')):
        for r in json.load(open(f)):
            if r['t'] <= 10:
                continue
            k = (r['seed'], round(r['t'] * 4))
            key = 'core_avg' if 'core_avg' in r else None
            pis[k] = 100 * (r['core_avg'] if key else (0.55 * r['supercore_ema'] + 0.25 * r['goods_ema']) / 0.80)
            gaps[k] = 100 * r['gap_avg']
    Y, X = [], []
    for (seed, t) in sorted(pis):
        lags = [(seed, t - j) for j in range(1, 5)]
        if all(l in pis for l in lags):
            Y.append(pis[(seed, t)])
            X.append([1.0] + [pis[l] for l in lags] + [gaps[(seed, t - 1)]])
    b, se, _, _ = ols(Y, X)
    s = sum(b[1:5])
    print(f"engine: sum of lags {s:.3f}, gap slope {b[5]:+.3f}, long-run {b[5] / (1 - s) if s < 1 else float('inf'):.2f} "
          f"| US 1961-2008 sum {US['1961']['sum']:.3f} slope {US['1961']['slope']:+.3f} ({US['1961']['slope_se']:.3f}); 1985-2019 sum {US['1985']['sum']:.3f} slope {US['1985']['slope']:+.3f} ({US['1985']['slope_se']:.3f})")
