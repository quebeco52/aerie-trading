#!/usr/bin/env python3
"""gb_inputs.py: the inputs of the one-sided gap drags (runs/G<arm>-<seed>.macro.jsonl rows that carry 'ebp').

For each drag f(x) = k * max(0, x) (or the asymmetric monetary slope), prints the input's mean and sd, the mean drag
in gap pp (W * k * E[f] / 1.44) and the part of it that is rectification: the drag less what a linear k * x (same slope
on both sides) would give, W * k * (E[max(0,x)] - E[x]) / 1.44. Env SEEDS (9-16), BURN (2), ARMS (A B C D).
"""
import json, math, os

H = os.path.dirname(os.path.abspath(__file__))
def seeds(s):
    out = []
    for p in s.split(','):
        a, _, b = p.partition('-'); out += list(range(int(a), int(b or a) + 1))
    return out
SEEDS = seeds(os.environ.get('SEEDS', '9-16')); BURN = float(os.environ.get('BURN', 2)); ARMS = os.environ.get('ARMS', 'A B C D').split()
PULL = 1.44
K = {'credit': 3.5, 'epu': 0.006, 'cat': 0.004}
MON_R, MON_A = 1.2505, 0.5505
def mean(x): return sum(x) / len(x)
def sd(x): m = mean(x); return math.sqrt(mean([(v - m) ** 2 for v in x]))
print(f'{"arm":4s}{"input":34s}{"E[x]":>10s}{"sd(x)":>10s}{"E[x+]":>10s}{"drag pp":>10s}{"rectif pp":>10s}  seeds')
for arm in ARMS:
    rows = []; used = []
    for s in SEEDS:
        p = f'{H}/runs/G{arm}-{s}.macro.jsonl'
        if not os.path.exists(p): continue
        rr = [json.loads(l) for l in open(p)]
        rr = [r for r in rr if r['t'] > BURN + 1e-9 and 'ebp' in r]
        if rr: rows += rr; used.append(s)
    if not rows: continue
    W = rows[0]['W']
    def line(name, xs, k, pos=None):
        xp = pos if pos is not None else [max(0.0, v) for v in xs]
        print(f'{arm:4s}{name:34s}{mean(xs):10.4f}{sd(xs):10.4f}{mean(xp):10.4f}{100 * W * k * mean(xp) / PULL:10.3f}{100 * W * k * (mean(xp) - mean(xs)) / PULL:10.3f}  {used[0]}-{used[-1]} ({len(used)})')
    ebpdev = [r['ebp'] - r['ebp_tr'] for r in rows]
    short = [max(0.0, r['cap_req'] - r['cap_built']) for r in rows]
    line('EBP - trend (no shortfall)', ebpdev, K['credit'])
    line('capital shortfall', short, K['credit'] * 0.22)
    line('excess credit premium (both)', [a + 0.22 * b for a, b in zip(ebpdev, short)], K['credit'])
    epu = [math.log(max(1.0, r['epu_ema']) / 100.0) for r in rows]
    line('log(EPU ema / 100)', epu, K['epu'])
    line('catastrophe loss ema - 1', [r['cat_ema'] - 1.0 for r in rows], K['cat'])
    st = [r['stance'] for r in rows]
    asym = [MON_R * v if v > 0 else MON_A * v for v in st]
    print(f'{arm:4s}{"monetary stance (transmitted)":34s}{mean(st):10.4f}{sd(st):10.4f}{"":10s}{-100 * W * mean(asym) / PULL:10.3f}{-100 * W * (mean(asym) - 0.5 * (MON_R + MON_A) * mean(st)) / PULL:10.3f}  (rectif vs mean slope)')
    print(f'{arm:4s}{"household debt service gap":34s}{mean([r["hdsg"] for r in rows]):10.4f}{sd([r["hdsg"] for r in rows]):10.4f}')
    print(f'{arm:4s}{"credit crisis drag state":34s}{mean([r["ccd"] for r in rows]):10.4f}{sd([r["ccd"] for r in rows]):10.4f}')
