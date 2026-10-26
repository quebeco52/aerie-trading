#!/usr/bin/env python3
"""hard2.py <seeds> ARM [YEARS]: anomaly scan of the segment machinery over a long run (default 40 years).
Reads runs/<arm>-<seed>.{seg.json,jsonl,idle.json}: plan-segment rows, venture rows (every firm holding an x: key),
deals, the probe's orphan / bad-value logs, the end-of-run ledger scan (slashDetail), quarterly GDP index. Prints
counts and up to 3 examples (ticker, seed, year) per check. Prints the final tables only.
"""
import json, math, os, re, statistics as st, sys
from collections import Counter, defaultdict

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
ARM = sys.argv[2]
YEARS = float(sys.argv[3]) if len(sys.argv) > 3 else 40.0
SEGMENTED = set(re.findall(r"'([A-Z]{3,5})' => \['main' =>", open(os.path.join(H, '..', '..', '..', 'src', 'Data', 'Company', 'Segments.php')).read()))
nan = float('nan')


def ex(lst, k=3):
    return '; '.join(str(x) for x in lst[:k])


def med(xs):
    xs = [x for x in xs if x is not None and x == x]
    return st.median(xs) if xs else nan


D = {}
for s in seeds:
    g = json.load(open(f'{H}/runs/{ARM}-{s}.seg.json'))
    for row in g['rows'] + g.get('ventures', []):
        for key in ('kappa', 'recs', 'targets', 'segrev', 'smar', 'vk', 'streams', 'xrec'):
            if key in row:
                row[key] = row.get(key) or {}
    D[s] = (g, json.loads(open(f'{H}/runs/{ARM}-{s}.jsonl').read().splitlines()[-1]), json.load(open(f'{H}/runs/{ARM}-{s}.idle.json'))['rows'])

# merged per-report view: (seed, tk, t) -> kappa, record revenue, report revenue, line shares
rep = {}
for s in seeds:
    g = D[s][0]
    for row in g['rows']:
        if row['kappa']:
            e = rep.setdefault((s, row['tk'], round(row['t'], 2)), {'kappa': row['kappa'], 'rec': {}, 'rev': row['rev'], 'lines': row['segrev'], 'tick': row.get('tick')})
            e['rec'].update({k: v['rev'] for k, v in row['recs'].items()})
    for v in g.get('ventures', []):
        if v['kappa']:
            e = rep.setdefault((s, v['tk'], round(v['t'], 2)), {'kappa': v['kappa'], 'rec': {}, 'rev': None, 'lines': v['streams']})
            e['kappa'] = v['kappa']
            e['rec'].update({k: x['rev'] for k, x in v['xrec'].items() if x})
            e['vrev'] = v['rev']
            e['vlines'] = v['streams']

print(f'## Anomaly scan, {ARM}, seeds {seeds}, {YEARS:.0f} years')
print('\n### Identities')
k1 = [(s, tk, t, round(sum(e['kappa'].values()), 6)) for (s, tk, t), e in rep.items() if abs(sum(e['kappa'].values()) - 1) > 1e-9]
print(f'sum kappa != 1: {len(k1)} of {len(rep)} {ex(k1)}')
k2 = []
n2 = 0
for (s, tk, t), e in rep.items():
    if e.get('rev') and e['rec'] and all(v is not None for v in e['rec'].values()):
        n2 += 1
        d = abs(sum(e['rec'].values()) - 4 * e['rev']) / (4 * e['rev'])
        if d > 1e-6:
            k2.append((s, tk, t, round(d, 4)))
print(f'sum of record revenue != 4 x report revenue (reports with plan-segment rows): {len(k2)} of {n2} {ex(k2)}')
k3 = []
n3 = 0
for s in seeds:
    for v in D[s][0].get('ventures', []):
        if v['rev'] > 0:
            n3 += 1
            d = abs(sum(v['streams'].values()) - v['rev']) / v['rev']
            if d > 1e-9:
                k3.append((s, v['tk'], v['t'], round(d, 6)))
