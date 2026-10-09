"""The economic vote in proportional-representation parliaments: the outgoing cabinet's combined vote share on growth over
the year to the vote and inflation over the term, in the Diet's own units (PoliticsEngine::economicVote).

  y = sum over the outgoing cabinet's parties of (share now - share at the last vote), as a fraction
  g = real growth per head over the year to the vote (month-weighted) less its trailing 10-year mean
  p = annualised inflation over the term less 2%
  spec 1: y = a + bg g + bp p                       (Fair's form)
  spec 2: + country fixed effects                   (slopes from each country's own swings)
  spec 3: slopes by cabinet type: single-party majority, single-party minority, coalition (Powell & Whitten 1993)
  clarity: the Diet's form. Growth enters for a party governing alone, inflation for every cabinet, and the coalitions
           that governed through the euro-area consolidations of 2008-2020 get a growth term of their own, the one
           period their swing moves with growth at all (negatively, the recovery coming with austerity).
Standard errors clustered by country (CR1).

Samples: JST = the 12 JST PR democracies 1946-2020 (rgdpmad, CPI); WB = every ParlGov PR democracy 1971-2020 (World
Bank real GDP per head, GDP deflator, wb/*.json), with and without the post-communist ones. Caretaker cabinets and
cabinets a party of which did not stand at both votes are dropped.

python3 vote_europe_fit.py
"""
import csv, math, os, sys, json, collections
H = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(H, '..'))
import jst_load

MAJORITARIAN = {'AUS', 'CAN', 'FRA', 'GBR', 'JPN', 'USA'}
POST_COMMUNIST = {'BGR', 'CZE', 'EST', 'HRV', 'HUN', 'LTU', 'LVA', 'POL', 'ROU', 'SVK', 'SVN'}
TARGET = 0.02


# --- Macro series: level by (iso, year) ---
def wb(indicator):
    out = {}
    for r in json.load(open(os.path.join(H, 'wb', indicator + '.json')))[1]:
        if r['value'] is not None:
            out[(r['countryiso3code'], int(r['date']))] = float(r['value'])
    return out

WB_GDP, WB_DEFL, WB_CPI = wb('NY.GDP.PCAP.KD'), wb('NY.GDP.DEFL.ZS'), wb('FP.CPI.TOTL')
JST = {(r['iso'], int(r['year'])): r for r in jst_load.load() if r['iso'] != 'USA'}
JST_GDP = {k: r['rgdpmad'] for k, r in JST.items() if r.get('rgdpmad')}
JST_CPI = {k: r['cpi'] for k, r in JST.items() if r.get('cpi')}


def gaps(gdp, prices, iso, date, prev_date):
    Y, m, Y0 = int(date[:4]), int(date[5:7]), int(prev_date[:4])
    levels = [gdp.get((iso, y)) for y in range(Y - 11, Y + 1)]
    if any(v is None or v <= 0 for v in levels) or Y <= Y0:
        return None
    d = [math.log(b / a) for a, b in zip(levels, levels[1:])]
    w = m / 12.0
    g = w * d[-1] + (1 - w) * d[-2] - sum(d[-11:-1]) / 10.0
    p1, p0 = prices.get((iso, Y)), prices.get((iso, Y0))
    if not p1 or not p0:
        return None
    return g, math.log(p1 / p0) / (Y - Y0) - TARGET


# --- ParlGov: each vote and the cabinet that went into it ---
cabinets = collections.defaultdict(dict)
caretaker = {}
for r in csv.DictReader(open(os.path.join(H, 'parlgov', 'view_cabinet.csv'))):
    caretaker[r['cabinet_id']] = r['caretaker'] == '1'
    if r['cabinet_party'] == '1':
        cabinets[r['cabinet_id']][r['party_id']] = float(r['seats'] or 0), float(r['election_seats_total'] or 0)
votes = collections.defaultdict(dict)
meta = {}
for r in csv.DictReader(open(os.path.join(H, 'parlgov', 'view_election.csv'))):
    if r['election_type'] != 'parliament' or not r['vote_share']:
        continue
    votes[r['election_id']][r['party_id']] = float(r['vote_share']) / 100.0
    meta[r['election_id']] = (r['country_name_short'], r['election_date'], r['previous_parliament_election_id'], r['previous_cabinet_id'])


def sample(source):
    rows = []
    for e, shares in votes.items():
        iso, date, prev, cab = meta[e]
        if iso in MAJORITARIAN or (iso == 'NZL' and date < '1996') or prev not in votes or not cab or caretaker.get(cab, True):
            continue
        parties = cabinets.get(cab, {})
        if not parties or any(p not in shares or p not in votes[prev] for p in parties):
            continue
        if source == 'JST':
            if not ('1946' <= date[:4] <= '2020') or (iso, 1990) not in JST:
                continue
            x = gaps(JST_GDP, JST_CPI, iso, date, meta[prev][1])
        else:
            if not ('1971' <= date[:4] <= '2020'):
                continue
            x = gaps(WB_GDP, WB_DEFL, iso, date, meta[prev][1])
        if x is None:
            continue
        seats = sum(s for s, _ in parties.values())
        total = max(t for _, t in parties.values())
        kind = 'coalition' if len(parties) > 1 else ('single majority' if total > 0 and seats > total / 2 else 'single minority')
        rows.append({'iso': iso, 'year': int(date[:4]), 'y': sum(shares[p] - votes[prev][p] for p in parties), 'g': x[0], 'p': x[1], 'kind': kind})
    return rows


