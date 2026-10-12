#!/usr/bin/env python3
"""Margin-ratchet removal: before (HEAD) vs after (working tree), paired by seed.
Targets: (1) ex-fin ex-REIT aggregate EBIT margin, mean of last 5 yearly values minus mean of years 0-2 (pp);
(2) median over surviving ex-fin firms of stored operatingMargin final - opening (pp); (3) failures per run
(bankruptcies + reorganizations). Context: financials' aggregate margin drift, ex-fin level in last 5 years,
per-industry stored-margin drift. Usage: analyse.py [runs dir] [--sectors]"""
import json, glob, os, sys, statistics as st
from collections import defaultdict

D = os.path.dirname(os.path.abspath(__file__))
R = next((a for a in sys.argv[1:] if not a.startswith('--')), os.path.join(D, 'runs'))
SECT = '--sectors' in sys.argv


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
    for t, f in r['final'].items():
        if not sel(f):
            continue
        for q in f['q']:
            if q['rev'] > 0:
                y = int(q['t'] - 1e-6)
                num[y] += q['ebit']; den[y] += q['rev']
    return {y: num[y] / den[y] for y in den if den[y] > 0}


def stats(r):
    Y = int(round(r['years']))
    exf = lambda f: not f['isfin'] and not f['ind'].startswith('REIT')
    fin = lambda f: f['isfin']
    o = {}
    for name, sel in (('exf', exf), ('fin', fin)):
        a = yearly_agg(r, sel)
        early = st.mean(a[y] for y in range(0, 3) if y in a)
        late = st.mean(a[y] for y in range(Y - 5, Y) if y in a)
        o[name + '_drift'] = 100 * (late - early)
        o[name + '_late'] = 100 * late
        o[name + '_early'] = 100 * early
    live = [f for f in r['final'].values() if not f['isfin'] and not f['dead']]
    o['som_med'] = 100 * st.median(f['om_final'] - f['om_seed'] for f in live)
    o['som_up'] = sum(1 for f in live if f['om_final'] > f['om_seed'] + 1e-9) / len(live)
    o['fail'] = len(r['dead']) + len(r['reorgs'])
    o['bk'] = len(r['dead'])
    return o


B, A = load('before'), load('after')
seeds = sorted(set(B) & set(A))
SB = {s: stats(B[s]) for s in seeds}
SA = {s: stats(A[s]) for s in seeds}
rows = [('1 ex-fin ex-REIT EBIT margin drift, pp', 'exf_drift'), ('2 median stored-margin change, pp', 'som_med'),
        ('3 failures per run (bk+reorg)', 'fail'), ('  bankruptcies per run', 'bk'),
        ('  share of ex-fin firms with stored margin up', 'som_up'),
        ('  financials EBIT margin drift, pp', 'fin_drift'), ('  ex-fin EBIT margin, years 0-2, %', 'exf_early'),
        ('  ex-fin EBIT margin, last 5 years, %', 'exf_late')]
n = len(seeds)
print(f'n = {n} paired seeds ({seeds[0]}-{seeds[-1]}), years {B[seeds[0]]["years"]}, tpy {B[seeds[0]]["tpy"]}')
print(f'{"quantity":48s} {"before":>9s} {"after":>9s} {"diff":>9s} {"se":>7s} {"t":>7s}  range diff')
for lab, k in rows:
    b = [SB[s][k] for s in seeds]; a = [SA[s][k] for s in seeds]; d = [x - y for x, y in zip(a, b)]
    se = st.stdev(d) / n ** 0.5 if n > 1 else float('nan')
    t = st.mean(d) / se if se and se == se and se > 0 else float('nan')
    print(f'{lab:48s} {st.mean(b):9.3f} {st.mean(a):9.3f} {st.mean(d):9.3f} {se:7.3f} {t:7.2f}  [{min(d):.2f}, {max(d):.2f}]')

if SECT:
    # Per industry, ex-fin: mean stored-margin change (pp, surviving firms) and own EBIT-margin drift, by arm.
    def ind_rows(runs):
        som, drift = defaultdict(list), defaultdict(list)
        for s in seeds:
            r = runs[s]; Y = int(round(r['years']))
            inds = {f['ind'] for f in r['final'].values() if not f['isfin']}
            for ind in inds:
                live = [f for f in r['final'].values() if f['ind'] == ind and not f['dead']]
                som[ind] += [100 * (f['om_final'] - f['om_seed']) for f in live]
                a = yearly_agg(r, lambda f, ind=ind: f['ind'] == ind)
                e = [a[y] for y in range(0, 3) if y in a]; l = [a[y] for y in range(Y - 5, Y) if y in a]
                if e and l:
                    drift[ind].append(100 * (st.mean(l) - st.mean(e)))
        return som, drift
    sb, db = ind_rows(B); sa, da = ind_rows(A)
    print(f'\n{"industry (ex-fin)":42s} {"dSOM bef":>8s} {"dSOM aft":>8s} {"EBITdr bef":>10s} {"EBITdr aft":>10s}')
    for ind in sorted(set(sb) | set(sa), key=lambda i: st.mean(da.get(i, [0])) if da.get(i) else 0):
        f = lambda v: f'{st.mean(v):8.2f}' if v else '     n/a'
        g = lambda v: f'{st.mean(v):10.2f}' if v else '       n/a'
        print(f'{ind[:42]:42s} {f(sb.get(ind))} {f(sa.get(ind))} {g(db.get(ind))} {g(da.get(ind))}')
