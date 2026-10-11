#!/usr/bin/env python3
"""analyse.py <armA> <armB> [years...]: leverage targets and guards for LeverageHarnessTest runs, paired by seed.

Population: non-financial operating firms, REITs excluded (the opening's `fin` flag and industry). Leverage is read off
the raw report (debt, cash) and the trailing health EBITDA, never off `dte`, which is gross in L0 and net in L1.
Targets per year bucket Y (quarters with Y <= t < Y+1): median net debt / EBITDA, net-cash share of firm-quarters,
share of firm-quarters rated BBB or below. Guards per seed: failures (bankruptcies + reorganisations), revolver draws,
emergency equity raises, payment defaults, and medians of ICR and P/E at the last year bucket.
"""
import glob, json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
A, B = sys.argv[1], sys.argv[2]
YEARS = [int(y) for y in sys.argv[3:]] or [9, 19]
LOW = {'BBB', 'BB', 'B', 'CCC', 'CC', 'C', 'D'}

def seed_metrics(path):
    rec = json.loads(open(path).readline())
    op = json.load(open(path.replace('.jsonl', '.open.json')))
    out = {}
    for y in YEARS:
        nd, cashq, low, icr, pe = [], 0, 0, [], []
        for t, fd in rec['final'].items():
            o = op.get(t, {})
            if o.get('fin') or str(o.get('industry', '')).startswith('REIT'):
                continue
            for q in fd['q']:
                if not (y <= q['t'] < y + 1) or q.get('fin'):
                    continue
                eb = q.get('hEbitda', 0.0)
                if eb > 0:
                    nd.append((q['debt'] - q['cash']) / eb)
                cashq += q['cash'] > q['debt']
                low += q.get('rating') in LOW
                icr.append(min(q.get('icr', 0.0), 100.0))
                if q.get('eps', 0) > 0:
                    pe.append(q['price'] / q['eps'])
        n = max(1, len(icr))
        out[y] = {'nd_ebitda': st.median(nd) if nd else math.nan, 'net_cash': cashq / n, 'bbb_or_below': low / n,
                  'icr': st.median(icr) if icr else math.nan, 'pe': st.median(pe) if pe else math.nan}
    ev = [e['d'] for evs in rec.get('events', {}).values() for e in evs]
    out['g'] = {'failures': len(rec.get('dead', {})) + len(rec.get('reorgs', [])),
                'revolver': sum(bool(re.search(r'Drew .*revolving', d)) for d in ev),
                'equity': sum(d.startswith('Issued new shares') for d in ev),
                'pay_default': sum(d.startswith('Failed to repay') for d in ev)}
    return out

def arm(name):
    res = {}
    for p in glob.glob(f'{H}/runs/{name}-*.jsonl'):
        s = int(re.search(rf'{name}-(\d+)\.jsonl$', p).group(1))
        try:
            res[s] = seed_metrics(p)
        except (ValueError, KeyError):
            pass
    return res

ra, rb = arm(A), arm(B)
seeds = sorted(set(ra) & set(rb))
print(f'arms {A} vs {B}, paired seeds n={len(seeds)}')

def row(label, get):
    a = [get(ra[s]) for s in seeds]; b = [get(rb[s]) for s in seeds]
    d = [y - x for x, y in zip(a, b)]
    se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else math.nan
    t = (st.mean(d) / se) if se and se == se and se > 0 else math.nan
    print(f'{label:<28} {A} {st.mean(a):8.3f}  {B} {st.mean(b):8.3f}  diff {st.mean(d):+8.3f}  se {se:7.3f}  t {t:6.2f}')

for y in YEARS:
    print(f'-- year {y} (targets)')
    for k in ('nd_ebitda', 'net_cash', 'bbb_or_below'):
        row(f'{k} y{y}', lambda r, k=k, y=y: r[y][k])
    for k in ('icr', 'pe'):
        row(f'guard median {k} y{y}', lambda r, k=k, y=y: r[y][k])
print('-- guards per seed (whole run)')
for k in ('failures', 'revolver', 'equity', 'pay_default'):
    row(k, lambda r, k=k: r['g'][k])
