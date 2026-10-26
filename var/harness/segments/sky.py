#!/usr/bin/env python3
"""sky.py <seeds> A B: SKYL with plant by segment (B) against one plant (A); per-year medians over seeds.

Internet kappa vs planned share and the internet record's capacity gap (probe `sky`), entry year; SKYL revenue,
internet revenue share (stream revenue), op margin, P/E y10-20, cap y20, ND/EBITDA y20, ratings, events; telecom peers
LOON and SKUA price level and revenue, and the internet record's share of telecom record revenue; identities on every
SKYL report in B; board (cap y20, defaults+deaths, B-or-worse, P/E) paired. Prints the final tables only.
"""
import json, math, os, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = sys.argv[2], sys.argv[3]
YRS = [1, 2, 3, 4, 5, 6, 8, 10, 12, 15, 20]
SEG = 'satellite_internet'
ORDER = ['AAA', 'AA+', 'AA', 'AA-', 'A+', 'A', 'A-', 'BBB+', 'BBB', 'BBB-', 'BB+', 'BB', 'BB-', 'B+', 'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D']
BAD = set(ORDER[ORDER.index('B'):])
nan = float('nan')


def med(xs):
    xs = [x for x in xs if x is not None and x == x]
    return st.median(xs) if xs else nan


R, G, K = {}, {}, {}
for a in (A, B):
    for s in seeds:
        R[a, s] = json.loads(open(f'{H}/runs/{a}-{s}.jsonl').read().splitlines()[-1])
        g = json.load(open(f'{H}/runs/{a}-{s}.seg.json'))
        for row in g['rows']:
            for key in ('kappa', 'recs', 'segrev'):
                row[key] = row.get(key) or {}
        G[a, s] = g
        K[a, s] = json.load(open(f'{H}/runs/{a}-{s}.idle.json'))
sk = lambda a, s: [r for r in G[a, s]['rows'] if r['tk'] == 'SKYL']
win = lambda rs, y: [r for r in rs if y - 1 < r['t'] <= y]

print(f'## SKYL, {B} vs {A}, n={len(seeds)} (medians over seeds of yearly means)')
print('\n### 1. Internet tracking (B): year | kappa | planned share | capacity gap | internet stream revenue share A / B')
entry = []
for s in seeds:
    e = [r['t'] for r in sk(B, s) if r['kappa'].get(SEG, 0) > 0]
    entry.append(min(e) if e else nan)
print('entry year by seed: ' + ', '.join(f's{s} {t:.2f}' for s, t in zip(seeds, entry)))
for y in YRS:
    k = med([st.fmean(r['kappa'].get(SEG, 0.0) for r in win(sk(B, s), y)) for s in seeds if win(sk(B, s), y)])
    p = med([med([(r.get('sky') or {}).get('plan') for r in win(sk(B, s), y)]) for s in seeds])
    g = med([med([(r.get('sky') or {}).get('cgap') for r in win(sk(B, s), y)]) for s in seeds])
    sh = {a: med([st.fmean(r['segrev'].get(SEG, 0.0) / sum(r['segrev'].values()) for r in win(sk(a, s), y) if sum(r['segrev'].values()) > 0) for s in seeds if win(sk(a, s), y)]) for a in (A, B)}
    print(f'y{y} | {k:.3f} | {p:.3f} | {g:+.3f} | {sh[A]:.3f} / {sh[B]:.3f}')
gaps = [(r.get('sky') or {}).get('cgap') for s in seeds for r in sk(B, s) if (r.get('sky') or {}).get('cgap') is not None]
print(f'capacity gap over all reports after entry: min {min(gaps):+.3f}, p5 {sorted(gaps)[len(gaps) // 20]:+.3f}, median {st.median(gaps):+.3f}, p95 {sorted(gaps)[-1 - len(gaps) // 20]:+.3f}, max {max(gaps):+.3f}' if gaps else 'no capacity gap read')

