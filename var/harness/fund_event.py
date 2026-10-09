#!/usr/bin/env python3
"""Event study of the sovereign fund's programmes: what the board does after one starts, against a MATCHED control.

A programme starts because the fund arm's own board just fell (buy) or ran up (trim), so a paired-by-date comparison
with the control arm is contaminated by that selection: by the start date the two paths have diverged, and the
treated path is at a local extreme the control path is not. The counterfactual has to be conditioned the same way.

For each programme at time t in <fund-arm>, with the board's prior-6-month log return p:
    matched = every monthly window of <control-arm> (all seeds) whose prior-6-month return is within +/-BIN of p
    effect_h = forward_h(fund, t) - mean(forward_h(matched))
so the market's own reversal after a move of that size is netted out, and what is left is what the programme adds.
Usage: RUNS=<dir> fund_event.py [fund-arm] [control-arm]
"""
import glob
import json
import math
import os
import statistics as st
import sys

RUNS = os.environ.get('RUNS', os.path.dirname(os.path.abspath(__file__)) + '/fund_runs180b')
ARM = sys.argv[1] if len(sys.argv) > 1 else 'fund'
CTRL = sys.argv[2] if len(sys.argv) > 2 else 'norebal'
BIN = 0.015
MIN_MATCHES = 30
HORIZONS = {'1m': 1 / 12, '3m': 0.25, '6m': 0.5, '12m': 1.0}
PRIOR_YEARS = 0.5


def load(a):
    out = {}
    for p in glob.glob(f'{RUNS}/{a}-*.jsonl'):
        r = json.loads(open(p).readline())
        out[r['seed']] = r
    return out


def ret(b, i, j):
    return math.log(b[j] / b[i])


treated, control = load(ARM), load(CTRL)
tpy = next(iter(treated.values()))['tpy']
kp = int(round(PRIOR_YEARS * tpy))
kh = {h: int(round(y * tpy)) for h, y in HORIZONS.items()}
kmax = max(kh.values())

# Control windows, monthly stride, across every control seed.
pool = []
for r in control.values():
    b = r['board']
    for i in range(kp, len(b) - kmax, max(1, tpy // 12)):
        pool.append((ret(b, i - kp, i), {h: ret(b, i, i + k) for h, k in kh.items()}))
pool.sort(key=lambda x: x[0])
priors = [x[0] for x in pool]


def matched(p):
    import bisect
    width = BIN
    while True:
        lo, hi = bisect.bisect_left(priors, p - width), bisect.bisect_right(priors, p + width)
        if hi - lo >= MIN_MATCHES or width > 0.2:
            return pool[lo:hi], width
        width *= 1.5


results = {kind: {h: [] for h in HORIZONS} for kind in ('buy', 'trim')}
meta = {kind: {'n': 0, 'size': [], 'prior': [], 'width': []} for kind in ('buy', 'trim')}
for r in treated.values():
    b = r['board']
    for prog in r['programmes']:
        kind = 'buy' if prog['share'] > 0 else 'trim'
        i = int(round(prog['t'] * tpy)) - 1
        if i - kp < 0 or i + kmax >= len(b):
            continue
        p = ret(b, i - kp, i)
        m, width = matched(p)
        if not m:
            continue
        meta[kind]['n'] += 1
        meta[kind]['size'].append(100 * abs(prog['share']))
        meta[kind]['prior'].append(100 * p)
        meta[kind]['width'].append(100 * width)
        for h, k in kh.items():
            expected = st.mean(x[1][h] for x in m)
            results[kind][h].append(100 * (ret(b, i, i + k) - expected))


def fmt(v):
    if len(v) < 2:
        return 'n/a'
    mean, se = st.mean(v), st.stdev(v) / math.sqrt(len(v))
    return f'{mean:+6.2f} ± {se:4.2f}  (z {mean / se if se else 0:+5.2f})'


print(f'{ARM} vs matched {CTRL} windows ({len(pool)} control windows) in {RUNS}')
for kind in ('buy', 'trim'):
    mt = meta[kind]
    if not mt['n']:
        continue
    print(f"\n{kind.upper()}: n={mt['n']}, size {st.mean(mt['size']):.2f}% of float, prior 6m board return "
          f"{st.mean(mt['prior']):+.1f}%, match width ±{st.mean(mt['width']):.1f}pp")
    for h in HORIZONS:
        print(f'  {h:4s} excess board return vs matched control: {fmt(results[kind][h])}')
