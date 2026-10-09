"""How party positions move between elections, from the Chapel Hill expert survey trend file (1999-2024 means,
https://github.com/chesdata/chesdata.github.io/releases/download/ches-trend/1999-2024_CHES_dataset_meansV2.csv).

Each party's placement is its home, plus a deviation that decays at phi a year and is renewed by shocks (AR(1)), plus
the experts' own error in each wave. Half the mean squared change between two waves g years apart is then
noise^2 + within^2 (1 - phi^g): a random walk would keep growing with g, a party anchored to a home levels off.
Western parties, positions relative to their country's mean in that wave, on the Diet's scale (a full expert scale is
two units). Per term (4 years): persistence phi^4 and the shock sd within * sqrt(1 - phi^8).
  state    <- lrecon (economic left-right, 0-10)
  openness <- eu_position (European integration, 1-7)
  council  <- antielite_salience (anti-elite rhetoric, 0-10; 2014-2024 only, so the experts' error is taken from the
              three long items and only phi and the within-party spread are fitted)
  environment <- environment (environment vs growth, 0-10; 2010-2024 only, fitted like the Council axis: with gaps of
              4-14 years only, a free fit runs off to a random walk)
python3 ches_fit.py"""
import csv, collections, itertools, statistics as st, math, os
rows = list(csv.DictReader(open(os.path.join(os.path.dirname(__file__) or '.', 'ches_trend.csv'))))
TERM = 4.0

def num(v):
    try: return float(v)
    except ValueError: return None

def pairs(var, half_scale):
    series = collections.defaultdict(dict)
    for r in rows:
        x = num(r[var])
        if r['eastwest'] == '1' and x is not None:
            series[(r['country'], r['party_id'])][int(r['year'])] = x / half_scale
    wave = collections.defaultdict(list)
    for (c, _), s in series.items():
        for y, x in s.items(): wave[(c, y)].append(x)
    mean = {k: st.mean(v) for k, v in wave.items()}
    out = collections.defaultdict(list)
    for (c, _), s in series.items():
        for a, b in itertools.combinations(sorted(s), 2):
            out[b - a].append(((s[b] - mean[(c, b)]) - (s[a] - mean[(c, a)])) ** 2 / 2)
    return {g: (st.mean(v), len(v)) for g, v in out.items() if len(v) >= 40}

def fit(obs, noise2=None):
    best = None
    for i in range(1, 1000):
        phi = i / 1000
        rows_ = [(1.0, 1 - phi ** g, y, w) for g, (y, w) in obs.items()]
        if noise2 is None:
            a11 = sum(w for _, _, _, w in rows_); a12 = sum(w * x for _, x, _, w in rows_); a22 = sum(w * x * x for _, x, _, w in rows_)
            b1 = sum(w * y for _, _, y, w in rows_); b2 = sum(w * x * y for _, x, y, w in rows_)
            det = a11 * a22 - a12 * a12
            if abs(det) < 1e-15: continue
            n2 = (b1 * a22 - b2 * a12) / det; u2 = (a11 * b2 - a12 * b1) / det
        else:
            n2 = noise2
            u2 = sum(w * x * (y - n2) for _, x, y, w in rows_) / sum(w * x * x for _, x, _, w in rows_)
        if n2 < 0 or u2 <= 0: continue
        sse = sum(w * (y - n2 - u2 * x) ** 2 for _, x, y, w in rows_)
        if best is None or sse < best[0]: best = (sse, phi, n2, u2)
    return best[1:]

def show(axis, var, phi, n2, u2, obs):
    rho = phi ** TERM
    print(f"{axis:9s} <- {var:18s} gaps {min(obs)}-{max(obs)}y  persistence/term {rho:.3f}  within sd {math.sqrt(u2):.3f}  "
          f"shock/term {math.sqrt(u2 * (1 - rho * rho)):.3f}  experts' error {math.sqrt(n2):.3f}")

noises = []
for axis, var, half in [('state', 'lrecon', 5.0), ('openness', 'eu_position', 3.0)]:
    obs = pairs(var, half); phi, n2, u2 = fit(obs); noises.append(n2); show(axis, var, phi, n2, u2, obs)
obs = pairs('galtan', 5.0); phi, n2, u2 = fit(obs); noises.append(n2); show('(galtan)', 'galtan', phi, n2, u2, obs)
n2 = st.mean(noises)
obs = pairs('antielite_salience', 5.0); phi, _, u2 = fit(obs, n2); show('council', 'antielite_salience', phi, n2, u2, obs)
obs = pairs('environment', 5.0); phi, _, u2 = fit(obs, n2); show('environment', 'environment', phi, n2, u2, obs)
