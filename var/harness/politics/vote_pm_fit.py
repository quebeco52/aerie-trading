"""Who carries the economic vote in a coalition: the prime minister's party, its partners, or neither
(Duch & Stevenson 2008). Same votes, macro gaps and estimator as vote_europe_fit.py, with the outcome split by party.

  y_pm       = the prime minister's party: share now - share at the last vote
  y_partners = the cabinet's other parties together
  y_cabinet  = the whole cabinet (vote_europe_fit.py's outcome, for the replication)
  g, p       = vote_europe_fit.py's growth and inflation gaps
  du         = change in the unemployment rate over the year to the vote, as a fraction (World Bank ILO series, 1991-)

Specs, each pooled with a constant and with country fixed effects, CR1 errors clustered by country:
  A: y on g, p                              coalitions
  B: A plus g x (vote in 2008-2020)         the euro-area consolidations, as vote_europe_fit.py's clarity spec
  C: A plus du                              coalitions with an unemployment series (1991-)
  S: y on g, p                              single-party cabinets, the same party measured the same way

python3 vote_pm_fit.py
"""
import csv, math, os, sys, json, collections
H = os.path.dirname(os.path.abspath(__file__))

MAJORITARIAN = {'AUS', 'CAN', 'FRA', 'GBR', 'JPN', 'USA'}
POST_COMMUNIST = {'BGR', 'CZE', 'EST', 'HRV', 'HUN', 'LTU', 'LVA', 'POL', 'ROU', 'SVK', 'SVN'}
TARGET = 0.02


def wb(indicator):
    out = {}
    for r in json.load(open(os.path.join(H, 'wb', indicator + '.json')))[1]:
        if r['value'] is not None:
            out[(r['countryiso3code'], int(r['date']))] = float(r['value'])
    return out

GDP, DEFL, UNEMP = wb('NY.GDP.PCAP.KD'), wb('NY.GDP.DEFL.ZS'), wb('SL.UEM.TOTL.ZS')


def gaps(iso, date, prev_date):
    Y, m, Y0 = int(date[:4]), int(date[5:7]), int(prev_date[:4])
    levels = [GDP.get((iso, y)) for y in range(Y - 11, Y + 1)]
    if any(v is None or v <= 0 for v in levels) or Y <= Y0:
        return None
    d = [math.log(b / a) for a, b in zip(levels, levels[1:])]
    w = m / 12.0
    g = w * d[-1] + (1 - w) * d[-2] - sum(d[-11:-1]) / 10.0
    p1, p0 = DEFL.get((iso, Y)), DEFL.get((iso, Y0))
    if not p1 or not p0:
        return None
    # Unemployment change over the year to the vote, month-weighted like growth.
    u = [UNEMP.get((iso, y)) for y in (Y - 2, Y - 1, Y)]
    du = None if any(v is None for v in u) else (w * (u[2] - u[1]) + (1 - w) * (u[1] - u[0])) / 100.0
    return g, math.log(p1 / p0) / (Y - Y0) - TARGET, du


# --- ParlGov: each vote, the cabinet that went into it, and that cabinet's prime minister ---
cabinets = collections.defaultdict(dict)
prime = {}
caretaker = {}
for r in csv.DictReader(open(os.path.join(H, 'parlgov', 'view_cabinet.csv'))):
    caretaker[r['cabinet_id']] = r['caretaker'] == '1'
    if r['cabinet_party'] == '1':
        cabinets[r['cabinet_id']][r['party_id']] = float(r['seats'] or 0)
        if r['prime_minister'] == '1':
            prime[r['cabinet_id']] = r['party_id']
votes = collections.defaultdict(dict)
meta = {}
for r in csv.DictReader(open(os.path.join(H, 'parlgov', 'view_election.csv'))):
    if r['election_type'] != 'parliament' or not r['vote_share']:
        continue
    votes[r['election_id']][r['party_id']] = float(r['vote_share']) / 100.0
    meta[r['election_id']] = (r['country_name_short'], r['election_date'], r['previous_parliament_election_id'], r['previous_cabinet_id'])