print(f'entered-line carve != actual revenue: {len(k3)} of {n3} {ex(k3)}')
cs = []
for s in seeds:
    prev = {}
    for row in D[s][0]['rows']:
        p = prev.get(row['tk'])
        prev[row['tk']] = row
        if not p or not row['kappa'] or not p.get('targets'):
            continue
        on = {k: v for k, v in row['recs'].items() if row['kappa'].get(k, 0) > 0 and v['cap'] > 0}
        tot = sum(v['cap'] for v in on.values())
        tg = {k: p['targets'].get(k, 0) for k in on}
        if len(on) > 1 and tot > 0 and sum(tg.values()) > 0:
            cs.append(max(abs(v['cap'] / tot - tg[k] / sum(tg.values())) for k, v in on.items()))
cs.sort()
print(f'record capacity split vs previous targets (plan segments): median {med(cs):.4f}, p99 {cs[int(.99 * len(cs))] if cs else nan:.4f}, max {max(cs, default=nan):.4f} (n {len(cs)})')
bad = [(s,) + tuple(b) for s in seeds for b in D[s][0].get('bad', [])]
print(f'negative or non-finite plant / pending / goodwill: {len(bad)} {ex(bad)}')

print('\n### Orphan state (carried key for a segment with no plant and no pending venture)')
orph = Counter()
oex = defaultdict(list)
for s in seeds:
    for key, (t, n) in D[s][0].get('orphans', {}).items():
        tk, kind, seg = key.split('|', 2)
        orph[kind] += 1
        oex[kind].append((tk, s, t, seg, n))
for kind, n in orph.most_common():
    print(f'{kind}: {n} segment-firms; e.g. ' + ex(sorted(oex[kind], key=lambda x: -x[4])))
sd = [(s,) + tuple(x) for s in seeds for x in D[s][0].get('slashDetail', [])]
dead_cap = [x for x in sd if x[3] > 0 and (x[6] is None or x[6] <= 0)]
print(f'ledger records of segments holding no plant: {sum(1 for x in sd if x[6] is None or x[6] <= 0)} of {len(sd)}; with capacity > 0: {len(dead_cap)}; '
      f'of those written within the last year of the run: {sum(1 for x in dead_cap if x[5] >= (YEARS - 1) * 360)}; e.g. ' + ex([(x[2], x[0], round(x[3] / 1e9, 2), x[5]) for x in dead_cap]))

print('\n### Weird outcomes')
big = defaultdict(list)
for (s, tk, t), e in rep.items():
    for lines in (e.get('lines') or {}, e.get('vlines') or {}):
        tot = sum(lines.values())
        for k, v in lines.items():
            if tot > 0 and k != 'main' and v / tot > 0.5:
                big[s, tk, k].append(t)
print(f'segment above 50% of its company\'s revenue: {len(big)} segment-firms {ex([(k[1], k[0], min(v), k[2], len(v)) for k, v in big.items()])}')
bigs = defaultdict(list)
for s in seeds:
    for v in D[s][0].get('ventures', []):
        for k, x in v['xrec'].items():
            if x and (x.get('as') or 0) > 0.15 and v['kappa'].get(k, 0) > 0:
                bigs[s, v['tk'], k].append(v['t'])
print(f'entered segment above 15% of its industry: {len(bigs)} {ex([(k[1], k[0], min(t), k[2], len(t)) for k, t in bigs.items()])}')
crowd = []
for s in seeds:
    byq = defaultdict(set)
    for v in D[s][0].get('ventures', []):
        for k, x in v['kappa'].items():
            if k.startswith('x:') and x > 0:
                byq[k[2:], round(v['t'] * 4)].add(v['tk'])
    worst = defaultdict(int)
    first = {}
    for (ind, qn), tks in byq.items():
        if len(tks) >= 3:
            worst[ind] = max(worst[ind], len(tks))
            first.setdefault(ind, (qn / 4, sorted(tks)))
    crowd += [(s, ind, worst[ind], first[ind]) for ind in worst]
print(f'industry with 3+ entrants at once: {len(crowd)} {ex(crowd)}')
reent = []
for s in seeds:
    g = D[s][0]
    ent = defaultdict(list)
    for v in g.get('ventures', []):
        if v.get('entry'):
            ent[v['tk'], v['entry']['industry']].append(v['t'])
    for d in g['deals']:
        if ', taking it into ' in d['d']:
            for k in d['kappa']:
                if k.startswith('x:'):
                    prev = [v for v in g.get('ventures', []) if v['tk'] == d['tk'] and v['t'] < d['t']]
                    if not prev or k not in prev[-1]['kappa']:
                        ent[d['tk'], k[2:]].append(d['t'])
    reent += [(tk, s, ind, sorted(ts)) for (tk, ind), ts in ent.items() if len(ts) >= 2]
