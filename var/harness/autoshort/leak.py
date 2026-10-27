"""leak.py <arm> <seeds>: per firm, y5+ mean own log(trend plant/plant) G, growth of trend plant and plant (%/yr, y1->end),
the accelerator's planned growth g + k*G, and the share of quarters with G > 0.05 but the filed gap <= 0 (gate shut)."""
import json, math, sys
from collections import defaultdict
arm, seeds = sys.argv[1], [int(s) for s in sys.argv[2].split(',')]
K = 0.062
agg = defaultdict(lambda: defaultdict(list))
for s in seeds:
    d = json.load(open(f'runs/{arm}-{s}.short.json'))
    by = defaultdict(list)
    for r in d['rows']:
        if r[12]:
            by[r[1]].append(r)
    for tk, rs in by.items():
        a, b = rs[3], rs[-1]
        yrs = b[0] - a[0]
        if yrs < 10:
            continue
        late = [x for x in rs if x[0] >= 5]
        G = [math.log(x[12] / x[11]) for x in late if x[11] > 0]
        shut = sum(1 for x in late if x[11] > 0 and math.log(x[12] / x[11]) > 0.05 and x[15] is not None and x[15] <= 0) / max(1, len(late))
        A = agg[(rs[0][2], tk)]
        A['G'].append(sum(G) / len(G)); A['tp'].append(100 * math.log(b[12] / a[12]) / yrs); A['cap'].append(100 * math.log(b[11] / a[11]) / yrs)
        A['shut'].append(shut); A['P'].append(sum(x[3] for x in late) / len(late))
        A['inf'].append(sum(x[17] for x in late) / len(late))
rows = sorted(agg.items(), key=lambda kv: -sum(kv[1]['G']) / len(kv[1]['G']))
m = lambda x: sum(x) / len(x)
print('industry | ticker | mean G | trend plant %/yr | plant %/yr | shortfall %/yr | k*G %/yr | gate shut share | firm P')
for (i, tk), A in rows[:14] + [r for r in rows[14:] if r[0][1] in ('FALC', 'EIDR')]:
    print(f"{i[:24]:24s} | {tk} | {m(A['G']):+.3f} | {m(A['tp']):+.2f} | {m(A['cap']):+.2f} | {m(A['tp'])-m(A['cap']):+.2f} | {100*K*m(A['G']):+.2f} | {m(A['shut']):.2f} | {m(A['P']):.3f}")
allG = [g for A in agg.values() for g in A['G']]
print(f"board: firms {len(agg)}, mean G {m(allG):+.3f}, share of firm-seeds with G>0.1: {sum(g>0.1 for g in allG)/len(allG):.2f}, G<-0.1: {sum(g<-0.1 for g in allG)/len(allG):.2f}")
print(f"board gate-shut share {m([x for A in agg.values() for x in A['shut']]):.2f}")
