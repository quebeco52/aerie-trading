#!/usr/bin/env python3
"""hard4.py <seeds> YEARS ARM0 ARM1 [ARM2...]: long-game check of segment fixes; board paired against ARM0; entry,
exits, sales and realism per arm. Reads runs/<arm>-<seed>.{jsonl,idle.json,seg.json}. Prints the final tables only.

Closeness: build entries carry it; buys are resolved against any held industry (IndustryAdjacency, Segments.php).
Exits: a built or bought x: segment abandoned (key) or sold whole (a 'sold its' event after which its kappa is 0).
Years on the block: sale time minus the carry:segment_for_sale stamp. Sale price over cost: buys against the price
paid, builds against the entry capex. Entrants' industry share: x: ledger records' revenue over the industry's at the
run's end (records with revenue in the last year).
"""
import json, math, os, re, statistics as st, sys
from collections import Counter, defaultdict
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(H, '..', '..', '..', 'src', 'Data', 'Company')
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
Y = float(sys.argv[2])
ARMS = sys.argv[3:]
ORDER = ['AAA', 'AA+', 'AA', 'AA-', 'A+', 'A', 'A-', 'BBB+', 'BBB', 'BBB-', 'BB+', 'BB', 'BB-', 'B+', 'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D']
BAD = set(ORDER[ORDER.index('B'):])
nan = float('nan')
LEVEL = {'CLOSE': 0.8, 'RELATED': 0.5, 'DISTANT': 0.25}
CLOSE = {}
for m in re.finditer(r"\[\s*'([^']+)'\s*,\s*'([^']+)'\s*,\s*self::([A-Z]+)\s*\]", open(os.path.join(SRC, 'IndustryAdjacency.php')).read()):
    for pair in ((m.group(1), m.group(2)), (m.group(2), m.group(1))):
        CLOSE[pair] = max(CLOSE.get(pair, 0.0), LEVEL[m.group(3)])
seg_src = open(os.path.join(SRC, 'Segments.php')).read()
heads = [(m.start(), m.group(1)) for m in re.finditer(r"'([A-Z]{3,5})' => \['main' =>", seg_src)]
PLAN = defaultdict(set)
for (i, tk), (j, _) in zip(heads, heads[1:] + [(len(seg_src), '')]):
    PLAN[tk].update(re.findall(r"'industry' => '([^']+)'", seg_src[i:j]))


def med(xs):
    xs = [x for x in xs if x is not None and x == x]
    return st.median(xs) if xs else nan


def q(xs, p):
    xs = sorted(x for x in xs if x is not None and x == x)
    return xs[min(len(xs) - 1, int(p * len(xs)))] if xs else nan


def money(pat, txt):
    m = re.search(pat, txt)
    return float(m.group(1).replace(',', '')) * {'B': 1e9, 'M': 1e6, 'T': 1e12, 'K': 1e3, '': 1.0}[m.group(2)] if m else None


D = {}
for a in ARMS:
    for s in seeds:
        g = json.load(open(f'{H}/runs/{a}-{s}.seg.json'))
        for v in g.get('ventures', []):
            for k in ('kappa', 'vk', 'streams', 'xrec'):
                v[k] = v.get(k) or {}
        D[a, s] = (json.loads(open(f'{H}/runs/{a}-{s}.jsonl').read().splitlines()[-1]), json.load(open(f'{H}/runs/{a}-{s}.idle.json')), g)


def board(a, s):
    r, k, g = D[a, s]
    fin = r['final']
    allq = [x for v in fin.values() for x in v['q']]
    rows = k['rows']
    cpi = lambda y: med([row[5] for row in rows if y - 1 < row[0] <= y])
    rev = lambda y: sum(x['rev'] for x in allq if y - 1 < x['t'] <= y)
    caps = g['cap']
    ppe = lambda t0, t1: sum(row[10] for row in caps if t0 < row[0] <= t1)
    ipl = [row[4] for row in rows if row[4] is not None]
    cap_at = lambda y: sum(x['price'] * x['shares'] for v in fin.values() for x in [next((z for z in reversed(v['q']) if z['t'] <= y), None)] if x and not (v['dead'] and v['q'][-1]['t'] < y)) / 1e12
    return {
        'cap y20 tn': cap_at(20), 'cap y40 tn': cap_at(40),
        f'median P/E y10-{Y:.0f}': st.median([x['price'] / x['eps'] for x in allq if 10 < x['t'] and x['eps'] > 0]),
        'median split-adj vol': st.median([math.sqrt(a_ / n_ * k['tpy']) for tk, (a_, n_, c_, _) in k['rv'].items() if n_ >= k['tpy'] and not fin[tk]['dead']]),
        'defaults+deaths': len(r['defaults']) + len(r['dead']),
        'B-or-worse share': sum(1 for x in allq if x['rating'] in BAD) / len(allq),
        'real revenue growth /yr': (math.log(rev(Y) / rev(1)) - math.log(cpi(Y) / cpi(1))) / (Y - 1),
        'capex / revenue': sum((row[6] or 0) + row[5] for row in caps) / sum(row[9] for row in caps),
        'net PP&E growth /yr': math.log(ppe(Y - 0.26, Y) / ppe(0, 0.26)) / (Y - 0.25),
        'glut share (< 0.95)': sum(1 for x in ipl if x < 0.95) / len(ipl),
        'shortage share (> 1.05)': sum(1 for x in ipl if x > 1.05) / len(ipl),
    }


