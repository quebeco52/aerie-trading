#!/usr/bin/env python3
"""div2.py <seeds> A B: market entry (5b-2), B vs A. Reads the VENTURE rows ('ventures' in runs/<arm>-<seed>.seg.json:
t, tk, entry, vk (venture momentum keys), kappa, segs (price/load), streams, rev, drag (cost share), cip, cap, style,
industry), the CAP_PROBE rows and the idle rows. Prints the final tables only.
"""
import json, math, os, statistics as st, sys
from collections import Counter, defaultdict
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = sys.argv[2], sys.argv[3]
YEARS = 20
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
        k = json.load(open(f'{H}/runs/{a}-{s}.idle.json'))
        D[a, s] = (r, g, k)

entries = []
for s in seeds:
    V = D[B, s][1]['ventures']
    for row in V:
        if row['entry']:
            e = row['entry']
            entries.append({'s': s, 't': row['t'], 'tk': row['tk'], 'home': row['industry'], 'target': e['industry'], 'seg': e['segment'],
                            'close': e['closeness'], 'capex': e['capex'], 'cap': row['cap'], 'style': row['style'],
                            'path': [x for x in V if x['tk'] == row['tk'] and x['t'] >= row['t'] and any(e['segment'] in k for k in x['vk'])]})
print(f'## Market entry, {B} vs {A}, n={len(seeds)} x {YEARS} years')
per = Counter(e['s'] for e in entries)
print(f'\n### 1. Entries: {len(entries)} total; per seed {[per[s] for s in seeds]}; {len(entries) / len(seeds) / YEARS:.2f} per year; distinct companies {len({e["tk"] for e in entries})}')
print('by closeness: ' + ', '.join(f'{c}: {n}' for c, n in sorted(Counter(e['close'] for e in entries).items())) + '; by style: ' + ', '.join(f'{c}: {n}' for c, n in sorted(Counter(e['style'] for e in entries).items())))
print('targets: ' + ', '.join(f'{c} {n}' for c, n in Counter(e['target'] for e in entries).most_common(10)))
print('seed year ticker | home -> target | closeness | capex bn | capex/cap | style')
for e in entries:
    print(f"s{e['s']} y{e['t']:.1f} {e['tk']} | {e['home']} -> {e['target']} | {e['close']} | {e['capex'] / 1e9:.2f} | {e['capex'] / e['cap'] if e['cap'] else nan:.4f} | {e['style']}")

print('\n### 2. Venture paths: kappa / revenue share at +1/+2/+4/+8 y; drag at +1 y; years to learning < 1; builds at +4/+8; kappa y20 or year ~0')
def at(path, t0, dy):
    w = [x for x in path if t0 + dy - 0.13 <= x['t'] <= t0 + dy + 0.13]
    return w[0] if w else None
agg = defaultdict(list)
for e in entries:
    seg, cells = e['seg'], []
    for dy in (1, 2, 4, 8):
        x = at(e['path'], e['t'], dy)
        if x is None:
            cells.append('  -  ')
            continue
        tot = sum(x['streams'].values())
        k, sh = x['kappa'].get(seg, 0.0), (x['streams'].get(seg, 0.0) / tot if tot else nan)
        agg['kappa', dy].append(k)
        agg['share', dy].append(sh)
        cells.append(f'{k:.3f}/{sh:.3f}')
    x1 = at(e['path'], e['t'], 1)
    drag = x1['drag'] if x1 else nan
    agg['drag'].append(drag)
    learn = [(x['t'], x['vk'].get(f'carry:segment_learning:{seg}')) for x in e['path']]
    pos = next((t - e['t'] for t, l in learn if l is not None and l < 1.0), None)
    gone = next((t - e['t'] for t, l in learn if l is None and t > e['t'] + 0.3 and f'carry:segment_pending:{seg}' not in str(learn)), None)
    if pos is not None:
        agg['pos'].append(pos)
    b = [at(e['path'], e['t'], dy) for dy in (4, 8)]
    bl = [x['vk'].get(f'carry:segment_builds:{seg}') if x else None for x in b]
    last = e['path'][-1] if e['path'] else None
    end = f"kappa {last['kappa'].get(seg, 0.0):.3f} at y{last['t']:.1f}" if last else 'no path'
    print(f"s{e['s']} {e['tk']}->{e['target'][:24]} | " + ' | '.join(cells) + f" | drag {drag:.4f} | learning<1 after {pos if pos is None else round(pos, 1)} y | builds +4/+8 {bl} | {end}")
print('medians: ' + ', '.join(f"kappa +{dy} {med(agg['kappa', dy]):.3f} share {med(agg['share', dy]):.3f}" for dy in (1, 2, 4, 8))
      + f"; drag +1 {med(agg['drag']):.4f}; years to learning<1 {med(agg['pos']):.1f} (reached by {len(agg['pos'])} of {len(entries)})")

