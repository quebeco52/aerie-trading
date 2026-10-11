#!/usr/bin/env python3
"""analyse_pe.py <base arm> <arm>...: where an operating-firm P/E move comes from, paired by seed against the base arm.

Over operating firm-quarters with 2 <= t < 20: medians of P/E, WACC, cost of equity (`coe`), levered beta (`lbeta`) and
the issuer spread (`dspread`); means of the macro 10y yield (`y10`), equity risk premium (`erp`), output gap EMA
(`gap`) and policy rate EMA (`pol`), read off one firm's rows. Each row prints the mean over seeds of arm minus base,
its SE and t.
"""
import glob, json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
BASE, ARMS = sys.argv[1], sys.argv[2:]
KEYS = ['P/E', 'WACC bp', 'CoE bp', 'lev beta', 'spread bp', 'y10 bp', 'ERP bp', 'gap pp', 'policy bp']


def metrics(path):
    rec = json.loads(open(path).readline())
    op = json.load(open(path.replace('.jsonl', '.open.json')))
    pe, wacc, coe, beta, spread = [], [], [], [], []
    for t, fd in rec['final'].items():
        if op.get(t, {}).get('fin'):
            continue
        for q in fd['q']:
            if not (2 <= q['t'] < 20):
                continue
            if q.get('eps', 0) > 0:
                pe.append(q['price'] / q['eps'])
            wacc.append(q.get('wacc', 0.0))
            coe.append(q.get('coe', 0.0))
            beta.append(q.get('lbeta', 0.0))
            spread.append(q.get('dspread', 0.0))
    ref = next(iter(rec['final'].values()))['q']
    macro = [q for q in ref if 2 <= q['t'] < 20]
    mean = lambda k: st.mean(q.get(k, 0.0) for q in macro)
    return {'P/E': st.median(pe), 'WACC bp': st.median(wacc) * 1e4, 'CoE bp': st.median(coe) * 1e4, 'lev beta': st.median(beta),
            'spread bp': st.median(spread) * 1e4, 'y10 bp': mean('y10') * 1e4, 'ERP bp': mean('erp') * 1e4,
            'gap pp': mean('gap') * 100, 'policy bp': mean('pol') * 1e4}


def arm(name):
    out = {}
    for p in glob.glob(f'{H}/runs/{name}-*.jsonl'):
        out[int(re.search(rf'{name}-(\d+)\.jsonl$', p).group(1))] = metrics(p)
    return out


base = arm(BASE)
print(f'{"":12s} {BASE + " level":>12s}' + ''.join(f'{a + " - " + BASE:>22s}' for a in ARMS))
results = {a: arm(a) for a in ARMS}
for k in KEYS:
    line = f'{k:12s} {st.mean(v[k] for v in base.values()):12.2f}'
    for a in ARMS:
        seeds = sorted(set(base) & set(results[a]))
        d = [results[a][s][k] - base[s][k] for s in seeds]
        se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else math.nan
        line += f'  {st.mean(d):+8.2f} (t {st.mean(d) / se if se else math.nan:5.1f})'
    print(line)
