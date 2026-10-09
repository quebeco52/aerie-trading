"""Party-family vote profiles: how each family's vote moves with the economy, net of governing.

ParlGov parliamentary elections 1946-2020 in the JST macro-history countries, each party tagged with its ParlGov family,
joined to the JST year of the vote. Election fixed effects absorb every shift common to the whole Diet, so what is
estimated is each family's move relative to the conservatives -- all the Diet's additive-logistic shares can use, since
they are renormalised after every move.

  y  = change in a party's log vote share since the last election (parties with >= 1% at both)
  x  = growth gap over the year to the vote (vs the trailing 10-year mean), inflation over the term less 2%,
       change in unemployment over the term, a JST financial crisis in the 5 years before the vote
  spec A: election FE + family dummies; family x macro; incumbent + incumbent x macro
  spec B: election FE + party FE + lagged log share (partial adjustment to each party's own normal vote)
Standard errors clustered by country.

python3 profile_fit.py [--pr]   (--pr: proportional-representation countries only)
"""
import csv, math, os, sys, json, collections
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
import jst_load

D = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'parlgov')
PR_ONLY = '--pr' in sys.argv
MAJORITARIAN = {'AUS', 'CAN', 'FRA', 'GBR', 'JPN'}
BASE = 'con'
MIN_SHARE = 1.0
VARS = ['growth', 'inflation', 'unemployment', 'crisis']

# --- JST ---
jst = {}
for r in jst_load.load():
    if r['iso'] != 'USA':
        jst[(r['iso'], int(r['year']))] = r
def val(iso, y, k):
    r = jst.get((iso, y))
    return None if r is None else r.get(k)

def macro(iso, date, prev_date):
    Y, m = int(date[:4]), int(date[5:7])
    Y0 = int(prev_date[:4])
    ys = [val(iso, y, 'rgdpmad') for y in range(Y - 12, Y + 1)]
    if any(v is None for v in ys):
        return None
    ly = [math.log(v) for v in ys]
    d = [b - a for a, b in zip(ly, ly[1:])]            # d[-1] = growth in Y, d[-2] in Y-1
    w = m / 12.0
    g = w * d[-1] + (1 - w) * d[-2]
    trend = sum(d[-11:-1]) / 10.0
    c1, c0 = val(iso, Y, 'cpi'), val(iso, Y0, 'cpi')
    if c1 is None or c0 is None:
        return None
    pi = (math.log(c1 / c0) / (Y - Y0)) if Y > Y0 else (math.log(c1 / val(iso, Y - 1, 'cpi')) if val(iso, Y - 1, 'cpi') else None)
    u1, u0 = val(iso, Y, 'unemp'), val(iso, Y0, 'unemp')
    if pi is None or u1 is None or u0 is None:
        return None
    crisis = any(val(iso, y, 'crisisJST') == 1.0 for y in range(Y - 5, Y)) or (m >= 7 and val(iso, Y, 'crisisJST') == 1.0)
    return {'growth': g - trend, 'inflation': pi - 0.02, 'unemployment': (u1 - u0) / 100.0, 'crisis': 1.0 if crisis else 0.0}

# --- ParlGov ---
family = {r['party_id']: r['family_name_short'] for r in csv.DictReader(open(f'{D}/view_party.csv'))}
cabinet = collections.defaultdict(set)
for r in csv.DictReader(open(f'{D}/view_cabinet.csv')):
    if r['cabinet_party'] == '1':
        cabinet[r['cabinet_id']].add(r['party_id'])
elections = collections.defaultdict(dict)
meta = {}
for r in csv.DictReader(open(f'{D}/view_election.csv')):
    if r['election_type'] != 'parliament' or not r['vote_share']:
        continue
    iso = r['country_name_short']
    if (iso, 1990) not in jst or (PR_ONLY and iso in MAJORITARIAN):
        continue
    elections[r['election_id']][r['party_id']] = float(r['vote_share'])
    meta[r['election_id']] = (iso, r['election_date'], r['previous_parliament_election_id'], r['previous_cabinet_id'])

rows = []
for e, shares in elections.items():
    iso, date, prev, prev_cab = meta[e]
    if not prev or prev not in elections or not ('1946' <= date[:4] <= '2020'):
        continue
    x = macro(iso, date, meta[prev][1])
    if x is None:
        continue
    for p, s in shares.items():
        s0 = elections[prev].get(p)
        if s0 is None or s < MIN_SHARE or s0 < MIN_SHARE:
            continue
        fam = family.get(p, 'none')
        rows.append({'e': e, 'p': p, 'iso': iso, 'fam': 'other' if fam in ('none', 'spec', 'code') else fam, 'y': math.log(s / s0), 'ly': math.log(s), 'lag': math.log(s0),
                     'inc': 1.0 if p in cabinet.get(prev_cab, set()) else 0.0, **x})
fams = sorted({r['fam'] for r in rows} - {BASE})

