#!/usr/bin/env python3
"""d1.py <seeds> A B: fix D1 (fair value on filed returns) against the base tree. Reads runs/<arm>-<seed>.{val.json,
jsonl}: quarterly snapshots [price index, price, F, EPS, shares, float cap, 15 channels, live CoE, financial flag] and
the run's quarter log (dividends `div`, buybacks `bb`, ratings). Year 1 skipped. Prints the final tables only.

Gate: per year, cross-sectional slope of (d log F over the next year + dividends per share over that year / F - CoE)
on log(EPS / F), EPS > 0, non-financials and financials separately.
"""
import json, math, os, statistics as st, sys
from collections import defaultdict
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = sys.argv[2], sys.argv[3]
ORDER = ['AAA', 'AA+', 'AA', 'AA-', 'A+', 'A', 'A-', 'BBB+', 'BBB', 'BBB-', 'BB+', 'BB', 'BB-', 'B+', 'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D']
BAD = set(ORDER[ORDER.index('B'):])
nan = float('nan')


def ols(x, y):
    if len(x) < 5:
        return nan, nan
    mx, my = st.fmean(x), st.fmean(y)
    sxx = sum((a - mx) ** 2 for a in x)
    syy = sum((b - my) ** 2 for b in y)
    if sxx <= 0 or syy <= 0:
        return nan, nan
    b = sum((a - mx) * (c - my) for a, c in zip(x, y)) / sxx
    return b, 1 - sum((c - my - b * (a - mx)) ** 2 for a, c in zip(x, y)) / syy


def mse(xs):
    xs = [x for x in xs if x == x]
    return (st.fmean(xs), st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else nan, len(xs)) if xs else (nan, nan, 0)


