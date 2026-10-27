#!/usr/bin/env python3
"""d2.py <seeds> A B: fix D2 (fair-value revision passed into the price) against A. Runs d1.py's per-seed measures
(predictability, the F gate, gap, board) and adds: per-seed values; median-name and index realised vol (daily,
split-adjusted, year 1 skipped); the share of name-ticks whose own-source variance hits the 80% cap and the median
announcement variance (probe audit); the revision channel `pol` (variance of its annual sum per name, its share of
var(g) over a trailing year); board E/P and log(P/F) against next-year board return (R2); the yield exposure b_hat at
years 5/10/20 (StockTracker EMA cov / var). Prints the final tables only.
"""
import contextlib, io, json, math, os, runpy, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = sys.argv[2], sys.argv[3]
nan = float('nan')

argv = sys.argv
sys.argv = ['d1.py', arg, A, B]
with contextlib.redirect_stdout(io.StringIO()):
    ns = runpy.run_path(os.path.join(H, 'd1.py'))
sys.argv = argv
R1 = ns['R_']


def ols_r2(x, y):
    mx, my = st.fmean(x), st.fmean(y)
    sxx = sum((a - mx) ** 2 for a in x)
    syy = sum((b - my) ** 2 for b in y)
    if sxx <= 0 or syy <= 0:
        return nan, nan
    b = sum((a - mx) * (c - my) for a, c in zip(x, y)) / sxx
    return b, 1 - sum((c - my - b * (a - mx)) ** 2 for a, c in zip(x, y)) / syy


def extra(arm, s):
    d = json.load(open(f'{H}/runs/{arm}-{s}.val.json'))
    out = {}
    tpy = d['tpy']
    vols = [math.sqrt(a / n * tpy) for a, n in d['rv'].values() if n > tpy]
    out['median name vol (daily)'] = st.median(vols)
    a, n = d['rvIndex']
    out['index vol (daily)'] = math.sqrt(a / n * tpy) if n else nan
    aud = d.get('audit', {})
    hits = sum(v.get('capHit', 0.0) for v in aud.values())
    nn = sum(v.get('n', 0.0) for v in aud.values())
    out['share of name-ticks at the own-variance cap'] = hits / nn if nn else nan
    out['median announcement variance (per name mean)'] = st.median([v['ann'] / v['n'] for v in aud.values() if v.get('n')])
    out['median revision variance (per name mean)'] = st.median([v.get('rev', 0.0) / v['n'] for v in aud.values() if v.get('n')])
    rows = d['rows']
    T = len(rows)
    tks = sorted({tk for _, snap in rows for tk in snap})
    P = {tk: [snap.get(tk) for _, snap in rows] for tk in tks}
    q0 = next(i for i, (t, _) in enumerate(rows) if t >= 1.0 - 1e-6)
    POL = 6 + 8
    pv = []
    for tk in tks:
        ann = []
        for i in range(q0, T - 3, 4):
            if all(P[tk][j] for j in range(i, i + 4)):
                ann.append(sum(P[tk][j][POL] for j in range(i, i + 4)))
        if len(ann) > 3:
            pv.append(st.pvariance(ann))
    out['var of annual revision (pol) per name, median'] = st.median(pv) if pv else nan
    gap = lambda tk, i: math.log(P[tk][i][1] / P[tk][i][2]) if P[tk][i] and P[tk][i][1] > 0 and P[tk][i][2] > 0 else None
    X, Y = [], []
    for i in range(q0 + 4, T):
        for tk in tks:
            g = gap(tk, i)
            if g is None or any(P[tk][j] is None for j in range(i - 3, i + 1)):
                continue
            X.append(sum(P[tk][j][POL] for j in range(i - 3, i + 1)))
            Y.append(g)
    my, mx = st.fmean(Y), st.fmean(X)
    out['revision share of var(g), 1y window'] = st.fmean([(x - mx) * (y - my) for x, y in zip(X, Y)]) / st.fmean([(y - my) ** 2 for y in Y])
    ep, pf, ry = [], [], []
    for i in range(q0, T - 4, 4):
        w = e = c = rw = rs = 0.0
        for tk in tks:
            v = P[tk][i]
            if not v or v[1] <= 0 or v[2] <= 0 or v[5] <= 0 or not all(P[tk][j] for j in range(i, i + 5)):
                continue
            r = sum(sum(P[tk][j][6:20]) for j in range(i + 1, i + 5))
            w += v[5]
            e += v[5] * v[3] / v[1]
            c += v[5] * v[2] / v[1]
            rw += v[5] * (math.exp(r) - 1)
        if w > 0:
            ep.append(e / w)
            pf.append(math.log(w / c))
            ry.append(math.log(1 + rw / w))
    out['_ep'], out['_pf'], out['_ry'] = ep, pf, ry
    bh = d.get("bhat") or {}
    for y in ('5', '10', '20'):
        cv = bh.get(y)
        out[f'b_hat y{y}'] = cv[0] / cv[1] if cv and cv[1] else nan
    return out


E = {(a, s): extra(a, s) for a in (A, B) for s in seeds}
for key in [k for k in E[A, seeds[0]] if not k.startswith('_')]:
    for s in seeds:
        R1[A, s][key] = E[A, s][key]
        R1[B, s][key] = E[B, s][key]
print(f'## D2: {B} vs {A}, seeds {seeds}, 20 years (year 1 skipped); per seed {A} / {B}, then paired {B} - {A}')
keys = list(R1[A, seeds[0]])
for key in keys:
    a = [R1[A, s][key] for s in seeds]
    b = [R1[B, s][key] for s in seeds]
    ok = [(x, y) for x, y in zip(a, b) if x == x and y == y]
    per = ' | '.join(f'{x:.4g}/{y:.4g}' for x, y in zip(a, b))
    print(f'{key} | {per} | ' + (fmt(paired([x for x, y in ok], [y for x, y in ok]), 4) if len(ok) > 1 else 'n/a'))
for arm in (A, B):
    ep = sum((E[arm, s]['_ep'] for s in seeds), [])
    pf = sum((E[arm, s]['_pf'] for s in seeds), [])
    ry = sum((E[arm, s]['_ry'] for s in seeds), [])
    b1, r1 = ols_r2(ep, ry)
    b2, r2 = ols_r2(pf, ry)
    print(f'{arm} board, pooled seed-years (n {len(ry)}): next-year return on board E/P slope {b1:+.2f} R2 {r1:.3f}; on board log(P/F) slope {b2:+.3f} R2 {r2:.3f}')
