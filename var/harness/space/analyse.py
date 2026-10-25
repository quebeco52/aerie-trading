#!/usr/bin/env python3
"""SKYL growth-story table from runs/<arm>-s<seed>.json (GRIP beside it). Usage: analyse.py [arm].

Year y = the four reports of year y (y 0 = the opening annual revenue). Real multiple deflates by the consumer price level
the report read, against the designed exp(0.02 t) x sumLevels(t) / sumLevels(0) at the year's middle. Cells print the
mean over seeds and [min, max].
"""
import json, math, statistics as st, sys
from pathlib import Path

arm = sys.argv[1] if len(sys.argv) > 1 else 'live'
P = Path(__file__).parent
files = sorted(P.glob(f'runs/{arm}-s[0-9]*.json'), key=lambda p: int(p.stem.split('-s')[-1]))
D = [json.load(open(p)) for p in files]
YEARS = (0, 5, 10, 15, 20)
ORDER = [a + b for a in ('AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C') for b in ('+', '', '-')] + ['D']
S3 = ('launch_services', 'government_missions', 'satellite_broadband')


def faded(e, t, h):
    return e * h / math.log(2) * (1 - 2 ** (-t / h))


def bass(t, p=0.03, q=0.38):
    if t <= 0:
        return 0.0
    x = math.exp(-(p + q) * t)
    return (1 - x) / (1 + (q / p) * x)


def levels(t):
    return 0.7 * math.exp(faded(0.06, t, 6.0)) + 0.3 + 2.0 * bass(t - 4)


def designed(t):
    return math.exp(0.02 * t) * levels(t) / levels(0)


R = {}


def put(k, v):
    R.setdefault(k, []).append(v)


def cell(k, fmt='{:.2f}'):
    xs = [x for x in R.get(k, []) if x is not None and x == x]
    if not xs:
        return '-'
    return f'{fmt.format(st.fmean(xs))} [{fmt.format(min(xs))}, {fmt.format(max(xs))}]'


def yr(q, y):
    return q[4 * (y - 1):4 * y]


