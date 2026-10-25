#!/usr/bin/env python3
"""Slump quarters (gap EMA < -1.5%), pooled over seeds 1-4: TERN margin and EBIT change scaled by the firm's mean |EBIT|
(robust to near-zero EBIT), plus the worst-seed stretch. Usage: slump.py live fix."""
import json, statistics as st, sys
from pathlib import Path
for arm in sys.argv[1:]:
    om_s, om_a, eb_s, eb_a, rv_s, rv_a = [], [], [], [], [], []
    for s in range(1, 5):
        q = json.load(open(Path(__file__).parent / 'runs' / f'{arm}-s{s}.json'))['q']['TERN']
        eb = [r['ebit'] for r in q]; scale = st.fmean(abs(x) for x in eb)
        for i in range(4, len(q)):
            d_om = q[i]['om'] - q[i - 4]['om']; d_eb = (eb[i] - eb[i - 4]) / scale; d_rv = q[i]['rev'] / q[i - 4]['rev'] - 1
            om_a.append(d_om); eb_a.append(d_eb); rv_a.append(d_rv)
            if q[i]['gap'] < -0.015:
                om_s.append(d_om); eb_s.append(d_eb); rv_s.append(d_rv)
    print(f'{arm}: slump n {len(om_s)}, rev yoy - mean {(st.fmean(rv_s) - st.fmean(rv_a)) * 100:+.2f}pp, '
          f'yoy margin change - mean {(st.fmean(om_s) - st.fmean(om_a)) * 100:+.2f}pp, '
          f'EBIT yoy change / mean|EBIT| - mean {(st.fmean(eb_s) - st.fmean(eb_a)) * 100:+.1f}%')
