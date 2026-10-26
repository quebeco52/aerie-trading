#!/usr/bin/env python3
"""hard3.py <seeds> A B [LONG]: deals and entered-segment margins. (a) entered (x:) segment margin over its industry's
listed peers' median operating margin (arm B, and LONG if given); every deal into an x: segment (the segment whose
kappa rose at the deal: goodwill / price, price / acquired line revenue (the x: line's annual revenue change across
the deal), margin over peers at the deal); goodwill / price for deals into main and seeded segments. (b) M&A volume A
vs B: deals per seed by type, top acquirers, goodwill / price by type. Prints the final tables only.
"""
import json, os, re, statistics as st, sys
from collections import Counter, defaultdict

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = sys.argv[2], sys.argv[3]
LONG = sys.argv[4] if len(sys.argv) > 4 else None
nan = float('nan')


def q(xs, p):
    xs = sorted(x for x in xs if x == x)
    return xs[min(len(xs) - 1, int(p * len(xs)))] if xs else nan


def money(pat, txt):
    m = re.search(pat, txt)
    return float(m.group(1).replace(',', '')) * {'B': 1e9, 'M': 1e6, 'T': 1e12, 'K': 1e3, '': 1.0}[m.group(2)] if m else None


def load(a, s):
    g = json.load(open(f'{H}/runs/{a}-{s}.seg.json'))
    for v in g.get('ventures', []):
        for k in ('kappa', 'vk', 'streams'):
            v[k] = v.get(k) or {}
    return g, json.loads(open(f'{H}/runs/{a}-{s}.jsonl').read().splitlines()[-1]), json.load(open(f'{H}/runs/{a}-{s}.idle.json'))['rows']


def peers(r, rows):
    ind_of = {row[1]: row[2] for row in rows}
    pm = defaultdict(list)
    for tk, v in r['final'].items():
        for x in v['q']:
            pm[ind_of.get(tk), round(x['t'] * 4)].append(x['om'])
    return {k: st.median(v) for k, v in pm.items()}


def part_a(arm):
    ratios = []
    xdeals, odeals = [], []
    for s in seeds:
        g, r, rows = load(arm, s)
        pm = peers(r, rows)
        V = g.get('ventures', [])
        for v in V:
            for k, m in v['vk'].items():
                if k.startswith('carry:segment_margin:x:'):
                    seg = k.split('margin:')[1]
                    p = pm.get((seg[2:], round(v['t'] * 4)))
                    if p and p > 0 and v['kappa'].get(seg, 0) > 0:
                        ratios.append(m / p)
        byfirm = defaultdict(list)
        for v in V:
            byfirm[v['tk']].append(v)
        for d in g['deals']:
            if ' executed a ' not in d['d'] or ', taking it into ' in d['d']:
                continue
            price = money(r'executed a \$([\d.,]+)\s*([BMTK]?)', d['d'])
            gw = money(r'\$([\d.,]+)\s*([BMTK]?) booked as goodwill', d['d']) or 0.0
            if not price:
                continue
            fr = byfirm.get(d['tk'], [])
            before = [v for v in fr if v['t'] < d['t']]
            after = [v for v in fr if v['t'] > d['t']]
            seg = None
            if before and after and d['t'] - before[-1]['t'] < 0.3:
                k0, k1 = before[-1]['kappa'], d['kappa']
                rises = {k: k1.get(k, 0) - k0.get(k, 0) for k in k1 if k.startswith('x:')}
                if rises and max(rises.values()) > 1e-6:
                    seg = max(rises, key=rises.get)
            lim = re.search(r'Sized to (.+?)\.$', d['d'])
            if seg:
                p = pm.get((seg[2:], round(d['t'] * 4)))
                m = before[-1]['vk'].get(f'carry:segment_margin:{seg}')
                dl = (after[0]['streams'].get(seg, 0) - before[-1]['streams'].get(seg, 0)) * 4
                xdeals.append({'s': s, 'tk': d['tk'], 't': d['t'], 'seg': seg, 'price': price, 'gw': gw / price,
                               'p/rev': price / dl if dl > 0 else nan, 'mrel': m / p if m is not None and p and p > 0 else nan,
                               'm': m, 'peer': p, 'limit': lim.group(1) if lim else '-'})
            else:
                odeals.append(gw / price)
    print(f'\n### (a) {arm}: entered-segment margin / listed peers\' median margin (venture reports, n {len(ratios)})')
    print(f'p50 {q(ratios, .5):.2f}, p90 {q(ratios, .9):.2f}, max {max(ratios, default=nan):.2f}; share above 2x {sum(1 for x in ratios if x > 2) / max(1, len(ratios)):.1%}')
    print(f'deals into x: segments: {len(xdeals)}; goodwill/price p50 {q([d["gw"] for d in xdeals], .5):.2f} p90 {q([d["gw"] for d in xdeals], .9):.2f}; '
          f'price / acquired line revenue p50 {q([d["p/rev"] for d in xdeals], .5):.2f} p90 {q([d["p/rev"] for d in xdeals], .9):.2f}; '
          f'segment margin / peers at the deal p50 {q([d["mrel"] for d in xdeals], .5):.2f} p90 {q([d["mrel"] for d in xdeals], .9):.2f} max {max([d["mrel"] for d in xdeals if d["mrel"] == d["mrel"]], default=nan):.2f}')
    print('binding limits: ' + ', '.join(f'{k} {v}' for k, v in Counter(d['limit'] for d in xdeals).most_common()))
    for d in sorted(xdeals, key=lambda d: -d['price'])[:5]:
        print(f"  s{d['s']} {d['tk']} y{d['t']:.1f} {d['seg']}: ${d['price'] / 1e9:.0f}bn, goodwill {d['gw']:.0%}, price/line rev {d['p/rev']:.2f}, margin {d['m'] if d['m'] is None else round(d['m'], 3)} vs peers {d['peer'] if d['peer'] is None else round(d['peer'], 3)}, {d['limit']}")
    print(f'deals into main or seeded segments: {len(odeals)}; goodwill/price p50 {q(odeals, .5):.2f} p90 {q(odeals, .9):.2f}')


def part_b():
    print(f'\n### (b) M&A volume, {A} vs {B} (per seed, 20 years)')
    for a in (A, B):
        types = Counter()
        acq = Counter()
        gw = defaultdict(list)
        for s in seeds:
            g = json.load(open(f'{H}/runs/{a}-{s}.seg.json'))
            for d in g['deals']:
                if ' executed a ' not in d['d']:
                    continue
                types[d['type']] += 1
                acq[d['tk']] += 1
                price = money(r'executed a \$([\d.,]+)\s*([BMTK]?)', d['d'])
                g_ = money(r'\$([\d.,]+)\s*([BMTK]?) booked as goodwill', d['d']) or 0.0
                if price:
                    gw[d['type']].append(g_ / price)
        n = len(seeds)
        print(f'{a}: deals per seed {sum(types.values()) / n:.1f}: ' + ', '.join(f'{k} {v / n:.1f}' for k, v in types.most_common()))
        print(f'   top acquirers (deals per seed): ' + ', '.join(f'{k} {v / n:.1f}' for k, v in acq.most_common(5)))
        print(f'   goodwill / price median by type: ' + ', '.join(f'{k} {q(v, .5):.2f}' for k, v in gw.items()))


print(f'## Deals and entered-segment margins')
part_a(B)
if LONG:
    part_a(LONG)
part_b()
