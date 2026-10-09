"""Wage error correction and labor-share persistence, fitted on US nonfarm business data (FRED).

Wage equation in the Blanchard & Katz (1999) form, quarterly, annualised:
    dw_t - pi^e_t - trend(da)_t = c + b * (u - u*)_{t-1} - lambda * ln LS_{t-1}
with pi^e the last four quarters' output-price inflation and trend(da) trailing ten-year productivity growth.
The price side (markup restoration) is fitted the same way for comparison. The labor share's persistence is
measured on 20-year windows, the length of a harness history, so the engine and the data share the small-sample bias.

    python3 labor_real.py            # uses labor_data.json when present, else pulls FRED
"""
import json, math, os, statistics as st, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
CACHE = os.path.join(HERE, 'labor_data.json')
SERIES = ['HCOMPBS', 'IPDNBS', 'OPHNFB', 'PRS85006173', 'UNRATE', 'NROU']

def pull():
    out = {}
    for s in SERIES:
        raw = urllib.request.urlopen(f'https://fred.stlouisfed.org/graph/fredgraph.csv?id={s}', timeout=60).read().decode().splitlines()[1:]
        out[s] = {r.split(',')[0]: float(r.split(',')[1]) for r in raw if r.split(',')[1] not in ('.', '')}
    return out

d = json.load(open(CACHE)) if os.path.exists(CACHE) else pull()
if not os.path.exists(CACHE):
    json.dump(d, open(CACHE, 'w'))

W, P, A, LS, U, NR = (d[s] for s in SERIES)
uq = {}
for k, v in U.items():
    y, m, _ = k.split('-')
    uq.setdefault(f"{y}-{(int(m) - 1) // 3 * 3 + 1:02d}-01", []).append(v)
uq = {k: st.mean(v) for k, v in uq.items() if len(v) == 3}

def ols(X, y):
    n, k = len(y), len(X[0])
    M = [[sum(X[i][a] * X[i][b] for i in range(n)) for b in range(k)] + [1.0 if a == j else 0.0 for j in range(k)] for a in range(k)]
    for c in range(k):
        p = max(range(c, k), key=lambda r: abs(M[r][c])); M[c], M[p] = M[p], M[c]
        pv = M[c][c]; M[c] = [v / pv for v in M[c]]
        for r in range(k):
            if r != c:
                f = M[r][c]; M[r] = [a - f * b for a, b in zip(M[r], M[c])]
    inv = [r[k:] for r in M]
    xty = [sum(X[i][a] * y[i] for i in range(n)) for a in range(k)]
    b = [sum(inv[a][c] * xty[c] for c in range(k)) for a in range(k)]
    res = [y[i] - sum(X[i][a] * b[a] for a in range(k)) for i in range(n)]
    s2 = sum(r * r for r in res) / (n - k)
    return b, [math.sqrt(s2 * inv[a][a]) for a in range(k)]

ks = sorted(k for k in W if k in P and k in A and k in LS)
lw, lp, la, ll = ([math.log(s[k]) for k in ks] for s in (W, P, A, LS))

print('Error correction on the lagged log labor share (annual speed; positive = correcting)')
fits = {}
for lo, hi in [(1960, 1999), (1960, 2019), (1985, 2019)]:
    for side in ('wage', 'price'):
        X, y = [], []
        for i in range(44, len(ks)):
            q1 = ks[i - 1]
            if not (lo <= int(ks[i][:4]) <= hi) or q1 not in NR or q1 not in uq:
                continue
            pie = lp[i - 1] - lp[i - 5]
            trend_a = (la[i - 1] - la[i - 41]) / 10
            y.append(4 * (lw[i] - lw[i - 1]) - pie - trend_a if side == 'wage' else 4 * (lp[i] - lp[i - 1]) - pie)
            X.append([1.0, (uq[q1] - NR[q1]) / 100, ll[i - 1]])
        b, se = ols(X, y)
        speed = -b[2] if side == 'wage' else b[2]
        fits[f'{lo}-{hi} {side}'] = (speed, se[2])
        print(f'  {lo}-{hi} {side:5s} n={len(y)}  EC {speed:+.3f}/yr (se {se[2]:.3f})  u-gap {b[1]:+.3f} (se {se[1]:.3f})')

def ar_speed(x):
    z, dz = x[:-1], [x[i] - x[i - 1] for i in range(1, len(x))]
    mz, md = st.mean(z), st.mean(dz)
    return -sum((a - mz) * (c - md) for a, c in zip(z, dz)) / sum((a - mz) ** 2 for a in z)

print('Labor share on 20-year windows (quarterly AR(1) around the window mean)')
ls_series = sorted(LS.items())
rows = []
for start in range(1948, 2005, 4):
    w = [math.log(v) for k, v in ls_series if start <= int(k[:4]) < start + 20]
    lam = ar_speed(w)
    rows.append((start, lam, st.pstdev(w)))
    print(f'  {start}-{start + 19}: half-life {math.log(2) / (-4 * math.log(1 - lam)) if lam > 0 else float("inf"):5.1f}y  sd {st.pstdev(w) * 100:.2f}%')
pre = [r for r in rows if r[0] + 19 <= 1999]
lam = st.median(r[1] for r in pre)
print(f'  windows ending by 1999: median half-life {math.log(2) / (-4 * math.log(1 - lam)):.1f}y, median sd {st.median(r[2] for r in pre) * 100:.2f}%')
json.dump({'fits': fits, 'windows': rows}, open(os.path.join(HERE, 'labor_fit.json'), 'w'), indent=1)
