#!/usr/bin/env python3
"""deals.py <seeds> A B: phase 4 deals by segment for the eight, per arm, from runs/<arm>-<seed>.seg.json 'deals'.

Whole-segment sales ("sold its <label> business": segment, year, kappa before from the last report), slices ("sold
its ... division"), acquisitions by segment ("for its <label> business", else main). Identities after deals: a sold
segment's record has capacity 0 and its tick never moves again; the capacity-split mismatch against the previous
report's kappa, split by whether a deal fell between the two reports. Prints the final tables only.
"""
import json, os, re, sys
from collections import Counter

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
ARMS = sys.argv[2:4]
EIGHT = ['TERN', 'ERNE', 'WEAV', 'JDAW', 'BIRD', 'RAVN', 'OWLS', 'TRIV']
LABELS = {  # (ticker, lower-case label) -> segment key, from src/Data/Company/Segments.php
    'air cargo': 'air_cargo', 'consumer devices': 'consumer_devices', 'advertising and cloud': 'ads_and_cloud',
    'merchant generation': 'merchant_power', 'terminals and storage': 'terminals', 'rail and manufacturing': 'industrial',
    'utility infrastructure and consumer goods': 'consumer', 'industrial products': 'industrial',
    'household and medical products': 'consumer',
}

for arm in ARMS:
    whole, slices, acq = [], Counter(), Counter()
    sold_bad = sold_n = 0
    mism = {'deal between': 0, 'no deal': 0}
    checked = 0
    for s in seeds:
        g = json.load(open(f'{H}/runs/{arm}-{s}.seg.json'))
        rows = {}
        for row in g['rows']:
            rows.setdefault(row['tk'], []).append(row)
        deals = [d for d in g.get('deals', []) if d['tk'] in EIGHT]
        sold = {}
        for d in deals:
            m = re.search(r' sold its (.+?) business for ', d['d'])
            if m and m.group(1).lower() in LABELS:
                seg = LABELS[m.group(1).lower()]
                before = [r for r in rows.get(d['tk'], []) if r['t'] <= d['t'] and r['kappa']]
                kb = before[-1]['kappa'].get(seg) if before else None
                whole.append((d['tk'], seg, s, d['t'], kb))
                sold[(d['tk'], seg)] = d['t']
            elif ' sold its ' in d['d']:
                slices[d['tk']] += 1
            elif ' executed a ' in d['d']:
                m = re.search(r' for its (.+?) business', d['d'])
                acq[(d['tk'], LABELS.get(m.group(1).lower(), m.group(1)) if m else 'main')] += 1
        # sold record: capacity 0 and tick frozen after the sale
        for (tk, seg), t in sold.items():
            after = [r['recs'].get(seg) for r in rows.get(tk, []) if r['t'] > t and r['recs'].get(seg) is not None]
            sold_n += len(after)
            ticks = {rec['tick'] for rec in after}
            sold_bad += sum(1 for rec in after if rec['cap'] != 0.0) + (len(ticks) > 1)
        # capacity split against the previous report's kappa (segments with plant on the ledger)
        for tk in EIGHT:
            rs = rows.get(tk, [])
            for p, r in zip(rs, rs[1:]):
                if not (p['kappa'] and r['kappa']):
                    continue
                on = {k: v for k, v in p['kappa'].items() if v > 0 and r['recs'].get(k, {}).get('cap', 0.0) > 0}
                tot = sum(r['recs'][k]['cap'] for k in on)
                if not on or tot <= 0:
                    continue
                checked += 1
                dev = max(abs(r['recs'][k]['cap'] / tot - v / sum(on.values())) for k, v in on.items())
                if dev > 1e-6:
                    between = any(d['tk'] == tk and p['t'] < d['t'] <= r['t'] for d in deals)
                    mism['deal between' if between else 'no deal'] += 1
    print(f'## {arm} (n={len(seeds)})')
    print('whole-segment sales (ticker, segment, seed, year, kappa before): ' + ('; '.join(f'{a} {b} s{c} y{d:.1f} k{e:.3f}' if e is not None else f'{a} {b} s{c} y{d:.1f}' for a, b, c, d, e in whole) or 'none'))
    print('slices by ticker: ' + (', '.join(f'{k} {v}' for k, v in sorted(slices.items())) or 'none'))
    print('acquisitions by ticker:segment: ' + (', '.join(f'{k[0]}:{k[1]} {v}' for k, v in sorted(acq.items())) or 'none'))
    print(f'sold-segment record checks after sale: {sold_n} reads, violations {sold_bad}')
    print(f'capacity split vs previous kappa: {checked} reports, mismatches {mism}')
