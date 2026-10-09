"""Drehmann-Juselius-Korinek replication on engine paths: python3 var/harness/djk_an.py [dir] [arms...]

Runs DJK (NBER w24549, 2018) eq. 7-9 local projections on CreditMacroProbeTest rows (fc_run.sh output), annual,
seed fixed effects, their BASE controls with one lag: real GDP growth, the ex-post real policy rate, the mortgage
spread over it, the change in the effective rate on the debt stock, real house-price growth and a crisis-start dummy.

  b = new borrowing / GDP = (change in debt + amortisation) / nominal GDP;  s = debt service / GDP.
  Debt = DTI x disposable income, disposable income = 0.727 x GDP (US 1976-2019), the engine's own convention.
  Amortisation = DSR - DTI x r (the BIS annuity the engine computes, DSR = DTI x (r + delta)).

Targets: DJK Appendix D, BASE row. Table 8 (GDP growth on b), Table 9 (on s), Table 6 (b persistence), Table 7 (s on b).
Also: the US lead-lag of the 3y change in DTI against the CBO gap (var/harness/us_*.csv, hhcredit_data.json), and the
cycle moments against CBO 1985-2019.
"""
import glob, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))

D = sys.argv[1] if len(sys.argv) > 1 else os.path.join(H, 'fc_runs')
ARMS = sys.argv[2:] or ['base']
BURN = 10.0
DPI_GDP = 0.727
MORTGAGE_SHARE, MORTGAGE_SPREAD, CONSUMER_SPREAD = 0.70, 0.017, 0.08
def ols_fe_cluster(groups):
    """(From stab_an.py.) OLS with group fixed effects and group-clustered SEs. groups: list of (Y list, X list-of-lists)."""
    Ys, Xs, gid = [], [], []
    for g, (Y, X) in enumerate(groups):
        if len(Y) < 3:
            continue
        my = st.mean(Y)
        mx = [st.mean(col) for col in zip(*X)]
        for yv, xv in zip(Y, X):
            Ys.append(yv - my)
            Xs.append([a - b for a, b in zip(xv, mx)])
            gid.append(g)
    k = len(Xs[0])
    XtX = [[sum(x[i] * x[j] for x in Xs) for j in range(k)] for i in range(k)]
    XtY = [sum(x[i] * y for x, y in zip(Xs, Ys)) for i in range(k)]
    inv = invert(XtX)
    b = [sum(inv[i][j] * XtY[j] for j in range(k)) for i in range(k)]
    resid = [y - sum(bi * xi for bi, xi in zip(b, x)) for x, y in zip(Xs, Ys)]
    meat = [[0.0] * k for _ in range(k)]
    for g in set(gid):
        s = [sum(Xs[n][i] * resid[n] for n in range(len(Xs)) if gid[n] == g) for i in range(k)]
        for i in range(k):
            for j in range(k):
                meat[i][j] += s[i] * s[j]
    V = mat(mat(inv, meat), inv)
    return b, [math.sqrt(max(0.0, V[i][i])) for i in range(k)], len(Ys)


def invert(A):
    n = len(A)
    M = [row[:] + [1.0 if i == j else 0.0 for j in range(n)] for i, row in enumerate(A)]
    for c in range(n):
        p = max(range(c, n), key=lambda r: abs(M[r][c]))
        M[c], M[p] = M[p], M[c]
        pv = M[c][c]
        M[c] = [v / pv for v in M[c]]
        for r in range(n):
            if r != c:
                f = M[r][c]
                M[r] = [a - f * b for a, b in zip(M[r], M[c])]
    return [row[n:] for row in M]


def mat(A, B):
    return [[sum(A[i][t] * B[t][j] for t in range(len(B))) for j in range(len(B[0]))] for i in range(len(A))]


DJK = {
    'yb': [0.126, 0.107, 0.024, -0.050, -0.097, -0.130, -0.086, -0.065],
    'ys': [-0.224, -0.268, -0.216, -0.152, -0.094, -0.009, 0.001, 0.021],
    'bb': [0.883, 0.804, 0.689, 0.537, 0.363, 0.244, 0.127, -0.005],
    'sb': [0.126, 0.233, 0.297, 0.337, 0.344, 0.344, 0.316, 0.281],
}
US_LEADLAG = [0.41, 0.53, 0.51, 0.39, 0.19, -0.03, -0.24]  # corr(3y dDTI_t, gap_{t+k}), k = -8..+16q, US 1976-2019


