#!/usr/bin/env python3
"""analyse_y40.py [seeds] [tag]: net debt/EBITDA every 5 years (trailing four reports) for named firms and the
non-financial board median, arms base and fix, runs/<arm>-<seed><tag>.jsonl. Also LOON price level per 5 years."""
import json, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = [int(s) for s in (sys.argv[1] if len(sys.argv) > 1 else '1,2').split(',')]
tag = sys.argv[2] if len(sys.argv) > 2 else '-y40'
NAMED = ['LOON', 'SKUA', 'SGRB', 'FALC']


def nd(q, y):
    rows = [x for x in q if y - 1 < x['t'] <= y]
    e = sum(x['ebitda'] for x in rows)
    return (rows[-1]['debt'] - rows[-1]['cash']) / e if rows and e > 0 else None


for s in seeds:
    for arm in ('base', 'fix'):
        r = json.loads(open(f'{H}/runs/{arm}-{s}{tag}.jsonl').readline())
        k = json.load(open(f'{H}/runs/{arm}-{s}{tag}.idle.json'))['rows']
        years = list(range(5, r['years'] + 1, 5))
        print(f'seed {s} {arm} | ' + ' | '.join(f'y{y}' for y in years))
        for tk in NAMED:
            q = r['final'][tk]['q']
            print(f'  {tk} ND/EBITDA | ' + ' | '.join('n/a' if nd(q, y) is None else f'{nd(q, y):.2f}' for y in years))
        for tk in ('LOON', 'SKUA'):
            pl = [row for row in k if row[1] == tk and row[4] is not None]
            print(f'  {tk} price level | ' + ' | '.join(
                f"{st.fmean(row[4] for row in pl if y - 1 < row[0] <= y):.3f}" for y in years))
        med = []
        for y in years:
            v = [nd(f['q'], y) for f in r['final'].values() if f['q'] and not f['q'][0]['fin']]
            med.append(st.median(x for x in v if x is not None))
        print('  board median ND/EBITDA | ' + ' | '.join(f'{m:.2f}' for m in med))
        ev = r['defaults'] if r['defaults'] else ''
        if ev or r['reorgs'] or r['dead']:
            print(f'  events: defaults {ev} reorgs {r["reorgs"]} dead {r["dead"]}')
