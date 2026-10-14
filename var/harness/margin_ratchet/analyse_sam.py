#!/usr/bin/env python3
"""Sector-drift SAM (CorporateMetrics::calculateAddressableShare): before (HEAD) vs after (working tree), paired.
Targets: (1) WEAV mean industry_price_level, years 25-30; (2) mean |industry_price_level - 1| over ex-fin
firm-quarters, years 25-30; (3) ex-fin ex-REIT aggregate EBIT margin drift (pp, last 5y minus years 0-2).
Context: failures per run, WEAV share of board cap at the end, WEAV EBIT margin years 25-30.
Then per-firm movers of the year 25-30 mean price level, and oddities. Usage: analyse_sam.py [runs dir]"""
import json, glob, os, sys, math, statistics as st
from collections import defaultdict

D = os.path.dirname(os.path.abspath(__file__))
R = next((a for a in sys.argv[1:] if not a.startswith('--')), os.path.join(D, 'runs'))
FOCUS = 'WEAV'
LO, HI = 25.0, 30.0


def load(arm):
    out = {}
    for p in glob.glob(os.path.join(R, f'{arm}-*.jsonl')):
        with open(p) as fh:
            line = fh.readline()
        if line:
            r = json.loads(line)
            out[r['seed']] = r
    return out


def yearly_agg(r, sel):
    num, den = defaultdict(float), defaultdict(float)
    for f in r['final'].values():
        if not sel(f):
            continue
        for q in f['q']:
            if q['rev'] > 0:
                y = int(q['t'] - 1e-6)
                num[y] += q['ebit']; den[y] += q['rev']
    return {y: num[y] / den[y] for y in den if den[y] > 0}


def win(f):
    return [q for q in f['q'] if LO <= q['t'] <= HI + 1e-6]


def firm_ipl(f):
    v = [q['ipl'] for q in win(f) if q.get('ipl') is not None]
    return st.mean(v) if v else float('nan')


def stats(r):
    Y = int(round(r['years']))
    exf = lambda f: not f['isfin'] and not f['ind'].startswith('REIT')
    a = yearly_agg(r, exf)
    o = {'exf_drift': 100 * (st.mean(a[y] for y in range(Y - 5, Y) if y in a) - st.mean(a[y] for y in range(0, 3) if y in a))}
    w = r['final'].get(FOCUS)
    o['weav_ipl'] = firm_ipl(w) if w else float('nan')
    dev = [abs(q['ipl'] - 1) for f in r['final'].values() if not f['isfin'] for q in win(f) if q.get('ipl') is not None]
    o['dev'] = st.mean(dev) if dev else float('nan')
    o['fail'] = len(r['dead']) + len(r['reorgs'])
    cap = {t: f['px'] * f.get('sh', 0.0) for t, f in r['final'].items() if not f['dead']}
    o['weav_cap'] = 100 * cap.get(FOCUS, 0.0) / sum(cap.values()) if cap else float('nan')
    wq = win(w) if w else []
    rev = sum(q['rev'] for q in wq)
    o['weav_om'] = 100 * sum(q['ebit'] for q in wq) / rev if rev > 0 else float('nan')
    return o


B, A = load('before'), load('after')
seeds = sorted(set(B) & set(A))
SB = {s: stats(B[s]) for s in seeds}
SA = {s: stats(A[s]) for s in seeds}
rows = [('1 WEAV industry price level, y25-30', 'weav_ipl'), ('2 mean |price level - 1|, ex-fin, y25-30', 'dev'),
        ('3 ex-fin ex-REIT EBIT margin drift, pp', 'exf_drift'), ('  failures per run (bk+reorg)', 'fail'),
        ('  WEAV share of board cap at end, %', 'weav_cap'), ('  WEAV EBIT margin y25-30, %', 'weav_om')]
n = len(seeds)
print(f'n = {n} paired seeds ({seeds[0]}-{seeds[-1]}), years {B[seeds[0]]["years"]}, tpy {B[seeds[0]]["tpy"]}')
print(f'{"quantity":44s} {"before":>8s} {"after":>8s} {"diff":>8s} {"se":>7s} {"t":>7s}  range diff')
for lab, k in rows:
    pr = [(SB[s][k], SA[s][k]) for s in seeds if not (math.isnan(SB[s][k]) or math.isnan(SA[s][k]))]
    b = [x for x, _ in pr]; a = [y for _, y in pr]; d = [y - x for x, y in pr]
    m = len(d)
    se = st.stdev(d) / m ** 0.5 if m > 1 else float('nan')
    t = st.mean(d) / se if se == se and se > 0 else float('nan')
    print(f'{lab:44s} {st.mean(b):8.3f} {st.mean(a):8.3f} {st.mean(d):8.3f} {se:7.3f} {t:7.2f}  [{min(d):.3f}, {max(d):.3f}]' + (f' n={m}' if m < n else ''))

# Per-firm movers: mean over seeds of (after - before) year 25-30 mean price level, ex-fin.
dd, lvb, lva = defaultdict(list), defaultdict(list), defaultdict(list)
for s in seeds:
    for t, fb in B[s]['final'].items():
        fa = A[s]['final'].get(t)
        if fb['isfin'] or not fa:
            continue
        xb, xa = firm_ipl(fb), firm_ipl(fa)
        if not (math.isnan(xb) or math.isnan(xa)):
            dd[t].append(xa - xb); lvb[t].append(xb); lva[t].append(xa)
mv = sorted((st.mean(v), t) for t, v in dd.items())
fmt = lambda d, t: f'{t:6s} {st.mean(lvb[t]):6.3f} -> {st.mean(lva[t]):6.3f}  diff {d:+.3f} (n={len(dd[t])})'
print('\nlargest falls in y25-30 mean price level (before -> after):')
for d, t in mv[:5]:
    print('  ' + fmt(d, t))
print('largest rises:')
for d, t in mv[::-1][:5]:
    print('  ' + fmt(d, t))

# Oddities: NaN/non-finite levels, after-arm quarters below 0.85, crashed seeds.
nan = sum(1 for R_ in (B, A) for s in seeds for f in R_[s]['final'].values() for q in f['q']
          if q.get('ipl') is not None and not math.isfinite(q['ipl']))
low = defaultdict(list)
for s in seeds:
    for t, f in A[s]['final'].items():
        m = [q['ipl'] for q in f['q'] if q.get('ipl') is not None]
        if m and min(m) < 0.85:
            low[t].append((s, round(min(m), 3)))
print(f'\nnon-finite price levels: {nan}; seeds missing an arm: {sorted(set(B) ^ set(A))}')
print('after-arm firms with a quarter below 0.85 (seed, min): ' + ('; '.join(f'{t} {v}' for t, v in sorted(low.items())) or 'none'))
mxb = max((q['ipl'] for s in seeds for f in B[s]['final'].values() for q in f['q'] if q.get('ipl') is not None), default=float('nan'))
mxa = max((q['ipl'] for s in seeds for f in A[s]['final'].values() for q in f['q'] if q.get('ipl') is not None), default=float('nan'))
capq = lambda R_: sum(1 for s in seeds for f in R_[s]['final'].values() if not f['isfin'] for q in win(f) if q.get('ipl') is not None and q['ipl'] >= 1.299)
allq = lambda R_: sum(1 for s in seeds for f in R_[s]['final'].values() if not f['isfin'] for q in win(f) if q.get('ipl') is not None)
print(f'max price level: before {mxb:.3f}, after {mxa:.3f}; ex-fin y25-30 quarters at the +30% cap: before {capq(B)}/{allq(B)}, after {capq(A)}/{allq(A)}')
