#!/usr/bin/env python3
"""lender_open.py <seeds> <armA> <armB>: lenders' first-year (quarters 1-4) annualised ROE, revenue/q and interest
income/q (bn), mean (se) over seeds; separates the opening calibration from 20-year drift."""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arms = sys.argv[2:4]
FIRMS = ['LAKE', 'RIVR', 'PLVR', 'TALN', 'STRK', 'POOL', 'EGRT', 'BNTH', 'ACC', 'ROOK']


def ms(x):
    return f"{st.mean(x):.3f} ({st.stdev(x) / math.sqrt(len(x)):.3f})"


print(f"year-1 ROE | rev/q bn | int income/q bn, {arms[0]} vs {arms[1]}, seeds {sys.argv[1]}")
for t in FIRMS:
    out = []
    for arm in arms:
        roe, rev, ii = [], [], []
        for s in seeds:
            q = json.loads(open(f"{H}/runs/{arm}-{s}.jsonl").readline())['final'][t]['q'][:4]
            roe.append(sum(r['ni'] for r in q) / st.mean(r['eq'] for r in q))
            rev.append(st.mean(r['rev'] for r in q) / 1e9); ii.append(st.mean(r['ii'] for r in q) / 1e9)
        out.append(f"{ms(roe)} {ms(rev)} {ms(ii)}")
    print(f"{t:5s} {out[0]:>42s} | {out[1]:>42s}")