# --- Estimation ---
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


def ols(rows, names, build, fe=False):
    X = [[build(r, k) for k in names] for r in rows]
    y = [r['y'] for r in rows]
    if fe:  # within transformation by country
        groups = collections.defaultdict(list)
        for i, r in enumerate(rows):
            groups[r['iso']].append(i)
        for idx in groups.values():
            for j in range(len(names)):
                m = sum(X[i][j] for i in idx) / len(idx)
                for i in idx:
                    X[i][j] -= m
            m = sum(y[i] for i in idx) / len(idx)
            for i in idx:
                y[i] -= m
    k = len(names)
    XtX = [[sum(X[i][a] * X[i][b] for i in range(len(rows))) for b in range(k)] for a in range(k)]
    beta = solve(XtX, [sum(X[i][a] * y[i] for i in range(len(rows))) for a in range(k)])
    u = [y[i] - sum(beta[j] * X[i][j] for j in range(k)) for i in range(len(rows))]
    inv = [list(c) for c in zip(*[solve(XtX, [1.0 if i == j else 0.0 for i in range(k)]) for j in range(k)])]
    scores = collections.defaultdict(lambda: [0.0] * k)
    for i, r in enumerate(rows):
        for j in range(k):
            scores[r['iso']][j] += X[i][j] * u[i]
    G, n = len(scores), len(rows)
    meat = [[sum(s[a] * s[b] for s in scores.values()) for b in range(k)] for a in range(k)]
    scale = G / (G - 1) * (n - 1) / (n - k)
    se = [math.sqrt(max(0.0, scale * sum(inv[j][c] * meat[c][d] * inv[d][j] for c in range(k) for d in range(k)))) for j in range(k)]
    return dict(zip(names, zip(beta, se))), math.sqrt(sum(v * v for v in u) / (n - k))


KINDS = ['single majority', 'single minority', 'coalition']


def build(r, k):
    if k == 'const': return 1.0
    if k in ('g', 'p'): return r[k]
    kind, v = k.split('|')
    if v == 'const': return 1.0 if r['kind'] == kind else 0.0
    return r[v] if r['kind'] == kind else 0.0


def clarity(label, rows):
    def b2(r, k):
        single = r['kind'] != 'coalition'
        return {'single': 1.0 if single else 0.0, 'coalition': 0.0 if single else 1.0, 'g single': r['g'] if single else 0.0,
                'g coalition 2008-2020': r['g'] if not single and r['year'] >= 2008 else 0.0, 'p': r['p']}[k]
    names = ['single', 'coalition', 'g single', 'g coalition 2008-2020', 'p']
    b, sd = ols(rows, names, b2)
    print(f"   clarity {label}: " + '  '.join(f"{k} {b[k][0]:+.4f} ({b[k][1]:.4f})" for k in names) + f"  resid sd {sd:.4f}")
    return b


def report(label, rows):
    print(f"\n== {label}: {len(rows)} votes, {len({r['iso'] for r in rows})} countries, {min(r['year'] for r in rows)}-{max(r['year'] for r in rows)}; "
          f"cabinet share change mean {sum(r['y'] for r in rows) / len(rows):+.4f}")
    print('   kinds: ' + ', '.join(f"{k} {sum(1 for r in rows if r['kind'] == k)}" for k in KINDS))
    out = {}
    for name, names, fe in (('pooled', ['const', 'g', 'p'], False), ('country FE', ['g', 'p'], True)):
        b, sd = ols(rows, names, build, fe)
        out[name] = b
        print(f"   {name:11s} growth {b['g'][0]:+.3f} ({b['g'][1]:.3f})  inflation {b['p'][0]:+.3f} ({b['p'][1]:.3f})"
              + (f"  const {b['const'][0]:+.4f} ({b['const'][1]:.4f})" if 'const' in b else '') + f"  resid sd {sd:.4f}")
    names = [f'{k}|{v}' for k in KINDS for v in ('const', 'g', 'p')]
    b, sd = ols(rows, names, build)
    out['by kind'] = b
    for k in KINDS:
        print(f"   {k:16s} growth {b[k + '|g'][0]:+.3f} ({b[k + '|g'][1]:.3f})  inflation {b[k + '|p'][0]:+.3f} ({b[k + '|p'][1]:.3f})  const {b[k + '|const'][0]:+.4f} ({b[k + '|const'][1]:.4f})")
    return out


print('Fair (US presidential, 2020 update): growth +0.673, inflation -0.721, residual sd 0.0295')
res = {}
jst_rows = sample('JST')
res['JST'] = report('JST PR democracies (CPI)', jst_rows)
res['JST']['clarity'] = clarity('JST', jst_rows)
wb_rows = sample('WB')
res['WB'] = report('World Bank, all ParlGov PR democracies (deflator)', wb_rows)
res['WB']['clarity'] = clarity('WB all', wb_rows)
west = [r for r in wb_rows if r['iso'] not in POST_COMMUNIST]
res['WB west'] = report('World Bank, established PR democracies (deflator)', west)
res['WB west']['clarity'] = clarity('WB west (the Diet\'s constants)', west)
json.dump({k: {s: {n: list(v) for n, v in b.items()} for s, b in d.items()} for k, d in res.items()}, open(os.path.join(H, 'vote_europe_fit.json'), 'w'), indent=1)