print(f'## Segment fixes over long games: {", ".join(ARMS)} (n={len(seeds)}, {Y:.0f} years, live macro)')
print(f'\n### 1. Board, paired against {ARMS[0]}')
B = {(a, s): board(a, s) for a in ARMS for s in seeds}
print('quantity | ' + ' | '.join(ARMS) + ' | ' + ' | '.join(f'{a} - {ARMS[0]}' for a in ARMS[1:]))
for key in B[ARMS[0], seeds[0]]:
    means = [st.fmean(B[a, s][key] for s in seeds) for a in ARMS]
    diffs = [fmt(paired([B[ARMS[0], s][key] for s in seeds], [B[a, s][key] for s in seeds]), 4) for a in ARMS[1:]]
    print(f'{key} | ' + ' | '.join(f'{m:.4g}' for m in means) + ' | ' + ' | '.join(diffs))


def entries(a, s):
    r, k, g = D[a, s]
    V = g.get('ventures', [])
    out = []
    for v in V:
        if v.get('entry'):
            out.append({'kind': 'build', 's': s, 'tk': v['tk'], 't': v['t'], 'seg': v['entry']['segment'], 'close': v['entry']['closeness'], 'cost': v['entry']['capex']})
    for d in g['deals']:
        if ', taking it into ' in d['d']:
            prev = [x for x in V if x['tk'] == d['tk'] and x['t'] < d['t']]
            before = set(prev[-1]['kappa']) if prev and d['t'] - prev[-1]['t'] < 0.3 else set()
            new = [kk for kk in d['kappa'] if kk.startswith('x:') and kk not in before] or [kk for kk in d['kappa'] if kk.startswith('x:')]
            seg = new[-1] if new else None
            held = {d['industry']} | PLAN[d['tk']] | {kk[2:] for kk in before if kk.startswith('x:')}
            c = max([CLOSE.get((h, seg[2:]), 0.0) for h in held] or [0.0]) if seg else 0.0
            out.append({'kind': 'buy', 's': s, 'tk': d['tk'], 't': d['t'], 'seg': seg, 'close': c, 'cost': money(r'executed a \$([\d.,]+)\s*([BMTK]?)', d['d'])})
    for e in out:
        e['exit'] = None
        path = [x for x in V if x['tk'] == e['tk'] and x['t'] >= e['t']]
        ab = next((x['t'] for x in path if f'carry:segment_venture_abandoned:{e["seg"]}' in x['vk']), None)
        stamp = next((x['vk'][f'carry:segment_for_sale:{e["seg"]}'] for x in path if f'carry:segment_for_sale:{e["seg"]}' in x['vk']), None)
        sale = next((d for d in g['deals'] if d['tk'] == e['tk'] and ' sold its ' in d['d'] and d['t'] > e['t'] and e['seg'] and d['kappa'].get(e['seg'], 0.0) == 0.0
                     and any(x['kappa'].get(e['seg'], 0) > 0 for x in path if x['t'] < d['t'])), None)
        if sale:
            e['exit'] = ('sold', sale['t'] - e['t'])
            e['sale'] = sale
            e['block'] = sale['t'] - stamp if isinstance(stamp, (int, float)) and stamp > 0 else None
        elif ab is not None:
            e['exit'] = ('abandoned', ab - e['t'])
        e['stamp'] = stamp
    return out