print(f'same company entering an industry twice (exit in between): {len(reent)} {ex(reent)}')
# segment margin vs listed peers' median op margin
odd = []
for s in seeds:
    r = D[s][1]['final']
    ind_of = {row[1]: row[2] for row in D[s][2]}
    peer = defaultdict(dict)
    for tk, v in r.items():
        for x in v['q']:
            peer[ind_of.get(tk), round(x['t'] * 4)].setdefault(tk, x['om'])
    runs = defaultdict(int)
    flagged = set()
    for v in D[s][0].get('ventures', []):
        for k, m in v['vk'].items():
            if not k.startswith('carry:segment_margin:x:'):
                continue
            seg = k.split('margin:')[1]
            pm = list(peer.get((seg[2:], round(v['t'] * 4)), {}).values())
            if len(pm) < 1 or v['kappa'].get(seg, 0) <= 0:
                continue
            pmed = st.median(pm)
            out = pmed > 0 and not (0.2 * pmed <= m <= 3 * pmed)
            runs[v['tk'], seg] = runs[v['tk'], seg] + 1 if out else 0
            if runs[v['tk'], seg] >= 8 and (v['tk'], seg) not in flagged:
                flagged.add((v['tk'], seg))
                odd.append((v['tk'], s, v['t'], seg, round(m, 3), round(pmed, 3)))
print(f'entered segment margin outside [0.2x, 3x] its listed peers for 8+ quarters: {len(odd)} {ex(odd)}')
pend = []
for s in seeds:
    starts = {}
    for v in D[s][0].get('ventures', []):
        if v.get('entry'):
            starts[v['tk'], v['entry']['segment']] = v['t']
        for k, x in v['vk'].items():
            if k.startswith('carry:segment_pending:') and x > 0:
                seg = k.split('pending:')[1]
                t0 = starts.get((v['tk'], seg))
                if t0 is not None and v['t'] - t0 > 3:
                    pend.append((v['tk'], s, v['t'], seg))
print(f'venture still pending after 3 years: {len({(p[0], p[1], p[3]) for p in pend})} {ex(pend)}')
drift = []
for s in seeds:
    g, r, _ = D[s]
    gd = lambda t0, t1: st.fmean([m[1] for m in g['macro'] if t0 < m[0] <= t1] or [nan])
    for tk, v in r['final'].items():
        r0 = sum(x['rev'] for x in v['q'] if x['t'] <= 1)
        r1 = sum(x['rev'] for x in v['q'] if YEARS - 1 < x['t'] <= YEARS)
        if r0 > 0 and r1 > 0:
            ratio = (r1 / gd(YEARS - 1, YEARS)) / (r0 / gd(0, 1))
            if ratio > 3 or ratio < 1 / 3:
                drift.append((tk, s, round(ratio, 2), 'segmented' if tk in SEGMENTED or any(vv['tk'] == tk for vv in g.get('ventures', [])) else 'one-plant'))
print(f'revenue / GDP drifted more than 3x from the opening: {len(drift)} firm-seeds ({sum(1 for d in drift if d[3] == "segmented")} segmented or entrants) {ex(sorted(drift, key=lambda d: -abs(math.log(d[2]))), 6)}')
abp = []
for s in seeds:
    seen = set()
    for v in D[s][0].get('ventures', []):
        for k in v['vk']:
            if k.startswith('carry:segment_venture_abandoned:'):
                seg = k.split('abandoned:')[1]
                if (v['tk'], seg) in seen:
                    continue
                seen.add((v['tk'], seg))
                m = v['vk'].get(f'carry:segment_margin:{seg}', 0)
                L = v['vk'].get(f'carry:segment_learning:{seg}', 0)
                if m * (1 - L) > 0:
                    abp.append((v['tk'], s, v['t'], seg, round(m * (1 - L), 3)))
