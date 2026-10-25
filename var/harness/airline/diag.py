#!/usr/bin/env python3
"""TERN diagnostics: price revenue against the cycle, load factor against the gap, seat-vs-demand mismatch."""
import json, statistics as st, sys
from pathlib import Path
arm = sys.argv[1] if len(sys.argv) > 1 else 'live'
for s in range(1, 5):
    q = json.load(open(Path(__file__).parent / 'runs' / f'{arm}-s{s}.json'))['q']['TERN']
    pr = [r['kpi']['price_revenue'] / r['rev'] for r in q]; g = [r['gap'] for r in q]
    lf = [r['kpi']['load_factor'] for r in q]; seat = [r['kpi']['seat_capacity_index'] for r in q]
    om = [r['om'] for r in q]
    hi = [i for i, v in enumerate(g) if v > 0.01]; lo = [i for i, v in enumerate(g) if v < -0.01]
    m = lambda xs, ii: st.fmean(xs[i] for i in ii) if ii else float('nan')
    print(f's{s} mean price rev/rev {st.fmean(pr):+.3f} corr(pr, gap) {st.correlation(pr, g):+.2f} corr(lf, gap) {st.correlation(lf, g):+.2f} '
          f'corr(seat, gap) {st.correlation(seat, g):+.2f} | gap>1%: n {len(hi)} lf {m(lf, hi):.3f} om {m(om, hi):.3f} pr {m(pr, hi):+.3f} '
          f'| gap<-1%: n {len(lo)} lf {m(lf, lo):.3f} om {m(om, lo):.3f} pr {m(pr, lo):+.3f} | all om {st.fmean(om):.3f}')
