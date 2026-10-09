"""Fund-financed stabilisation (2026-09-27 hybrid): python3 var/harness/stab_an.py [runs dir]

Reads a fund harness batch with arms fund / nostab / nofund (fund_arms.sh, FundHarnessTest.php) and prints:
  1. the IMF Norway Selected Issues 2025 Table 5 regression on the engine's annual discretionary balance,
     dB_y = a_seed + b1 gap_y + b2 dB_(y-1), with B = tax-rate deviation - purchases above baseline - the fund's
     stabilisation (all % of GDP at trend output), seed fixed effects and seed-clustered standard errors.
     Norway: b1 +0.450 (0.194), b2 -0.452 (0.093). The nostab arm is the US-fitted tax leg alone.
  2. a sanity regression of the stabilisation alone, which should recover the rule's signs and rough sizes;
  3. the gate: fund vs nostab (paired by seed) and fund vs nofund (independent) on the cycle, the floor, the curve
     and the fund's own solvency.
"""
import glob, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
D = sys.argv[1] if len(sys.argv) > 1 else os.path.join(H, 'fund_runs180g')
TAX = 0.21            # MacroEngine::TARGET_CORPORATE_TAX_RATE, also the purchases share of GDP
ELB = 0.00105         # MacroEngine::EFFECTIVE_LOWER_BOUND 0.001, with rounding room
BURN_YEARS = 5        # inception and the first budget rounds

src = open(os.path.join(H, 'fund_an.py')).read()
lib = {'__file__': os.path.join(H, 'fund_an.py'), '__name__': 'lib'}
exec(compile(src[:src.rindex('\nmain()')], 'fund_an', 'exec'), lib)  # the helpers, without its main()
run_stats = lib['run_stats']


def load(arm):
    out = {}
    for p in sorted(glob.glob(f'{D}/{arm}-*.jsonl')):
        for line in open(p):
            r = json.loads(line)
            out[r['seed']] = r
    return out


def annual(r):
    """Annual averages of the quarterly rows, keyed by integer year."""
    years = {}
    for q in r['quarters']:
        y = int(q['t'] - 1e-9)
        years.setdefault(y, []).append(q)
    rows = []
    for y in sorted(years):
        qs = years[y]
        if len(qs) < 4 or y < BURN_YEARS:
            continue
        m = lambda k: st.mean(x.get(k, 0.0) for x in qs)
        stab = m('stab')
        B = (m('tax') - TAX) - TAX * (m('gov') / 100.0 - 1.0) - stab
        rows.append({'y': y, 'gap': 100 * m('gap'), 'B': 100 * B, 'D': 100 * stab})
    return rows


def ols_fe_cluster(groups):
    """OLS with group fixed effects and group-clustered SEs. groups: list of (Y list, X list-of-lists)."""
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


def imf_regression(runs, label):
    groups = []
    for r in runs.values():
        a = annual(r)
        Y, X = [], []
        for prev2, prev, cur in zip(a, a[1:], a[2:]):
            Y.append(cur['B'] - prev['B'])
            X.append([cur['gap'], prev['B'] - prev2['B']])
        groups.append((Y, X))
    b, se, n = ols_fe_cluster(groups)
    print(f"  {label:8s} gap {b[0]:+.3f} ({se[0]:.3f})  lagged change {b[1]:+.3f} ({se[1]:.3f})  n {n}")
    return b


def rule_sanity(runs):
    groups = []
    for r in runs.values():
        a = annual(r)
        Y, X = [], []
        for prev2, prev, cur in zip(a, a[1:], a[2:]):
            Y.append(cur['D'] - prev['D'])
            X.append([cur['gap'], prev['D'] - prev2['D'], prev['D']])
        groups.append((Y, X))
    b, se, n = ols_fe_cluster(groups)
    print(f"  dD on gap {b[0]:+.3f} ({se[0]:.3f}), last change {b[1]:+.3f} ({se[1]:.3f}), level {b[2]:+.3f} ({se[2]:.3f})")


