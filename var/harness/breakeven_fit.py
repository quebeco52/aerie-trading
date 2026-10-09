"""US 10-year breakeven on its inflation drivers: python3 var/harness/breakeven_fit.py

Gurkaynak, Sack & Wright (2010): breakeven = expected inflation over the bond's life + inflation risk premium
- TIPS liquidity premium. Regressed as one reduced form on the state an engine can see:
    BE = a + b_core (core CPI yoy - 2) + b_noncore (headline yoy - core yoy) [+ b_gap CBO gap] [+ b_ebp GZ EBP]
Monthly, Newey-West (12 lags) standard errors. Data: breakeven_data.json (FRED + Gilchrist-Zakrajsek EBP).
"""
import json, math, os

H = os.path.dirname(os.path.abspath(__file__))
D = json.load(open(os.path.join(H, 'breakeven_data.json')))


def yoy(s, k):
    y, m = int(k[:4]), int(k[5:])
    p = f'{y - 1}-{m:02d}'
    return 100 * (s[k] / s[p] - 1) if k in s and p in s else None


def cbo_gap(k):
    """Quarterly CBO gap (log real GDP over potential, %) carried to each month of its quarter."""
    y, m = int(k[:4]), int(k[5:])
    q = f'{y}-{(m - 1) // 3 * 3 + 1:02d}'
    return 100 * math.log(D['GDPC1'][q] / D['GDPPOT'][q]) if q in D['GDPC1'] and q in D['GDPPOT'] else None


def solve(A, b):
    n = len(b)
    M = [A[i][:] + [b[i]] for i in range(n)]
    for i in range(n):
        p = max(range(i, n), key=lambda r: abs(M[r][i]))
        M[i], M[p] = M[p], M[i]
        for r in range(n):
            if r != i:
                f = M[r][i] / M[i][i]
                M[r] = [x - f * y for x, y in zip(M[r], M[i])]
    return [M[i][n] / M[i][i] for i in range(n)]


def inverse(A):
    n = len(A)
    return [list(col) for col in zip(*[solve(A, [1.0 if r == c else 0.0 for r in range(n)]) for c in range(n)])]


def ols_nw(y, X, lags=12):
    k, n = len(X[0]), len(y)
    XtX = [[sum(r[i] * r[j] for r in X) for j in range(k)] for i in range(k)]
    b = solve(XtX, [sum(r[i] * v for r, v in zip(X, y)) for i in range(k)])
    e = [v - sum(bi * xi for bi, xi in zip(b, r)) for v, r in zip(y, X)]
    S = [[0.0] * k for _ in range(k)]
    for L in range(lags + 1):
        w = 1.0 if L == 0 else 2.0 * (1 - L / (lags + 1))
        for t in range(L, n):
            for i in range(k):
                for j in range(k):
                    S[i][j] += w * 0.5 * e[t] * e[t - L] * (X[t][i] * X[t - L][j] + X[t - L][i] * X[t][j])
    Q = inverse(XtX)
    V = [[sum(Q[i][a] * S[a][c] * Q[c][j] for a in range(k) for c in range(k)) for j in range(k)] for i in range(k)]
    ybar = sum(y) / n
    r2 = 1 - sum(v * v for v in e) / sum((v - ybar) ** 2 for v in y)
    return b, [math.sqrt(V[i][i]) for i in range(k)], r2, n


def regressors(f, k):
    """The spec's row for month k, or None where a series has not started or has a gap (Oct 2025 CPI)."""
    try:
        row = f(k)
    except TypeError:
        return None
    return row if None not in row else None


CRISIS = lambda k: '2008-07' <= k <= '2009-06' or '2020-02' <= k <= '2020-06'
SPECS = {
    'core+noncore': lambda k: [1.0, yoy(D['CPILFESL'], k) - 2.0, yoy(D['CPIAUCSL'], k) - yoy(D['CPILFESL'], k)],
    '+gap': lambda k: [1.0, yoy(D['CPILFESL'], k) - 2.0, yoy(D['CPIAUCSL'], k) - yoy(D['CPILFESL'], k), cbo_gap(k)],
    '+ebp': lambda k: [1.0, yoy(D['CPILFESL'], k) - 2.0, yoy(D['CPIAUCSL'], k) - yoy(D['CPILFESL'], k), D['EBP'].get(k)],
    '+gap+ebp': lambda k: [1.0, yoy(D['CPILFESL'], k) - 2.0, yoy(D['CPIAUCSL'], k) - yoy(D['CPILFESL'], k), cbo_gap(k), D['EBP'].get(k)],
}
NAMES = {'core+noncore': ['a', 'core', 'noncore'], '+gap': ['a', 'core', 'noncore', 'gap'],
         '+ebp': ['a', 'core', 'noncore', 'ebp'], '+gap+ebp': ['a', 'core', 'noncore', 'gap', 'ebp']}
results = {}
for series in ('T10YIE', 'T5YIE'):
    for lo, hi in (('2003-01', '2019-12'), ('2003-01', '2026-12')):
        for drop in (False, True):
            for spec, f in SPECS.items():
                ks = [k for k in sorted(D[series]) if lo <= k <= hi and not (drop and CRISIS(k))]
                pts = [(D[series][k], row) for k in ks if (row := regressors(f, k)) is not None]
                b, se, r2, n = ols_nw([p[0] for p in pts], [p[1] for p in pts])
                tag = f"{series} {lo[:4]}-{min(hi[:4], ks[-1][:4])}{' ex-crisis' if drop else ''} {spec}"
                results[tag] = {nm: [round(v, 3), round(s, 3)] for nm, v, s in zip(NAMES[spec], b, se)} | {'r2': round(r2, 2), 'n': n}
                print(f"{tag:48s} n={n:3d} R2={r2:.2f}  " + '  '.join(f"{nm} {v:+.3f} ({s:.3f})" for nm, v, s in zip(NAMES[spec], b, se)))
json.dump(results, open(os.path.join(H, 'breakeven_fit.json'), 'w'), indent=1)
