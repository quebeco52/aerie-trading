#!/usr/bin/env python3
"""analyse_types.py <arm>...: rating mix by firm type, revolver draws, and the watched firms' ratings and spreads.

Firm types from the opening industry: operating, REIT, asset manager (asset management, private equity, hedge fund,
distressed debt), insurer, capital-rated institution (banks, investment banks, brokerages, clearing), other financial.
Firm-quarters with 2 <= t < 20, pooled over every seed of the arm.
"""
import collections, glob, json, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
GRADES = ['AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'D']
WATCH = ['TIER', 'SWAN', 'SAFE', 'FALC']


def firm_type(o):
    ind = str(o.get('industry', ''))
    if ind.startswith('REIT') and ind != 'REIT - Mortgage':
        return 'REIT'
    if ind in ('Asset Management', 'Private Equity', 'Hedge Fund', 'Distressed Debt'):
        return 'asset mgr'
    if ind.startswith('Insurance -'):
        return 'insurer'
    if ind.startswith('Banks') or ind in ('Investment Banking', 'Brokerages', 'Financial Clearinghouses'):
        return 'bank/broker'
    return 'other fin' if o.get('fin') else 'operating'


for arm in sys.argv[1:]:
    mix = collections.defaultdict(collections.Counter)
    watch = collections.defaultdict(lambda: (collections.Counter(), []))
    draws = collections.Counter()
    paths = sorted(glob.glob(f'{H}/runs/{arm}-*.jsonl'))
    for p in paths:
        rec = json.loads(open(p).readline())
        op = json.load(open(p.replace('.jsonl', '.open.json')))
        for t, fd in rec['final'].items():
            kind = firm_type(op.get(t, {}))
            for q in fd['q']:
                if 2 <= q['t'] < 20:
                    mix[kind][q.get('rating')] += 1
                    if t in WATCH:
                        watch[t][0][q.get('rating')] += 1
                        watch[t][1].append(q.get('dspread', 0.0) * 1e4)
        for t, evs in rec.get('events', {}).items():
            draws[t] += sum(bool(re.search(r'Drew .*revolving', e['d'])) for e in evs)
    n = max(1, len(paths))
    print(f'== {arm} ({n} seeds): revolver draws/seed {sum(draws.values()) / n:.2f}; top {[(t, round(c / n, 1)) for t, c in draws.most_common(4)]}')
    for kind, c in sorted(mix.items()):
        tot = sum(c.values())
        print(f'   {kind:<11} ' + ' '.join(f'{g} {100 * c[g] / tot:4.1f}' for g in GRADES if c[g]))
    for t in WATCH:
        c, s = watch[t]
        tot = max(1, sum(c.values()))
        print(f'   {t:<5} median spread {st.median(s) if s else float("nan"):6.0f} bp; ' + ' '.join(f'{g} {100 * c[g] / tot:.0f}%' for g in GRADES if c[g]))
