"""Merger-review lever: paired replays of one recorded economy under strict (2023), cabinet-set and lenient (2010) review.

python3 an.py [runs_dir]
"""
import json, sys, statistics as st, glob, os
from collections import defaultdict

D = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(__file__), 'runs')
FIN = json.load(open(os.path.join(os.path.dirname(__file__), 'fin.json')))
ARMS = ['strict', 'pol', 'loose']


def load(arm):
    out = {}
    for f in glob.glob(f'{D}/{arm}-*.jsonl'):
        for line in open(f):
            r = json.loads(line)
            out[r['seed']] = r
    return out


def metrics(r):
    m = {}
    deals = r['deals']
    op = [d for d in deals if not FIN.get(d['ind'], False)]
    fin = [d for d in deals if FIN.get(d['ind'], False)]
    m['deals'] = len(deals)
    m['deal $T'] = sum(d['b'] for d in deals) / 1000
    m['op deal $T'] = sum(d['b'] for d in op) / 1000
    m['fin deal $T'] = sum(d['b'] for d in fin) / 1000
    m['sized by review %'] = 100 * sum(d['lim'] == 'the largest deal merger review clears' for d in deals) / max(1, len(deals))
    m['mean leniency'] = st.mean(d['len'] for d in deals) if deals else 0.0
    last = max(c['y'] for c in r['conc'])
    for y in (10, 20, last):
        rows = [c for c in r['conc'] if c['y'] == y]
        ind = {}
        for c in rows:
            ind.setdefault(c['ind'], c['h'])
        m[f'y{y} mean share'] = st.mean(c['s'] for c in rows)
        m[f'y{y} firms >30%'] = sum(c['s'] > 0.30 for c in rows)
        m[f'y{y} firms >50%'] = sum(c['s'] > 0.50 for c in rows)
        m[f'y{y} max share'] = max(c['s'] for c in rows)
        m[f'y{y} mean HHI'] = 10000 * st.mean(ind.values())
        m[f'y{y} ind HHI>2500'] = sum(h > 0.25 for h in ind.values())
        m[f'y{y} op margin'] = st.mean(c['om'] for c in rows if not FIN.get(c['ind'], False))
    eq = sorted(v['eq_x'] for v in r['final'].values() if not v['dead'])
    m['median eq_x'] = eq[len(eq) // 2]
    m['deaths'] = len(r['dead'])
    return m


data = {a: load(a) for a in ARMS}
seeds = sorted(set.intersection(*(set(d) for d in data.values())))
print(f'seeds {seeds}, years {data["strict"][seeds[0]]["years"]}')
per = {a: {s: metrics(data[a][s]) for s in seeds} for a in ARMS}
keys = list(per['strict'][seeds[0]].keys())
print(f"{'':24s}" + ''.join(f'{a:>10s}' for a in ARMS) + f"{'pol-str':>16s}{'loose-str':>16s}")
for k in keys:
    means = [st.mean(per[a][s][k] for s in seeds) for a in ARMS]
    diffs = []
    for a in ('pol', 'loose'):
        d = [per[a][s][k] - per['strict'][s][k] for s in seeds]
        se = st.stdev(d) / len(d) ** 0.5 if len(d) > 1 else 0.0
        diffs.append(f'{st.mean(d):+8.3f}±{se:5.3f}')
    print(f'{k:24s}' + ''.join(f'{v:10.3f}' for v in means) + ''.join(f'{x:>16s}' for x in diffs))

# Biggest movers: firms whose final trend share moved most under cabinet-set review, averaged over seeds.
share = defaultdict(lambda: defaultdict(list))
for a in ARMS:
    for s in seeds:
        r = data[a][s]
        last = max(c['y'] for c in r['conc'])
        for c in r['conc']:
            if c['y'] == last:
                share[c['tk']][a].append(c['s'])
rows = []
for tk, v in share.items():
    if all(len(v[a]) == len(seeds) for a in ARMS):
        rows.append((st.mean(v['pol']) - st.mean(v['strict']), tk, st.mean(v['strict']), st.mean(v['pol']), st.mean(v['loose'])))
rows.sort(reverse=True)
print('\nfinal trend share, strict / pol / loose (top movers)')
for d, tk, a, b, c in rows[:10]:
    print(f'  {tk:6s} {a:.3f} {b:.3f} {c:.3f}  ({d:+.3f})')
