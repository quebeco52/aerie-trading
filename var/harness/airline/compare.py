#!/usr/bin/env python3
"""TERN side by side across arms from runs/<arm>-summary.json (written by analyse.py). Usage: compare.py live fix."""
import json, statistics as st, sys
from pathlib import Path
arms = sys.argv[1:] or ['live', 'fix']
S = {a: json.load(open(Path(__file__).parent / 'runs' / f'{a}-summary.json')) for a in arms}
PCT = {'open om', 'rev CAGR', 'om mean', 'om min', 'om max', 'neg EBIT q share', 'vol', 'maxDD', 'EPS beat rate'}
print(f'{"TERN, seeds 1-4":30s}' + ''.join(f'{a:>26s}' for a in arms))
for m in S[arms[0]]['rows']:
    cells = []
    for a in arms:
        vs = S[a]['rows'].get(m, {}).get('TERN')
        if vs is None:
            cells.append('-')
        elif isinstance(vs[0], str):
            cells.append(' '.join(vs) if len(' '.join(vs)) <= 26 else vs[0] + '..')
        else:
            mean = st.fmean(vs)
            cells.append(f'{mean * 100:.1f}%' if m in PCT else f'{mean:.2f}')
    if m == 'EPS g SD':
        continue
    print(f'{m:30s}' + ''.join(f'{c:>26s}' for c in cells))
for a in arms:
    p = S[a]['pooled']['TERN']
    print(f'slump pooled {a}: n {p["n"]}, rev yoy - mean {p["rev"]:+.2f}pp, EBIT yoy - mean {p["ebit"]:+.2f}pp')