E = {a: [e for s in seeds for e in entries(a, s)] for a in ARMS}
print('\n### 2. Entry and exits')
for a in ARMS:
    es = E[a]
    print(f'{a}: ' + '; '.join(f"{kind} {sum(1 for e in es if e['kind'] == kind) / len(seeds) / Y:.3f}/yr (" + ', '.join(f"{c}: {sum(1 for e in es if e['kind'] == kind and e['close'] == c)}" for c in (0.8, 0.5, 0.25)) + ')' for kind in ('build', 'buy')))
    held = []
    for y in (10, 20, 30, 40):
        bc = Counter()
        for s in seeds:
            last = {}
            for v in D[a, s][2].get('ventures', []):
                if y - 0.26 < v['t'] <= y:
                    last[v['tk']] = v
            for v in last.values():
                for kk, x in v['kappa'].items():
                    if kk.startswith('x:') and x > 0:
                        bc['built' if f'carry:segment_venture_start:{kk}' in v['vk'] else 'bought'] += 1
        held.append(f"y{y} built {bc['built'] / len(seeds):.1f} bought {bc['bought'] / len(seeds):.1f}")
    print('   held per seed: ' + '; '.join(held))
    for kind in ('build', 'buy'):
        cells = []
        for c in (0.8, 0.5, 0.25):
            el = [e for e in es if e['kind'] == kind and e['close'] == c and Y - e['t'] >= 10]
            n_ex = Counter(e['exit'][0] for e in el if e['exit'] and e['exit'][1] <= 10)
            cells.append(f"{c}: {sum(n_ex.values())}/{len(el)} {dict(n_ex) if n_ex else ''}".rstrip())
        print(f'   exits within 10 y [{kind}]: ' + '; '.join(cells))
    sales = [e for e in es if e['exit'] and e['exit'][0] == 'sold']
    ratio = [money(r' for \$([\d.,]+)\s*([BMTK]?)', e['sale']['d']) / e['cost'] for e in sales if e['cost'] and money(r' for \$([\d.,]+)\s*([BMTK]?)', e['sale']['d'])]
    loss = sum(1 for e in sales if 'loss' in e['sale']['d'])
    pr = {'build': [], 'buy': []}
    for e in sales:
        V = D[a, e['s']][2].get('ventures', [])
        before = [x for x in V if x['tk'] == e['tk'] and x['t'] < e['sale']['t'] and x['kappa'].get(e['seg'], 0) > 0]
        sp = money(r' for \$([\d.,]+)\s*([BMTK]?)', e['sale']['d'])
        line = before[-1]['streams'].get(e['seg'], 0.0) * 4 if before else 0.0
        if sp and line > 0:
            pr[e['kind']].append(sp / line)
    print('   sale price / annual revenue of the business: ' + '; '.join(f'{k} median {med(v):.2f} p10 {q(v, .1):.2f} p90 {q(v, .9):.2f} (n {len(v)})' for k, v in pr.items()))
    print(f"   sales {len(sales)}: years on the block median {med([e.get('block') for e in sales]):.2f}; sale price / cost median {med(ratio):.2f} p10 {q(ratio, .1):.2f} p90 {q(ratio, .9):.2f}; "
          f"losses {loss}/{len(sales)}; for sale but never sold {sum(1 for e in es if isinstance(e['stamp'], (int, float)) and e['stamp'] > 0 and not (e['exit'] and e['exit'][0] == 'sold'))}")

print('\n### 3. Realism')
for a in ARMS:
    cells = []
    for y in (20, 40):
        f30 = f50 = 0
        for s in seeds:
            last = {}
            for v in D[a, s][2].get('ventures', []):
                if y - 0.26 < v['t'] <= y:
                    last[v['tk']] = v
            for v in last.values():
                tot = sum(v['streams'].values())
                xs = sum(x for kk, x in v['streams'].items() if kk.startswith('x:'))
                if tot > 0:
                    f30 += xs / tot >= 0.3
                    f50 += xs / tot >= 0.5
        cells.append(f'y{y} >=30% {f30 / len(seeds):.2f}, >=50% {f50 / len(seeds):.2f}')
    shares, single = [], []
    for s in seeds:
        g = D[a, s][2]
        tot = {i: v[1] for i, v in g.get('ind', {}).items()}
        xr = defaultdict(float)
        for ind, key, cap, rev, tick, plant in g.get('slashDetail', []):
            if '/x:' in key and rev > 0:
                xr[ind] += rev
                if tot.get(ind):
                    single.append((rev / tot[ind], key, s))
        shares += [xr[i] / tot[i] for i in xr if tot.get(i)]
    top = max(single, default=(nan, '', 0))
    rel = []
    for s_ in seeds:
        r_, k_, g_ = D[a, s_]
        ind_of = {row[1]: row[2] for row in k_['rows']}
        pm = defaultdict(list)
        for tk_, v_ in r_['final'].items():
            for x_ in v_['q']:
                pm[ind_of.get(tk_), round(x_['t'] * 4)].append(x_['om'])
        for v_ in g_.get('ventures', []):
            for kk, m_ in v_['vk'].items():
                if kk.startswith('carry:segment_margin:x:'):
                    sg = kk.split('margin:')[1]
                    p_ = pm.get((sg[2:], round(v_['t'] * 4)))
                    if p_ and v_['kappa'].get(sg, 0) > 0 and st.median(p_) > 0:
                        rel.append(m_ / st.median(p_))
    print(f'{a}: firms per seed with entered revenue ' + '; '.join(cells) + f'; entrants\' share of an entered industry at the end: median {med(shares):.3f}, max {max(shares, default=nan):.3f}; '
          f'largest single entrant {top[0]:.3f} ({top[1]}, s{top[2]}); entered margin / peers p90 {q(rel, .9):.2f}, above 2x {sum(1 for x in rel if x > 2) / max(1, len(rel)):.1%}')