def sample():
    rows = []
    for e, shares in votes.items():
        iso, date, prev, cab = meta[e]
        if iso in MAJORITARIAN or iso in POST_COMMUNIST or (iso == 'NZL' and date < '1996') or prev not in votes or not cab or caretaker.get(cab, True):
            continue
        if not ('1971' <= date[:4] <= '2020'):
            continue
        parties = cabinets.get(cab, {})
        pm = prime.get(cab)
        if not parties or pm not in parties or any(p not in shares or p not in votes[prev] for p in parties):
            continue
        x = gaps(iso, date, meta[prev][1])
        if x is None:
            continue
        change = {p: shares[p] - votes[prev][p] for p in parties}
        rows.append({
            'iso': iso, 'year': int(date[:4]), 'coalition': len(parties) > 1,
            'y_pm': change[pm], 'y_partners': sum(v for p, v in change.items() if p != pm), 'y_cabinet': sum(change.values()),
            'pm_share': votes[prev][pm], 'cabinet_share': sum(votes[prev][p] for p in parties),
            'g': x[0], 'p': x[1], 'du': x[2],
        })
    return rows


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


def ols(rows, outcome, names, fe):
    X = [[1.0 if k == 'const' else (r['g'] if k == 'g x 2008-2020' and r['year'] >= 2008 else (0.0 if k == 'g x 2008-2020' else r[k])) for k in names] for r in rows]
    y = [r[outcome] for r in rows]
    if fe:
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
    k, n = len(names), len(rows)
    XtX = [[sum(X[i][a] * X[i][b] for i in range(n)) for b in range(k)] for a in range(k)]
    beta = solve(XtX, [sum(X[i][a] * y[i] for i in range(n)) for a in range(k)])
    u = [y[i] - sum(beta[j] * X[i][j] for j in range(k)) for i in range(n)]
    inv = [list(c) for c in zip(*[solve(XtX, [1.0 if i == j else 0.0 for i in range(k)]) for j in range(k)])]
    scores = collections.defaultdict(lambda: [0.0] * k)
    for i, r in enumerate(rows):
        for j in range(k):
            scores[r['iso']][j] += X[i][j] * u[i]
    G = len(scores)
    meat = [[sum(s[a] * s[b] for s in scores.values()) for b in range(k)] for a in range(k)]
    scale = G / (G - 1) * (n - 1) / (n - k)
    se = [math.sqrt(max(0.0, scale * sum(inv[j][c] * meat[c][d] * inv[d][j] for c in range(k) for d in range(k)))) for j in range(k)]
    return dict(zip(names, zip(beta, se))), math.sqrt(sum(v * v for v in u) / (n - k))


def show(label, rows, extra=()):
    print(f"\n== {label}: {len(rows)} votes, {len({r['iso'] for r in rows})} countries, {min(r['year'] for r in rows)}-{max(r['year'] for r in rows)}")
    print(f"   mean share at the last vote: PM party {sum(r['pm_share'] for r in rows) / len(rows):.3f}, cabinet {sum(r['cabinet_share'] for r in rows) / len(rows):.3f}")
    out = {}
    for outcome in ('y_pm', 'y_partners', 'y_cabinet'):
        if outcome == 'y_partners' and not rows[0]['coalition']:
            continue
        for fe in (False, True):
            names = (['g', 'p'] if fe else ['const', 'g', 'p']) + list(extra)
            b, sd = ols(rows, outcome, names, fe)
            tag = f"{outcome:10s} {'country FE' if fe else 'pooled    '}"
            print('   ' + tag + '  ' + '  '.join(f"{k} {b[k][0]:+.3f} ({b[k][1]:.3f})" for k in names) + f"  resid sd {sd:.4f}")
            out[tag.strip()] = {k: list(v) for k, v in b.items()}
    return out


rows = sample()
coalitions = [r for r in rows if r['coalition']]
singles = [r for r in rows if not r['coalition']]
res = {
    'A coalitions': show('A: coalitions, growth and inflation', coalitions),
    'B coalitions': show('B: coalitions, with the 2008-2020 consolidations apart', coalitions, ('g x 2008-2020',)),
    'C coalitions': show('C: coalitions with unemployment (1991-)', [r for r in coalitions if r['du'] is not None], ('du',)),
    'S singles': show('S: single-party cabinets', singles),
    'S singles du': show('S: single-party cabinets with unemployment (1991-)', [r for r in singles if r['du'] is not None], ('du',)),
}
json.dump(res, open(os.path.join(H, 'vote_pm_fit.json'), 'w'), indent=1)
