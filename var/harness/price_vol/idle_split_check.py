#!/usr/bin/env python3
"""idle_split_check.py <arm> <seeds> [tag]: the idle harness's rv (raw tick log returns) with and without the stock
splits it contains. Splits are read off quarter-row share counts (ratio within 10% of 2, 3 or 4, buybacks shave it);
each one's log(ratio)^2 comes out of the sum of squares. Prints the board median ratio and share > 1.1x per seed."""
import json, math, statistics as st, sys
from pathlib import Path
D = Path(__file__).resolve().parent.parent / 'idle' / 'runs'
arm, tag = sys.argv[1], (sys.argv[3] if len(sys.argv) > 3 else '-oc')
seeds = [int(x) for x in sys.argv[2].split(',')]
print(f'{"seed":>4} {"raw med":>8} {"adj med":>8} {"raw>1.1":>8} {"adj>1.1":>8} {"splits/name":>11} {"TERN raw":>8} {"TERN adj":>8}')
agg = {'r': [], 'a': [], 'ro': [], 'ao': []}
for s in seeds:
    k = json.loads((D / f'{arm}-{s}{tag}.idle.json').read_text())
    f = json.loads((D / f'{arm}-{s}{tag}.jsonl').read_text().splitlines()[0])['final']
    raw, adj, ns, tern = [], [], [], ('-', '-')
    for tk, (ss, n, cfg, _) in k['rv'].items():
        if n < 100:
            continue
        sh = [r.get('shares') for r in f.get(tk, {}).get('q', []) if isinstance(r, dict) and r.get('shares')]
        add, c = 0.0, 0
        for a, b in zip(sh, sh[1:]):
            r = b / a
            if any(abs(r - m) < 0.1 * m for m in (2, 3, 4)):
                add += math.log(round(r)) ** 2
                c += 1
        v, va = math.sqrt(ss / n * k['tpy']) / cfg, math.sqrt(max(0.0, ss - add) / n * k['tpy']) / cfg
        raw.append(v); adj.append(va); ns.append(c)
        if tk == 'TERN':
            tern = (f'{v * cfg:.3f}', f'{va * cfg:.3f}')
    agg['r'].append(st.median(raw)); agg['a'].append(st.median(adj))
    agg['ro'].append(st.mean(x > 1.1 for x in raw)); agg['ao'].append(st.mean(x > 1.1 for x in adj))
    print(f'{s:>4} {st.median(raw):>8.3f} {st.median(adj):>8.3f} {agg["ro"][-1]:>8.0%} {agg["ao"][-1]:>8.0%} {st.mean(ns):>11.2f} {tern[0]:>8} {tern[1]:>8}')
print(f'{"mean":>4} {st.mean(agg["r"]):>8.3f} {st.mean(agg["a"]):>8.3f} {st.mean(agg["ro"]):>8.0%} {st.mean(agg["ao"]):>8.0%}')
