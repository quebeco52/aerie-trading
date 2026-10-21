#!/usr/bin/env python3
"""i_bridge.py <seeds> <arms...>: SWAN/POOL equity bridge over the run: d(eq) vs sum(ni - div - bb), the residual
(issuance, marks, other) as $B and as share of d(eq), split-unadjusted share-count ratio end/start, and the two names'
mean share of board market cap. Values are means over seeds."""
import json, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
for arm in sys.argv[2:]:
    for tk in ('SWAN', 'POOL'):
        rows = []
        for s in seeds:
            fin = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())['final']; q = fin[tk]['q']
            de = q[-1]['eq'] - q[0]['eq']; ret = sum(x['ni'] - x['div'] - x['bb'] for x in q[1:])
            caps = [sum(v['q'][-1]['price'] * v['q'][-1]['shares'] for v in fin.values() if v['q'])]
            w = q[-1]['price'] * q[-1]['shares'] / caps[0]
            rows.append((de / 1e9, ret / 1e9, (de - ret) / 1e9, (de - ret) / de if de else 0,
                         q[-1]['shares'] / q[0]['shares'], w))
        m = [st.mean(c) for c in zip(*rows)]
        print(f'{arm:5s} {tk}: d eq ${m[0]:.0f}B  retained ${m[1]:.0f}B  residual ${m[2]:.0f}B ({100 * m[3]:.0f}% of d eq)'
              f'  shares end/start {m[4]:.2f}  end cap weight {100 * m[5]:.1f}%')
