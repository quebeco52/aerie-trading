#!/usr/bin/env python3
"""p5b.py <seeds> A B: one-plant harvest (B) against A. Reads the CAP_PROBE rows in runs/<arm>-<seed>.seg.json:
[t, ticker, segmented, financial, gate fails, organic capex, operating capex, maintenance capex, scheduled capex,
revenue, net PP&E, style, plantReplaced, plantLoad, industryPriceLevel, output gap, industry]. One-plant = not
segmented, not financial, not a segmented ticker. Prints the final tables only.
"""
import json, math, os, statistics as st, sys
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


def med(xs):
    xs = [x for x in xs if x is not None and x == x]
    return st.median(xs) if xs else nan


def q(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(p * len(xs)))] if xs else nan


D = {}
for a in (A, B):
    for s in seeds:
        r = json.loads(open(f'{H}/runs/{a}-{s}.jsonl').read().splitlines()[-1])
        g = json.load(open(f'{H}/runs/{a}-{s}.seg.json'))
        k = json.load(open(f'{H}/runs/{a}-{s}.idle.json'))
        D[a, s] = (r, g, k)
SEGTK = {row['tk'] for s in seeds for row in D[B, s][1]['rows']}
one = lambda a, s: [row for row in D[a, s][1]['cap'] if not row[2] and not row[3] and row[1] not in SEGTK]

print(f'## One-plant harvest, {B} vs {A}, n={len(seeds)}')
print(f'\n### 1. How often it bites ({B}, one-plant firms)')
rep = [row for s in seeds for row in one(B, s)]
hv = [row for row in rep if row[12] is not None and row[12] < 1 - 1e-12]
print(f'reports {len(rep)}; plantReplaced < 1 in {len(hv)} ({len(hv) / len(rep):.2%}); when < 1 median {med([r[12] for r in hv]):.3f}, p10 {q([r[12] for r in hv], .1):.3f}, min {min([r[12] for r in hv], default=nan):.3f}')
byi, byt = defaultdict(lambda: [0, 0]), defaultdict(lambda: [0, 0])
for row in rep:
    h = row[12] is not None and row[12] < 1 - 1e-12
    byi[row[16]][0] += h; byi[row[16]][1] += 1
    byt[row[1]][0] += h; byt[row[1]][1] += 1
print('top industries by harvest share: ' + '; '.join(f'{i} {h / n:.0%} ({n})' for i, (h, n) in sorted(byi.items(), key=lambda x: -x[1][0] / x[1][1])[:8]))
print('top tickers: ' + '; '.join(f'{t} {h / n:.0%}' for t, (h, n) in sorted(byt.items(), key=lambda x: -x[1][0] / x[1][1])[:10]))
styles = defaultdict(lambda: [0, 0])
for row in rep:
    styles[row[11]][0] += row[12] is not None and row[12] < 1 - 1e-12
    styles[row[11]][1] += 1
print('harvest share by style: ' + '; '.join(f'{k} {h / n:.1%}' for k, (h, n) in sorted(styles.items())))
for a in (A, B):
    after, other = [], []
    for s in seeds:
        by = defaultdict(list)
        for row in one(a, s):
            by[row[1]].append(row)
        hb = {(row[1], row[0]) for row in one(B, s) if row[12] is not None and row[12] < 1 - 1e-12}
        for tk, rs in by.items():
            for i in range(len(rs) - 4):
                if rs[i][10] > 0 and rs[i + 4][10] > 0:
                    g = math.log(rs[i + 4][10] / rs[i][10])
                    (after if (tk, rs[i][0]) in hb else other).append(g)
    print(f'{a}: net PP&E log growth over the 4 reports after a report harvested in {B}: median {med(after):+.4f} (n {len(after)}); other reports {med(other):+.4f}')

print(f'\n### 2. Pro-cyclical? ({B}) harvest share by output gap sign')
neg = [row for row in rep if row[15] is not None and row[15] < 0]
pos = [row for row in rep if row[15] is not None and row[15] >= 0]
sh = lambda rs: sum(1 for r in rs if r[12] is not None and r[12] < 1 - 1e-12) / len(rs) if rs else nan
print(f'output gap < 0: {sh(neg):.2%} of {len(neg)}; >= 0: {sh(pos):.2%} of {len(pos)}; gap < -2%: {sh([r for r in neg if r[15] < -0.02]):.2%} of {len([r for r in neg if r[15] < -0.02])}')
per = {s: (sh([r for r in one(B, s) if r[15] < 0]), sh([r for r in one(B, s) if r[15] >= 0])) for s in seeds}
print('per seed (neg / pos): ' + ', '.join(f's{s} {a:.2%}/{b:.2%}' for s, (a, b) in per.items()))

