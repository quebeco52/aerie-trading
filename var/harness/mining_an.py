"""Mining model A/B: control (commodity model, 'fix' runs) vs treatment ('mining' runs), paired on the same paths."""
import glob
import json
import math
import statistics as st
import sys
from collections import Counter

H = sys.argv[1] if len(sys.argv) > 1 else '.'
ARMS = sys.argv[2].split(',') if len(sys.argv) > 2 else ['fix', 'mining']
LABEL = {'fix': 'commodity', 'mining': 'mining', 'reseed': 'mining+30%'}


def load(arm):
    return {int(f.rsplit('-', 1)[1].split('.')[0]): json.loads(open(f).readline()) for f in glob.glob(f'{H}/runs/{arm}-*.jsonl')}


def ols(x, y):
    mx, my = st.mean(x), st.mean(y)
    sxx = sum((a - mx) ** 2 for a in x)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / sxx


def corr(x, y):
    mx, my = st.mean(x), st.mean(y)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / math.sqrt(sum((a - mx) ** 2 for a in x) * sum((b - my) ** 2 for b in y))


def pct(v, p):
    s = sorted(v)
    return s[min(len(s) - 1, max(0, int(round(p * (len(s) - 1)))))]


data = {a: load(a) for a in ARMS}
seeds = sorted(set.intersection(*[set(data[a]) for a in ARMS]))
print(f'paired paths: {len(seeds)}\n')

print(f'{"CNDR":34s}' + ''.join(f'{LABEL[a]:>12s}' for a in ARMS))
metrics = {}
for a in ARMS:
    el, cm, cg, oms, losses, n, eq, dead = [], [], [], [], 0, 0, [], 0
    for s in seeds:
        f = data[a][s]['final']['CNDR']
        dead += 1 if f['dead'] else 0
        q = [x for x in f['q'] if x['rev'] > 0]
        dy = [math.log(q[i]['rev'] / q[i - 4]['rev']) for i in range(4, len(q))]
        dm = [math.log(q[i]['metals'] / q[i - 4]['metals']) for i in range(4, len(q))]
        el.append(ols(dm, dy))
        cm.append(corr([x['metals'] for x in q], [x['om'] for x in q]))
        cg.append(corr([x['gap'] for x in q], [x['om'] for x in q]))
        oms += [x['om'] for x in q]
        losses += sum(1 for x in q if x['ebit'] < 0)
        n += len(q)
        eq.append(f['eq_x'])
    metrics[a] = {
        'revenue elasticity to metals (YoY)': st.median(el),
        'corr(margin, metals price)': st.median(cm),
        'corr(margin, domestic output gap)': st.median(cg),
        'operating margin p5': pct(oms, .05),
        'operating margin p50': st.median(oms),
        'operating margin p95': pct(oms, .95),
        'loss-quarter share': losses / n,
        'equity multiple over 20y': st.median(eq),
        'deaths': dead,
    }
for k in metrics[ARMS[0]]:
    print(f'  {k:32s}' + ''.join(f'{metrics[a][k]:12.3f}' for a in ARMS))

print()
for a in ARMS:
    deaths = Counter(t for s in seeds for t in (data[a][s]['dead'] or {}))
    reorgs = Counter(x['t'] for s in seeds for x in (data[a][s]['reorgs'] or []))
    print(f'{LABEL[a]:10s} market deaths {sum(deaths.values())} {dict(deaths)}  reorgs {sum(reorgs.values())} {dict(reorgs)}')

# Everyone else should be untouched but for the market interplay (prices, industry ledgers, M&A).
shifts = []
for t in data['fix'][seeds[0]]['final']:
    if t == 'CNDR':
        continue
    d = []
    for s in seeds:
        a = [x['om'] for x in data['fix'][s]['final'][t]['q']]
        b = [x['om'] for x in data[ARMS[-1]][s]['final'][t]['q']]
        if len(a) > 20 and len(b) > 20:
            d.append(st.median(b) - st.median(a))
    if d:
        shifts.append((t, st.mean(d), st.pstdev(d) / math.sqrt(len(d))))
print(f'\nother firms: mean per-firm median-margin shift {100 * st.mean(s for _, s, _ in shifts):+.3f}pp; '
      f'firms beyond 3 standard errors: {[(t, round(100 * m, 2)) for t, m, se in shifts if se > 0 and abs(m) > 3 * se]}')
