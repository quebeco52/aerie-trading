#!/usr/bin/env python3
"""Fix C (Winter 1994 capacity-constrained insurer supply): before (ds-after) vs after (c-after), paired by seed.
Targets (SAFE): (1) log change of equity / trend NGDP, seed -> last quarter; (2) log change of premium (revenue) /
trend NGDP, first quarter -> mean of the last 4 quarters; (3) share of quarters y10+ in a hard market.
Guardrails: DOVE (1) and written share; SAFE EBIT/rev y10+, written share, payout; failures; median ex-fin EBIT drift;
insurers stuck in a hard market (>= 90% of y10+ quarters) or with premium/trend NGDP down more than 50%.
Usage: analyse_c.py [runs dir] [--a=<after arm>] [--ctx=<context arm>]   (default --a=after; c2 = fix C + capital target)
naic: share of y10+ quarters where div+bb >= 98% of max(10% surplus, trailing-4q NI)/4 (trailing NI approximates the
engine's trailing EPS x 4 x shares)."""
import json, glob, os, sys, math, statistics as st

D = os.path.dirname(os.path.abspath(__file__))
R = next((a for a in sys.argv[1:] if not a.startswith('--')), os.path.join(D, 'fixc', 'runs'))
OPT = dict(a[2:].split('=', 1) for a in sys.argv[1:] if a.startswith('--') and '=' in a)
AARM, CARM = OPT.get('a', 'after'), OPT.get('ctx')
Y0 = 10
INS = ('Insurance', 'Reinsurance')


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
    f = r['final'][t]; q = f['q']
    late = [x for x in q if x['t'] > Y0]
    tail = q[-4:]
    o = {}
    o['leq'] = math.log(max(q[-1]['eq'], 1.0) / q[-1]['tngdp']) - math.log(f['eq0'] / q[0]['tngdp'])
    o['lrev'] = math.log(max(st.mean(x['rev'] / x['tngdp'] for x in tail), 1.0)) - math.log(q[0]['rev'] / q[0]['tngdp'])
    o['hm'] = sum(1 for x in late if (x.get('hm') or 0) > 0) / len(late) if late else float('nan')
    rev = sum(x['rev'] for x in late)
    o['marg'] = 100 * sum(x['ebit'] for x in late) / rev if rev > 0 else float('nan')
    ws = [x['ws'] for x in late if x.get('ws') is not None]
    o['ws'] = st.mean(ws) if ws else float('nan')
    ni = sum(x['ni'] for x in late)
    o['payout'] = sum(x['div'] + x['bb'] for x in late) / ni if ni > 0 else float('nan')
    o['eqrev'] = q[-1]['eq'] / (4 * st.mean(x['rev'] for x in tail)) if tail else float('nan')
    nb = 0
    for i, x in enumerate(q):
        if x['t'] <= Y0 or x.get('ss') is None:
            continue
        tni = sum(y['ni'] for y in q[max(0, i - 3):i + 1])
        ceil = max(0.10 * max(0.0, x['ss']), max(0.0, tni)) / 4
        nb += 1 if ceil > 0 and x['div'] + x['bb'] >= 0.98 * ceil else 0
    o['naic'] = nb / len(late) if late else float('nan')
    o['flags'] = sum(1 for x in q if any(isinstance(v, float) and math.isnan(v) for v in x.values()) or x['eq'] < 0 or x['bb'] > 0.10 * max(x['eq'], 0))
    return o


def board(r):
    o = {'fail': len(r['dead']) + len(r['reorgs'])}
    drifts = []
    stuck = 0
    for t, f in r['final'].items():
        if f['dead'] or not f['q']:
            continue
        if any(k in f['ind'] for k in INS):
            x = firm(r, t)
            if x['hm'] >= 0.9 or x['lrev'] < math.log(0.5):
                stuck += 1
        if f['isfin'] or f['ind'].startswith('REIT'):
            continue
        def m(lo, hi):
            xs = [x for x in f['q'] if lo <= x['t'] - 1e-6 < hi]
            rv = sum(x['rev'] for x in xs)
            return sum(x['ebit'] for x in xs) / rv if rv > 0 else None
        a, b = m(0, 3), m(r['years'] - 5, r['years'])
        if a is not None and b is not None:
            drifts.append(100 * (b - a))
    o['exf'] = st.median(drifts) if drifts else float('nan')
    o['stuck'] = stuck
    return o


