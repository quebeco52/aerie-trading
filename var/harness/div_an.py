"""CNDR pure-play (single) vs diversified major (div) on the same recorded macro paths (with gold)."""
import glob
import json
import math
import statistics as st
import sys
from collections import Counter

H = sys.argv[1] if len(sys.argv) > 1 else '.'
ARMS = ['single', 'div']


def load(arm):
    return {int(f.rsplit('-', 1)[1].split('.')[0]): json.loads(open(f).readline()) for f in glob.glob(f'{H}/runs2/{arm}-*.jsonl')}


def corr(x, y):
    mx, my = st.mean(x), st.mean(y)
    den = math.sqrt(sum((a - mx) ** 2 for a in x) * sum((b - my) ** 2 for b in y))
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / den if den > 0 else float('nan')


def pct(v, p):
    s = sorted(v)
    return s[min(len(s) - 1, max(0, int(round(p * (len(s) - 1)))))]


data = {a: load(a) for a in ARMS}
seeds = sorted(set(data['single']) & set(data['div']))
print(f'paired paths: {len(seeds)}')

gold_metals = []
for s in seeds:
    q = data['div'][s]['final']['CNDR']['q']
    gold_metals.append(corr([x['gold'] for x in q], [x['metals'] for x in q]))
print(f'in-path corr(gold, metals) median {st.median(gold_metals):+.2f}\n')

print(f'{"CNDR":38s}{"pure-play":>12s}{"diversified":>12s}')
out = {}
for a in ARMS:
    yoy, oms, sd_om, loss, n, bust_rev, dead, eq, cm = [], [], [], 0, 0, [], 0, [], []
    for s in seeds:
        f = data[a][s]['final']['CNDR']
        dead += 1 if f['dead'] else 0
        eq.append(f['eq_x'])
        q = [x for x in f['q'] if x['rev'] > 0]
        oms += [x['om'] for x in q]
        sd_om.append(st.pstdev([x['om'] for x in q]))
        loss += sum(1 for x in q if x['ebit'] < 0)
        n += len(q)
        cm.append(corr([x['metals'] for x in q], [x['om'] for x in q]))
        for i in range(4, len(q)):
            r = math.log(q[i]['rev'] / q[i - 4]['rev'])
            yoy.append(r)
            if q[i]['metals'] / q[i - 4]['metals'] < 0.85:
                bust_rev.append(r)
    out[a] = {
        'revenue YoY log sd': st.pstdev(yoy),
        'revenue YoY in metals busts (-15%+)': st.mean(bust_rev) if bust_rev else float('nan'),
        'operating margin p5': pct(oms, .05),
        'operating margin p50': st.median(oms),
        'operating margin p95': pct(oms, .95),
        'within-path margin sd': st.median(sd_om),
        'corr(margin, metals)': st.median(cm),
        'loss-quarter share': loss / n,
        'equity multiple over 20y': st.median(eq),
        'deaths': dead,
    }
for k in out['single']:
    print(f'  {k:36s}' + ''.join(f'{out[a][k]:12.3f}' for a in ARMS))

print()
for a in ARMS:
    deaths = Counter(t for s in seeds for t in (data[a][s]['dead'] or {}))
    reorgs = Counter(x['t'] for s in seeds for x in (data[a][s]['reorgs'] or []))
    print(f'{a:7s} market deaths {sum(deaths.values())} {dict(deaths)}  reorgs {sum(reorgs.values())} {dict(reorgs)}')
