"""The engine's gap response to one credit crisis against 2008's: python3 var/harness/crisis_irf_an.py <tag> [tag ...]

CrisisImpulseProbeTest pairs (crisis minus no crisis, same draws), annual averages of the quarter-average gap over the
years after the crisis. Target: the CBO gap (FRED GDPC1 / GDPPOT, current vintage) in the years from 2008Q4, the
premium jump the engine books, less the gap an average normal recession (1990, 2001) would have left from the 2007Q4
peak: -3.2 -3.2 -3.6 -3.4 -2.5 -2.0 -1.2 -0.9 pp. One episode, with the 2009 stimulus inside it, so read to about a
point; J weights each year at 1pp. The boom behind it is the engine's credit gap at Lehman, +4.3pp (CRISIS_GAP).
"""
import glob, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
D = os.environ.get('IRF_DIR', os.path.join(H, 'crisis_irf'))
TARGET = [-3.2, -3.2, -3.6, -3.4, -2.5, -2.0, -1.2, -0.9]
BURN = 20.0

for tag in sys.argv[1:]:
    diffs = []
    extra = {'policy': [], 'u': [], 'infl': []}
    for p in sorted(glob.glob(f'{D}/{tag}-*.json')):
        for seed, r in json.load(open(p)).items():
            b, c = r['base'], r['crisis']
            year = {}
            for x, y in zip(b, c):
                k = math.ceil(x['t'] - BURN - 1e-9)
                if 1 <= k <= len(TARGET):
                    year.setdefault(k, []).append((y['gap'] - x['gap'], y['policy'] - x['policy'], y['u'] - x['u'], y['infl'] - x['infl']))
            if len(year) == len(TARGET):
                diffs.append([100 * st.mean(v[0] for v in year[k]) for k in range(1, len(TARGET) + 1)])
                for i, key in enumerate(('policy', 'u', 'infl'), start=1):
                    extra[key].append([100 * st.mean(v[i] for v in year[k]) for k in range(1, len(TARGET) + 1)])
    if not diffs:
        print(f"{tag}: no pairs")
        continue
    m = [st.mean(d[k] for d in diffs) for k in range(len(TARGET))]
    se = [st.stdev(d[k] for d in diffs) / math.sqrt(len(diffs)) for k in range(len(TARGET))]
    J = sum((a - t) ** 2 for a, t in zip(m, TARGET))
    print(f"{tag:22s} n{len(diffs):3d} gap " + ' '.join(f"{a:+5.2f}" for a in m) + f"  J {J:5.2f}")
    print(f"{'':28s}(se " + ' '.join(f"{s:4.2f}" for s in se) + ")")
    for key in ('policy', 'u', 'infl'):
        mm = [st.mean(d[k] for d in extra[key]) for k in range(len(TARGET))]
        print(f"{'':28s}{key:6s} " + ' '.join(f"{a:+5.2f}" for a in mm))
print(f"{'2008 target':28s}gap " + ' '.join(f"{t:+5.2f}" for t in TARGET))
