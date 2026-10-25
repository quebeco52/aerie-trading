#!/usr/bin/env python3
"""analyse_fare.py [seeds] [A] [B]: airline fare-elasticity check (section n), TERN and board, B - A paired.

TERN events = firm-level defaults (any quarter in default), reorganisations and failures, counted per seed.
Drawdown episodes: trailing-4q EBITDA margin falls > 5 points below its prior 8-quarter peak; an episode runs until
the margin is back within 5 points; trough = its minimum. Classified by crude (energy index EMA) rise over the 8
quarters before the trough (< or >= 30%) and the minimum output gap in the episode (< or >= -2%).
Opening cap and EPS at the seed (onStart); P/E opening = opening cap / shares / EPS.
"""
import json, math, os, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
seeds = [int(x) for x in (sys.argv[1] if len(sys.argv) > 1 else ','.join(map(str, range(1, 17)))).split(',')]
A, B = (sys.argv[2], sys.argv[3]) if len(sys.argv) > 3 else ('headn', 'fare')
ORDER = ['AAA', 'AA+', 'AA', 'AA-', 'A+', 'A', 'A-', 'BBB+', 'BBB', 'BBB-', 'BB+', 'BB', 'BB-', 'B+', 'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D']
BAD = set(ORDER[ORDER.index('B'):])
CCC = set(ORDER[ORDER.index('CCC+'):])


def pct(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, max(0, int(math.ceil(p * len(xs))) - 1))] if xs else float('nan')


def run(arm, s):
    r = json.loads(open(f'{H}/runs/{arm}-{s}-oc.jsonl').readline())
    k = json.load(open(f'{H}/runs/{arm}-{s}-oc.idle.json'))
    q = r['final']['TERN']['q']
    kp = k['kpi']['TERN']
    out = {}
    out['TERN events'] = ('TERN' in (r['defaults'] or {})) + sum(1 for e in r['reorgs'] if e['t'] == 'TERN') + ('TERN' in r['dead'])
    out['TERN CCC-or-worse quarters'] = sum(1 for x in q if x['rating'] in CCC)
    allq = [x for f in r['final'].values() for x in f['q']]
    out['board defaults+deaths'] = len(r['defaults']) + len(r['dead'])
    out['board B-or-worse share'] = sum(1 for x in allq if x['rating'] in BAD) / len(allq)
    mg = []
    for i in range(3, len(q)):
        rev = sum(x['rev'] for x in q[i - 3:i + 1])
        mg.append((q[i]['t'], sum(x['ebitda'] for x in q[i - 3:i + 1]) / rev if rev > 0 else float('nan'), i))
    out['TERN mean ttm EBITDA margin'] = st.fmean(m for _, m, _ in mg if not math.isnan(m))
    out['TERN mean op margin'] = st.fmean(x['om'] for x in q)
    shares0 = q[0]['shares']
    out['TERN opening cap ($bn)'] = kp[1] / 1e9
    out['TERN opening P/E'] = (kp[1] / shares0) / kp[2] if kp[2] > 0 else float('nan')
    pe = [x['price'] / x['eps'] for x in q if 3 <= x['t'] <= 20 and x['eps'] > 0]
    out['TERN median P/E y3-20'] = st.median(pe) if pe else float('nan')
    lf = [row[1].get('load_factor') for row in kp[0] if row[1].get('load_factor') is not None]
    out['TERN mean load factor'] = st.fmean(lf) if lf else float('nan')
    a, n, cfg, _ = k['rv']['TERN']
    out['TERN realised vol'] = math.sqrt(a / n * k['tpy']) if n else float('nan')
    out['TERN max drawdown'] = kp[4]
    caps = sum(f['q'][-1]['price'] * f['q'][-1]['shares'] for f in r['final'].values() if f['q'] and not f['dead'])
    out['board cap y20 ($tn)'] = caps / 1e12
    rv = [math.sqrt(a / n * k['tpy']) for tk, (a, n, c, _) in k['rv'].items() if n >= k['tpy'] and not r['final'][tk]['dead']]
    out['board median realised vol'] = st.median(rv)
    # drawdown episodes
    eps, i = [], 0
    while i < len(mg):
        t, m, qi = mg[i]
        prior = [x[1] for x in mg[max(0, i - 8):i] if not math.isnan(x[1])]
        if prior and not math.isnan(m) and m < max(prior) - 0.05:
            peak = max(prior)
            j, trough, tq = i, m, qi
            while j < len(mg) and not math.isnan(mg[j][1]) and mg[j][1] < peak - 0.05:
                if mg[j][1] < trough:
                    trough, tq = mg[j][1], mg[j][2]
                j += 1
            crude_now = q[tq]['crude']
            crude_then = q[max(0, tq - 8)]['crude']
            gap_min = min(x['gap'] for x in q[mg[i][2]:mg[min(j, len(mg) - 1)][2] + 1])
            eps.append({'trough': trough, 'fall': peak - trough, 'oil': crude_then > 0 and crude_now / crude_then - 1 >= 0.30, 'gapmin': gap_min})
            i = j
        else:
            i += 1
    return out, eps


ra = {s: run(A, s) for s in seeds}
rb = {s: run(B, s) for s in seeds}
print(f'quantity | {A} | {B} | {B}-{A} (se) t n')
for key in ra[seeds[0]][0]:
    a = [ra[s][0][key] for s in seeds]
    b = [rb[s][0][key] for s in seeds]
    ok = [(x, y) for x, y in zip(a, b) if not (math.isnan(x) or math.isnan(y))]
    print(f'{key} | {st.fmean(x for x, _ in ok):.4g} | {st.fmean(y for _, y in ok):.4g} | {fmt(paired([x for x, _ in ok], [y for _, y in ok]), 4)}')
for arm, rr in ((A, ra), (B, rb)):
    ev = [f's{s}' for s in seeds if rr[s][0]['TERN events']]
    print(f'{arm}: TERN event seeds {ev}')

print('\nTERN EBITDA-margin drawdown episodes (> 5 pts below prior 8q peak): n, trough median / p10, fall median')
for arm, rr in ((A, ra), (B, rb)):
    allep = [e for s in seeds for e in rr[s][1]]
    for name, f in (('no oil spike, gap >= -2%', lambda e: not e['oil'] and e['gapmin'] >= -0.02),
                    ('no oil spike, gap < -2%', lambda e: not e['oil'] and e['gapmin'] < -0.02),
                    ('oil spike, gap >= -2%', lambda e: e['oil'] and e['gapmin'] >= -0.02),
                    ('oil spike, gap < -2%', lambda e: e['oil'] and e['gapmin'] < -0.02)):
        e = [x for x in allep if f(x)]
        if e:
            print(f'{arm} | {name} | {len(e)} | {st.median(x["trough"] for x in e):.3f} / {pct([x["trough"] for x in e], .1):.3f} | {st.median(x["fall"] for x in e):.3f}')
        else:
            print(f'{arm} | {name} | 0 | - | -')
