#!/usr/bin/env python3
"""cal1x.py <seeds> ARM: bought-entry risk and sales (5b-4). Outcome draw by closeness; put up for sale and sold within
10 y; years to sale; sale price / price paid; gain or loss on sale; company goodwill change at the sale vs the tracked
segment goodwill; buying again after a sale; sum of tracked goodwill <= company goodwill on every venture row.
Prints the final tables only.
"""
import json, math, os, re, statistics as st, sys
from collections import defaultdict

H = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(H, '..', '..', '..', 'src', 'Data', 'Company')
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
ARM = sys.argv[2]
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


buys, rows_all = [], {}
for s in seeds:
    g = json.load(open(f'{H}/runs/{ARM}-{s}.seg.json'))
    V = g['ventures']
    for x in V:
        for k in ('kappa', 'vk', 'streams'):
            x[k] = x.get(k) or {}
    rows_all[s] = (g, V)
    for d in g['deals']:
        if ', taking it into ' not in d['d']:
            continue
        prev = [x for x in V if x['tk'] == d['tk'] and x['t'] < d['t']]
        before = set(prev[-1]['kappa']) if prev and d['t'] - prev[-1]['t'] < 0.3 else set()
        new = [k for k in d['kappa'] if k.startswith('x:') and k not in before] or [k for k in d['kappa'] if k.startswith('x:')]
        seg = new[-1] if new else None
        held = {d['industry']} | PLAN[d['tk']] | {k[2:] for k in before if k.startswith('x:')}
        c = max([CLOSE.get((h, seg[2:]), 0.0) for h in held] or [0.0]) if seg else 0.0
        path = [x for x in V if x['tk'] == d['tk'] and x['t'] >= d['t'] and any(k.endswith(':' + seg) for k in x['vk'])] if seg else []
        out = next((x['vk'].get(f'carry:segment_venture_outcome:{seg}') for x in path if f'carry:segment_venture_outcome:{seg}' in x['vk']), None)
        fs = next((x['t'] for x in path if f'carry:segment_for_sale:{seg}' in x['vk']), None)
        sale = None
        for e in g['deals']:
            if e['tk'] == d['tk'] and ' sold its ' in e['d'] and e['t'] > d['t'] and seg and e['kappa'].get(seg, 0.0) == 0.0:
                pre = [x for x in V if x['tk'] == d['tk'] and x['t'] < e['t'] and x['kappa'].get(seg, 0) > 0]
                if pre:
                    sale = (e, pre[-1])
                    break
        buys.append({'s': s, 'd': d, 'seg': seg, 'close': c, 'out': out, 'fs': fs, 'sale': sale, 'price': money(r'executed a \$([\d.,]+)\s*([BMTK]?)', d['d']),
                     'gw': money(r'\$([\d.,]+)\s*([BMTK]?) booked as goodwill', d['d']) or 0.0, 'left': 20.0 - d['t']})

print(f'## Bought entries, {ARM}, n={len(seeds)}: {len(buys)} buys')
print('\n### outcome draw (log factor) by closeness')
for c in (0.8, 0.5, 0.25):
    o = [b['out'] for b in buys if b['close'] == c and b['out'] is not None]
    print(f'{c}: n {len(o)}, mean {st.fmean(o) if o else nan:+.3f}, median {med(o):+.3f}, p10 {q(o, .1):+.3f}, p90 {q(o, .9):+.3f}')

print('\n### put up for sale and sold within 10 y (buys with 10 y left), by closeness')
for c in ('all', 0.8, 0.5, 0.25):
    bs = [b for b in buys if (c == 'all' or b['close'] == c) and b['left'] >= 10]
    fs = sum(1 for b in bs if b['fs'] is not None and b['fs'] - b['d']['t'] <= 10)
    sold = sum(1 for b in bs if b['sale'] and b['sale'][0]['t'] - b['d']['t'] <= 10)
    print(f'{c}: n {len(bs)}; for sale {fs} ({fs / max(1, len(bs)):.0%}); sold {sold} ({sold / max(1, len(bs)):.0%})')
allsold = [b for b in buys if b['sale']]
yrs = [b['sale'][0]['t'] - b['d']['t'] for b in allsold]
fsy = [b['fs'] - b['d']['t'] for b in buys if b['fs'] is not None]
print(f'all sales {len(allsold)}: years entry -> sale median {med(yrs):.1f} (p10 {q(yrs, .1):.1f}, p90 {q(yrs, .9):.1f}); years entry -> for sale median {med(fsy):.1f}; '
      f'for sale but unsold by the end {sum(1 for b in buys if b["fs"] is not None and not b["sale"])}')

print('\n### sale terms')
ratios, gains, gwl = [], [], []
for b in allsold:
    e, pre = b['sale']
    sp = money(r'for \$([\d.,]+)\s*([BMTK]?)', e['d'])
    if sp and b['price']:
        ratios.append(sp / b['price'])
    gl = money(r'\$([\d.,]+)\s*([BMTK]?) (?:loss|gain)', e['d'])
    if gl is not None:
        gains.append((-1 if 'loss' in e['d'] else 1) * gl / 1e9)
    post = next((x for x in rows_all[b['s']][1] if x['tk'] == b['d']['tk'] and x['t'] > e['t']), None)
    tracked = pre['vk'].get(f'carry:segment_goodwill:{b["seg"]}')
    if post and tracked is not None:
        gwl.append(((pre['gw'] - post['gw']) / 1e9, tracked / 1e9, b['d']['tk'], b['s']))
print(f'sale price / price paid: median {med(ratios):.3f} p10 {q(ratios, .1):.3f} p90 {q(ratios, .9):.3f} (n {len(ratios)})')
print(f'gain (+) / loss (-) on sale, bn: median {med(gains):+.2f} p10 {q(gains, .1):+.2f} p90 {q(gains, .9):+.2f}; losses {sum(1 for g in gains if g < 0)} of {len(gains)}')
for dg, tr, tk, s in gwl[:8]:
    print(f'  s{s} {tk}: company goodwill fell {dg:.2f} bn across the sale; tracked segment goodwill before it {tr:.2f} bn')
print(f'company goodwill drop - tracked goodwill: median {med([a - b for a, b, _, _ in gwl]):+.3f} bn (n {len(gwl)})')
again = [(b['s'], b['d']['tk']) for b in allsold if any(x['d']['tk'] == b['d']['tk'] and x['s'] == b['s'] and x['d']['t'] > b['sale'][0]['t'] for x in buys)]
print(f'firms buying again after a sale: {again}')

print('\n### tracked goodwill vs company goodwill (every venture row)')
over = []
n = 0
for s in seeds:
    for x in rows_all[s][1]:
        tr = sum(v for k, v in x['vk'].items() if k.startswith('carry:segment_goodwill:'))
        if tr:
            n += 1
            if tr > x['gw'] * (1 + 1e-9) + 1.0:
                over.append((s, x['tk'], x['t'], round(tr / 1e9, 2), round(x['gw'] / 1e9, 2)))
print(f'rows with tracked goodwill {n}; rows with tracked > company goodwill {len(over)} {over[:5]}')
