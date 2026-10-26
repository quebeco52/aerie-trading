#!/usr/bin/env python3
"""same.py <seeds> A B: are two arms output-identical? Per seed, the first report where each stream differs:
quarter log (revenue, EPS, price, rating, debt, cash), listener rows (net income is not in them: revenue, operating /
maintenance capex, net PP&E, industry price level, plant load), segment kappa and targets, y20 cap, deals, defaults,
reorganisations. Counts of reports, companies, segmented reports; venture keys (':x:', segment_pending,
segment_learning) and TICKER/x: records. Prints the final table only.
"""
import json, os, sys

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = sys.argv[2], sys.argv[3]
TOL = 1e-9


def close(x, y):
    if isinstance(x, (int, float)) and isinstance(y, (int, float)) and not isinstance(x, bool):
        return abs(x - y) <= TOL * max(1.0, abs(x), abs(y))
    if isinstance(x, dict) and isinstance(y, dict):
        return x.keys() == y.keys() and all(close(x[k], y[k]) for k in x)
    if isinstance(x, list) and isinstance(y, list):
        return len(x) == len(y) and all(close(a, b) for a, b in zip(x, y))
    return x == y


def first_diff(xs, ys, label):
    for i, (x, y) in enumerate(zip(xs, ys)):
        if not close(x, y):
            return f'{label}: first at item {i}: {str(x)[:160]} vs {str(y)[:160]}'
    if len(xs) != len(ys):
        return f'{label}: lengths {len(xs)} vs {len(ys)}'
    return None


for s in seeds:
    ra = json.loads(open(f'{H}/runs/{A}-{s}.jsonl').read().splitlines()[-1])
    rb = json.loads(open(f'{H}/runs/{B}-{s}.jsonl').read().splitlines()[-1])
    ga = json.load(open(f'{H}/runs/{A}-{s}.seg.json'))
    gb = json.load(open(f'{H}/runs/{B}-{s}.seg.json'))
    diffs = []
    qa = [(tk, x) for tk in sorted(ra['final']) for x in ra['final'][tk]['q']]
    qb = [(tk, x) for tk in sorted(rb['final']) for x in rb['final'][tk]['q']]
    diffs.append(first_diff(qa, qb, 'quarter log (rev, eps, price, rating, debt, cash, ebitda)'))
    diffs.append(first_diff([r[:11] + r[12:16] for r in ga.get('cap', [])], [r[:11] + r[12:16] for r in gb.get('cap', [])], 'listener (capex, maintenance, PP&E, price level, load)'))
    diffs.append(first_diff([(r['t'], r['tk'], r.get('kappa') or {}, r.get('targets') or {}) for r in ga['rows']],
                            [(r['t'], r['tk'], r.get('kappa') or {}, r.get('targets') or {}) for r in gb['rows']], 'segment kappa and targets'))
    cap = lambda r: {tk: v['q'][-1]['price'] * v['q'][-1]['shares'] for tk, v in r['final'].items() if v['q']}
    diffs.append(first_diff([cap(ra)], [cap(rb)], 'y20 caps'))
    diffs.append(first_diff([ga.get('deals', [])], [gb.get('deals', [])], 'deals'))
    diffs.append(first_diff([ra['defaults'], ra['reorgs'], ra['dead']], [rb['defaults'], rb['reorgs'], rb['dead']], 'defaults/reorgs/deaths'))
    diffs.append(first_diff([{k: v for k, v in ra.items() if k not in ('secs',)}], [{k: v for k, v in rb.items() if k not in ('secs',)}], 'whole run output'))
    counts = lambda r, g: (sum(len(v['q']) for v in r['final'].values()), len(r['final']), sum(1 for x in g['rows'] if x.get('kappa')))
    ven = gb['leaks'].get('venture', 0)
    xrec = [x for x in gb.get('slash', []) if '/x:' in x]
    print(f's{s}: ' + ('IDENTICAL' if not any(diffs) else 'DIFFER') + f'; reports/companies/segmented {A} {counts(ra, ga)} {B} {counts(rb, gb)}; '
          f'venture keys {B} {ven}, x: records {len(xrec)}')
    for d in diffs:
        if d:
            print('    ' + d)
