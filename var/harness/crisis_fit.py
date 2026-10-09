"""Credit-crisis hazard on JST household credit.

gap = household credit/GDP - its Basel III one-sided HP trend (lambda 400,000 quarterly = 1,562.5 annual), which
follows the secular financial deepening a slow EMA lags;
P(crisis starts in year t) = logistic(b0 + b1 * gap_{t-1}), off inside 5 years of the last crisis and in war years.
"""
import math, json, os
import jst_load

H = os.path.dirname(os.path.abspath(__file__))
rows = jst_load.load()
WAR = set(range(1914, 1920)) | set(range(1939, 1947))
REFRACTORY = 5
LAMBDA_ANNUAL = 400000.0 / 4 ** 4   # Basel III one-sided HP, lambda 400,000 on quarterly data (Ravn & Uhlig 2002 scaling)


def hp_trend_last(y, lam):
    """Last point of the HP trend of y: (I + lam D'D) tau = y, solved by banded Cholesky (bandwidth 2)."""
    n = len(y)
    if n < 3:
        return y[-1]
    # diagonals of A = I + lam * D'D
    d0 = [1.0 + lam * v for v in ([1, 5] + [6] * (n - 4) + [5, 1])] if n >= 4 else None
    if n == 3:
        d0 = [1 + lam, 1 + 4 * lam, 1 + lam]
    d1 = [lam * v for v in ([-2] + [-4] * (n - 3) + [-2])] if n >= 3 else []
    if n == 3:
        d1 = [-2 * lam, -2 * lam]
    d2 = [lam] * (n - 2)
    # banded LDL^T
    L1, L2, D = [0.0] * n, [0.0] * n, [0.0] * n
    for i in range(n):
        a = d0[i] - (L1[i] ** 2) * (D[i - 1] if i >= 1 else 0) - (L2[i] ** 2) * (D[i - 2] if i >= 2 else 0)
        D[i] = a
        if i + 2 < n:
            L2[i + 2] = d2[i] / D[i]
        if i + 1 < n:
            L1[i + 1] = (d1[i] - (L1[i] * L2[i + 1] * D[i - 1] if i >= 1 else 0.0)) / D[i]
    z = [0.0] * n
    for i in range(n):
        z[i] = y[i] - (L1[i] * z[i - 1] if i >= 1 else 0) - (L2[i] * z[i - 2] if i >= 2 else 0)
    x = [0.0] * n
    for i in range(n - 1, -1, -1):
        x[i] = z[i] / D[i] - (L1[i + 1] * x[i + 1] if i + 1 < n else 0) - (L2[i + 2] * x[i + 2] if i + 2 < n else 0)
    return x[-1]


obs = []
by = {}
for r in rows:
    by.setdefault(r['iso'], []).append(r)
for iso, rs in by.items():
    rs.sort(key=lambda r: r['year'])
    history, first, last_crisis, prev_gap = [], None, -99, None
    for r in rs:
        y = int(r['year'])
        if r['thh'] is None or r['gdp'] in (None, 0.0):
            history, prev_gap = [], None
            continue
        ratio = r['thh'] / r['gdp']
        history.append(ratio)
        first = y if first is None else first
        gap = ratio - hp_trend_last(history, LAMBDA_ANNUAL)
        crisis = 1 if r['crisisJST'] == 1.0 else 0
        if prev_gap is not None and y - first >= 10 and y not in WAR and y - last_crisis > REFRACTORY:
            obs.append((iso, y, prev_gap, crisis))
        if crisis:
            last_crisis = y
        prev_gap = gap


def logit_mle(X, Y):
    b = [0.0] * len(X[0])
    for _ in range(50):
        g = [0.0] * len(b)
        Hs = [[0.0] * len(b) for _ in b]
        for x, y in zip(X, Y):
            p = 1 / (1 + math.exp(-sum(bi * xi for bi, xi in zip(b, x))))
            for i in range(len(b)):
                g[i] += (y - p) * x[i]
                for j in range(len(b)):
                    Hs[i][j] -= p * (1 - p) * x[i] * x[j]
        # Newton step: b -= H^-1 g
        n = len(b)
        M = [Hs[i][:] + [g[i]] for i in range(n)]
        for c in range(n):
            pv = max(range(c, n), key=lambda r_: abs(M[r_][c]))
            M[c], M[pv] = M[pv], M[c]
            for r_ in range(n):
                if r_ != c:
                    f = M[r_][c] / M[c][c]
                    M[r_] = [a - f * bb for a, bb in zip(M[r_], M[c])]
        step = [M[i][n] / M[i][i] for i in range(n)]
        b = [bi - si for bi, si in zip(b, step)]
        if max(abs(s) for s in step) < 1e-10:
            break
    # standard errors from -H^-1
    n = len(b)
    Minv = [Hs[i][:] + [1.0 if i == j else 0.0 for j in range(n)] for i in range(n)]
    for c in range(n):
        pv = max(range(c, n), key=lambda r_: abs(Minv[r_][c]))
        Minv[c], Minv[pv] = Minv[pv], Minv[c]
        d = Minv[c][c]
        Minv[c] = [v / d for v in Minv[c]]
        for r_ in range(n):
            if r_ != c:
                f = Minv[r_][c]
                Minv[r_] = [a - f * bb for a, bb in zip(Minv[r_], Minv[c])]
    se = [math.sqrt(-Minv[i][n + i]) for i in range(n)]
    ll = sum(y * math.log(1 / (1 + math.exp(-sum(bi * xi for bi, xi in zip(b, x))))) + (1 - y) * math.log(1 - 1 / (1 + math.exp(-sum(bi * xi for bi, xi in zip(b, x))))) for x, y in zip(X, Y))
    return b, se, ll


for label, sel in [('all years', lambda o: True), ('post-1950', lambda o: o[1] >= 1950)]:
    sub = [o for o in obs if sel(o)]
    X = [[1.0, o[2]] for o in sub]
    Y = [o[3] for o in sub]
    b, se, ll = logit_mle(X, Y)
    base = sum(Y) / len(Y)
    gaps = sorted(o[2] for o in sub)
    sd = (sum((g - sum(gaps) / len(gaps)) ** 2 for g in gaps) / len(gaps)) ** 0.5
    print(f"{label}: n {len(sub)}, crises {sum(Y)} ({base * 100:.1f}%/yr outside refractory); b0 {b[0]:+.2f} ({se[0]:.2f}), b1 {b[1]:+.2f} ({se[1]:.2f}) per unit of household credit/GDP gap; "
          f"gap sd {sd * 100:.1f} pts of GDP; hazard at gap 0 {100 / (1 + math.exp(-b[0])):.2f}%, at +10pts {100 / (1 + math.exp(-b[0] - 0.10 * b[1])):.1f}%")
    if label == 'post-1950':
        json.dump({'b0': b[0], 'b0_se': se[0], 'b1_gdp_points': b[1], 'b1_se': se[1], 'gap_sd_gdp_points': sd, 'n': len(sub), 'crises': sum(Y)},
                  open(os.path.join(H, 'crisis_fit.json'), 'w'), indent=1)
us = [o for o in obs if o[0] == 'USA' and o[1] in (1929, 1984, 2006, 2007)]
print('US gaps (points of GDP, prior year):', [(o[1], round(o[2] * 100, 1)) for o in us])
