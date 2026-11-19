#!/usr/bin/env python3
"""Analyst anchor: revenue surprise SD and lag-1 autocorrelation per firm, board medians, EPS beat rate, guards.
Pairs base vs fix by seed (Δ = fix - base). Usage: analyse.py [arm_a] [arm_b]."""
import json
import math
import statistics as st
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, mean_se, fmt  # noqa: E402

H = Path(__file__).resolve().parent
A = sys.argv[1] if len(sys.argv) > 1 else 'base'
B = sys.argv[2] if len(sys.argv) > 2 else 'fix'
FIRMS = ['SINK', 'CASC', 'DIPR', 'GANN', 'ALBT', 'BOWR', 'CNDR', 'FNCH', 'WING']
WARMUP = 4  # reports dropped per firm


def ac1(xs):
    if len(xs) < 8:
        return None
    m = sum(xs) / len(xs)
    d = sum((x - m) ** 2 for x in xs)
    if d <= 0:
        return None
    return sum((xs[i] - m) * (xs[i - 1] - m) for i in range(1, len(xs))) / d


def load(arm):
    out = {}
    for f in sorted((H / 'runs').glob(f'{arm}-*.jsonl')):
        if f.name.endswith('.macro.jsonl'):
            continue
        seed = int(f.stem.split('-')[1])
        rec = json.loads(f.read_text().splitlines()[-1])
        firm_sd, firm_ac, beats, n_eps, rets = {}, {}, {}, {}, []
        beat_all = [0, 0]
        for t, fin in rec['final'].items():
            q = fin['q'][WARMUP:]
            s = [r['rev'] / r['crev'] - 1 for r in q if r.get('crev', 0) > 0]
            if len(s) >= 8:
                firm_sd[t] = st.stdev(s)
                a = ac1(s)
                if a is not None:
                    firm_ac[t] = a
            b = [1 if r['reps'] >= r['ceps'] else 0 for r in q if r.get('crev', 0) > 0]
            if b:
                beats[t] = sum(b) / len(b)
                beat_all[0] += sum(b)
                beat_all[1] += len(b)
            for r0, r1 in zip(fin['q'], fin['q'][1:]):
                if r0['price'] > 0 and r1['price'] > 0 and r0['shares'] > 0 and r1['shares'] > 0:
                    k = r1['shares'] / r0['shares']
                    adj = k if (k > 1.5 or k < 0.67) else 1.0
                    rets.append(math.log(r1['price'] * adj / r0['price']))
        gaps = [json.loads(l)['gap'] for l in (H / 'runs' / f'{arm}-{seed}.macro.jsonl').read_text().splitlines()]
        out[seed] = {
            'sd': firm_sd, 'ac': firm_ac, 'beat': beats,
            'med_sd': st.median(firm_sd.values()), 'med_ac': st.median(firm_ac.values()),
            'beat_all': beat_all[0] / max(1, beat_all[1]),
            'gap_sd': st.stdev(gaps), 'ret_sd': st.stdev(rets), 'dead': len(rec['dead']),
            'cleared': rec.get('cleared'), 'left': rec.get('left_after_clear'),
        }
    return out


a, b = load(A), load(B)
seeds = sorted(set(a) & set(b))


def row(name, fa, fb, scale=1.0, dig=3):
    xa = {s: fa(a[s]) for s in seeds if fa(a[s]) is not None}
    xb = {s: fb(b[s]) for s in seeds if fb(b[s]) is not None}
    ma = mean_se([v * scale for v in xa.values()])
    mb = mean_se([v * scale for v in xb.values()])
    p = paired({k: v * scale for k, v in xa.items()}, {k: v * scale for k, v in xb.items()})
    print(f'{name:<22} {ma[1]:>9.{dig}f} {mb[1]:>9.{dig}f}   {fmt(p)}')


print(f'arms {A} vs {B}, seeds {seeds}; Δ = {B} - {A} (se) t n')
print(f'sanity: {A} keys cleared {[a[s]["cleared"] for s in seeds]}, left after clear {[a[s]["left"] for s in seeds]}')
print(f'{"quantity":<22} {A:>9} {B:>9}   Δ (se) t n')
print('-- revenue surprise SD (%) / lag-1 autocorrelation')
for t in FIRMS:
    row(f'{t} SD(s) %', lambda r, t=t: r['sd'].get(t), lambda r, t=t: r['sd'].get(t), 100, 2)
    row(f'{t} ac1', lambda r, t=t: r['ac'].get(t), lambda r, t=t: r['ac'].get(t))
print('-- board medians over firms')
row('median SD(s) %', lambda r: r['med_sd'], lambda r: r['med_sd'], 100, 2)
row('median ac1', lambda r: r['med_ac'], lambda r: r['med_ac'])
print('-- EPS beat rate (reported >= consensus)')
row('board beat', lambda r: r['beat_all'], lambda r: r['beat_all'])
for t in ['SINK', 'CASC']:
    row(f'{t} beat', lambda r, t=t: r['beat'].get(t), lambda r, t=t: r['beat'].get(t))
print('-- guards')
row('output gap SD pp', lambda r: r['gap_sd'], lambda r: r['gap_sd'], 100, 2)
row('price ret SD q %', lambda r: r['ret_sd'], lambda r: r['ret_sd'], 100, 2)
row('bankruptcies', lambda r: r['dead'], lambda r: r['dead'], 1, 2)