print('\n### 3. Target industry price: incumbents\' industry_price_level, 4 quarters before vs quarters 4-12 after entry; venture price')
pre, post, vp = [], [], []
for e in entries:
    rows = [row for row in D[B, e['s']][2]['rows'] if row[2] == e['target'] and row[4] is not None and row[1] != e['tk']]
    b = [row[4] for row in rows if e['t'] - 1.0 <= row[0] < e['t']]
    a_ = [row[4] for row in rows if e['t'] + 1.0 <= row[0] <= e['t'] + 3.0]
    # same windows in A (no entry) as the control
    rA = [row for row in D[A, e['s']][2]['rows'] if row[2] == e['target'] and row[4] is not None and row[1] != e['tk']]
    bA = [row[4] for row in rA if e['t'] - 1.0 <= row[0] < e['t']]
    aA = [row[4] for row in rA if e['t'] + 1.0 <= row[0] <= e['t'] + 3.0]
    if b and a_ and bA and aA:
        pre.append((st.fmean(a_) - st.fmean(b)) - (st.fmean(aA) - st.fmean(bA)))
        post.append(st.fmean(a_) - st.fmean(b))
    vp += [x['segs'].get(e['seg'], {}).get('price') for x in e['path'] if e['t'] + 1.0 <= x['t'] <= e['t'] + 3.0]
print(f'entries with listed incumbents {len(pre)} of {len(entries)}: incumbent price change after - before median {med(post):+.4f}; '
      f'difference-in-difference vs {A} median {med(pre):+.4f}, mean {st.fmean(pre) if pre else nan:+.4f}; venture price q4-12 median {med(vp):.4f}')

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
    return {'cap y20 tn': sum(v['q'][-1]['price'] * v['q'][-1]['shares'] for v in fin.values() if v['q'] and not v['dead']) / 1e12,
            'defaults+deaths': len(r['defaults']) + len(r['dead']),
            'B-or-worse share': sum(1 for x in allq if x['rating'] in BAD) / len(allq),
            'median P/E y10-20': st.median([x['price'] / x['eps'] for x in allq if 10 < x['t'] <= 20 and x['eps'] > 0]),
            'median split-adj vol': st.median([math.sqrt(a_ / n_ * k['tpy']) for tk, (a_, n_, c, _) in k['rv'].items() if n_ >= k['tpy'] and not fin[tk]['dead']]),
            'real revenue growth /yr y1-20': (math.log(r1 / r0) - math.log(cpi(20) / cpi(1))) / 19,
            'net PP&E growth /yr (nominal)': math.log(ppe1 / ppe0) / 19.75 if ppe0 > 0 and ppe1 > 0 else nan,
            'capex / revenue': sum(row[6] + row[5] for row in caps) / sum(row[9] for row in caps),
            'reports': len(caps), 'companies': len({row[1] for row in caps})}
bA = {s: board(A, s) for s in seeds}
bB = {s: board(B, s) for s in seeds}
for key in bA[seeds[0]]:
    a, b = [bA[s][key] for s in seeds], [bB[s][key] for s in seeds]
    print(f'{key} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)}')

print(f'\n### 5. Identities ({B}, venture rows)')
n = e1 = e2 = e3 = 0
m1 = m2 = 0.0
over = []
holders = set()
for s in seeds:
    for x in D[B, s][1]['ventures']:
        n += 1
        holders.add((s, x['tk']))
        if x['rev'] > 0:
            m1 = max(m1, abs(sum(x['streams'].values()) - x['rev']) / x['rev'])
        if x['kappa']:
            m2 = max(m2, abs(sum(x['kappa'].values()) - 1))
        pend = sum(v for k, v in x['vk'].items() if 'segment_pending' in k)
        if pend > x['cip'] * (1 + 1e-9) + 1.0:
            e3 += 1
            over.append((s, x['tk'], x['t'], pend / 1e9, x['cip'] / 1e9))
print(f'{n} venture rows over {len(holders)} firm-seeds: max |sum streamRevenue - actualRevenue| / revenue {m1:.1e}; max |sum kappa - 1| {m2:.1e}; pending > CIP balance in {e3} rows {over[:4]}')
ent = {(e['s'], e['tk']) for e in entries}
print(f'firm-seeds with venture keys but no entry: {len(holders - ent)} {sorted(holders - ent)[:6]}')
for a in (A, B):
    leak = sum(D[a, s][1]['leaks'].get('venture', 0) for s in seeds)
    print(f'{a}: venture keys counted by the segment probe {leak}; reports {sum(bA[s]["reports"] if a == A else bB[s]["reports"] for s in seeds)}; companies per seed {[ (bA if a == A else bB)[s]["companies"] for s in seeds]}')
