"""Full-market comparison of two arms over the same seed numbers (different macro paths): deaths, recessions, margins.

    python3 tfp_market_an.py <harness dir> pre tfp
"""
import glob, json, math, statistics as st, sys
from collections import Counter

H, arms = sys.argv[1], sys.argv[2:]


def load(arm):
    return {int(f.rsplit('-', 1)[1].split('.')[0]): json.loads(open(f).readline()) for f in glob.glob(f'{H}/runs2/{arm}-*.jsonl')}


data = {a: load(a) for a in arms}
seeds = sorted(set.intersection(*(set(d) for d in data.values())))
print(f'seeds: {len(seeds)}')
for arm, D in data.items():
    deaths = Counter(t for s in seeds for t in (D[s]['dead'] or {}))
    death_seeds = sum(1 for s in seeds if D[s]['dead'])
    reorgs = sum(len(D[s]['reorgs'] or []) for s in seeds)
    gaps = [x['gap'] for s in seeds for x in D[s]['final']['WING']['q']]
    rec = sum(1 for s in seeds if any(x['gap'] < -0.03 for x in D[s]['final']['WING']['q']))
    deep = sum(1 for s in seeds if any(x['gap'] < -0.05 for x in D[s]['final']['WING']['q']))
    om = [x['om'] for s in seeds for t, f in D[s]['final'].items() for x in f['q'] if x['rev'] > 0]
    print(f'{arm:5s}: deaths {sum(deaths.values())} in {death_seeds} seeds {dict(deaths.most_common(6))}  reorgs {reorgs}')
    print(f'       gapEma sd {st.pstdev(gaps) * 100:.2f}%  seeds reaching -3%: {rec}  reaching -5%: {deep}  mean margin {st.mean(om) * 100:.2f}%  loss quarters {sum(v < 0 for v in om) / len(om) * 100:.1f}%')
if len(arms) == 2:
    a, b = (sum(1 for s in seeds if data[x][s]['dead']) for x in arms)
    n = len(seeds)
    p = (a + b) / (2 * n)
    z = (b / n - a / n) / math.sqrt(max(1e-12, 2 * p * (1 - p) / n))
    print(f'seeds with a death: {a}/{n} vs {b}/{n}  (two-proportion z {z:+.2f})')