# --- estimation ---
def partial(cols, groups, tol=1e-11):
    """Alternating projections: residualise each column on the fixed effects (Guimaraes & Portugal 2010)."""
    out = []
    for c in cols:
        v = list(c)
        for _ in range(10000):
            delta = 0.0
            for g in groups:
                sums, counts = collections.defaultdict(float), collections.defaultdict(int)
                for gi, vi in zip(g, v):
                    sums[gi] += vi; counts[gi] += 1
                for i, gi in enumerate(g):
                    m = sums[gi] / counts[gi]
                    v[i] -= m
                    delta = max(delta, abs(m))
            if delta < tol:
                break
        out.append(v)
    return out

def solve(A, b):
    n = len(A)
    M = [row[:] + [bb] for row, bb in zip(A, b)]
    for i in range(n):
        piv = max(range(i, n), key=lambda r: abs(M[r][i]))
        M[i], M[piv] = M[piv], M[i]
        for r in range(n):
            if r != i and M[i][i] != 0:
                f = M[r][i] / M[i][i]
                M[r] = [a - f * c for a, c in zip(M[r], M[i])]
    return [M[i][n] / M[i][i] for i in range(n)]

def inverse(A):
    n = len(A)
    return [list(col) for col in zip(*[solve(A, [1.0 if i == j else 0.0 for i in range(n)]) for j in range(n)])]

def ols(rows, names, build, yname, groups):
    X = [[build(r, k) for r in rows] for k in names]
    y = [r[yname] for r in rows]
    Xt = partial(X, groups)
    yt = partial([y], groups)[0]
    n, k = len(rows), len(names)
    XtX = [[sum(a * b for a, b in zip(Xt[i], Xt[j])) for j in range(k)] for i in range(k)]
    Xty = [sum(a * b for a, b in zip(Xt[i], yt)) for i in range(k)]
    beta = solve(XtX, Xty)
    u = [yt[i] - sum(beta[j] * Xt[j][i] for j in range(k)) for i in range(n)]
    inv = inverse(XtX)
    clusters = collections.defaultdict(lambda: [0.0] * k)
    for i, r in enumerate(rows):
        for j in range(k):
            clusters[r['iso']][j] += Xt[j][i] * u[i]
    G = len(clusters)
    meat = [[sum(s[a] * s[b] for s in clusters.values()) for b in range(k)] for a in range(k)]
    V = [[sum(inv[a][c] * meat[c][d] * inv[d][b] for c in range(k) for d in range(k)) for b in range(k)] for a in range(k)]
    scale = G / (G - 1)
    se = [math.sqrt(max(0.0, V[j][j] * scale)) for j in range(k)]
    return dict(zip(names, zip(beta, se))), math.sqrt(sum(v * v for v in u) / n)

def build(r, k):
    if k == 'lag': return r['lag']
    if k == 'inc': return r['inc']
    if k.startswith('inc*'): return r['inc'] * r[k[4:]]
    if k.startswith('fam:'): return 1.0 if r['fam'] == k[4:] else 0.0
    f, v = k.split('*')
    return r[v] if r['fam'] == f else 0.0

core = ['inc'] + [f'inc*{v}' for v in VARS] + [f'{f}*{v}' for f in fams for v in VARS]
eid = [r['e'] for r in rows]
pid = [r['p'] for r in rows]
A, sdA = ols(rows, core + [f'fam:{f}' for f in fams], build, 'y', [eid])
B, sdB = ols(rows, ['lag'] + core, build, 'ly', [eid, pid])

print(f"{'PR countries' if PR_ONLY else 'all JST countries'}: {len(rows)} party-votes, {len(set(eid))} elections, {len(set(pid))} parties, "
      f"{len({r['iso'] for r in rows})} countries; residual sd A {sdA:.3f}, B {sdB:.3f}")
print(f"crisis windows: {sum(1 for e in set(eid) if next(r for r in rows if r['e'] == e)['crisis'])} elections")
print(f"B: persistence of log share {B['lag'][0]:.3f} ({B['lag'][1]:.3f})")
print('\nGoverning (log share):')
for k in ['inc'] + [f'inc*{v}' for v in VARS]:
    print(f"  {k:22s} A {A[k][0]:+8.3f} ({A[k][1]:.3f})   B {B[k][0]:+8.3f} ({B[k][1]:.3f})")
print(f"\nFamilies against the conservatives (log share per unit; growth, inflation, unemployment as fractions; crisis 0/1):")
counts = collections.Counter(r['fam'] for r in rows)
print(f"  {'family':6s} {'n':>5s} {'spec':4s} " + ''.join(f'{v:>18s}' for v in VARS))
for f in fams:
    for name, M in (('A', A), ('B', B)):
        cells = []
        for v in VARS:
            b, se = M[f'{f}*{v}']
            cells.append(f"{b:+7.2f} ({se:4.2f}){'*' if abs(b) > 1.96 * se else ' '}")
        print(f"  {f if name == 'A' else '':6s} {counts[f] if name == 'A' else '':>5} {name:4s} " + ''.join(f'{c:>18s}' for c in cells))
json.dump({'rows': len(rows), 'A': A, 'B': B, 'counts': counts}, open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'profile_fit' + ('_pr' if PR_ONLY else '') + '.json'), 'w'), indent=1)
