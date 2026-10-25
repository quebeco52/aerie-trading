#!/usr/bin/env python3
"""analyse_gate.py [seeds] [arm]: growth-gate anatomy for the probed firms (ovr_gate log, idle.json `probe`).

Per firm, over all growth-capex decisions: share with raw gap > 0.05 and the gate failed (filed gap clamped to 0);
share with raw gap > 0.05, gate passed, and internal funds below the budget (growth capex = funds < budget);
share with raw gap > 0.05, gate passed and fully funded. Also per-firm event counts in the given arms.
"""
import json, os, sys
from collections import Counter

H = os.path.dirname(os.path.abspath(__file__))
seeds = [int(s) for s in (sys.argv[1] if len(sys.argv) > 1 else '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16').split(',')]
arm = sys.argv[2] if len(sys.argv) > 2 else 'gate'
n, short, failed, bound, funded = Counter(), Counter(), Counter(), Counter(), Counter()
for s in seeds:
    for p in json.load(open(f'{H}/runs/{arm}-{s}-oc.idle.json'))['probe']:
        t = p['ticker']
        n[t] += 1
        if p['raw'] > 0.05:
            short[t] += 1
            if p['gate_failed']:
                failed[t] += 1
            elif p['budget'] is not None and p['funds'] < p['budget'] - 1e-6:
                bound[t] += 1
            else:
                funded[t] += 1
print('firm | decisions | raw gap > 0.05 | gate failed while short | passed, funds below budget | passed, fully funded')
for t in sorted(n):
    f = lambda c: f'{c[t] / n[t]:.3f}'
    print(f'{t} | {n[t]} | {f(short)} | {f(failed)} | {f(bound)} | {f(funded)}')

print('\nevents per firm (seeds with a default, reorganisation or failure)')
for a in ('val', 'prior', arm):
    c = Counter()
    for s in seeds:
        name = a if a != 'val' or os.path.exists(f'{H}/runs/val-{s}-oc.jsonl') else 'head5'
        r = json.loads(open(f'{H}/runs/{name}-{s}-oc.jsonl').readline())
        firms = set((r['defaults'] or {}).keys()) | {e['t'] for e in r['reorgs']} | set(r['dead'])
        for tk in firms:
            c[tk] += 1
    print(f'{a}: ' + ', '.join(f'{tk} {k}' for tk, k in c.most_common()))
