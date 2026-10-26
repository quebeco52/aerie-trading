#!/usr/bin/env python3
"""analyse.py <arm> <seeds>: valuation predictability from runs/<arm>-<seed>.val.json (quarterly snapshots per name:
[price index (split-adjusted), price, fair value per share, EPS, shares, float cap, 15 channel sums over the quarter in
Probe::NAMES order]). Year 1 skipped. Next-year return = sum of the 14 price channels over the next 4 quarters (total
log return, dividends added back by `rep`). Gap g = log(P / F). Prints the final tables only (per seed, then mean and
SE across seeds).
"""
import json, math, os, statistics as st, sys
from collections import defaultdict

H = os.path.dirname(os.path.abspath(__file__))
ARM = sys.argv[1]
arg = sys.argv[2]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
GROUPS = {'market': [0, 6], 'sector': [1], 'own': [2, 5], 'flow': [9, 10], 'news': [7, 8, 11, 12, 13], 'pull': [3], 'breaker': [4]}
nan = float('nan')


def ols(x, y):
    n = len(x)
    if n < 3:
        return nan, nan, nan
    mx, my = st.fmean(x), st.fmean(y)
    sxx = sum((a - mx) ** 2 for a in x)
    if sxx <= 0:
        return nan, nan, nan
    b = sum((a - mx) * (c - my) for a, c in zip(x, y)) / sxx
    res = [c - my - b * (a - mx) for a, c in zip(x, y)]
    syy = sum((c - my) ** 2 for c in y)
    r2 = 1 - sum(r * r for r in res) / syy if syy > 0 else nan
    se = math.sqrt(sum(r * r for r in res) / (n - 2) / sxx)
    return b, r2, se


def mols(X, y):
    """Multiple OLS with intercept; returns slopes (no intercept). X: list of rows."""
    k = len(X[0])
    n = len(y)
    A = [[1.0] + list(r) for r in X]
    # normal equations
    m = k + 1
    XtX = [[sum(A[i][p] * A[i][q] for i in range(n)) for q in range(m)] for p in range(m)]
    Xty = [sum(A[i][p] * y[i] for i in range(n)) for p in range(m)]
    # Gauss-Jordan
    M = [row[:] + [v] for row, v in zip(XtX, Xty)]
    for c in range(m):
        piv = max(range(c, m), key=lambda r: abs(M[r][c]))
        if abs(M[piv][c]) < 1e-15:
            return [nan] * k
        M[c], M[piv] = M[piv], M[c]
        for r in range(m):
            if r != c:
                f = M[r][c] / M[c][c]
                M[r] = [a - f * b for a, b in zip(M[r], M[c])]
    return [M[p][m] / M[p][p] for p in range(1, m)]


def mse(xs):
    xs = [x for x in xs if x == x]
    if not xs:
        return nan, nan, 0
    return (st.fmean(xs), st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else nan, len(xs))


def fmt(xs, d=4):
    m, se, n = mse(xs)
    return f'{m:+.{d}f} ({se:.{d}f})'