B, A = load('before'), load(AARM)
C = load(CARM) if CARM else {}
seeds = sorted(set(B) & set(A))
rows = []
for t in ('SAFE', 'DOVE'):
    for k, lab in (('leq', 'log chg equity/trend NGDP'), ('lrev', 'log chg premium/trend NGDP'), ('hm', f'hard-market share y{Y0}+'),
                   ('marg', f'EBIT/rev y{Y0}+ (%)'), ('ws', f'written share y{Y0}+'), ('payout', '(div+bb)/NI y10+'),
                   ('eqrev', 'equity / annual premium, end'), ('naic', 'NAIC ceiling binds, share y10+'), ('flags', 'flag qtrs (NaN/neg eq/bb>10%eq)')):
        rows.append((f'{t} {lab}', lambda r, t=t, k=k: firm(r, t)[k]))
for k, lab in (('fail', 'failures/run (bk+reorg)'), ('exf', 'median ex-fin EBIT-margin drift pp'), ('stuck', 'insurers stuck hard / premium halved')):
    rows.append((lab, lambda r, k=k: board(r)[k]))

print(f'insurer_capital fix C: n={len(seeds)} seeds {seeds}, years {B[seeds[0]]["years"]}, tpy {B[seeds[0]]["tpy"]}')
print(f'arms: before vs {AARM}' + (f'; ctx column = {CARM}' if CARM else ''))
print(f'{"quantity":44s} {"before":>10s} {AARM:>10s} {"diff":>9s} {"se":>8s} {"t":>6s}  {AARM} range' + (f'   {CARM} mean' if CARM else ''))
for lab, fn in rows:
    b = [fn(B[s]) for s in seeds]; a = [fn(A[s]) for s in seeds]
    pairs = [(x, y) for x, y in zip(b, a) if not (math.isnan(x) or math.isnan(y))]
    if len(pairs) < 2:
        print(f'{lab:44s} n<2'); continue
    d = [y - x for x, y in pairs]
    se = st.stdev(d) / math.sqrt(len(d))
    tt = st.mean(d) / se if se > 0 else float('nan')
    print(f'{lab:44s} {st.mean(x for x, _ in pairs):10.3f} {st.mean(y for _, y in pairs):10.3f} {st.mean(d):9.3f} {se:8.3f} {tt:6.1f}  [{min(y for _, y in pairs):.3f}, {max(y for _, y in pairs):.3f}]' + (f'   {st.mean(v for v in (fn(C[s]) for s in seeds if s in C) if not math.isnan(v)):.3f}' if C else ''))
for t in ('SAFE', 'DOVE'):
    hot = [s for s in seeds if firm(A[s], t)['hm'] > 0.25]
    print(f'{t} seeds with hard-market share y10+ > 25% ({AARM}): {hot}; before: {[s for s in seeds if firm(B[s], t)["hm"] > 0.25]}')
print('\nSAFE per seed (before | after): log chg eq/tNGDP, log chg prem/tNGDP, hard share, styles y10+')
for s in seeds:
    fb, fa = firm(B[s], 'SAFE'), firm(A[s], 'SAFE')
    A_ = A
    sb = sorted({x['style'] for x in B[s]['final']['SAFE']['q'] if x['t'] > Y0}); sa = sorted({x['style'] for x in A[s]['final']['SAFE']['q'] if x['t'] > Y0})
    print(f'  seed {s}: {fb["leq"]:.2f} {fb["lrev"]:.2f} {fb["hm"]:.2f} {sb} | {fa["leq"]:.2f} {fa["lrev"]:.2f} {fa["hm"]:.2f} {sa}')
