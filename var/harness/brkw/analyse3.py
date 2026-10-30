#!/usr/bin/env python3
"""analyse3.py <seeds> A B C: BRKW and board means per arm, per-seed C values, paired C - B. Final table only."""
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, fmt
from analyse import seeds, brkw, board

ss = seeds(sys.argv[1])
arms = sys.argv[2:5]
r = {a: {s: {**brkw(a, s), **board(a, s)[0]} for s in ss} for a in arms}
A, B, C = arms
mean = lambda a, k: sum(r[a][s][k] for s in ss) / len(ss)
print(f'BRKW + board, seeds {sys.argv[1]}; mean {A} | {B} | {C}; {C} per seed; paired {C}-{B} (se) t n')
for k in r[A][ss[0]]:
    per = ' '.join(f'{r[C][s][k]:.3g}' for s in ss)
    print(f'{k:18s} {mean(A, k):9.3g} {mean(B, k):9.3g} {mean(C, k):9.3g}   {per:34s} '
          f'{fmt(paired([r[B][s][k] for s in ss], [r[C][s][k] for s in ss]))}')
