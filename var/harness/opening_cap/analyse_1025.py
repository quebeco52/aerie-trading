#!/usr/bin/env python3
"""10-25 AVCT added, GDWT x2.5, TSTN: board tick-1 / Q1-mean / Q1-end cap (USD T), A (git archive HEAD) vs B (working
tree), seeds 1-16, paired by seed."""
import json, math, os, statistics as st
D = os.path.dirname(os.path.abspath(__file__))
S = range(1, 17)
A, B = os.environ.get('A', 'head1025'), os.environ.get('B', 'r4_1025')
L = {s: (json.load(open(f'{D}/runs/{A}-{s}.json')), json.load(open(f'{D}/runs/{B}-{s}.json'))) for s in S}
def ms(xs): return st.mean(xs), st.stdev(xs) / math.sqrt(len(xs))
print(f'{"quantity":24s} {A:>16s} {B:>16s} {"paired diff (se)":>18s}')
for k, l in (('cap_tick1', 'board tick 1, $T'), ('cap_q1_mean', 'board Q1 mean, $T'), ('cap_q1_end', 'board Q1 end, $T')):
    a = ms([L[s][0][k] / 1e12 for s in S]); b = ms([L[s][1][k] / 1e12 for s in S])
    d = ms([(L[s][1][k] - L[s][0][k]) / 1e12 for s in S])
    print(f'{l:24s} {a[0]:8.3f} ({a[1]:.3f}) {b[0]:8.3f} ({b[1]:.3f}) {d[0]:+9.3f} ({d[1]:.3f})')
for t in ('GDWT', 'TSTN', 'AVCT'):
    parts = []
    for k in ('firm_seed', 'firm_tick1', 'firm_q1_end'):
        aa = [L[s][0][k].get(t) for s in S]
        b = ms([L[s][1][k][t] / 1e9 for s in S])
        a = f'{st.mean(aa) / 1e9:.1f}' if all(aa) else '-'
        parts.append(f'{k[5:]} {a} -> {b[0]:.1f} ({b[1]:.1f})')
    print(f'{t} $B: ' + '; '.join(parts))
print(f'n_stocks {L[1][0]["n_stocks"]} -> {L[1][1]["n_stocks"]}, n={len(S)} per arm')
