#!/usr/bin/env python3
"""econ.py <seeds> A B: segment economics (margin, turnover) for the eight, A vs B, from runs/<arm>-<seed>.seg.json.

Firm stable margin and asset turnover (stock fields) at y1/y10/y20, B - A paired at y20; segment margins and
turnovers (carry:segment_margin/turnover) in B; revenue identity on every report (all records, sold ones included);
lag of segment revenue behind its target (|stream revenue share - filed target| at the same report); record
revenue/capacity of each segment against its company's; whole-segment sale prices against the segment's earnings
(annual record revenue x segment margin at the last report before the sale). Prints the final tables only.
"""
import json, math, os, re, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = sys.argv[2], sys.argv[3]
EIGHT = ['TERN', 'ERNE', 'WEAV', 'JDAW', 'BIRD', 'RAVN', 'OWLS', 'TRIV']
LABELS = {'air cargo': 'air_cargo', 'consumer devices': 'consumer_devices', 'advertising and cloud': 'ads_and_cloud',
          'merchant generation': 'merchant_power', 'terminals and storage': 'terminals', 'rail and manufacturing': 'industrial',
          'utility infrastructure and consumer goods': 'consumer', 'industrial products': 'industrial',
          'household and medical products': 'consumer'}


def med(xs):
    xs = list(xs)
    return st.median(xs) if xs else float('nan')


def q(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(p * len(xs)))] if xs else float('nan')


def money(txt):
    m = re.match(r'\$([\d.,]+)\s*([BMT]?)', txt)
    return float(m.group(1).replace(',', '')) * {'B': 1e9, 'M': 1e6, 'T': 1e12, '': 1.0}[m.group(2)] if m else float('nan')


G = {}
for arm in (A, B):
    for s in seeds:
        g = json.load(open(f'{H}/runs/{arm}-{s}.seg.json'))
        for row in g['rows']:
            for key in ('kappa', 'recs', 'targets', 'smar', 'stur', 'segrev'):
                row[key] = row.get(key) or {}
        G[arm, s] = g
rows = lambda arm, s, tk: [r for r in G[arm, s]['rows'] if r['tk'] == tk]
yr = lambda arm, s, tk, y, f: [f(r) for r in rows(arm, s, tk) if y - 1 < r['t'] <= y]

print(f'## Segment economics, {B} vs {A}, n={len(seeds)}')
print('\n### Firm stable margin and asset turnover (median over seeds of the yearly mean); y20 paired diff')
print(f'ticker | margin y1/y10/y20 {A} | {B} | diff y20 (se) t | turnover y1/y10/y20 {A} | {B} | diff y20 (se) t')
for tk in EIGHT:
    cells = {}
    for arm in (A, B):
        for key in ('fmar', 'ftur'):
            v = {y: [st.fmean(x) for s in seeds for x in [yr(arm, s, tk, y, lambda r: r[key])] if x] for y in (1, 10, 20)}
            cells[arm, key] = '/'.join(f'{med(v[y]):.3f}' for y in (1, 10, 20))
            cells[arm, key, 'y20'] = {s: st.fmean(x) for s in seeds for x in [yr(arm, s, tk, 20, lambda r: r[key])] if x}
    print(f"{tk} | {cells[A, 'fmar']} | {cells[B, 'fmar']} | {fmt(paired(cells[A, 'fmar', 'y20'], cells[B, 'fmar', 'y20']), 4)} | "
          f"{cells[A, 'ftur']} | {cells[B, 'ftur']} | {fmt(paired(cells[A, 'ftur', 'y20'], cells[B, 'ftur', 'y20']), 3)}")

print(f'\n### Segment margins and turnovers in {B} (median over seeds, y1/y10/y20)')
for tk in EIGHT:
    segs = sorted({k for s in seeds for r in rows(B, s, tk) for k in r['smar']}, key=lambda k: k != 'main')
    out = []
    for seg in segs:
        m = ['/'.join(f'{med(v):.3f}' if v else 'n/a' for v in [[st.fmean(x) for s in seeds for x in [[z for z in yr(B, s, tk, y, lambda r: r[key].get(seg)) if z is not None]] if x] for y in (1, 10, 20)]) for key in ('smar', 'stur')]
        out.append(f'{seg} margin {m[0]} turnover {m[1]}')
    print(f'{tk}: ' + '; '.join(out))

print('\n### Identities and lag (every report with the books open, sold records included)')
for arm in (A, B):
    err, n, stale, lag, lagmax, rel = 0.0, 0, 0, [], 0.0, {}
    for s in seeds:
        for tk in EIGHT:
            for r in rows(arm, s, tk):
                if not r['kappa'] or r['rev'] <= 0:
                    continue
                n += 1
                err = max(err, abs(sum(v['rev'] for v in r['recs'].values()) - 4 * r['rev']) / (4 * r['rev']))
                stale += any(v['tick'] != r['tick'] for k, v in r['recs'].items() if r['kappa'].get(k, 0) > 0)
                tot = sum(r['segrev'].values())
                if tot > 0 and r['targets']:
                    for k, tg in r['targets'].items():
                        if r['kappa'].get(k, 0) > 0:
                            d = abs(r['segrev'].get(k, 0.0) / tot - tg)
                            lag.append(d)
                            lagmax = max(lagmax, d)
                on = {k: v for k, v in r['recs'].items() if r['kappa'].get(k, 0) > 0 and v['cap'] > 0 and v['rev'] > 0}
                if len(on) > 1:
                    agg = sum(v['rev'] for v in on.values()) / sum(v['cap'] for v in on.values())
                    for k, v in on.items():
                        rel.setdefault(f'{tk}:{k}', []).append((v['rev'] / v['cap']) / agg)
    print(f'{arm}: {n} reports; max |sum record rev - 4 rev| / 4 rev {err:.1e}; reports with a live record not written this report {stale}; '
          f'|segment revenue share - target| median {med(lag):.4f} p95 {q(lag, .95):.4f} max {lagmax:.4f}')
    print(f'  record rev/cap over the company\'s, median [p10, p90]: ' + '; '.join(f'{k} {med(v):.3f} [{q(v, .1):.3f}, {q(v, .9):.3f}]' for k, v in sorted(rel.items())))

print('\n### Whole-segment sales: price against the segment\'s earnings (annual record revenue x segment margin, last report before)')
for arm in (A, B):
    out = []
    for s in seeds:
        for d in G[arm, s].get('deals', []):
            m = re.search(r' sold its (.+?) business for (\$[\d.,]+\s*[BMT]?)', d['d'])
            if not m or m.group(1).lower() not in LABELS:
                continue
            seg = LABELS[m.group(1).lower()]
            prev = [r for r in rows(arm, s, d['tk']) if r['t'] <= d['t'] and r['kappa'].get(seg, 0) > 0]
            if not prev:
                out.append(f"{d['tk']}:{seg} s{s} y{d['t']:.1f} n/a")
                continue
            p = prev[-1]
            rev = p['recs'].get(seg, {}).get('rev', float('nan'))
            mar = p['smar'].get(seg, p['fmar'])
            price = money(m.group(2))
            out.append(f"{d['tk']}:{seg} s{s} y{d['t']:.1f} kappa {p['kappa'][seg]:.3f} price {price / 1e9:.1f}bn, revenue {rev / 1e9:.1f}bn, margin {mar:.3f}, price/EBIT {price / (rev * mar) if rev * mar > 0 else float('nan'):.1f}")
    print(f'{arm}: {len(out)} sales' + ('' if not out else ': ' + '; '.join(out)))
