#!/usr/bin/env python3
"""analyse_decline.py [seeds] [armA] [armB] [tag]: listed firms in industries whose true level exp(x) fell below 0.7.

Plant over believed need = exp(-gap), gap = the ledger's log(need/plant) logged per report. For each arm: mean
plant/need and operating margin by years since the truth first crossed 0.7 (before = the year before), and the
firm-quarter count. Then one line per (seed, industry, firm) with plant/need and margin at the crossing, +2y, +4y, end.
"""
import json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = [int(s) for s in (sys.argv[1] if len(sys.argv) > 1 else '1,2,3,4').split(',')]
A, B = (sys.argv[2], sys.argv[3]) if len(sys.argv) > 3 else ('onbase', 'on2')
tag = sys.argv[4] if len(sys.argv) > 4 else '-oc'
BUCKETS = [('-1..0', -1, 0), ('0..2', 0, 2), ('2..4', 2, 4), ('4+', 4, 99)]


def load(arm, s):
    oq = json.load(open(f'{H}/runs/{arm}-{s}{tag}.outlook.json'))['quarters']
    rows = json.load(open(f'{H}/runs/{arm}-{s}{tag}.idle.json'))['rows']
    r = json.loads(open(f'{H}/runs/{arm}-{s}{tag}.jsonl').readline())
    cross = {}
    for t, _, ind in oq:
        for name, rec in ind.items():
            if name not in cross and math.exp(rec[0]) < 0.7:
                cross[name] = t
    om = {(tk, x['t']): x['om'] for tk, f in r['final'].items() for x in f['q']}
    firms = {}
    for row in rows:
        t, tk, ind = row[0], row[1], row[2]
        if ind in cross and len(row) > 6 and row[6] is not None and (tk, t) in om:
            firms.setdefault((ind, tk), []).append((t - cross[ind], math.exp(-row[6]), om[(tk, t)]))
    return cross, firms


def at(series, dt):
    c = [x for x in series if x[0] >= dt]
    return c[0] if c else None


lines = {}
print(f'years since truth < 0.7 | {A} plant/need | {B} plant/need | {A} op margin | {B} op margin | n {A} | n {B}')
pooled = {A: {}, B: {}}
for s in seeds:
    for arm in (A, B):
        cross, firms = load(arm, s)
        for (ind, tk), ser in firms.items():
            for name, lo, hi in BUCKETS:
                pooled[arm].setdefault(name, []).extend((p, m) for d, p, m in ser if lo <= d < hi)
            lines.setdefault((s, ind, tk), {})[arm] = ser
for name, _, _ in BUCKETS:
    a, b = pooled[A].get(name, []), pooled[B].get(name, [])
    f = lambda xs, i: f'{st.fmean(x[i] for x in xs):.3f}' if xs else '-'
    print(f'{name} | {f(a, 0)} | {f(b, 0)} | {f(a, 1)} | {f(b, 1)} | {len(a)} | {len(b)}')

print(f'\nseed industry firm | plant/need {A} at 0, +2, +4, end | {B} ... || op margin {A} 0, +2, +4, end | {B} ...')
for (s, ind, tk), d in sorted(lines.items()):
    def cells(ser, i):
        if not ser:
            return '-'
        pts = [at(ser, 0), at(ser, 2), at(ser, 4), ser[-1]]
        return ' '.join('-' if p is None else f'{p[i]:.2f}' for p in pts)
    print(f'{s} {ind} {tk} | {cells(d.get(A), 1)} | {cells(d.get(B), 1)} || {cells(d.get(A), 2)} | {cells(d.get(B), 2)}')