def load(arm):
    by = {}
    for p in sorted(glob.glob(f'{D}/{arm}-*.json')):
        for r in json.load(open(p)):
            by.setdefault(r['seed'], []).append(r)
    return by


def annual(q):
    """Quarter rows -> year rows (years after BURN)."""
    g_trend = math.log(q[-1]['ngdp'] / q[0]['ngdp']) / (q[-1]['t'] - q[0]['t'])
    years = {}
    for r in q:
        y = math.ceil(r['t'] - 1e-9)
        years.setdefault(y, []).append(r)
    out = []
    prev_end = None
    for y in sorted(years):
        rs = years[y]
        if len(rs) < 4:
            prev_end = rs[-1]
            continue
        eff = [MORTGAGE_SHARE * (r['y10_ema'] + MORTGAGE_SPREAD) + (1 - MORTGAGE_SHARE) * (max(0.0, r['pol_ema']) + CONSUMER_SPREAD) for r in rs]
        # Debt is a ratio to income in the engine, so debt read off the year-end GDP level would book a strong fourth
        # quarter as borrowing. New borrowing per GDP is the year's change in leverage plus what keeps it level as
        # income grows at its trend and what repays the stock: c x (dDTI + DTI x (g + delta)).
        amort = st.mean(max(0.0, r['dsr'] - r['dti'] * e) for r, e in zip(rs, eff))
        dti_avg = st.mean(r['dti'] for r in rs)
        row = {
            'y': y,
            'lgdp': math.log(st.mean(r['pot'] * (1 + r['gap_avg']) for r in rs)),
            'b': DPI_GDP * ((rs[-1]['dti'] - prev_end['dti']) + dti_avg * g_trend + amort) if prev_end else None,
            's': DPI_GDP * st.mean(r['dsr'] for r in rs),
            'rr': st.mean(r['policy'] - r['infl'] for r in rs),
            'spr': st.mean(r['y10_ema'] + MORTGAGE_SPREAD - r['policy'] for r in rs),
            'eff': st.mean(eff),
            'lhp': math.log(st.mean(r['resi'] for r in rs)),
            'crisis': 1.0 if any(r['crisis'] is not None and y - 1 < r['crisis'] <= y for r in rs) else 0.0,
            'gap': st.mean(r['gap_avg'] for r in rs),
            'dti_end': st.mean(r['dti'] for r in rs),
        }
        out.append(row)
        prev_end = rs[-1]
    for a, b in zip(out, out[1:]):
        b['dy'] = b['lgdp'] - a['lgdp']
        b['deff'] = b['eff'] - a['eff']
        b['dhp'] = b['lhp'] - a['lhp']
    return [r for r in out if r['y'] > BURN and 'dy' in r and r['b'] is not None]


CTRL = ['dy', 'rr', 'spr', 'deff', 'dhp', 'crisis']


def lp(seeds, lhs, horizons=8):
    """DJK eq. 7-9: lhs_{t+h-1} on b_{t-1}, s_{t-1}, controls_{t-1}, controls_{t-2}; seed FE, seed-clustered SE."""
    res = []
    for h in range(1, horizons + 1):
        groups = []
        for rows in seeds:
            Y, X = [], []
            for i in range(2, len(rows) - h + 1):
                t1, t2 = rows[i - 1], rows[i - 2]
                Y.append(rows[i + h - 1][lhs])
                X.append([t1['b'], t1['s']] + [t1[c] for c in CTRL] + [t2[c] for c in CTRL])
            groups.append((Y, X))
        b, se, n = ols_fe_cluster(groups)
        res.append((b[0], se[0], b[1], se[1]))
    return res


def corr(a, b):
    ma, mb = st.mean(a), st.mean(b)
    sa = math.sqrt(sum((x - ma) ** 2 for x in a)); sb = math.sqrt(sum((y - mb) ** 2 for y in b))
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / (sa * sb)


def leadlag(by):
    out = []
    for k in (-8, -4, 0, 4, 8, 12, 16):
        xs, ys = [], []
        for q in by.values():
            q = [r for r in q if r['t'] > BURN]
            for t in range(12, len(q)):
                if 0 <= t + k < len(q):
                    xs.append(100 * (q[t]['dti'] - q[t - 12]['dti'])); ys.append(q[t + k]['gap_avg'])
        out.append(corr(xs, ys))
    return out


