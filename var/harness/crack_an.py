"""Crack recalibration A/B: old crack (runs2/rec-*) vs new crack (runs2/rec3-*), live runs on the same seeds."""
import glob
import json
import math
import statistics as st
import sys
from collections import Counter

H = sys.argv[1] if len(sys.argv) > 1 else '.'
ARMS = {'rec': 'old crack', 'rec3': 'new crack', 'rec4': '+capture', 'rec5': 'per-bbl'}


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
seeds = sorted(set.intersection(*[set(d) for d in data.values()]))
print(f'paired seeds: {len(seeds)}')

for ticker in ['CASC', 'FULM']:
    print(f'\n{ticker:36s}' + ''.join(f'{v:>12s}' for v in ARMS.values()))
    out = {}
    for a in ARMS:
        oms, losses, n, cm, dead, byq = [], 0, 0, [], 0, {0: [], 1: [], 2: [], 3: []}
        for s in seeds:
            f = data[a][s]['final'][ticker]
            dead += 1 if f['dead'] else 0
            q = [x for x in f['q'] if x['rev'] > 0]
            oms += [x['om'] for x in q]
            losses += sum(1 for x in q if x['ebit'] < 0)
            n += len(q)
            cm.append(corr([x['crack'] for x in q], [x['om'] for x in q]))
            for x in q:
                byq[int((x['t'] % 1.0) * 4) % 4].append(x['om'])
        mean_om = st.mean(oms)
        out[a] = {
            'operating margin p5': pct(oms, .05),
            'operating margin p50': st.median(oms),
            'operating margin p95': pct(oms, .95),
            'operating margin max': max(oms),
            'loss-quarter share': losses / n,
            'corr(margin, crack)': st.median(cm),
            'margin Q1 (Jan-Mar)': st.mean(byq[0]),
            'margin Q2 (Apr-Jun)': st.mean(byq[1]),
            'margin Q3 (Jul-Sep)': st.mean(byq[2]),
            'margin Q4 (Oct-Dec)': st.mean(byq[3]),
            'deaths': dead,
        }
    for k in out['rec']:
        print(f'  {k:34s}' + ''.join(f'{out[a][k]:12.3f}' for a in ARMS))

print()
for a, label in ARMS.items():
    deaths = Counter(t for s in seeds for t in (data[a][s]['dead'] or {}))
    reorgs = Counter(x['t'] for s in seeds for x in (data[a][s]['reorgs'] or []))
    print(f'{label:10s} market deaths {sum(deaths.values())} {dict(deaths)}  reorgs {sum(reorgs.values())} {dict(reorgs)}')


# CASC's operating margin by crack level, against Valero's refining operating income over total revenue.
valero = [(9.04, -0.021, 2020), (16.12, 0.044, 2018), (16.94, 0.037, 2019), (17.82, 0.016, 2021), (30.16, 0.080, 2023), (36.66, 0.090, 2022)]
bins = [(0, 12), (12, 20), (20, 28), (28, 36), (36, 99)]
print('\nCASC margin by crack level (median, last arm) vs Valero')
last = list(ARMS)[-1]
for lo, hi in bins:
    ms = [x['om'] for s in seeds for x in data[last][s]['final']['CASC']['q'] if x['rev'] > 0 and lo <= x['crack'] < hi]
    vs = [f"{y}: {100*m:.1f}% @ ${c:.0f}" for c, m, y in valero if lo <= c < hi]
    if ms:
        print(f"  crack ${lo:>2}-{hi:<2}: CASC {100*st.median(ms):5.1f}% (n={len(ms):4d})   Valero: {', '.join(vs) if vs else '-'}")
