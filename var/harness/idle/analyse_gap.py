#!/usr/bin/env python3
"""analyse_gap.py [seeds] [tag]: fair value's own elasticity to the filed capacity gap, from the `gapp` arm.

Columns (idle.json rows): 7 filed gap, 9 x_fv at the filed gap, 11 x_fv at gap 0, 12 x_fv at gap + 0.1.
Local elasticity = ln(fv(g + 0.1) / fv(g)) / 0.1; average catch-up elasticity = ln(fv(g) / fv(0)) / g (|g| > 0.02).
Also checks the probe is read-only (gapp OUT == val OUT).
"""
import json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
P = os.path.abspath(os.path.join(H, '..', '..', '..'))
seeds = [int(s) for s in (sys.argv[1] if len(sys.argv) > 1 else '1,2,3,4').split(',')]
tag = sys.argv[2] if len(sys.argv) > 2 else '-oc'
table = {m.group(1): float(m.group(2)) for m in re.finditer(r"'([^']+)' => \['drift_vol' => ([0-9.]+)",
                                                            open(f'{P}/src/Data/Company/IndustryOutlooks.php').read())}
CAT = {0.025: 'staple', 0.04: 'steady', 0.06: 'shifting', 0.09: 'volatile'}


def pct(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, max(0, int(math.ceil(p * len(xs))) - 1))]


for s in seeds:
    a, b = [json.loads(open(f'{H}/runs/{x}-{s}{tag}.jsonl').readline()) for x in ('val', 'gapp')]
    a.pop('secs', None); b.pop('secs', None)
    print(f'probe read-only seed {s}: {a == b}')

loc, avg = {}, {}
for s in seeds:
    for r in json.load(open(f'{H}/runs/gapp-{s}{tag}.idle.json'))['rows']:
        if len(r) < 13 or r[0] < 10 or not r[9] or not r[11] or not r[12] or r[9] <= 0 or r[11] <= 0 or r[12] <= 0:
            continue
        c = CAT.get(table.get(r[2], 0.0), 'off')
        for key in (c, 'all'):
            loc.setdefault(key, []).append(math.log(r[12] / r[9]) / 0.1)
            if r[7] is not None and abs(r[7]) > 0.02:
                avg.setdefault(key, []).append((math.log(r[9] / r[11]) / r[7], r[7] > 0))
print('category | fq | local elasticity median / p10 / p90 | share < 0.05 | avg catch-up elasticity, gap>0 median | gap<0 median')
for c in ('staple', 'steady', 'shifting', 'volatile', 'off', 'all'):
    l = loc.get(c, [])
    if not l:
        continue
    pos = [e for e, p in avg.get(c, []) if p]
    neg = [e for e, p in avg.get(c, []) if not p]
    print(f'{c} | {len(l)} | {st.median(l):.3f} / {pct(l, .1):.3f} / {pct(l, .9):.3f} | {sum(1 for x in l if x < 0.05) / len(l):.2f} | '
          f'{st.median(pos) if pos else float("nan"):.3f} (n {len(pos)}) | {st.median(neg) if neg else float("nan"):.3f} (n {len(neg)})')
