#!/usr/bin/env python3
"""d3.py <seeds> A B C: three arms side by side (per-seed means), paired C - A and C - B. Uses d1.py's measures and
d2.py's extras (vol, cap hits, announcement and revision variance, revision channel, board E/P and log(P/F) R2), plus a
cyclical read: the correlation, over seed-years, of the board's float-weighted annual d log F and d log P with the
year's change in the output gap (mean of the reports' `gap` in the run's quarter log). Prints the final tables only.
"""
import contextlib, io, json, math, os, runpy, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B, C = sys.argv[2], sys.argv[3], sys.argv[4]
nan = float('nan')


def load(a1, a2):
    argv = sys.argv
    sys.argv = ['d2.py', arg, a1, a2]
    with contextlib.redirect_stdout(io.StringIO()):
        ns = runpy.run_path(os.path.join(H, 'd2.py'))
    sys.argv = argv
    return ns


nsAC = load(A, C)
nsBC = load(B, C)
M = {}
for ns, arms in ((nsAC, (A, C)), (nsBC, (B, C))):
    for a in arms:
        for s in seeds:
            M[a, s] = dict(ns['R1'][a, s])
            M[a, s].update({k: v for k, v in ns['E'][a, s].items()})
    ols_r2 = ns['ols_r2']


def cyc(arm, s):
    d = json.load(open(f'{H}/runs/{arm}-{s}.val.json'))
    R = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').read().splitlines()[-1])
    gaps = {}
    for f in R['final'].values():
        for x in f['q']:
            gaps.setdefault(round(x['t'] * 4), []).append(x.get('gap', 0.0) or 0.0)
    rows = d['rows']
    T = len(rows)
    q0 = next(i for i, (t, _) in enumerate(rows) if t >= 1.0 - 1e-6)
    out = []
    for i in range(q0, T - 4, 4):
        a, b = rows[i][1], rows[i + 4][1]
        w = dF = dP = 0.0
        for tk, v in a.items():
            u = b.get(tk)
            if not u or v[2] <= 0 or u[2] <= 0 or v[5] <= 0:
                continue
            w += v[5]
            dF += v[5] * (math.log(u[2] * u[0] / u[1]) - math.log(v[2] * v[0] / v[1]))
            dP += v[5] * math.log(u[0] / v[0])
        g0 = gaps.get(round(rows[i][0] * 4)) or gaps.get(round(rows[i][0] * 4) - 1)
        g1 = gaps.get(round(rows[i + 4][0] * 4)) or gaps.get(round(rows[i + 4][0] * 4) - 1)
        if w > 0 and g0 and g1:
            out.append((dF / w, dP / w, st.fmean(g1) - st.fmean(g0)))
    return out


def corr(x, y):
    mx, my = st.fmean(x), st.fmean(y)
    sx = math.sqrt(sum((a - mx) ** 2 for a in x))
    sy = math.sqrt(sum((b - my) ** 2 for b in y))
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / (sx * sy)


print(f'## {A} / {B} / {C}, seeds {seeds}, 20 years (year 1 skipped): means over seeds; paired {C} - {A} and {C} - {B}')
keys = [k for k in M[C, seeds[0]] if not k.startswith('_')]
for key in keys:
    vals = {a: [M[a, s].get(key, nan) for s in seeds] for a in (A, B, C)}
    m = {a: (st.fmean([x for x in v if x == x]) if any(x == x for x in v) else nan) for a, v in vals.items()}
    pd = []
    for ref in (A, B):
        ok = [(x, y) for x, y in zip(vals[ref], vals[C]) if x == x and y == y]
        pd.append(fmt(paired([x for x, y in ok], [y for x, y in ok]), 4) if len(ok) > 1 else 'n/a')
    print(f'{key} | {m[A]:.4g} / {m[B]:.4g} / {m[C]:.4g} | {pd[0]} | {pd[1]}')
for a in (A, B, C):
    ep = sum((M[a, s]['_ep'] for s in seeds), [])
    pf = sum((M[a, s]['_pf'] for s in seeds), [])
    ry = sum((M[a, s]['_ry'] for s in seeds), [])
    b1, r1 = ols_r2(ep, ry)
    b2, r2 = ols_r2(pf, ry)
    cy = sum((cyc(a, s) for s in seeds), [])
    print(f'{a}: board next-year return on E/P R2 {r1:.3f}, on log(P/F) R2 {r2:.3f} (n {len(ry)}); corr with the change in output gap: '
          f'board d log F {corr([x[0] for x in cy], [x[2] for x in cy]):+.3f}, d log P {corr([x[1] for x in cy], [x[2] for x in cy]):+.3f} (n {len(cy)}); '
          f'sd board d log F {st.pstdev([x[0] for x in cy]):.3f}, d log P {st.pstdev([x[1] for x in cy]):.3f}')
