"""How party vote shares move between elections, from ParlGov (view_election.csv in parlgov/): West European
parliamentary elections since 1945.

A party's log vote share is its normal vote, plus a lasting deviation that decays at phi a year and is renewed by
shocks (AR(1)), plus a short-term swing that lasts only the one vote (Converse 1966). Half the mean squared change
between two elections g years apart is then short^2 + lasting^2 (1 - phi^g): a random walk would keep growing with g,
a party anchored to a normal vote levels off. Parties that averaged at least MIN_MEAN of the vote over their lifetime.
python3 vote_fit.py"""
import csv, collections, itertools, math, os, statistics as st
D = os.path.join(os.path.dirname(__file__) or '.', 'parlgov')
WEST = {'AUT', 'BEL', 'CHE', 'DEU', 'DNK', 'ESP', 'FIN', 'FRA', 'GBR', 'GRC', 'IRL', 'ISL', 'ITA', 'LUX', 'MLT', 'NLD', 'NOR', 'PRT', 'SWE'}
NORDIC = {'DNK', 'SWE', 'NOR', 'FIN', 'ISL'}
SKIP = {'none', 'no-seat', 'one-seat', 'Ind', 'oth'}
TERM = 4.0

def series(countries, min_mean, since='1945'):
    s = collections.defaultdict(dict)
    for r in csv.DictReader(open(f'{D}/view_election.csv')):
        if r['country_name_short'] in countries and r['election_type'] == 'parliament' and r['election_date'] >= since \
                and r['vote_share'] and r['party_name_short'] not in SKIP:
            v = float(r['vote_share']) / 100
            if v >= 0.01:
                y, m, d = map(int, r['election_date'].split('-'))
                s[(r['country_name_short'], r['party_id'])][y + (m - 1) / 12 + d / 365] = math.log(v)
    return {k: v for k, v in s.items() if len(v) >= 6 and st.mean(math.exp(x) for x in v.values()) >= min_mean}

def variogram(s, max_gap=40):
    out = collections.defaultdict(list)
    for x in s.values():
        for a, b in itertools.combinations(sorted(x), 2):
            g = round(b - a)
            if 1 <= g <= max_gap:
                out[g].append((x[b] - x[a]) ** 2 / 2)
    return {g: (st.mean(v), len(v)) for g, v in sorted(out.items()) if len(v) >= 30}

def fit(obs, drift=False):
    best = None
    for i in range(1, 1000):
        phi = i / 1000
        cols = [(1.0, 1 - phi ** g) + ((g,) if drift else ()) for g in obs]
        ys = [y for y, _ in obs.values()]; ws = [w for _, w in obs.values()]
        k = len(cols[0])
        A = [[sum(w * c[i] * c[j] for c, w in zip(cols, ws)) for j in range(k)] for i in range(k)]
        b = [sum(w * c[i] * y for c, y, w in zip(cols, ys, ws)) for i in range(k)]
        try: beta = solve(A, b)
        except ZeroDivisionError: continue
        if min(beta) < 0: continue
        sse = sum(w * (y - sum(bb * cc for bb, cc in zip(beta, c))) ** 2 for c, y, w in zip(cols, ys, ws))
        if best is None or sse < best[0]: best = (sse, phi, beta)
    return best

def solve(A, b):
    n = len(b); M = [row[:] + [bb] for row, bb in zip(A, b)]
    for i in range(n):
        p = max(range(i, n), key=lambda r: abs(M[r][i])); M[i], M[p] = M[p], M[i]
        if abs(M[i][i]) < 1e-15: raise ZeroDivisionError
        for r in range(n):
            if r != i:
                f = M[r][i] / M[i][i]; M[r] = [x - f * y for x, y in zip(M[r], M[i])]
    return [M[i][n] / M[i][i] for i in range(n)]

for label, countries in (('Western Europe', WEST), ('Nordic', NORDIC)):
    for min_mean in (0.05, 0.10):
        s = series(countries, min_mean)
        obs = variogram(s)
        sse, phi, (short2, lasting2) = fit(obs)
        rho = phi ** TERM
        print(f"{label:15s} mean>={min_mean:.2f} parties {len(s):3d}  phi/yr {phi:.3f}  persistence/term {rho:.3f}  "
              f"short sd {math.sqrt(short2):.3f}  lasting sd {math.sqrt(lasting2):.3f}  lasting shock/term {math.sqrt(lasting2 * (1 - rho * rho)):.3f}")
        sse2, phi2, beta2 = fit(obs, drift=True)
        print(f"{'':15s} with a random walk too: phi/yr {phi2:.3f}  short sd {math.sqrt(beta2[0]):.3f}  lasting sd {math.sqrt(beta2[1]):.3f}  "
              f"walk sd/yr {math.sqrt(beta2[2]):.4f}  sse {sse2:.5f} vs {sse:.5f}")
        print('   gap: ' + '  '.join(f"{g}:{y:.3f}" for g, (y, n) in obs.items() if g in (1, 2, 3, 4, 6, 8, 12, 16, 20, 25, 30, 35, 40)))
