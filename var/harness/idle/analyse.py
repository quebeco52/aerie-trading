#!/usr/bin/env python3
"""analyse.py [seeds]: base vs idle OUT identity per seed, the idle KPIs in the idle arm, and paired targets if they differ."""
import json, os, sys, statistics
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
seeds = [int(s) for s in (sys.argv[1] if len(sys.argv) > 1 else '1,2,3,4').split(',')]
BAD = {'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D', 'SD'}


def load(arm, s):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
    r.pop('secs', None)
    return r


def first_diff(a, b, path=''):
    if type(a) is not type(b):
        return path
    if isinstance(a, dict):
        for k in sorted(set(a) | set(b)):
            if k not in a or k not in b:
                return f'{path}.{k}'
            d = first_diff(a[k], b[k], f'{path}.{k}')
            if d:
                return d
        return None
    if isinstance(a, list):
        if len(a) != len(b):
            return f'{path}[len]'
        for i, (x, y) in enumerate(zip(a, b)):
            d = first_diff(x, y, f'{path}[{i}]')
            if d:
                return d
        return None
    return None if a == b else path


def targets(r):
    qs = [q for f in r['final'].values() for q in f['q']]
    tmax = max(q['t'] for q in qs)
    cap = sum(f['q'][-1]['price'] * f['q'][-1]['shares'] for f in r['final'].values() if f['q'] and not f['dead'])
    return {
        'board cap end ($tn)': cap / 1e12,
        'median op margin': statistics.median(q['om'] for q in qs),
        'defaults+deaths': len(r['defaults']) + len(r['dead']),
        'share B or worse': sum(1 for q in qs if q['rating'] in BAD) / len(qs),
    }


ident = True
print('seed | OUT identical | first difference')
for s in seeds:
    d = first_diff(load('base', s), load('idle', s))
    ident &= d is None
    print(f'{s} | {"yes" if d is None else "no"} | {d or "-"}')

rows = [row for s in seeds for row in json.load(open(f'{H}/runs/idle-{s}.idle.json'))['rows']]
idle = [r for r in rows if r[3] is not None and r[3] > 1e-12]
lvl = [r for r in rows if r[4] is not None]
print(f'\nidle arm: {len(rows)} firm-quarters, {len(idle)} with idle_plant_share > 0')
if idle:
    print(f'max idle_plant_share {max(r[3] for r in idle):.4f}')
    by = {}
    for r in idle:
        by.setdefault((r[1], r[2]), []).append(r[3])
    for (t, ind), v in sorted(by.items(), key=lambda kv: -len(kv[1])):
        print(f'  {t} ({ind}): {len(v)} quarters, max {max(v):.4f}')
if lvl:
    m = min(lvl, key=lambda r: r[4])
    print(f'min industry_price_level {m[4]:.4f} ({m[1]}, {m[2]}, t={m[0]})')
    print(f'firm-quarters with price level < 0.95: {sum(1 for r in lvl if r[4] < 0.95)}')

if not ident:
    print('\nquantity | base | idle | idle-base (se) t n')
    tb = [targets(load('base', s)) for s in seeds]
    ti = [targets(load('idle', s)) for s in seeds]
    for k in tb[0]:
        a = [x[k] for x in tb]
        b = [x[k] for x in ti]
        p = paired(a, b)
        print(f'{k} | {statistics.mean(a):.4g} | {statistics.mean(b):.4g} | {fmt(p)}')