per = defaultdict(dict)
fm_all = defaultdict(list)
for s in seeds:
    D = json.load(open(f'{H}/runs/{ARM}-{s}.val.json'))
    rows = D['rows']
    T = len(rows)
    tks = sorted({tk for _, snap in rows for tk in snap})
    # per-name series indexed by quarter
    P = {tk: [None] * T for tk in tks}
    for i, (t, snap) in enumerate(rows):
        for tk, v in snap.items():
            P[tk][i] = v
    times = [t for t, _ in rows]
    q0 = next(i for i, t in enumerate(times) if t >= 1.0 - 1e-6)

    def ret(tk, i, k):
        """total log return over quarters i+1 .. i+k (channels 0..13)."""
        if i + k >= T or any(P[tk][j] is None for j in range(i, i + k + 1)):
            return None
        return sum(sum(P[tk][j][6:20]) for j in range(i + 1, i + k + 1))

    def gap(tk, i):
        v = P[tk][i]
        return math.log(v[1] / v[2]) if v and v[1] > 0 and v[2] > 0 else None

    def dlogF(tk, i):
        a, b = P[tk][i - 1], P[tk][i]
        if not a or not b or a[2] <= 0 or b[2] <= 0:
            return None
        return math.log(b[2] * (b[0] / b[1]) / (a[2] * (a[0] / a[1])))

    # identity check: g_i - g_{i-1} = sum channels - dlogF
    resid = []
    for tk in tks:
        for i in range(q0 + 1, T):
            g1, g0, dF = gap(tk, i), gap(tk, i - 1), dlogF(tk, i)
            if None in (g1, g0, dF) or P[tk][i] is None:
                continue
            resid.append(abs((g1 - g0) - (sum(P[tk][i][6:20]) - dF)))
    per[s]['identity resid median'] = st.median(resid) if resid else nan

    # 1. yearly sorts at year ends
    years = [i for i in range(q0, T) if (i - q0) % 4 == 0 and i + 4 < T]
    ep_spread, pf_spread, mom_spread = [], [], []
    ep_slope, ep_r2, pf_slope, pf_r2 = [], [], [], []
    fm = []
    gk = {}
    for i in years:
        xs = []
        for tk in tks:
            v = P[tk][i]
            r = ret(tk, i, 4)
            g = gap(tk, i)
            if v is None or r is None or g is None or v[1] <= 0:
                continue
            past = ret(tk, i - 4, 4) if i - 4 >= 0 else None
            xs.append((tk, v[3] / v[1], g, r, past))
        if len(xs) < 20:
            continue
        def spread(key, hi_cheap=True):
            z = sorted(xs, key=key)
            n5 = len(z) // 5
            lo, hi = z[:n5], z[-n5:]
            m = lambda grp: st.fmean(math.exp(x[3]) - 1 for x in grp)
            return (m(hi) - m(lo)) if hi_cheap else (m(lo) - m(hi))
        ep_spread.append(spread(lambda x: x[1]))
        pf_spread.append(spread(lambda x: -x[2]))
        mm = [x for x in xs if x[4] is not None]
        if len(mm) >= 20:
            z = sorted(mm, key=lambda x: x[4])
            n5 = len(z) // 5
            mom_spread.append(st.fmean(math.exp(x[3]) - 1 for x in z[-n5:]) - st.fmean(math.exp(x[3]) - 1 for x in z[:n5]))
        pos = [x for x in xs if x[1] > 0]
        b, r2, _ = ols([math.log(x[1]) for x in pos], [x[3] for x in pos])
        ep_slope.append(b); ep_r2.append(r2)
        b, r2, _ = ols([x[2] for x in xs], [x[3] for x in xs])
        pf_slope.append(b); pf_r2.append(r2)
    per[s].update({'Q5-Q1 E/P spread': st.fmean(ep_spread), 'E/P spread years >0': f'{sum(1 for x in ep_spread if x > 0)}/{len(ep_spread)}',
                   'Q5-Q1 -log(P/F) spread': st.fmean(pf_spread), '-log(P/F) spread years >0': f'{sum(1 for x in pf_spread if x > 0)}/{len(pf_spread)}',
                   'slope ret~log E/P': st.fmean(ep_slope), 'R2 ret~log E/P': st.fmean(ep_r2),
                   'slope ret~log(P/F)': st.fmean(pf_slope), 'R2 ret~log(P/F)': st.fmean(pf_r2),
                   'momentum Q5-Q1': st.fmean(mom_spread)})

    # 2. gap dynamics
    sds = []
    pairs = []
    for i in range(q0, T):
        gs = [gap(tk, i) for tk in tks if gap(tk, i) is not None]
        if len(gs) > 5:
            sds.append(st.pstdev(gs))
        if i + 1 < T:
            for tk in tks:
                a, b_ = gap(tk, i), gap(tk, i + 1)
                if a is not None and b_ is not None:
                    pairs.append((a, b_))
    rho, _, _ = ols([a for a, b in pairs], [b for a, b in pairs])
    per[s]['sd of g (cross-section)'] = st.fmean(sds)
    per[s]['AR1 of g (quarterly)'] = rho
    per[s]['g half-life (years)'] = (-math.log(2) / math.log(rho) / 4) if 0 < rho < 1 else nan

    # 3. attribution over trailing windows (W quarters): g_i = g_{i-W} + sum over window of (groups) - sum dlogF
    for W in (4, 8):
        X, Y = [], []
        for i in range(q0 + W, T):
            for tk in tks:
                g, g0 = gap(tk, i), gap(tk, i - W)
                if g is None or g0 is None or any(P[tk][j] is None for j in range(i - W, i + 1)):
                    continue
                dFs = [dlogF(tk, j) for j in range(i - W + 1, i + 1)]
                if None in dFs:
                    continue
                pieces = [sum(sum(P[tk][j][6 + c] for c in cols) for j in range(i - W + 1, i + 1)) for cols in GROUPS.values()]
                pieces.append(-sum(dFs))
                pieces.append(g0)
                # what the channels count that the price does not: dividends added back by `rep` (exact identity)
                pieces.append(g - sum(pieces))
                X.append(pieces)
                Y.append(g)
        names = list(GROUPS) + ['-dlogF', 'initial gap', 'dividends (identity)']
        my = st.fmean(Y)
        vy = st.fmean([(y - my) ** 2 for y in Y])
        for k, nm in enumerate(names):
            col = [x[k] for x in X]
            mc = st.fmean(col)
            per[s][f'share var(g) W{W // 4}y {nm}'] = st.fmean([(c - mc) * (y - my) for c, y in zip(col, Y)]) / vy
    # predictive regression (Fama-MacBeth by year): next-year return on 1y cumulated pieces
    slopes = []
    for i in years:
        if i - 4 < q0 - 4 or i - 4 < 0:
            continue
        X, Y = [], []
        for tk in tks:
            r = ret(tk, i, 4)
            if r is None or any(P[tk][j] is None for j in range(i - 3, i + 1)):
                continue
            dFs = [dlogF(tk, j) for j in range(i - 3, i + 1)]
            if None in dFs:
                continue
            X.append([sum(sum(P[tk][j][6 + c] for c in cols) for j in range(i - 3, i + 1)) for nm, cols in GROUPS.items() if nm != 'breaker'] + [-sum(dFs)])
            Y.append(r)
        if len(Y) > 15:
            slopes.append(mols(X, Y))
    names = [nm for nm in GROUPS if nm != 'breaker'] + ['-dlogF']
    for k, nm in enumerate(names):
        col = [sl[k] for sl in slopes if sl[k] == sl[k]]
        per[s][f'FM slope next-year ret on 1y {nm}'] = st.fmean(col) if col else nan
        fm_all[nm] += col

    # 4. variance split of annual log price change; vol of log F vs log P
    dP, dFv, dG = [], [], []
    volP, volF = [], []
    for tk in tks:
        lp = []
        lf = []
        for i in range(q0, T):
            v = P[tk][i]
            if v is None or v[2] <= 0:
                lp.append(None); lf.append(None)
                continue
            lp.append(math.log(v[0]))
            lf.append(math.log(v[2] * v[0] / v[1]))
        for a in range(0, len(lp) - 4, 4):
            if None not in (lp[a], lp[a + 4], lf[a], lf[a + 4]):
                dp = lp[a + 4] - lp[a]
                df = lf[a + 4] - lf[a]
                dP.append(dp); dFv.append(df); dG.append(dp - df)
        qp = [lp[j + 1] - lp[j] for j in range(len(lp) - 1) if lp[j] is not None and lp[j + 1] is not None]
        qf = [lf[j + 1] - lf[j] for j in range(len(lf) - 1) if lf[j] is not None and lf[j + 1] is not None]
        if len(qp) > 8:
            volP.append(st.pstdev(qp) * 2)
            volF.append(st.pstdev(qf) * 2)
    mP = st.fmean(dP)
    vP = st.fmean([(x - mP) ** 2 for x in dP])
    cov = lambda a, b: st.fmean([(x - st.fmean(a)) * (y - mP) for x, y in zip(a, b)])
    per[s]['share var(dlogP): dlogF'] = cov(dFv, dP) / vP
    per[s]['share var(dlogP): dg'] = cov(dG, dP) / vP
    per[s]['vol log P (median firm, annualised)'] = st.median(volP)
    per[s]['vol log F (median firm, annualised)'] = st.median(volF)

    # 5. index: float-weighted board gap vs next-year board return
    bx, by = [], []
    for i in years:
        num = den = 0.0
        rw = rs = 0.0
        for tk in tks:
            v = P[tk][i]
            r = ret(tk, i, 4)
            if v is None or v[2] <= 0 or v[5] <= 0:
                continue
            num += v[5]
            den += v[5] * v[2] / v[1]
            if r is not None:
                rw += v[5] * (math.exp(r) - 1)
                rs += v[5]
        if den > 0 and rs > 0:
            bx.append(math.log(num / den))
            by.append(math.log(1 + rw / rs))
    b, r2, se = ols(bx, by)
    per[s]['index slope ret~board log(P/F)'] = b
    per[s]['index R2'] = r2
    per[s]['index n years'] = len(bx)
    per[s]['_bx'] = bx
    per[s]['_by'] = by
    per[s]['unmatched'] = D['unmatched']

