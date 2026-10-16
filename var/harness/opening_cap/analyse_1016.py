#!/usr/bin/env python3
"""10-16 SKUA + DIPR + KTWK added: board tick-1 / Q1-mean / Q1-end cap (USD T) vs A (default head1016: git archive HEAD 0da5644b), seeds 1-16, same seeds."""
import json, math, os, statistics as st
D = os.path.dirname(os.path.abspath(__file__))
S = range(1, 17)
A, B = os.environ.get('A', 'head1016'), os.environ.get('B', 'r3_1016')
def load(arm, s):
    p = f'{D}/runs/{arm}-{s}.json'
    return json.load(open(p)) if os.path.exists(p) else None
def ms(xs): return st.mean(xs), st.stdev(xs) / math.sqrt(len(xs))
ha = all(load(A, s) for s in S)
print(f'{"quantity":32s} {A:>16s} {B:>16s} {"diff (se)":>16s}')
for k, l in (('cap_tick1', 'board tick 1, $T'), ('cap_q1_mean', 'board Q1 mean, $T'), ('cap_q1_end', 'board Q1 end, $T')):
    b = ms([load(B, s)[k] / 1e12 for s in S])
    if ha:
        a = ms([load(A, s)[k] / 1e12 for s in S])
        print(f'{l:32s} {a[0]:8.3f} ({a[1]:.3f}) {b[0]:8.3f} ({b[1]:.3f}) {b[0]-a[0]:+8.3f} ({math.hypot(a[1], b[1]):.3f})')
    else:
        print(f'{l:32s} {"-":>16s} {b[0]:8.3f} ({b[1]:.3f})')
for t in ('SKUA', 'DIPR', 'KTWK', 'LOON'):
    for k, l in (('firm_seed', 'seed'), ('firm_tick1', 'tick 1'), ('firm_q1_end', 'Q1 end')):
        vb = ms([load(B, s)[k][t] / 1e9 for s in S])
        print(f'{t+" "+l+", $B":32s} {"":>16s} {vb[0]:8.1f} ({vb[1]:.1f})')
print(f'n_stocks {load(A,1)["n_stocks"] if ha else "?"} -> {load(B,1)["n_stocks"]}, n={len(S)} per arm')
