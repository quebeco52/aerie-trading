"""The normal vote's drift: ParlGov's half mean squared change in log share between elections g years apart, times each
party's mean share (the Diet's variance scaling, vote_sim.py), fitted as short + lasting (1 - phi^g) + walk g with phi
held at PoliticsEngine::ELECTION_NORMAL_VOTE_PERSISTENCE. A positive walk term is a normal vote that drifts; a level
plateau is one that does not. python3 walk_fit.py"""
import collections, itertools, math, statistics as st, sys
sys.argv = [sys.argv[0]]
exec(open('vote_fit.py').read().split("for label, countries in")[0])
PHI = 0.905

def weighted_variogram(s, max_gap=40):
    out = collections.defaultdict(list)
    for x in s.values():
        n = st.mean(math.exp(v) for v in x.values())
        for a, b in itertools.combinations(sorted(x), 2):
            g = round(b - a)
            if 1 <= g <= max_gap:
                out[g].append(n * (x[b] - x[a]) ** 2 / 2)
    return {g: (st.mean(v), len(v)) for g, v in sorted(out.items()) if len(v) >= 30}

def ols(obs, cols_of):
    cols = [cols_of(g) for g in obs]; ys = [y for y, _ in obs.values()]; ws = [w for _, w in obs.values()]
    k = len(cols[0])
    A = [[sum(w * c[i] * c[j] for c, w in zip(cols, ws)) for j in range(k)] for i in range(k)]
    b = [sum(w * c[i] * y for c, y, w in zip(cols, ys, ws)) for i in range(k)]
    beta = solve(A, b)
    sse = sum(w * (y - sum(bb * cc for bb, cc in zip(beta, c))) ** 2 for c, y, w in zip(cols, ys, ws))
    # Standard errors from the weighted residual variance (weights are pair counts; gaps overlap, so these are optimistic).
    n = len(ys); s2 = sse / max(1, sum(ws)) * sum(ws) / max(1, n - k)
    inv = [[0.0] * k for _ in range(k)]
    for j in range(k):
        e = [1.0 if i == j else 0.0 for i in range(k)]; col = solve([r[:] for r in A], e)
        for i in range(k): inv[i][j] = col[i]
    se = [math.sqrt(max(0.0, s2 * inv[i][i] * sum(ws) / n)) for i in range(k)]
    return beta, se, sse

for label, countries in (('Western Europe', WEST), ('Nordic', NORDIC), ('West less Nordic', WEST - NORDIC)):
    s = series(countries, 0.05)
    obs = weighted_variogram(s)
    plateau, _, sse0 = ols(obs, lambda g: (1.0, 1 - PHI ** g))
    walk, se, sse1 = ols(obs, lambda g: (1.0, 1 - PHI ** g, g))
    print(f"{label:17s} parties {len(s):3d}  plateau: short {1000*plateau[0]:.1f} lasting {1000*plateau[1]:.1f} (sse {sse0:.5f})  "
          f"walk: short {1000*walk[0]:.1f} lasting {1000*walk[1]:.1f} walk/yr {1000*walk[2]:.2f} (se {1000*se[2]:.2f}) (sse {sse1:.5f})")
    print('   x1000: ' + '  '.join(f"{g}:{1000*y:.1f}" for g, (y, n) in obs.items() if g in (1, 2, 4, 8, 12, 16, 20, 25, 30, 35, 40)))

# The walk on top of the Diet's own short-run fit: short and lasting held at the Nordic values the Diet reproduces
# (vote_sim.py: 1.2 and 19.1 x1e-3), the walk the only free term, its standard error from a bootstrap over parties.
import random
SHORT, LASTING = 0.0012, 0.0191
def constrained(obs):
    num = sum(w * g * (y - SHORT - LASTING * (1 - PHI ** g)) for g, (y, w) in obs.items())
    den = sum(w * g * g for g, (y, w) in obs.items())
    return num / den
for label, countries in (('Western Europe', WEST), ('West less Nordic', WEST - NORDIC)):
    s = series(countries, 0.05); keys = list(s)
    point = constrained(weighted_variogram(s))
    rng = random.Random(1); boot = []
    for _ in range(200):
        pick = [rng.choice(keys) for _ in keys]
        sample = {(i, k): s[k] for i, k in enumerate(pick)}
        boot.append(constrained(weighted_variogram(sample)))
    print(f"{label:17s} walk/yr x1000 on the Diet's short run: {1000*point:.3f} (bootstrap se {1000*st.pstdev(boot):.3f}, "
          f"2.5-97.5% {1000*sorted(boot)[5]:.3f}..{1000*sorted(boot)[194]:.3f})")