for d in D:
    q, o = d['q']['SKYL'], d['opening']['SKYL']
    ny = len(q) // 4
    cpi0 = q[0]['cpi']
    for y in YEARS:
        if y == 0:
            put('rev $B y0', o['rev'] / 1e9); put('real x y0', 1.0)
            put('om y0', o['om'] * 100)
            continue
        rows = yr(q, y)
        rev = sum(r['rev'] for r in rows)
        real = rev / st.fmean(r['cpi'] for r in rows) * cpi0
        put(f'rev $B y{y}', rev / 1e9); put(f'real x y{y}', real / o['rev'])
        put(f'designed x y{y}', designed(y - 0.5))
        put(f'real/designed y{y}', real / o['rev'] / designed(y - 0.5))
        put(f'om y{y}', st.fmean(r['om'] for r in rows) * 100)
        for s in S3:
            put(f'share {s[:9]} y{y}', sum(r['streams'][s] for r in rows) / rev)
        put(f'bb penetration y{y}', rows[-1]['kpi']['broadband_penetration'])
        put(f'launch cost idx y{y}', rows[-1]['kpi']['launch_cost_index'])
        g = [r['diag'] for r in rows]
        put(f'roic ttm y{y}', g[-1]['roic_ttm'] * 100)
        prev = yr(q, y - 1) if y > 1 else q[:1]
        ppe_prev = prev[-1]['diag']['ppe']
        put(f'plant growth % y{y}', (g[-1]['ppe'] / ppe_prev - 1) * 100)
        put(f'designed nominal g % y{y}', st.fmean(x['faded_g'] + max(0.0, x['infl']) for x in g) * 100)
        put(f'capex/ocf y{y}', sum(-x['icf'] for x in g) / max(1.0, sum(x['ocf'] for x in g)))
        put(f'growth budget cap % y{y}', g[-1]['baseline_roic'] * g[-1]['capex_ratio'] * g[-1]['reinvest_bias'] * 100)
        put(f'hurdle - baseline roic pp y{y}', ((g[-1]['applied_hurdle'] or 0) - g[-1]['baseline_roic']) * 100)
        put(f'addressable share y{y}', g[-1]['addr_share'])
        put(f'saturation penalty y{y}', g[-1]['sat_penalty'])
        put(f'debt/EBITDA y{y}', rows[-1]['debt'] / max(1.0, sum(x['ebitda'] for x in g)))
        put(f'payout y{y}', g[-1]['payout'])
    caps0 = d['cap1']['SKYL']
    put('cap $B y0', caps0 / 1e9); put('P/E y0', o['px'] / o['eps'])
    for y in (2, 5, 10, 15, 20):
        r = q[4 * y - 1]
        put(f'cap $B y{y}', r['cap'] / 1e9); put(f'cap x y{y}', r['cap'] / caps0)
        put(f'P/E y{y}', r['px'] / r['eps'] if r['eps'] > 0 else float('nan'))
    om = [r['om'] for r in q]; ni = [r['ni'] for r in q]; sh = [r['sh'] for r in q]
    put('loss quarters', sum(1 for r in q if r['ebit'] < 0))
    eps_y = [sum(ni[4 * y:4 * y + 4]) / sh[4 * y + 3] for y in range(ny)]
    put('EPS g SD %', st.stdev([(b - a) / abs(a) for a, b in zip(eps_y, eps_y[1:]) if a]) * 100)
    put('neg EPS years', sum(1 for e in eps_y if e <= 0))
    daily = d['daily']; idx = [x['i'] for x in daily]
    px, adj = [], 1.0
    for i, x in enumerate(daily):
        if i and daily[i - 1]['SKYL_sh'] > 0:
            ratio = x['SKYL_sh'] / daily[i - 1]['SKYL_sh']
            if ratio > 1.2 or ratio < 0.8:
                adj *= ratio
        px.append(x['SKYL'] * adj)
    lr = [math.log(b / a) for a, b in zip(px, px[1:]) if a > 0 and b > 0]
    put('vol %', st.stdev(lr) * math.sqrt(len(daily) / ny) * 100)
    rw = [math.log(px[i + 5] / px[i]) for i in range(0, len(px) - 5, 5)]
    mw = [math.log(idx[i + 5] / idx[i]) for i in range(0, len(idx) - 5, 5)]
    ma, mb = st.fmean(rw), st.fmean(mw)
    put('beta wk', sum((x - ma) * (y - mb) for x, y in zip(rw, mw)) / (len(rw) - 1) / st.variance(mw))
    peak, dd = px[0], 0.0
    for x in px:
        peak = max(peak, x); dd = min(dd, x / peak - 1)
    put('maxDD %', dd * 100)
    bt = [r for r in q if r['ceps'] is not None and r['reps'] is not None]
    put('beat rate %', st.fmean(1.0 if r['reps'] > r['ceps'] else 0.0 for r in bt) * 100)
    put('worst rating idx', max(ORDER.index(r['rating']) if r['rating'] in ORDER else len(ORDER) - 1 for r in q))
    put('distress q', sum(1 for r in q if r['qid'] or r['pd']))
    put('dead/reorg/default', int('SKYL' in d['dead']) + sum(1 for x in d.get('reorgs', []) if x['t'] == 'SKYL') + (d.get('defaults') or {}).get('SKYL', 0))
    divq = [i for i, r in enumerate(q) if r['div'] > 0]
    put('first dividend year', (divq[0] + 1) / 4 if divq else None)
    put('dividend years paid', len({i // 4 for i in divq}))
    grd = [r['regime'].get('state:regime:fleet_grounded', 0) for r in q]
    on = [i for i in range(len(q)) if grd[i] == 1]
    put('launch failures', len(on)); put('failure lore', len(d.get('failures', {}).get('SKYL', [])))
    for i in on:
        if i < 4 or i + 2 >= len(q):
            continue
        pre = st.fmean(q[j]['streams']['launch_services'] for j in range(i - 4, i))
        for h in (0, 1, 2):
            put(f'launch rev h{h} vs pre4', q[i + h]['streams']['launch_services'] / pre - 1)
        k0 = max(0, round(q[i]['t'] * len(daily) / ny) - 2); k1 = min(len(daily) - 1, k0 + len(daily) // (4 * ny))
        put('xret +1q at failure', (daily[k1]['SKYL'] / daily[k0]['SKYL']) / (daily[k1]['i'] / daily[k0]['i']) - 1)

print(f'arm {arm}, seeds {[d["seed"] for d in D]}; SKYL mean [min, max] over seeds')
for k in R:
    if k == 'worst rating idx':
        print(f'{k:32s} worst {ORDER[max(R[k])]}, best {ORDER[min(R[k])]}')
        continue
    print(f'{k:32s} {cell(k)}')
