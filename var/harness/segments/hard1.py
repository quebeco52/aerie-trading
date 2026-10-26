#!/usr/bin/env python3
"""hard1.py <seeds> A B: cumulative board check of the whole segment rework (A = the board before segments, B = head).
Paired by seed. Reads runs/<arm>-<seed>.{jsonl,idle.json,seg.json} (seg.json: cap rows, ind table at the end,
macro quarterly GDP index, deals, ventures). Prints the final tables only.
"""
import json, math, os, re, statistics as st, sys
from collections import defaultdict
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = sys.argv[2], sys.argv[3]
ORDER = ['AAA', 'AA+', 'AA', 'AA-', 'A+', 'A', 'A-', 'BBB+', 'BBB', 'BBB-', 'BB+', 'BB', 'BB-', 'B+', 'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D']
BAD = set(ORDER[ORDER.index('B'):])
nan = float('nan')
SEGMENTED = set(re.findall(r"'([A-Z]{3,5})' => \['main' =>", open(os.path.join(H, '..', '..', '..', 'src', 'Data', 'Company', 'Segments.php')).read()))


def med(xs):
    xs = [x for x in xs if x is not None and x == x]
    return st.median(xs) if xs else nan


def q(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(p * len(xs)))] if xs else nan


D = {}
for a in (A, B):
    for s in seeds:
        D[a, s] = (json.loads(open(f'{H}/runs/{a}-{s}.jsonl').read().splitlines()[-1]), json.load(open(f'{H}/runs/{a}-{s}.idle.json')),
                   json.load(open(f'{H}/runs/{a}-{s}.seg.json')))


def gdp(g, t0, t1):
    xs = [m[1] for m in g['macro'] if t0 < m[0] <= t1]
    return st.fmean(xs) if xs else nan


def board(a, s):
    r, k, g = D[a, s]
    fin = r['final']
    allq = [x for v in fin.values() for x in v['q']]
    rows = k['rows']
    cpi = lambda y: med([row[5] for row in rows if y - 1 < row[0] <= y])
    sumq = lambda key, t0, t1: sum(x[key] for x in allq if t0 < x['t'] <= t1)
    r0, r1 = sumq('rev', 0, 1), sumq('rev', 19, 20)
    n0, n1 = sumq('ni', 0, 1), sumq('ni', 19, 20)
    g0, g1 = gdp(g, 0, 1), gdp(g, 19, 20)
    nd = []
    for v in fin.values():
        if not v['q'] or v['q'][0]['fin']:
            continue
        y = [x for x in v['q'] if 19 < x['t'] <= 20]
        e = sum(x['ebitda'] for x in y)
        if len(y) >= 4 and e > 0:
            nd.append((y[-1]['debt'] - y[-1]['cash']) / e)
    caps = g['cap']
    ppe0 = sum(row[10] for row in caps if row[0] <= 0.26)
    ppe1 = sum(row[10] for row in caps if 19.74 < row[0] <= 20)
    ipl = [row[4] for row in rows if row[4] is not None]
    spells, run = [], defaultdict(int)
    by = defaultdict(list)
    for row in rows:
        if row[4] is not None:
            by[row[1]].append(row[4])
    for tk, xs in by.items():
        c = 0
        for x in xs + [2.0]:
            if x < 0.95:
                c += 1
            elif c:
                spells.append(c)
                c = 0
    return {
        'cap y20 tn': sum(v['q'][-1]['price'] * v['q'][-1]['shares'] for v in fin.values() if v['q'] and not v['dead']) / 1e12,
        'median P/E y10-20': st.median([x['price'] / x['eps'] for x in allq if 10 < x['t'] <= 20 and x['eps'] > 0]),
        'median split-adj vol': st.median([math.sqrt(a_ / n_ * k['tpy']) for tk, (a_, n_, c_, _) in k['rv'].items() if n_ >= k['tpy'] and not fin[tk]['dead']]),
        'defaults+deaths': len(r['defaults']) + len(r['dead']),
        'B-or-worse share': sum(1 for x in allq if x['rating'] in BAD) / len(allq),
        'median ND/EBITDA y20': st.median(nd),
        'real revenue growth /yr': (math.log(r1 / r0) - math.log(cpi(20) / cpi(1))) / 19,
        'capex / revenue': sum((row[6] or 0) + row[5] for row in caps) / sum(row[9] for row in caps),
        'net PP&E growth /yr': math.log(ppe1 / ppe0) / 19.75,
        'log(revenue/GDP) y20 vs y1': math.log((r1 / g1) / (r0 / g0)),
        'log(net income/GDP) y20 vs y1': math.log((n1 / g1) / (n0 / g0)) if n0 > 0 and n1 > 0 else nan,
        'glut share (price < 0.95)': sum(1 for x in ipl if x < 0.95) / len(ipl),
        'glut spell median (q)': med(spells),
        'glut spell p90 (q)': q(spells, .9),
        'share price > 1.05': sum(1 for x in ipl if x > 1.05) / len(ipl),
    }


