#!/usr/bin/env python3
"""analyse_outlook.py [seeds] [arm] [tag]: industry outlook state from runs/<arm>-<seed><tag>.outlook.json.

Per seed: number of industries, dispersion of exp(x) at the last quarter (sd, min, p10, p90, max), belief error
|b - x| with b = m + v/2 (mean and max at year 20, mean over all quarters), and the zero-sum check
max |sum w e^x / sum w - 1| over quarters."""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = [int(s) for s in (sys.argv[1] if len(sys.argv) > 1 else '1,2,3,4').split(',')]
arm = sys.argv[2] if len(sys.argv) > 2 else 'on'
tag = sys.argv[3] if len(sys.argv) > 3 else '-oc'


def pct(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, max(0, int(math.ceil(p * len(xs))) - 1))]


print('seed | n ind | quarters | exp(x) y20 sd | min | p10 | p90 | max | |b-x| y20 mean | max | |b-x| all-q mean | '
      'max |sum w e^x/sum w - 1| | fringe f y20 sd')
agg = {'sd': [], 'err20': [], 'errall': [], 'zs': []}
for s in seeds:
    q = json.load(open(f'{H}/runs/{arm}-{s}{tag}.outlook.json'))['quarters']
    if not q:
        print(f'{s} | no outlook state logged')
        continue
    last = q[-1][2]
    ex = [math.exp(r[0]) for r in last.values()]
    err = lambda rows: [abs(r[1] + r[2] / 2.0 - r[0]) for r in rows.values()]
    e20 = err(last)
    eall = [e for _, _, rows in q for e in err(rows)]
    zs = max(abs(sum(r[4] * math.exp(r[0]) for r in rows.values()) / sum(r[4] for r in rows.values()) - 1.0)
             for _, _, rows in q if rows)
    fsd = st.pstdev(r[3] for r in last.values())
    print(f'{s} | {len(last)} | {len(q)} | {st.pstdev(ex):.3f} | {min(ex):.3f} | {pct(ex, .1):.3f} | {pct(ex, .9):.3f} | '
          f'{max(ex):.3f} | {st.fmean(e20):.3f} | {max(e20):.3f} | {st.fmean(eall):.3f} | {zs:.1e} | {fsd:.3f}')
    agg['sd'].append(st.pstdev(ex)); agg['err20'].append(st.fmean(e20)); agg['errall'].append(st.fmean(eall)); agg['zs'].append(zs)
if agg['sd']:
    print(f"mean | | | {st.fmean(agg['sd']):.3f} | | | | | {st.fmean(agg['err20']):.3f} | | {st.fmean(agg['errall']):.3f} | "
          f"{max(agg['zs']):.1e} |")
    print(f"reference: 20y random walk at 0.08 gives sd(x) {0.08 * math.sqrt(20):.3f}; steady belief sd at half-life 2y "
          f"is the filter's sqrt(v)")
