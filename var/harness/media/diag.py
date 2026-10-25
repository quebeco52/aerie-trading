#!/usr/bin/env python3
"""Extra BELL diagnostics: cycle wiring, political ratio by term position, annual growth SDs, dividend-cut timing."""
import json, math, statistics as st, sys
from pathlib import Path
arm = sys.argv[1] if len(sys.argv) > 1 else 'live'
S3 = ('carriage_fees', 'airtime_advertising', 'political_advertising')
ratio = {}
for s in range(1, 5):
    d = json.load(open(Path(__file__).parent / 'runs' / f'{arm}-s{s}.json'))
    q = d['q']['BELL']
    gap = [r['gap'] for r in q]
    c = lambda a, b: st.correlation(a, b)
    up = [r['kpi']['upfront_price_index'] for r in q]; au = [r['kpi']['audience_index'] for r in q]
    air = [r['streams']['airtime_advertising'] for r in q]
    ay = [math.log(b / a) for a, b in zip(air, air[4:])]
    auy = [math.log(b / a) for a, b in zip(au, au[4:])]
    upy = [math.log(b / a) for a, b in zip(up, up[4:])]
    rev = [r['rev'] for r in q]; eb = [r['ebit'] for r in q]; ni = [r['ni'] for r in q]
    A = lambda xs: [sum(xs[4 * y:4 * y + 4]) for y in range(len(xs) // 4)]
    g = lambda xs: st.stdev([b / a - 1 for a, b in zip(xs, xs[1:])])
    dps = [r['div'] / r['sh'] for r in q]
    cuts = [round(q[i + 1]['t'], 2) for i, (a, b) in enumerate(zip(dps, dps[1:])) if a > 0 and b < 0.9 * a]
    print(f's{s} corr(upfront,gap) {c(up, gap):+.2f} corr(audience,gap) {c(au, gap):+.2f} '
          f'sd yoy: airtime {st.stdev(ay):.3f} audience {st.stdev(auy):.3f} upfront {st.stdev(upy):.3f} | '
          f'annual growth SD rev {g(A(rev)):.3f} EBIT {g(A(eb)):.3f} NI {g(A(ni)):.3f} | div cut at t {cuts} | '
          f'om by cal q ' + ' '.join(f"{st.fmean(r['om'] for r in q if (math.floor(round(r['tt'] * 4, 6)) - 1) % 4 == k):.3f}" for k in range(4)))
    for r in q:
        pos = int(math.floor(round(r['tt'] * 4, 6)) - 1) % 16
        if pos >= 12:
            ratio.setdefault(pos, []).append(r['streams']['political_advertising'] / r['streams']['carriage_fees'] * 0.48 / 0.02)
print('political / (carriage-implied plan x 2%) by term position (design 1, 3, 5, 7): '
      + ' '.join(f'{p}:{st.fmean(v):.2f} (n {len(v)})' for p, v in sorted(ratio.items())))
