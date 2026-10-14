#!/usr/bin/env python3
"""10-14 WEAV refit + JDAW: board tick-1 / Q1-mean cap (USD T) vs newfirms1013, seeds 1-16, unpaired; WEAV, JDAW caps."""
import json, math, os, statistics as st
D = os.path.dirname(os.path.abspath(__file__))
S = range(1, 17)
def load(arm, s): return json.load(open(f'{D}/runs/{arm}-{s}.json'))
def ms(xs): return st.mean(xs), st.stdev(xs) / math.sqrt(len(xs))
A, B = 'jdaw1014', 'jdaw2x1014'
print(f'{"quantity":32s} {A:>16s} {B:>16s} {"diff (se)":>16s}')
for k, l in (('cap_tick1', 'board tick 1, $T'), ('cap_q1_mean', 'board Q1 mean, $T'), ('cap_q1_end', 'board Q1 end, $T')):
    a = ms([load(A, s)[k] / 1e12 for s in S]); b = ms([load(B, s)[k] / 1e12 for s in S])
    print(f'{l:32s} {a[0]:8.3f} ({a[1]:.3f}) {b[0]:8.3f} ({b[1]:.3f}) {b[0]-a[0]:+8.3f} ({math.hypot(a[1], b[1]):.3f})')
for t in ('WEAV', 'JDAW'):
    for k, l in (('firm_seed', 'seed'), ('firm_tick1', 'tick 1'), ('firm_q1_end', 'Q1 end')):
        va = [load(A, s)[k].get(t) for s in S]; vb = [load(B, s)[k].get(t) for s in S]
        fa = f'{ms([v/1e9 for v in va])[0]:8.1f} ({ms([v/1e9 for v in va])[1]:.1f})' if va[0] else f'{"-":>16s}'
        fb = ms([v / 1e9 for v in vb])
        print(f'{t+" "+l+", $B":32s} {fa:>16s} {fb[0]:8.1f} ({fb[1]:.1f})')
print(f'n_stocks {load(A,1)["n_stocks"]} -> {load(B,1)["n_stocks"]}, n={len(S)} per arm')
