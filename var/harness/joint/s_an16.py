#!/usr/bin/env python3
"""s_an16.py: Batch S extension, rpX1 paired by seed against rpIPP / rpD12 / rpF0 on seeds 1-16 and 9-16.
Board CAGR and acquisitions use s_an.py's definitions (imported). HUMM addressable share = capital / (1e12 x nominal
GDP index x sam_ratio 0.375), without the sector's secular-growth factor (not in the rows); capital as equity and as
equity + debt - cash. Expansion events = 'Deployed $X in organic expansion'; debt steps = quarters with debt up > 10%."""
import json, math, os, statistics as st, sys, collections

H = os.path.dirname(os.path.abspath(__file__))
src = open(f'{H}/s_an.py').read()
ns = {'__file__': f'{H}/s_an.py'}
exec(src.split('\nargs = sys.argv')[0], ns)
acqs, cagr = ns['acqs'], ns['cagr']
EXCL = ns['EXCL']
TAM, HUMM_SAM = 1e12, 0.375


def row(arm, s):
    p = f'{H}/runs/{arm}-{s}.jsonl'
    if not os.path.exists(p): return None
    r = json.loads(open(p).readline()); fin = r['final']
    o = {}
    names = {tk: v['q'] for tk, v in fin.items() if tk not in EXCL and len(v['q']) >= 70}
    n = min(len(x) for x in names.values())
    cap = lambda i: sum(x[i]['price'] * x[i]['shares'] for x in names.values())
    yrs = st.mean(x[n - 1]['t'] for x in names.values()) - st.mean(x[3]['t'] for x in names.values())
    o['board CAGR %'] = cagr(cap(3), cap(n - 1), yrs)
    o['M&A/run'] = sum(acqs(v['q']) for v in fin.values())
    o['acq'] = {tk: acqs(v['q']) for tk, v in fin.items()}
    q = fin['SAFE']['q']
    o['SAFE cap CAGR %'] = cagr(q[0]['price'] * q[0]['shares'], q[-1]['price'] * q[-1]['shares'], q[-1]['t'] - q[0]['t'])
    j = min(79, len(q) - 1)
    o['SAFE P/B q79'] = q[j]['price'] * q[j]['shares'] / q[j]['eq'] if q[j]['eq'] > 0 else math.nan
    h = fin['HUMM']['q']
    ng = [json.loads(l)['nominal_gdp'] for l in open(f'{H}/runs/{arm}-{s}.macro.jsonl')]
    o['HUMM acquisitions'] = acqs(h)
    for lab, i in (('q0', 0), ('q79', min(79, len(h) - 1, len(ng) - 1))):
        d = TAM * ng[i] * HUMM_SAM
        o[f'HUMM share eq {lab}'] = h[i]['eq'] / d
        o[f'HUMM share IC {lab}'] = (h[i]['eq'] + h[i]['debt'] - h[i]['cash']) / d
    o['HUMM expansion events'] = sum('organic expansion' in x['d'] for x in r['events'].get('HUMM', []))
    o['HUMM debt steps >10%'] = sum(1 for a, b in zip(h, h[1:]) if a['debt'] > 0 and b['debt'] > 1.1 * a['debt'])
    return o


def pair(a, b, k, seeds, R):
    ps = [(R[b][s][k], R[a][s][k]) for s in seeds if R[a].get(s) and R[b].get(s)
          and not math.isnan(R[a][s][k]) and not math.isnan(R[b][s][k])]
    d = [y - x for x, y in ps]
    if len(d) < 2: return f'n={len(d)}'
    m, se = st.mean(d), st.stdev(d) / math.sqrt(len(d))
    return (f'{st.mean(y for _, y in ps):.3g} | {st.mean(x for x, _ in ps):.3g} | {m:+.3g} ({se:.2g}) '
            f't{(m / se if se else math.inf):+.1f} | {len(d)}')


arms = ['rpX1', 'rpIPP', 'rpD12', 'rpF0']
R = {a: {s: row(a, s) for s in range(1, 17)} for a in arms}
for lab, seeds in (('1-16', list(range(1, 17))), ('9-16', list(range(9, 17)))):
    print(f'\n# seeds {lab}, paired by seed (replayed recNS macros)')
    print('| quantity | rpX1 | base | rpX1 - base (se) t | n |\n|---|---|---|---|---|')
    for b in ('rpIPP', 'rpD12', 'rpF0'):
        print(f'| board CAGR % vs {b} | ' + pair('rpX1', b, 'board CAGR %', seeds, R) + ' |')
    for k in ('M&A/run', 'SAFE cap CAGR %', 'SAFE P/B q79', 'HUMM acquisitions', 'HUMM share eq q0', 'HUMM share eq q79',
              'HUMM share IC q0', 'HUMM share IC q79', 'HUMM expansion events', 'HUMM debt steps >10%'):
        print(f'| {k} vs rpIPP | ' + pair('rpX1', 'rpIPP', k, seeds, R) + ' |')
    ok = [s for s in seeds if R['rpX1'][s] and R['rpIPP'][s]]
    tks = set().union(*(R['rpX1'][s]['acq'] for s in ok))
    dd = {tk: [R['rpX1'][s]['acq'].get(tk, 0) - R['rpIPP'][s]['acq'].get(tk, 0) for s in ok] for tk in tks}
    print('\nlargest per-firm drops in acquisitions/run, rpX1 - rpIPP:')
    print('| firm | rpIPP | rpX1 | diff (se) |\n|---|---|---|---|')
    for tk in sorted(dd, key=lambda t: st.mean(dd[t]))[:5]:
        b = st.mean(R['rpIPP'][s]['acq'].get(tk, 0) for s in ok)
        print(f'| {tk} | {b:.2f} | {b + st.mean(dd[tk]):.2f} | {st.mean(dd[tk]):+.2f} ({st.stdev(dd[tk]) / math.sqrt(len(ok)):.2f}) |')