print('\n### 3. Gluts, board-wide (industry_price_level KPI < 0.97)')
gl = {}
for a in (A, B):
    share, lens, p90s, pr = {}, [], [], []
    for s in seeds:
        n = tot = 0
        by = defaultdict(list)
        for row in D[a, s][2]['rows']:
            if row[4] is not None:
                by[row[1]].append(row[4])
        for tk, xs in by.items():
            run = 0
            for x in xs + [2.0]:
                if x != 2.0:
                    tot += 1
                if x < 0.97:
                    n += 1
                    run += 1
                    pr.append(x)
                elif run:
                    lens.append(run)
                    run = 0
        share[s] = n / tot
    gl[a] = share
    print(f'{a}: share {st.fmean(share.values()):.3%}; spells {len(lens)}, median {med(lens):.1f}, p90 {q(lens, .9)} quarters; mean price in glut quarters {st.fmean(pr):.4f}')
print(f'glut share {B} - {A}: {fmt(paired(gl[A], gl[B]), 4)}')

print(f'\n### 4. Board, {B} - {A}, paired n={len(seeds)}')
def board(a, s):
    r, g, k = D[a, s]
    fin = r['final']
    allq = [x for v in fin.values() for x in v['q']]
    cpi = lambda y: med([row[5] for row in k['rows'] if y - 1 < row[0] <= y])
    r0 = sum(x['rev'] for x in allq if x['t'] <= 1)
    r1 = sum(x['rev'] for x in allq if 19 < x['t'] <= 20)
    caps = g['cap']
    ppe0 = sum(row[10] for row in caps if row[0] <= 0.26)
    ppe1 = sum(row[10] for row in caps if 19.74 < row[0] <= 20)
    rev = sum(row[9] for row in caps)
    return {'cap y20 tn': sum(v['q'][-1]['price'] * v['q'][-1]['shares'] for v in fin.values() if v['q'] and not v['dead']) / 1e12,
            'defaults+deaths': len(r['defaults']) + len(r['dead']),
            'B-or-worse share': sum(1 for x in allq if x['rating'] in BAD) / len(allq),
            'median P/E y10-20': st.median([x['price'] / x['eps'] for x in allq if 10 < x['t'] <= 20 and x['eps'] > 0]),
            'median split-adj vol': st.median([math.sqrt(a_ / n_ * k['tpy']) for tk, (a_, n_, c, _) in k['rv'].items() if n_ >= k['tpy'] and not fin[tk]['dead']]),
            'real revenue growth /yr y1-20': (math.log(r1 / r0) - math.log(cpi(20) / cpi(1))) / 19,
            'net PP&E growth /yr (nominal)': math.log(ppe1 / ppe0) / 19.75 if ppe0 > 0 and ppe1 > 0 else nan,
            'capex / revenue': sum(row[6] + row[5] for row in caps) / rev,
            'maintenance capex / revenue': sum(row[7] for row in caps) / rev,
            'reports': len(caps),
            'companies': len({row[1] for row in caps})}
bA = {s: board(A, s) for s in seeds}
bB = {s: board(B, s) for s in seeds}
for key in bA[seeds[0]]:
    a, b = [bA[s][key] for s in seeds], [bB[s][key] for s in seeds]
    print(f'{key} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)}')

print(f'\n### 5. Identities')
for a in (A, B):
    n, e1, e2 = 0, 0.0, 0.0
    for s in seeds:
        for r in D[a, s][1]['rows']:
            km = r.get('kappa') or {}
            if not km:
                continue
            n += 1
            e1 = max(e1, abs(sum(km.values()) - 1))
            if r['rev'] > 0:
                e2 = max(e2, abs(sum(v['rev'] for v in (r.get('recs') or {}).values()) - 4 * r['rev']) / (4 * r['rev']))
    leaks = sum(D[a, s][1]['leaks']['momentum'] for s in seeds)
    print(f'{a}: segmented reports {n}, max |sum kappa - 1| {e1:.1e}, max |record revenue - 4 rev| / 4 rev {e2:.1e}, unsegmented reports with a segment key {leaks}; '
          f'PHPUnit runs passing (harness assertions): {sum(1 for s in seeds if "OK (1 test" in open(f"{H}/runs/{a}-{s}.log").read())} of {len(seeds)}')
