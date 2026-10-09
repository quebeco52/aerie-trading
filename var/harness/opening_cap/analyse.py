#!/usr/bin/env python3
"""First-quarter board cap (USD) with and without PLVR. Docblock seeds 11-12, then seeds 1-16 for the SE."""
import json, math, os, statistics as st
D = os.path.dirname(os.path.abspath(__file__))
def load(arm, s): return json.load(open(f'{D}/runs/{arm}-{s}.json'))
def ms(xs): return st.mean(xs), (st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float('nan'))
K = [('cap_q1_mean', 'Q1 mean'), ('cap_tick1', 'tick 1'), ('cap_q1_end', 'Q1 end')]
print('USD trillions; live macro fed the board cap, 360 tpy, operator every 15 ticks; constant now 19.9')
print(f'{"seed":>5} ' + ' '.join(f'{"with "+l:>13s} {"w/o "+l:>13s} {"PLVR":>7s}' for _, l in K) + '  stocks with/w/o  PLVR mcap tick1')
for s in (11, 12):
    w, o = load('with', s), load('without', s)
    print(f'{s:>5} ' + ' '.join(f'{w[k]/1e12:13.3f} {o[k]/1e12:13.3f} {(w[k]-o[k])/1e12:7.3f}' for k, _ in K)
          + f'  {w["n_stocks"]}/{o["n_stocks"]}  {w["firm_tick1"]["PLVR"]/1e12:.3f}')
for label, seeds in (('mean 11-12', (11, 12)), ('mean 1-16', range(1, 17))):
    cells = []
    for k, _ in K:
        a = ms([load('with', s)[k] / 1e12 for s in seeds]); b = ms([load('without', s)[k] / 1e12 for s in seeds])
        d = ms([(load('with', s)[k] - load('without', s)[k]) / 1e12 for s in seeds])
        cells.append(f'{a[0]:7.3f}({a[1]:.3f}) {b[0]:7.3f}({b[1]:.3f}) {d[0]:5.3f}({d[1]:.3f})')
    print(f'{label:>10} ' + ' '.join(cells) + f'  n={len(list(seeds))}')
pl = [load('with', s)['firm_tick1']['PLVR'] / 1e12 for s in range(1, 17)]
pq = [load('with', s)['firm_q1_end']['PLVR'] / 1e12 for s in range(1, 17)]
print(f'PLVR price x shares, seeds 1-16: tick 1 {st.mean(pl):.3f} ({st.stdev(pl)/4:.3f}), Q1 end {st.mean(pq):.3f} ({st.stdev(pq)/4:.3f}); seed value {load("with",1)["firm_seed"]["PLVR"]/1e12:.3f}')
r = [load('with', s)['cap_q1_mean'] / 1e12 for s in range(1, 17)]
print(f'Q1 mean cap with PLVR, seeds 1-16 range {min(r):.3f}..{max(r):.3f}')