print(f'## Valuation predictability, {ARM}, seeds {seeds} (year 1 skipped; quarterly samples)')
keys = [k for k in per[seeds[0]] if not k.startswith('_')]
print('quantity | ' + ' | '.join(f's{s}' for s in seeds) + ' | mean (se)')
for k in keys:
    vals = [per[s][k] for s in seeds]
    if all(isinstance(v, (int, float)) for v in vals):
        print(f'{k} | ' + ' | '.join(f'{v:+.4f}' if isinstance(v, float) else str(v) for v in vals) + f' | {fmt([float(v) for v in vals])}')
    else:
        print(f'{k} | ' + ' | '.join(str(v) for v in vals) + ' |')
bx = sum((per[s]['_bx'] for s in seeds), [])
by = sum((per[s]['_by'] for s in seeds), [])
b, r2, se = ols(bx, by)
print(f'index pooled over seeds: slope {b:+.3f} (se {se:.3f}), R2 {r2:.3f}, n {len(bx)}')
print('FM slopes pooled over all seed-years (mean, se, t): ' + '; '.join(
    f'{nm} {st.fmean(v):+.3f} ({st.stdev(v) / math.sqrt(len(v)):.3f}, t {st.fmean(v) / (st.stdev(v) / math.sqrt(len(v))):.1f})' for nm, v in fm_all.items() if len(v) > 2))
