#!/usr/bin/env python3
"""drift.py <seeds> ARM [ARM...]: entered segments' record share at entry vs at the first priced report.

Buys: the deal record ('xrec' on the deal event: capacity, addressable_share, anchor_demand_share, anchor_outlook,
filed outlook, trend GDP) against the venture row's record at the first report after. Builds: the record at the entry
report (venture row with 'entry') against the first report where the venture's record has revenue > 0. Prints the
final table only.
"""
import json, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]


def q(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(p * len(xs)))] if xs else float('nan')


def summary(label, pairs):
    if not pairs:
        print(f'{label}: none')
        return
    a = [x for x, y in pairs]
    b = [y for x, y in pairs]
    r = [y / x for x, y in pairs if x > 0]
    print(f'{label} (n {len(pairs)}): share at entry median {st.median(a):.4f} max {max(a):.4f}; at first report median {st.median(b):.4f} max {max(b):.4f}; '
          f'ratio first/entry median {st.median(r):.3f} p10 {q(r, .1):.3f} p90 {q(r, .9):.3f} max {max(r):.3f}')


for arm in sys.argv[2:]:
    buys, builds, notes = [], [], []
    for s in seeds:
        g = json.load(open(f'{H}/runs/{arm}-{s}.seg.json'))
        V = g['ventures']
        for d in g['deals']:
            if not d.get('xrec'):
                continue
            for seg, rec in d['xrec'].items():
                nxt = next((x for x in V if x['tk'] == d['tk'] and x['t'] > d['t'] and (x.get('xrec') or {}).get(seg)), None)
                if rec.get('as') and nxt:
                    r2 = nxt['xrec'][seg]
                    buys.append((rec['as'], r2['as']))
                    mkt = rec['ads'] * rec['gdp'] if rec.get('ads') and rec.get('gdp') else None
                    notes.append((rec['as'], r2['as'], d['tk'], s, round(d['t'], 2), seg, rec.get('outlook'), r2.get('outlook'), rec['cap'] / mkt if mkt else None))
        for row in V:
            if not row['entry']:
                continue
            seg = row['entry']['segment']
            r0 = (row.get('xrec') or {}).get(seg)
            nxt = next((x for x in V if x['tk'] == row['tk'] and x['t'] > row['t'] and ((x.get('xrec') or {}).get(seg) or {}).get('rev')), None)
            if r0 and r0.get('as') and nxt:
                builds.append((r0['as'], nxt['xrec'][seg]['as']))
    print(f'## {arm}')
    summary('buys: deal record vs first report', buys)
    summary('builds: entry record vs first report with revenue', builds)
    notes.sort(key=lambda x: -max(x[0], x[1]))
    for n in notes[:4]:
        print(f'  largest: {n[2]} s{n[3]} y{n[4]} {n[5]}: as {n[0]:.4f} -> {n[1]:.4f}; outlook at deal {n[6]}, first report {n[7]}; capacity / entry market {n[8]}')