print(f'## Cumulative check: {B} vs {A} (paired, n={len(seeds)}, 20 years)')
print(f'\n### Board\nquantity | {A} | {B} | diff (se) t n')
bA = {s: board(A, s) for s in seeds}
bB = {s: board(B, s) for s in seeds}
for key in bA[seeds[0]]:
    a = {s: bA[s][key] for s in seeds if bA[s][key] == bA[s][key] and bB[s][key] == bB[s][key]}
    b = {s: bB[s][key] for s in a}
    print(f'{key} | {st.fmean(a.values()):.4g} | {st.fmean(b.values()):.4g} | {fmt(paired(a, b), 4)}')

print('\n### Industries with |t| > 3 (mean price level of listed firms over 20 y; ledger revenue and sellers at y20)')
inds = sorted({row[2] for s in seeds for a in (A, B) for row in D[a, s][1]['rows']} | {i for s in seeds for a in (A, B) for i in D[a, s][2].get('ind', {})})
hits = []
for ind in inds:
    for lab, f in (('price level', lambda a, s: st.fmean([row[4] for row in D[a, s][1]['rows'] if row[2] == ind and row[4] is not None] or [nan])),
                   ('ledger revenue (log)', lambda a, s: math.log(D[a, s][2]['ind'][ind][1]) if D[a, s][2].get('ind', {}).get(ind, [0, 0])[1] > 0 else nan),
                   ('sellers', lambda a, s: float(D[a, s][2].get('ind', {}).get(ind, [nan])[0]))):
        a = {s: f(A, s) for s in seeds}
        b = {s: f(B, s) for s in seeds}
        keep = [s for s in seeds if a[s] == a[s] and b[s] == b[s]]
        if len(keep) < 3:
            if lab == 'sellers' and any(b[s] == b[s] and a[s] != a[s] for s in seeds):
                hits.append((99, ind, lab, f'new in {B}: {[b[s] for s in seeds]}'))
            continue
        p = paired({s: a[s] for s in keep}, {s: b[s] for s in keep})
        if p.t == p.t and abs(p.t) > 3 or (p.se == 0 and p.diff != 0):
            hits.append((abs(p.t) if p.t == p.t else 99, ind, lab, f'{st.fmean(a[s] for s in keep):.4g} -> {st.fmean(b[s] for s in keep):.4g}, {fmt(p, 4)}'))
for t, ind, lab, txt in sorted(hits, key=lambda h: -h[0]):
    print(f'{ind} | {lab} | {txt}')

print('\n### Firms: largest year-20 revenue moves (paired log ratio)')
buyers, builders = set(), set()
for s in seeds:
    g = D[B, s][2]
    buyers |= {d['tk'] for d in g['deals'] if ', taking it into ' in d['d']}
    builders |= {v['tk'] for v in g.get('ventures', []) if v.get('entry')}
res = []
for tk in D[A, seeds[0]][0]['final']:
    lr = {}
    for s in seeds:
        ra = sum(x['rev'] for x in D[A, s][0]['final'].get(tk, {'q': []})['q'] if 19 < x['t'] <= 20)
        rb = sum(x['rev'] for x in D[B, s][0]['final'].get(tk, {'q': []})['q'] if 19 < x['t'] <= 20)
        if ra > 0 and rb > 0:
            lr[s] = math.log(rb / ra)
    if len(lr) >= 3:
        p = paired({s: 0.0 for s in lr}, lr)
        res.append((p, tk))
res.sort(key=lambda x: -abs(x[0].t) if x[0].t == x[0].t else 0)
for p, tk in res[:10]:
    tags = [t for t, cond in (('segmented', tk in SEGMENTED), ('entrant', tk in builders), ('buyer', tk in buyers), ('reseeded', tk in ('OWLS', 'TRIV'))) if cond]
    print(f'{tk} | {fmt(p, 3)} | {", ".join(tags) or "untouched"}')
print(f'(firms with |t| > 2: {sum(1 for p, tk in res if p.t == p.t and abs(p.t) > 2)} of {len(res)})')
