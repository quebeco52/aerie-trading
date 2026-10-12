#!/usr/bin/env python3
"""Insurer capital change: before (HEAD) vs after (working tree), paired by seed.
Targets (SAFE): (1) log(equity/NGDP) at the last quarter minus log(seed equity / first-quarter NGDP);
(2) sum EBIT / sum revenue over years 10..end; (3) mean written share over years 10..end.
Side checks: same for DOVE; SAFE buybacks/yr (share of seed equity, NGDP-deflated) and payout (div+bb)/NI over
years 10..end; failures per run; divestitures per run; median ex-fin firm EBIT-margin drift (last 5y - years 0-2).
Also flags NaN, negative equity, and quarters where buybacks exceed 10% of equity.
Usage: analyse.py [runs dir] [--styles]"""
import json, glob, os, sys, math, statistics as st
from collections import Counter

D = os.path.dirname(os.path.abspath(__file__))
R = next((a for a in sys.argv[1:] if not a.startswith('--')), os.path.join(D, 'runs'))
Y0 = 10


def load(arm):
    out = {}
    for p in glob.glob(os.path.join(R, f'{arm}-*.jsonl')):
        with open(p) as fh:
            line = fh.readline()
        if line:
            r = json.loads(line)
            out[r['seed']] = r
    return out


def firm(r, t):
    f = r['final'][t]
    q = f['q']
    o = {}
    if not q:
        return o
    o['leq'] = math.log(max(q[-1]['eq'], 1.0) / q[-1]['ngdp']) - math.log(f['eq0'] / q[0]['ngdp'])
    late = [x for x in q if x['t'] > Y0]
    rev = sum(x['rev'] for x in late)
    o['marg'] = 100 * sum(x['ebit'] for x in late) / rev if rev > 0 else float('nan')
    ws = [x['ws'] for x in late if x.get('ws') is not None]
    o['ws'] = st.mean(ws) if ws else float('nan')
    os_ = [x['os'] for x in late if x.get('os') is not None]
    o['os'] = st.mean(os_) if os_ else float('nan')
    yrs = max(1e-9, (late[-1]['t'] - Y0)) if late else 1.0
    o['bb_eq'] = 100 * sum(x['bb'] / x['ngdp'] for x in late) / yrs / f['eq0']
    ni = sum(x['ni'] for x in late)
    o['payout'] = sum(x['div'] + x['bb'] for x in late) / ni if ni > 0 else float('nan')
    o['cash_dep'] = st.mean(x['cash'] / x['cdep'] for x in late if x['cdep'] > 0) if late else float('nan')
    o['styles'] = Counter(x['style'] for x in late)
    o['flags'] = sum(1 for x in q if any(isinstance(v, float) and math.isnan(v) for v in x.values()) or x['eq'] < 0 or x['bb'] > 0.10 * max(x['eq'], 0))
    o['dead'] = f['dead']
    return o


def board(r):
    o = {'fail': len(r['dead']) + len(r['reorgs']), 'divest': r.get('event_types', {}).get('DIVESTITURE', 0)}
    drifts = []
    for t, f in r['final'].items():
        if f['isfin'] or f['ind'].startswith('REIT') or f['dead']:
            continue
        def m(lo, hi):
            xs = [x for x in f['q'] if lo <= x['t'] - 1e-6 < hi]
            rv = sum(x['rev'] for x in xs)
            return sum(x['ebit'] for x in xs) / rv if rv > 0 else None
        a, b = m(0, 3), m(r['years'] - 5, r['years'])
        if a is not None and b is not None:
            drifts.append(100 * (b - a))
    o['exf_drift_med'] = st.median(drifts) if drifts else float('nan')
    o['flags_all'] = sum(1 for f in r['final'].values() for x in f['q'] if x['eq'] < 0 and not f['dead'])
    return o


B, A = load('before'), load('after')
seeds = sorted(set(B) & set(A))
rows = []
for t in ('SAFE', 'DOVE'):
    for k, lab in (('leq', 'log chg equity/NGDP'), ('marg', f'EBIT/rev y{Y0}+ (%)'), ('ws', f'written share y{Y0}+'),
                   ('os', f'observed share y{Y0}+'), ('bb_eq', 'buybacks/yr, % seed eq (real)'), ('payout', '(div+bb)/NI'),
                   ('cash_dep', 'treasury / float'), ('flags', 'flag quarters (NaN/neg eq/bb>10%eq)')):
        rows.append((f'{t} {lab}', lambda r, t=t, k=k: firm(r, t).get(k, float('nan'))))
for k, lab in (('fail', 'failures/run (bk+reorg)'), ('divest', 'divestitures/run'), ('exf_drift_med', 'median ex-fin EBIT-margin drift pp'),
               ('flags_all', 'neg-equity quarters, live firms')):
    rows.append((lab, lambda r, k=k: board(r)[k]))

print(f'insurer_capital: n={len(seeds)} seeds {seeds}, years {B[seeds[0]]["years"]}, tpy {B[seeds[0]]["tpy"]}')
print(f'{"quantity":44s} {"before":>10s} {"after":>10s} {"diff":>9s} {"se":>8s} {"t":>6s}  after range')
for lab, fn in rows:
    b = [fn(B[s]) for s in seeds]; a = [fn(A[s]) for s in seeds]
    pairs = [(x, y) for x, y in zip(b, a) if not (math.isnan(x) or math.isnan(y))]
    if len(pairs) < 2:
        print(f'{lab:44s} n<2'); continue
    d = [y - x for x, y in pairs]
    se = st.stdev(d) / math.sqrt(len(d))
    tt = st.mean(d) / se if se > 0 else float('nan')
    print(f'{lab:44s} {st.mean(x for x, _ in pairs):10.3f} {st.mean(y for _, y in pairs):10.3f} {st.mean(d):9.3f} {se:8.3f} {tt:6.1f}  [{min(y for _, y in pairs):.3f}, {max(y for _, y in pairs):.3f}]')

if '--styles' in sys.argv:
    print('\nSAFE manager style, quarters y10+ (per seed, before | after):')
    for s in seeds:
        print(f'  seed {s}: {dict(firm(B[s], "SAFE")["styles"])} | {dict(firm(A[s], "SAFE")["styles"])}')
