#!/usr/bin/env python3
"""analyse_fund.py [seeds] [A] [B]: catch-up-after-the-cap check (section j), B - A paired (default val, fund).

Reuses analyse_phase5's loaders and interval regressions. Fair value's elasticity to the gap from GAPPROBE columns
(B arm; A's reference is the `gapp` arm, seeds 1-4). Buybacks and share issuance (\"Issued new shares to raise $X\"
events) over years 10-20, each as an annual share of the mean board cap.
"""
import math, os, re, statistics as st, sys, json

H = os.path.dirname(os.path.abspath(__file__))
src = open(f'{H}/analyse_phase5.py').read()
exec(src.split('# ---------------------------------------------------------------- 1.')[0])
exec(src[src.index('def interval_pairs'):src.index('def slopes')])

seeds = [int(x) for x in (sys.argv[1] if len(sys.argv) > 1 else '1,2,3,4,5,6,7,8').split(',')]
A, B = (sys.argv[2], sys.argv[3]) if len(sys.argv) > 3 else ('val', 'fund')
AMT = re.compile(r'\$([\d.,]+)\s*([BMK]?)')
MULT = {'B': 1e9, 'M': 1e6, 'K': 1e3, '': 1.0}


def money(text):
    m = AMT.search(text)
    return float(m.group(1).replace(',', '')) * MULT[m.group(2)] if m else 0.0


def extra(arm, s):
    r, k, oq = load(arm, s, '-oc')
    b = board(r, k, 0, 20)
    rv = [math.sqrt(a / n * k['tpy']) for tk, (a, n, c, _) in k['rv'].items() if n >= k['tpy'] and not r['final'][tk]['dead']]
    caps = {}
    for f in r['final'].values():
        for x in f['q']:
            if 10 < x['t'] <= 20:
                caps[round(x['t'] * 4)] = caps.get(round(x['t'] * 4), 0.0) + x['price'] * x['shares']
    mean_cap = st.fmean(caps.values()) if caps else float('nan')
    bb = sum(x['bb'] for f in r['final'].values() for x in f['q'] if 10 < x['t'] <= 20)
    iss = sum(money(e['d']) for ev in r['events'].values() for e in ev if 10 < e['t'] <= 20 and e['d'].startswith('Issued new shares'))
    deals = sum(1 for ev in r['events'].values() for e in ev if 10 < e['t'] <= 20 and ('CONGLOMERATE EXPANSION' in e['d'] or 'LEVERAGED BUYOUT' in e['d']))
    return {
        'cap y20 ($tn)': b['cap end ($tn)'],
        'median P/E y10-20': board(r, k, 10, 20)['median P/E'],
        'median realised vol': st.median(rv),
        'p90 realised vol': pct(rv, 0.9),
        'defaults+deaths': b['defaults+deaths'],
        'B-or-worse share': b['B-or-worse share'],
        'median ND/EBITDA y20': b['median ND/EBITDA end'],
        'buybacks / cap per yr (y10-20)': bb / 10.0 / mean_cap,
        'share issuance / cap per yr (y10-20)': iss / 10.0 / mean_cap,
        'M&A deals y10-20': deals,
    }


print(f'## 1. Revision regressions, per-seed OLS slope on the belief change (mean (se) n)')
for arm in (A, B):
    per = {s: interval_pairs(arm, s, '-oc') for s in seeds}
    print(f'{arm} | fv | price | FV/EPS (log)')
    for c, _ in CATS + [('all', 0)]:
        cells = []
        for key in ('fv', 'price', 'FV/EPS (log)'):
            vals = []
            for s in seeds:
                pr = [p for p in per[s] if (c == 'all' or p['cat'] == c) and key in p]
                vals.append(ols([p['rev'] for p in pr], [p[key] for p in pr]))
            cells.append(seed_mean(vals))
        print(f'{c} | ' + ' | '.join(cells))
    if arm == A:
        fa = [ols([p['rev'] for p in per[s] if 'fv' in p], [p['fv'] for p in per[s] if 'fv' in p]) for s in seeds]
        pa = [ols([p['rev'] for p in per[s] if 'price' in p], [p['price'] for p in per[s] if 'price' in p]) for s in seeds]
    else:
        fb = [ols([p['rev'] for p in per[s] if 'fv' in p], [p['fv'] for p in per[s] if 'fv' in p]) for s in seeds]
        pb = [ols([p['rev'] for p in per[s] if 'price' in p], [p['price'] for p in per[s] if 'price' in p]) for s in seeds]
print(f'paired slope difference {B}-{A}: fv {fmt(paired(fa, fb), 3)} | price {fmt(paired(pa, pb), 3)}')


def elasticity(arm, ss):
    loc = []
    for s in ss:
        for r in json.load(open(f'{H}/runs/{arm}-{s}-oc.idle.json'))['rows']:
            if len(r) >= 13 and r[0] >= 10 and r[9] and r[11] and r[12] and min(r[9], r[11], r[12]) > 0:
                loc.append(math.log(r[12] / r[9]) / 0.1)
    return loc


for arm, ss in (('gapp', [1, 2, 3, 4]), (B, seeds)):
    l = elasticity(arm, ss)
    if l:
        print(f'fair value elasticity to the gap, {arm} seeds {ss[0]}-{ss[-1]}, y10-20: median {st.median(l):.3f}, p10 {pct(l, .1):.3f}, '
              f'p90 {pct(l, .9):.3f}, share < 0.05 {sum(1 for x in l if x < 0.05) / len(l):.2f} (fq {len(l)})')

print(f'\n## 2. Board, {B} - {A}')
ea = {s: extra(A, s) for s in seeds}
eb = {s: extra(B, s) for s in seeds}
print(f'quantity | {A} | {B} | {B}-{A} (se) t n')
for key in ea[seeds[0]]:
    a = [ea[s][key] for s in seeds]
    b = [eb[s][key] for s in seeds]
    print(f'{key} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)}')
for arm in (A, B):
    for s in seeds:
        r = load(arm, s, '-oc')[0]
        ev = [f'{tk} default {q}q' for tk, q in (r['defaults'] or {}).items()] + [f"{e['t']} reorg y{e['year']}" for e in r['reorgs']] + [f'{d} failed' for d in r['dead']]
        if ev:
            print(f'  event {arm} s{s}: ' + '; '.join(ev))
