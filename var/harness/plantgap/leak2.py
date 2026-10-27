"""leak2.py <arm> <seeds> [tickers]: where the accelerator's plan goes, per firm, years 5+ (runs/<arm>-<s>.pg.jsonl).
Columns (%/yr of net PP&E unless said): plan = the accelerator's growth budget; int = built from internal funds;
trs = built by the treasury (to CIP); lost = plan left unbuilt; mnt-gap = maintenance short of wear at replacement cost;
ppe = realised net PP&E growth; rgc-ppe = growth of the capital base capacity is read on less net PP&E growth;
cip/ic = CIP share of invested capital; nwc/ic; gate0 = share of reports with the build gate shut; fc = funding-constrained."""
import json, math, sys
from collections import defaultdict
arm, seeds = sys.argv[1], [int(s) for s in sys.argv[2].split(',')]
want = sys.argv[3].split(',') if len(sys.argv) > 3 else None
A = defaultdict(lambda: defaultdict(list))
for s in seeds:
    by = defaultdict(list)
    for line in open(f'runs/{arm}-{s}.pg.jsonl'):
        r = json.loads(line)
        if r.get('seg') or r.get('plan') is None:
            continue
        by[r['tk']].append(r)
    for tk, rs in by.items():
        late = [r for r in rs if r['t'] >= 5 and r['ppe'] > 0]
        if len(late) < 20:
            continue
        P = sum(r['ppe'] for r in late)
        yrs = late[-1]['t'] - late[0]['t']
        f = lambda k: 100 * sum(r[k] or 0 for r in late) / P * 4
        a = A[(rs[0]['ind'], tk)]
        a['plan'].append(f('plan')); a['int'].append(f('grow')); a['trs'].append(f('org')); a['lost'].append(f('bout'))
        a['mnt'].append(100 * sum(r['dep'] * r['rcr'] - r['maint'] for r in late) / P * 4)
        a['ppe'].append(100 * math.log(late[-1]['ppe'] / late[0]['ppe']) / yrs)
        a['rgc'].append(100 * (math.log(late[-1]['rgc'] / late[0]['rgc']) - math.log(late[-1]['ppe'] / late[0]['ppe'])) / yrs)
        a['cip'].append(sum(r['cip'] / r['ic'] for r in late) / len(late)); a['nwc'].append(sum(r['nwc'] / r['ic'] for r in late) / len(late))
        a['gate'].append(sum(1 for r in late if r['gate'] == 0) / len(late)); a['fc'].append(sum(r['fc'] for r in late) / len(late))
        a['gap'].append(sum(r['gap'] for r in late) / len(late)); a['g'].append(100 * sum(r['g'] for r in late) / len(late))
        a['reval'].append(f('reval')); a['sdg'].append(100 * (sum(math.log(r['rgc']) for r in late[-8:]) / 8 - sum(math.log(r['rgc']) for r in late[:8]) / 8))
m = lambda x: sum(x) / len(x)
rows = sorted(A.items(), key=lambda kv: -m(kv[1]['gap']))
print('industry                 | tk   |  gap  |  g   | plan |  int |  trs | lost | mnt-gap| reval|  ppe | rgc-ppe| cip/ic| nwc/ic| gate0|  fc')
for (ind, tk), a in rows:
    if want and tk not in want and not (want == ['top'] and rows.index(((ind, tk), a)) < 16):
        continue
    print(f"{ind[:24]:24s} | {tk:4s} | {m(a['gap']):+.3f} | {m(a['g']):4.1f} | {m(a['plan']):4.1f} | {m(a['int']):4.1f} | {m(a['trs']):4.1f} | {m(a['lost']):4.1f} | {m(a['mnt']):+6.2f} | {m(a['reval']):4.1f} | {m(a['ppe']):4.1f} | {m(a['rgc']):+6.2f} | {m(a['cip']):.3f} | {m(a['nwc']):+.3f} | {m(a['gate']):.2f} | {m(a['fc']):.2f}")
allg = [g for a in A.values() for g in a['gap']]
print(f"board firms {len(A)} mean gap {m(allg):+.3f}")