def extra_stats(r):
    q = r['quarters']
    gap = [x['gap'] for x in q]
    s = {'floor_pct': 100 * sum(1 for x in q if x['policy'] <= ELB) / len(q)}
    # Busts: excursions below -2%, from the zero crossing before to the one after (the CBO table's definition).
    depths, recov, i = [], [], 0
    while i < len(gap):
        if gap[i] < -0.02:
            j = i
            while j > 0 and gap[j - 1] < 0:
                j -= 1
            k = i
            while k < len(gap) and gap[k] < 0:
                k += 1
            seg = gap[j:k]
            trough = j + seg.index(min(seg))
            depths.append(-100 * min(seg))
            if k < len(gap):
                recov.append(k - trough)
            i = k
        else:
            i += 1
    years = r['years']
    s['busts_per_century'] = 100 * len(depths) / years
    s['bust_depth'] = st.mean(depths) if depths else float('nan')
    s['bust_recovery_q'] = st.mean(recov) if recov else float('nan')
    stab = [100 * x.get('stab', 0.0) for x in q if x['t'] >= BURN_YEARS]
    s['stab_sd'] = st.pstdev(stab) if stab else 0.0
    s['stab_max'] = max(stab) if stab else 0.0
    s['stab_min'] = min(stab) if stab else 0.0
    fund = [x['fund_gdp'] for x in q if x['fund_gdp'] > 0]
    s['fund_min'] = min(fund) if fund else float('nan')
    return s


def all_stats(r):
    s = run_stats(r)
    s.update(extra_stats(r))
    return s


def compare(a, b, paired, keys, label):
    print(f"\n{label}")
    seeds = sorted(set(a) & set(b))
    ok = lambda row, k: k in row and row[k] == row[k]   # present and not NaN
    for k in keys:
        xa = [a[s][k] for s in seeds if ok(a[s], k)]
        xb = [b[s][k] for s in seeds if ok(b[s], k)]
        if len(xa) < 2 or len(xb) < 2:
            continue
        if paired:
            d = [a[s][k] - b[s][k] for s in seeds if ok(a[s], k) and ok(b[s], k)]
            diff, se = st.mean(d), st.stdev(d) / math.sqrt(len(d))
        else:
            diff = st.mean(xa) - st.mean(xb)
            se = math.sqrt(st.variance(xa) / len(xa) + st.variance(xb) / len(xb))
        z = diff / se if se > 0 else float('nan')
        flag = '  <--' if abs(z) > 2 else ''
        print(f"  {k:22s} {st.mean(xa):8.3f} vs {st.mean(xb):8.3f}  diff {diff:+8.3f} ± {se:6.3f}  z {z:+5.1f}{flag}")


arms = {a: load(a) for a in ('fund', 'nostab', 'nofund')}
print(f"{D}: " + ', '.join(f"{a} {len(v)}" for a, v in arms.items()))

print("\nIMF Table 5 regression on the annual discretionary balance (Norway: gap +0.450 (0.194), lagged -0.452 (0.093))")
for a in ('fund', 'nostab', 'nofund'):
    if arms[a]:
        imf_regression(arms[a], a)
if arms['fund']:
    print("\nRule sanity (fund arm, annual averages; the rule itself is -beta, -phi, -kappa on half-yearly readings)")
    rule_sanity(arms['fund'])

S = {a: {s: all_stats(r) for s, r in v.items()} for a, v in arms.items()}
KEYS = ['gap_sd', 'acf4', 'acf8', 'acf12', 'acf16', 'gap_skew', 'busts_per_century', 'bust_depth', 'bust_recovery_q',
        'busts_pct_q', 'booms_pct_q', 'floor_pct', 'inverted_pct', 'y10_mean', 'debt_late', 'fund_gdp_last', 'fund_min',
        'stab_sd', 'stab_max', 'stab_min', 'max_dd', 'deaths']
if arms['fund'] and arms['nostab']:
    compare(S['fund'], S['nostab'], True, KEYS, 'fund (stabilisation) vs nostab, paired by seed')
if arms['fund'] and arms['nofund']:
    compare(S['fund'], S['nofund'], False, KEYS, 'fund (stabilisation) vs nofund, independent')
