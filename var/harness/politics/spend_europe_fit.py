"""Partisan government purchases in parliamentary democracies: does a cabinet further toward the state end of the economic
axis hold government consumption higher? The Diet's purchases process is a log-OU of purchases over potential, so the
lever would move its mean, and the long-run shift is what is estimated.

  s(i,t) = log(real government final consumption / real GDP)              (World Bank NE.CON.GOVT.KD, NY.GDP.MKTP.KD)
  s(i,t) = a_i + d_t + rho s(i,t-1) + b x(i,t-1) + c1 growth(i,t) + c2 growth(i,t-1) + e
  x      = the cabinet's place on the state-market scale (ParlGov state_market, 0 state .. 10 market), its parties'
           weighted by seats, the year's cabinets weighted by days in office; lagged a year, the budget it passes
  long-run shift per point of the scale = b / (1 - rho); per unit of the Diet's size-of-state axis (+1 the state end,
  a full expert scale two units) = -5 b / (1 - rho)
Country and year fixed effects; standard errors clustered by country, the long-run shift's by the delta method.

python3 spend_europe_fit.py
"""
import csv, math, os, json, collections, datetime
H = os.path.dirname(os.path.abspath(__file__))
MAJORITARIAN = {'AUS', 'CAN', 'FRA', 'GBR', 'JPN', 'USA'}
POST_COMMUNIST = {'BGR', 'CZE', 'EST', 'HRV', 'HUN', 'LTU', 'LVA', 'POL', 'ROU', 'SVK', 'SVN'}


def wb(indicator):
    out = {}
    for r in json.load(open(os.path.join(H, 'wb', indicator + '.json')))[1]:
        if r['value'] is not None:
            out[(r['countryiso3code'], int(r['date']))] = float(r['value'])
    return out


G, Y = wb('NE.CON.GOVT.KD'), wb('NY.GDP.MKTP.KD')

# --- Each cabinet's place on the state-market scale, and the year's, weighted by days in office ---
market = {r['party_id']: float(r['state_market']) for r in csv.DictReader(open(os.path.join(H, 'parlgov', 'view_party.csv'))) if r['state_market']}
cabinet = {}
for r in csv.DictReader(open(os.path.join(H, 'parlgov', 'view_cabinet.csv'))):
    c = cabinet.setdefault(r['cabinet_id'], {'iso': r['country_name_short'], 'start': r['start_date'], 'caretaker': r['caretaker'] == '1', 'parties': []})
    if r['cabinet_party'] == '1':
        c['parties'].append((r['party_id'], float(r['seats'] or 0)))


def position(c):
    known = [(market[p], s) for p, s in c['parties'] if p in market and s > 0]
    seats = sum(s for _, s in known)
    if c['caretaker'] or not known or seats < 0.5 * sum(s for _, s in c['parties']):
        return None
    return sum(m * s for m, s in known) / seats


by_country = collections.defaultdict(list)
for c in cabinet.values():
    by_country[c['iso']].append(c)
yearly = {}
for iso, cabs in by_country.items():
    cabs.sort(key=lambda c: c['start'])
    spans = collections.defaultdict(lambda: [0.0, 0.0])
    for c, nxt in zip(cabs, cabs[1:] + [None]):
        a = datetime.date.fromisoformat(c['start'])
        b = datetime.date.fromisoformat(nxt['start']) if nxt else datetime.date(2021, 1, 1)
        x = position(c)
        d = a
        while d < b:
            end = min(b, datetime.date(d.year + 1, 1, 1))
            days = (end - d).days
            if x is not None:
                spans[d.year][0] += x * days
                spans[d.year][1] += days
            d = end
    for year, (sx, days) in spans.items():
        if days >= 183:
            yearly[(iso, year)] = sx / days

# --- Panel ---
def panel(keep, lo, hi):
    rows = []
    for (iso, year), x in yearly.items():
        if not keep(iso) or not (lo <= year + 1 <= hi):
            continue
        t = year + 1
        need = [G.get((iso, t)), Y.get((iso, t)), G.get((iso, t - 1)), Y.get((iso, t - 1)), Y.get((iso, t - 2))]
        if any(v is None or v <= 0 for v in need):
            continue
        g1, y1, g0, y0, ym = need
        rows.append({'iso': iso, 'year': t, 's': math.log(g1 / y1), 'lag': math.log(g0 / y0), 'x': x,
                     'gr': math.log(y1 / y0), 'grl': math.log(y0 / ym)})
    return rows


