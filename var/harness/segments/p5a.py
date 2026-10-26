#!/usr/bin/env python3
"""p5a.py <seeds> A B: phase 5a (capital follows return), B vs A. Reads runs/<arm>-<seed>.{jsonl,idle.json,seg.json}
with the CAP_PROBE rows ('cap': [t, ticker, segmented, financial, gate fails, organic capex, operating capex,
maintenance capex, scheduled capex, revenue, net PP&E, style]). Prints the final tables only.
"""
import json, math, os, statistics as st, sys
from collections import Counter
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


D = {}
for a in (A, B):
    for s in seeds:
        r = json.loads(open(f'{H}/runs/{a}-{s}.jsonl').read().splitlines()[-1])
        g = json.load(open(f'{H}/runs/{a}-{s}.seg.json'))
        for row in g['rows']:
            for key in ('kappa', 'recs'):
                row[key] = row.get(key) or {}
        k = json.load(open(f'{H}/runs/{a}-{s}.idle.json'))
        D[a, s] = (r, g, k)
SEGTK = sorted({row['tk'] for s in seeds for row in D[B, s][1]['rows']})

print(f'## Phase 5a, {B} vs {A}, n={len(seeds)}')
print('\n### 1. One-plant firms (not segmented, not financial): treasury organic capex when the growth gate fails; capex/revenue')
lk = {}
for a in (A, B):
    org, nf, nfo, gr, rv = [], 0, 0, {}, {}
    for s in seeds:
        o = gs = r_ = 0.0
        for row in D[a, s][1]['cap']:
            t, tk, seg, fin, fails, organic, opx, mnt, sch, rev, ppe = row[:11]
            if seg or fin or tk in SEGTK:
                continue
            if fails:
                nf += 1
                nfo += organic > 0
                o += organic
            gs += max(0.0, opx - mnt - sch) + organic
            r_ += rev
        org.append(o / 1e9)
        gr[s] = gs / r_ if r_ else nan
    lk[a] = gr
    print(f'{a}: gate-failing reports {nf}, with treasury organic capex > 0: {nfo}; organic capex in them per seed {st.fmean(org):.1f} bn (max {max(org):.1f})')
print(f'growth capex (operating - maintenance - scheduled + treasury organic) / revenue: {A} {st.fmean(lk[A].values()):.4f}, {B} {st.fmean(lk[B].values()):.4f}, {fmt(paired(lk[A], lk[B]), 4)}')

print(f'\n### 2. Harvest, segmented companies ({B})')
fails = Counter()
tot = Counter()
for s in seeds:
    for row in D[B, s][1]['rows']:
        for k in row['kappa']:
            if row['kappa'][k] <= 0:
                continue
            b = (row.get('builds') or {}).get(k)
            if b is None:
                continue
            tot[row['tk'], k] += 1
            fails[row['tk'], k] += b == 0
if tot:
    print('share of reports with carry:segment_builds = 0: ' + '; '.join(f'{tk}:{k} {fails[tk, k] / n:.2f}' for (tk, k), n in sorted(tot.items()) if fails[tk, k]))
    print('never failing: ' + ', '.join(f'{tk}:{k}' for (tk, k), n in sorted(tot.items()) if not fails[tk, k]))
drift = {a: [] for a in (A, B)}
dfail = []
for a in (A, B):
    for s in seeds:
        by = {}
        for row in D[a, s][1]['rows']:
            by.setdefault(row['tk'], []).append(row)
        for tk, rs in by.items():
            for p, r in zip(rs, rs[1:]):
                for k, v in p['kappa'].items():
                    if v > 0 and r['kappa'].get(k, 0) > 0:
                        d = math.log(r['kappa'][k] / v) * 4
                        drift[a].append(d)
                        if a == B and (p.get('builds') or {}).get(k) == 0:
                            dfail.append(d)
print(f'kappa drift (log/yr) median all reports {A} {med(drift[A]):+.4f}, {B} {med(drift[B]):+.4f}; {B} after a failing report median {med(dfail):+.4f} mean {st.fmean(dfail) if dfail else nan:+.4f} (n {len(dfail)})')
shifts = []
for tk in SEGTK:
    for y in (10, 20):
        segs = {k for s in seeds for row in D[B, s][1]['rows'] if row['tk'] == tk for k in row['kappa']}
        for k in segs:
            v = {a: med([st.fmean(r['kappa'].get(k, 0.0) for r in D[a, s][1]['rows'] if r['tk'] == tk and y - 1 < r['t'] <= y and r['kappa']) for s in seeds
                         if any(r['kappa'] for r in D[a, s][1]['rows'] if r['tk'] == tk and y - 1 < r['t'] <= y)]) for a in (A, B)}
            shifts.append((abs(v[B] - v[A]), y, tk, k, v[A], v[B]))