print(f'ventures abandoned while their operating margin was positive: {len(abp)} {ex(abp)}')
quick = []
for s in seeds:
    g = D[s][0]
    for d in g['deals']:
        if ', taking it into ' in d['d']:
            segs = [k for k in d['kappa'] if k.startswith('x:')]
            for e in g['deals']:
                if e['tk'] == d['tk'] and ' sold its ' in e['d'] and 0 < e['t'] - d['t'] <= 1.0 and any(e['kappa'].get(k, 1) == 0 for k in segs):
                    quick.append((d['tk'], s, d['t'], round(e['t'] - d['t'], 2)))
print(f'bought segments sold within a year: {len(quick)} {ex(quick)}')
jumps = []
for s in seeds:
    g = D[s][0]
    dealt = defaultdict(list)
    for d in g['deals']:
        dealt[d['tk']].append(d['t'])
    series = defaultdict(list)
    for (ss, tk, t), e in sorted(rep.items()):
        if ss == s:
            series[tk].append((t, e['kappa']))
    for tk, xs in series.items():
        for (t0, k0), (t1, k1) in zip(xs, xs[1:]):
            if any(t0 < d <= t1 + 0.01 for d in dealt[tk]):
                continue
            for k, v0 in k0.items():
                v1 = k1.get(k, 0.0)
                if v0 > 0.01 and abs(v1 - v0) / v0 > 0.2:
                    jumps.append((tk, s, t1, k, round(v0, 3), round(v1, 3)))
print(f'segment plant share jumping > 20% in a quarter with no deal: {len(jumps)} {ex(jumps)}')

print('\n### Long-run drift')
dec = [10, 20, 30, 40][: int(YEARS // 10)]
ipl = defaultdict(lambda: defaultdict(list))
for s in seeds:
    for row in D[s][2]:
        if row[4] is not None:
            for y in dec:
                if y - 1 < row[0] <= y:
                    ipl[row[2]][y].append(row[4])
lev = {ind: [med(v[y]) for y in dec] for ind, v in ipl.items()}
allv = {y: [lev[i][j] for i in lev if lev[i][j] == lev[i][j]] for j, y in enumerate(dec)}
print('industry price level, median over industries [min, max]: ' + '; '.join(f'y{y} {med(allv[y]):.3f} [{min(allv[y]):.3f}, {max(allv[y]):.3f}]' for y in dec))
movers = sorted(lev.items(), key=lambda kv: -abs((kv[1][-1] if kv[1][-1] == kv[1][-1] else 1) - 1))[:6]
print('furthest from 1 at the last decade: ' + '; '.join(f'{i} ' + '/'.join(f'{x:.3f}' for x in v) for i, v in movers))
cnt = {y: Counter() for y in dec}
for s in seeds:
    g = D[s][0]
    for y in dec:
        held = {}
        for row in g['rows']:
            if y - 0.26 < row['t'] <= y and row['kappa']:
                held[row['tk']] = row['kappa']
        for v in g.get('ventures', []):
            if y - 0.26 < v['t'] <= y and v['kappa']:
                held[v['tk']] = (v['kappa'], v['vk'])
        for tk, h in held.items():
            kap, vk = (h if isinstance(h, tuple) else (h, {}))
            for k, x in kap.items():
                if x <= 0 or k == 'main':
                    continue
                if not k.startswith('x:'):
                    cnt[y]['plan segments'] += 1
                elif f'carry:segment_venture_start:{k}' in vk:
                    cnt[y]['built ventures'] += 1
                else:
                    cnt[y]['bought segments'] += 1
print('held per seed (mean): ' + '; '.join(f'y{y} ' + ', '.join(f'{k} {v / len(seeds):.1f}' for k, v in sorted(cnt[y].items())) for y in dec))
gwr = []
for s in seeds:
    last = {}
    for v in D[s][0].get('ventures', []):
        if v['t'] > YEARS - 0.3:
            last[v['tk']] = v
    for tk, v in last.items():
        tr = sum(x for k, x in v['vk'].items() if k.startswith('carry:segment_goodwill:'))
        if v.get('gw'):
            gwr.append((tr / v['gw'], tk, s, round(tr / 1e9, 2), round(v['gw'] / 1e9, 2)))
print(f'entered segments\' goodwill / company goodwill at the end: median {med([x[0] for x in gwr]):.3f}, max {max([x[0] for x in gwr], default=nan):.3f}; '
      f'above 1: {sum(1 for x in gwr if x[0] > 1 + 1e-9)}; e.g. ' + ex(sorted(gwr, reverse=True)))