def run(arm, s):
    D = json.load(open(f'{H}/runs/{arm}-{s}.val.json'))
    R = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').read().splitlines()[-1])
    rows = D['rows']
    T = len(rows)
    tks = sorted({tk for _, snap in rows for tk in snap})
    P = {tk: [snap.get(tk) for _, snap in rows] for tk in tks}
    q0 = next(i for i, (t, _) in enumerate(rows) if t >= 1.0 - 1e-6)
    divq = defaultdict(float)
    for tk, f in R['final'].items():
        for x in f['q']:
            divq[tk, round(x['t'] * 4)] += x.get('div', 0.0) or 0.0
    qidx = [round(t * 4) for t, _ in rows]
    out = {}
    ret = lambda tk, i: sum(sum(P[tk][j][6:20]) for j in range(i + 1, i + 5)) if i + 4 < T and all(P[tk][j] for j in range(i, i + 5)) else None
    gap = lambda tk, i: math.log(P[tk][i][1] / P[tk][i][2]) if P[tk][i] and P[tk][i][1] > 0 and P[tk][i][2] > 0 else None
    years = [i for i in range(q0, T - 4) if (i - q0) % 4 == 0]
    sp_ep, sp_pf, mom, sl, r2 = [], [], [], [], []
    gate = {0: [], 1: []}
    for i in years:
        xs = []
        for tk in tks:
            v, r, g = P[tk][i], ret(tk, i), gap(tk, i)
            if v is None or r is None or g is None:
                continue
            past = ret(tk, i - 4) if i - 4 >= 0 else None
            xs.append((v[3] / v[1], g, r, past))
        if len(xs) < 20:
            continue
        def spread(key, rows_):
            z = sorted(rows_, key=key)
            n5 = len(z) // 5
            return st.fmean(math.exp(x[2]) - 1 for x in z[-n5:]) - st.fmean(math.exp(x[2]) - 1 for x in z[:n5])
        sp_ep.append(spread(lambda x: x[0], xs))
        sp_pf.append(spread(lambda x: -x[1], xs))
        mm = [x for x in xs if x[3] is not None]
        if len(mm) >= 20:
            mom.append(spread(lambda x: x[3], mm))
        b, rr = ols([math.log(x[0]) for x in xs if x[0] > 0], [x[2] for x in xs if x[0] > 0])
        sl.append(b); r2.append(rr)
        for fin in (0, 1):
            gx, gy = [], []
            for tk in tks:
                v0, v1 = P[tk][i], P[tk][i + 4]
                if not v0 or not v1 or v0[2] <= 0 or v1[2] <= 0 or v0[3] <= 0 or v0[21] is None or int(v0[22]) != fin:
                    continue
                dlf = math.log(v1[2] * v1[0] / v1[1]) - math.log(v0[2] * v0[0] / v0[1])
                dps = sum(divq.get((tk, qidx[j]), 0.0) for j in range(i + 1, i + 5)) / v0[4]
                gy.append(dlf + dps / v0[2] - v0[21])
                gx.append(math.log(v0[3] / v0[2]))
            b, _ = ols(gx, gy)
            gate[fin].append(b)
    out['E/P spread'] = st.fmean(sp_ep)
    out['E/P years > 0'] = sum(1 for x in sp_ep if x > 0) / len(sp_ep)
    out['-log(P/F) spread'] = st.fmean(sp_pf)
    out['-log(P/F) years > 0'] = sum(1 for x in sp_pf if x > 0) / len(sp_pf)
    out['slope ret ~ log E/P'] = st.fmean(sl)
    out['R2 ret ~ log E/P'] = st.fmean(r2)
    out['momentum Q5-Q1'] = st.fmean(mom)
    out['gate slope, non-financials'] = mse(gate[0])[0]
    out['gate slope, financials'] = mse(gate[1])[0]
    sds, pairs = [], []
    for i in range(q0, T):
        gs = [gap(tk, i) for tk in tks if gap(tk, i) is not None]
        sds.append(st.pstdev(gs))
        if i + 1 < T:
            pairs += [(gap(tk, i), gap(tk, i + 1)) for tk in tks if gap(tk, i) is not None and gap(tk, i + 1) is not None]
    rho, _ = ols([a for a, b in pairs], [b for a, b in pairs])
    rho = rho + 0  # slope of g_{t+1} on g_t
    out['sd log(P/F)'] = st.fmean(sds)
    out['g half-life (y)'] = -math.log(2) / math.log(rho) / 4 if 0 < rho < 1 else nan
    # board
    fin = R['final']
    allq = [x for v in fin.values() for x in v['q']]
    out['cap y20 tn'] = sum(v['q'][-1]['price'] * v['q'][-1]['shares'] for v in fin.values() if v['q'] and not v['dead']) / 1e12
    out['median P/E y10-20'] = st.median([x['price'] / x['eps'] for x in allq if 10 < x['t'] <= 20 and x['eps'] > 0])
    vols = []
    for tk in tks:
        lp = [math.log(v[0]) for v in P[tk][q0:] if v and v[0] > 0]
        d = [b - a for a, b in zip(lp, lp[1:])]
        if len(d) > 8:
            vols.append(st.pstdev(d) * 2)
    out['median vol (quarterly, annualised)'] = st.median(vols)
    out['defaults+deaths'] = len(R['defaults']) + len(R['dead'])
    out['B-or-worse share'] = sum(1 for x in allq if x['rating'] in BAD) / len(allq)
    out['buybacks bn (sum)'] = sum(x.get('bb', 0.0) or 0.0 for x in allq) / 1e9
    iss = 0.0
    for tk in tks:
        for a, b in zip(P[tk], P[tk][1:]):
            if a and b:
                adj_a, adj_b = a[0] / a[1], b[0] / b[1]
                ds = b[4] / adj_b - a[4] / adj_a
                if ds > 0:
                    iss += ds * b[0]
    out['issuance bn (split-adjusted share growth x price)'] = iss / 1e9
    out['deals'] = sum(D.get('deals', {}).values())
    # oddities
    out['firms with |d log F| > log 1.3 in quarter 1'] = sum(1 for tk in tks if P[tk][0] and abs(P[tk][0][20]) > math.log(1.3))
    out['NaN or non-positive P/F/CoE values'] = sum(1 for tk in tks for v in P[tk] if v and (not (v[1] > 0) or not (v[2] > 0) or v[21] is None or v[21] != v[21] or v[21] <= 0))
    out['unmatched'] = D['unmatched']
    return out


R_ = {(a, s): run(a, s) for a in (A, B) for s in seeds}
print(f'## D1: {B} vs {A}, seeds {seeds}, 20 years (year 1 skipped)')
print(f'quantity | {A} | {B} | {B} - {A} paired')
for key in R_[A, seeds[0]]:
    a = [R_[A, s][key] for s in seeds]
    b = [R_[B, s][key] for s in seeds]
    ok = [(x, y) for x, y in zip(a, b) if x == x and y == y]
    print(f'{key} | {mse(a)[0]:.4g} ({mse(a)[1]:.3g}) | {mse(b)[0]:.4g} ({mse(b)[1]:.3g}) | ' + (fmt(paired([x for x, y in ok], [y for x, y in ok]), 4) if len(ok) > 1 else 'n/a'))