def demean(rows, cols):
    """Two-way fixed effects by alternating projections (Guimaraes & Portugal 2010)."""
    out = {c: [r[c] for r in rows] for c in cols}
    for c in cols:
        v = out[c]
        for _ in range(500):
            delta = 0.0
            for key in ('iso', 'year'):
                sums, counts = collections.defaultdict(float), collections.defaultdict(int)
                for r, val in zip(rows, v):
                    sums[r[key]] += val
                    counts[r[key]] += 1
                for i, r in enumerate(rows):
                    m = sums[r[key]] / counts[r[key]]
                    v[i] -= m
                    delta = max(delta, abs(m))
            if delta < 1e-12:
                break
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


def fit(rows, names):
    d = demean(rows, names + ['s'])
    n, k = len(rows), len(names)
    X = [[d[c][i] for c in names] for i in range(n)]
    y = d['s']
    XtX = [[sum(X[i][a] * X[i][b] for i in range(n)) for b in range(k)] for a in range(k)]
    beta = solve(XtX, [sum(X[i][a] * y[i] for i in range(n)) for a in range(k)])
    u = [y[i] - sum(beta[j] * X[i][j] for j in range(k)) for i in range(n)]
    inv = [list(c) for c in zip(*[solve(XtX, [1.0 if i == j else 0.0 for i in range(k)]) for j in range(k)])]
    scores = collections.defaultdict(lambda: [0.0] * k)
    for i, r in enumerate(rows):
        for j in range(k):
            scores[r['iso']][j] += X[i][j] * u[i]
    G_ = len(scores)
    meat = [[sum(s[a] * s[b] for s in scores.values()) for b in range(k)] for a in range(k)]
    V = [[G_ / (G_ - 1) * sum(inv[a][c] * meat[c][e] * inv[e][b] for c in range(k) for e in range(k)) for b in range(k)] for a in range(k)]
    return dict(zip(names, beta)), V, math.sqrt(sum(v * v for v in u) / n)


def report(label, rows):
    names = ['lag', 'x', 'gr', 'grl']
    b, V, sd = fit(rows, names)
    ix, il = names.index('x'), names.index('lag')
    rho, bx = b['lag'], b['x']
    lr = bx / (1 - rho)
    # Delta method: d lr / d bx = 1/(1-rho), d lr / d rho = bx/(1-rho)^2.
    gx, gl = 1 / (1 - rho), bx / (1 - rho) ** 2
    se_lr = math.sqrt(gx * gx * V[ix][ix] + 2 * gx * gl * V[ix][il] + gl * gl * V[il][il])
    spread = [r['x'] for r in rows]
    m = sum(spread) / len(spread)
    print(f"{label:44s} n={len(rows):4d} countries={len({r['iso'] for r in rows}):2d}  rho {rho:.3f} ({math.sqrt(V[il][il]):.3f})  "
          f"b {bx:+.5f} ({math.sqrt(V[ix][ix]):.5f})  long run per point {lr:+.4f} ({se_lr:.4f})  per Diet unit {-5 * lr:+.4f} ({5 * se_lr:.4f})  "
          f"cabinet sd {math.sqrt(sum((v - m) ** 2 for v in spread) / len(spread)):.2f}")
    return {'rho': rho, 'b': bx, 'long_run_per_point': lr, 'se': se_lr, 'per_diet_unit': -5 * lr, 'n': len(rows)}


out = {}
west = lambda iso: iso not in MAJORITARIAN and iso not in POST_COMMUNIST
pr = lambda iso: iso not in MAJORITARIAN
every = lambda iso: True
for label, keep in (('established PR democracies', west), ('all PR democracies', pr), ('all ParlGov democracies', every)):
    for era, lo, hi in (('1971-2020', 1971, 2020), ('1971-1989', 1971, 1989), ('1990-2020', 1990, 2020)):
        rows = panel(keep, lo, hi)
        if len({r['iso'] for r in rows}) > 5:
            out[f'{label} {era}'] = report(f'{label}, {era}', rows)
json.dump(out, open(os.path.join(H, 'spend_europe_fit.json'), 'w'), indent=1)