print('\n### 2. SKYL company (median over seeds)')
for a in (A, B):
    q = {s: R[a, s]['final']['SKYL']['q'] for s in seeds}
    rev = lambda s, y: sum(x['rev'] for x in q[s] if y - 1 < x['t'] <= y)
    cpi = lambda s, y: med([row[5] for row in K[a, s]['rows'] if row[1] == 'SKYL' and y - 1 < row[0] <= y])
    g10 = med([(math.log(rev(s, 10) / rev(s, 1)) - math.log(cpi(s, 10) / cpi(s, 1))) / 9 for s in seeds if rev(s, 1) > 0 and rev(s, 10) > 0])
    g20 = med([(math.log(rev(s, 20) / rev(s, 10)) - math.log(cpi(s, 20) / cpi(s, 10))) / 10 for s in seeds if rev(s, 10) > 0 and rev(s, 20) > 0])
    om = med([st.fmean(x['om'] for x in q[s]) for s in seeds])
    pe = med([x['price'] / x['eps'] for s in seeds for x in q[s] if 10 < x['t'] <= 20 and x['eps'] > 0])
    cap = med([q[s][-1]['price'] * q[s][-1]['shares'] / 1e9 for s in seeds if q[s]])
    nd = []
    for s in seeds:
        y = [x for x in q[s] if 19 < x['t'] <= 20]
        e = sum(x['ebitda'] for x in y)
        nd.append((y[-1]['debt'] - y[-1]['cash']) / e if len(y) >= 4 and e > 0 else nan)
    worst = [max((ORDER.index(x['rating']) for x in q[s] if x['rating'] in ORDER), default=0) for s in seeds]
    ev = [s for s in seeds if 'SKYL' in (R[a, s]['defaults'] or {}) or 'SKYL' in R[a, s]['dead'] or any(e['t'] == 'SKYL' for e in R[a, s]['reorgs'])]
    sales = sum(1 for s in seeds for d in G[a, s].get('deals', []) if d['tk'] == 'SKYL' and ' sold its ' in d['d'] and ' business for ' in d['d'] and 'division' not in d['d'])
    print(f'{a}: revenue y1 {med([rev(s, 1) for s in seeds]) / 1e9:.1f} bn, y20 {med([rev(s, 20) for s in seeds]) / 1e9:.1f} bn; real growth/yr y1-10 {g10:+.3f}, y10-20 {g20:+.3f}; '
          f'op margin {om:.3f}; P/E y10-20 {pe:.1f}; cap y20 {cap:.0f} bn; ND/EBITDA y20 {med(nd):.2f}; worst rating by seed {[ORDER[i] for i in worst]}; '
          f'default/reorg/death seeds {ev}; whole-segment sales {sales}')

print('\n### 3. Telecom peers: year | LOON price level A / B | SKUA price level A / B | LOON rev bn A / B | SKUA rev bn A / B | internet record share of telecom revenue (B)')
for y in YRS:
    cells = []
    for tk in ('LOON', 'SKUA'):
        cells.append(' / '.join(f"{med([med([row[4] for row in K[a, s]['rows'] if row[1] == tk and y - 1 < row[0] <= y]) for s in seeds]):.3f}" for a in (A, B)))
    for tk in ('LOON', 'SKUA'):
        cells.append(' / '.join(f"{med([sum(x['rev'] for x in R[a, s]['final'][tk]['q'] if y - 1 < x['t'] <= y) for s in seeds]) / 1e9:.1f}" for a in (A, B)))
    share = []
    for s in seeds:
        w = win(sk(B, s), y)
        if w and SEG in w[-1]['recs']:
            own = w[-1]['recs'][SEG]['rev']
            peers = sum(sum(x['rev'] for x in R[B, s]['final'][tk]['q'] if y - 1 < x['t'] <= y) for tk in ('LOON', 'SKUA'))
            share.append(own / (own + peers) if own + peers > 0 else nan)
    print(f'y{y} | ' + ' | '.join(cells) + f' | {med(share):.3f}')

print(f'\n### 4. Identities, every SKYL report in {B}')
n = e1 = e2 = nopath = early = 0
for s in seeds:
    t0 = min([r['t'] for r in sk(B, s) if r['kappa'].get(SEG, 0) > 0] or [99])
    for r in sk(B, s):
        if not r['kappa']:
            continue
        n += 1
        e1 = max(e1, abs(sum(r['kappa'].values()) - 1))
        if r['rev'] > 0:
            e2 = max(e2, abs(sum(v['rev'] for v in r['recs'].values()) - 4 * r['rev']) / (4 * r['rev']))
        if r['t'] >= t0 and (r['recs'].get(SEG, {}).get('path') is None or r['recs'].get(SEG, {}).get('apath') is None):
            nopath += 1
        if r['t'] < t0 and SEG in r['kappa']:
            early += 1
print(f'{n} reports: max |sum kappa - 1| {e1:.1e}; max |main + internet record revenue - 4 rev| / 4 rev {e2:.1e}; reports after entry without path/anchor_path {nopath}; '
      f'reports before entry with an internet kappa key {early}')

print(f'\n### 5. Board, {B} - {A} (n={len(seeds)}, descriptive)')
for name, f in (('cap y20 tn', lambda r: sum(v['q'][-1]['price'] * v['q'][-1]['shares'] for v in r['final'].values() if v['q'] and not v['dead']) / 1e12),
                ('defaults+deaths', lambda r: len(r['defaults']) + len(r['dead'])),
                ('B-or-worse share', lambda r: sum(1 for v in r['final'].values() for x in v['q'] if x['rating'] in BAD) / sum(len(v['q']) for v in r['final'].values())),
                ('median P/E y10-20', lambda r: st.median([x['price'] / x['eps'] for v in r['final'].values() for x in v['q'] if 10 < x['t'] <= 20 and x['eps'] > 0]))):
    a = [f(R[A, s]) for s in seeds]
    b = [f(R[B, s]) for s in seeds]
    print(f'{name} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)}')