def cycle(by, w=35.0):
    rows = []
    for q in by.values():
        g = [100 * r['gap_avg'] for r in q if BURN < r['t'] <= BURN + w]
        n = len(g); m = sum(g) / n; sd = math.sqrt(sum((x - m) ** 2 for x in g) / n)
        rows.append((m, sd, max(g), min(g), sum(x > 1 for x in g) / n, sum(x > 2 for x in g) / n, sum(x < -2 for x in g) / n))
    return [st.median(c) for c in zip(*rows)]


def crises(by):
    n, yrs = 0, 0.0
    for q in by.values():
        q = [r for r in q if r['t'] > BURN]
        n += len({r['crisis'] for r in q if r['crisis'] is not None and r['crisis'] > BURN})
        yrs += q[-1]['t'] - q[0]['t']
    return 100 * n / yrs


for arm in ARMS:
    by = load(arm)
    if not by:
        continue
    seeds = [annual(q) for q in by.values()]
    gy = lp(seeds, 'dy')
    gb = lp(seeds, 'b')
    gs = lp(seeds, 's')
    print(f"\n=== {arm}: {len(seeds)} seeds, {sum(len(s) for s in seeds)} seed-years")
    print("  h      GDP<-b (DJK)          GDP<-s (DJK)          b<-b (DJK)       s<-b (DJK)")
    for h in range(8):
        print(f"  {h + 1}  {gy[h][0]:+.3f}±{gy[h][1]:.3f} ({DJK['yb'][h]:+.3f})  {gy[h][2]:+.3f}±{gy[h][3]:.3f} ({DJK['ys'][h]:+.3f})  "
              f"{gb[h][0]:+.3f} ({DJK['bb'][h]:+.3f})  {gs[h][0]:+.3f} ({DJK['sb'][h]:+.3f})")
    rm = lambda k, idx, src: math.sqrt(st.mean((src[h][idx] - DJK[k][h]) ** 2 for h in range(8)))
    print(f"  RMSE: GDP<-b {rm('yb', 0, gy):.3f}  GDP<-s {rm('ys', 2, gy):.3f}  b<-b {rm('bb', 0, gb):.3f}  s<-b {rm('sb', 0, gs):.3f}")
    ll = leadlag(by)
    print("  corr(3y dDTI, gap_{t+k}) k=-8..+16q: " + ' '.join(f"{c:+.2f}" for c in ll) + "   US " + ' '.join(f"{c:+.2f}" for c in US_LEADLAG))
    c = cycle(by)
    print(f"  cycle (35y median): mean {c[0]:+.2f} sd {c[1]:.2f} max {c[2]:+.2f} min {c[3]:+.2f} >+1 {c[4]:.1%} >+2 {c[5]:.1%} <-2 {c[6]:.1%}"
          f"   | CBO 85-19 sd 1.62 max +2.48 >+1 22% >+2 1% <-2 17%   | crises/century {crises(by):.2f}")
    ac = lambda key: corr([s[i][key] for s in seeds for i in range(1, len(s))], [s[i - 1][key] for s in seeds for i in range(1, len(s))])
    print(f"  raw AR(1) of annual b: {ac('b'):+.2f}")
    # US real FHFA HPI / CPI 1977-2019 annual growth: sd 3.86%, AR 0.71 / 0.33 / 0.00; annual dDTI sd 3.37pp, AR1 0.80.
    hp = [(s[i]['dhp'], s[i - 1]['dhp'], s[i - 2]['dhp'] if i >= 2 else None, s[i - 3]['dhp'] if i >= 3 else None) for s in seeds for i in range(1, len(s))]
    ac_k = lambda k: corr([h[0] for h in hp if h[k] is not None], [h[k] for h in hp if h[k] is not None])
    ddti = [(s[i]['dti_end'] - s[i - 1]['dti_end'], s[i - 1]['dti_end'] - s[i - 2]['dti_end']) for s in seeds for i in range(2, len(s))]
    print(f"  house growth sd {100 * st.pstdev(h[0] for h in hp):.2f}% AR {ac_k(1):+.2f} {ac_k(2):+.2f} {ac_k(3):+.2f}   (US 3.86% +0.71 +0.33 +0.00)"
          f"  | annual dDTI sd {100 * st.pstdev(d[0] for d in ddti):.2f}pp AR1 {corr([d[0] for d in ddti], [d[1] for d in ddti]):+.2f}  (US 3.37pp +0.80)")
    bs = [r['b'] for s in seeds for r in s]
    print(f"  b mean {100 * st.mean(bs):.2f}% GDP sd {100 * st.pstdev(bs):.2f}  s mean {100 * st.mean(r['s'] for s in seeds for r in s):.2f}%")