shifts.sort(reverse=True)
print('largest kappa shifts (median over seeds): ' + '; '.join(f'y{y} {tk}:{k} {a:.3f} -> {b:.3f}' for d, y, tk, k, a, b in shifts[:8]))
sales = {a: [f"s{s} {d['tk']} y{d['t']:.1f}: {d['d'][:70]}" for s in seeds for d in D[a, s][1].get('deals', []) if ' sold its ' in d['d'] and 'division' not in d['d']] for a in (A, B)}
print(f'whole-segment sales {A}: {sales[A] or "none"}; {B}: {sales[B] or "none"}')
styles = {row[1]: row[11] for s in seeds for row in D[B, s][1]['cap'] if row[1] in SEGTK}
emp = sorted(tk for tk, st_ in styles.items() if st_ and 'empire' in str(st_))
print(f'segmented empire builders: {emp or "none"} (styles: {dict(sorted(styles.items()))})')

print('\n### 3. Gluts, board-wide: firm-quarters with industry_price_level < 0.97; median spell length (quarters)')
for a in (A, B):
    n = tot_ = 0
    spells = []
    for s in seeds:
        by = {}
        for row in D[a, s][2]['rows']:
            if row[4] is not None:
                by.setdefault(row[1], []).append(row[4])
        for tk, xs in by.items():
            run = 0
            for x in xs + [2.0]:
                tot_ += x != 2.0
                if x < 0.97:
                    n += 1
                    run += 1
                elif run:
                    spells.append(run)
                    run = 0
    print(f'{a}: {n} of {tot_} firm-quarters ({n / tot_:.3%}); spells {len(spells)}, median length {med(spells):.1f}, p90 {sorted(spells)[int(0.9 * len(spells))] if spells else nan}')

print(f'\n### 4. Board, {B} - {A}, paired n={len(seeds)}')
def board(a, s):
    r, g, k = D[a, s]
    fin = r['final']
    allq = [x for v in fin.values() for x in v['q']]
    cpi = lambda y: med([row[5] for row in k['rows'] if y - 1 < row[0] <= y])
    r0 = sum(x['rev'] for x in allq if x['t'] <= 1)
    r1 = sum(x['rev'] for x in allq if 19 < x['t'] <= 20)
    caps = g['cap']
    p0 = sum(row[10] for row in caps if row[0] <= 1.0) / max(1, len({row[1] for row in caps if row[0] <= 1.0}))
    p1 = sum(row[10] for row in caps if 19 < row[0] <= 20) / max(1, len({row[1] for row in caps if 19 < row[0] <= 20}))
    ppe0 = sum(row[10] for row in caps if row[0] <= 0.26)
    ppe1 = sum(row[10] for row in caps if 19.74 < row[0] <= 20)
    return {'cap y20 tn': sum(v['q'][-1]['price'] * v['q'][-1]['shares'] for v in fin.values() if v['q'] and not v['dead']) / 1e12,
            'defaults+deaths': len(r['defaults']) + len(r['dead']),
            'B-or-worse share': sum(1 for x in allq if x['rating'] in BAD) / len(allq),
            'median P/E y10-20': st.median([x['price'] / x['eps'] for x in allq if 10 < x['t'] <= 20 and x['eps'] > 0]),
            'median split-adj vol': st.median([math.sqrt(a_ / n_ * k['tpy']) for tk, (a_, n_, c, _) in k['rv'].items() if n_ >= k['tpy'] and not fin[tk]['dead']]),
            'real revenue growth /yr y1-20': (math.log(r1 / r0) - math.log(cpi(20) / cpi(1))) / 19,
            'net PP&E growth /yr (nominal, first to last quarter)': math.log(ppe1 / ppe0) / 19.75 if ppe0 > 0 and ppe1 > 0 else nan,
            'capex / revenue (operating capex + organic)': sum(row[6] + row[5] for row in caps) / sum(row[9] for row in caps)}
bA = {s: board(A, s) for s in seeds}
bB = {s: board(B, s) for s in seeds}
for key in bA[seeds[0]]:
    a, b = [bA[s][key] for s in seeds], [bB[s][key] for s in seeds]
    print(f'{key} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)}')

print(f'\n### 5. Identities ({B}, every segmented report)')
n, e1, e2 = 0, 0.0, 0.0
for s in seeds:
    for r in D[B, s][1]['rows']:
        if not r['kappa']:
            continue
        n += 1
        e1 = max(e1, abs(sum(r['kappa'].values()) - 1))
        if r['rev'] > 0:
            e2 = max(e2, abs(sum(v['rev'] for v in r['recs'].values()) - 4 * r['rev']) / (4 * r['rev']))
print(f'{n} reports over {len(SEGTK)} companies: max |sum kappa - 1| {e1:.1e}; max |record revenue - 4 rev| / 4 rev {e2:.1e}')
