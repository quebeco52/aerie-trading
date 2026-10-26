#!/usr/bin/env python3
"""long.py <seeds> [arm] [years]: one-arm long game for the eight segmented companies (default p4live, 40 years).

Per segment at years 1, 10, 20, 30, 40 (reports in (Y-1, Y], pooled over seeds): kappa and a_s (record
addressable_share) median [p10, p90]; P_s and L_s are not filed, so the firm's KPIs (industry_price_level,
idle_plant_share), the segment's sales per plant (record revenue / capacity) and its industry's peer price stand in;
segment record revenue growth against its industry's listed peers per decade; deals per decade; identities in the last
year. Prints the final tables only.
"""
import json, math, os, re, statistics as st, sys
from collections import Counter

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1] if len(sys.argv) > 1 else '1-8'
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
ARM = sys.argv[2] if len(sys.argv) > 2 else 'p4live'
Y = int(sys.argv[3]) if len(sys.argv) > 3 else 40
YEARS = [1] + list(range(10, Y + 1, 10))
EIGHT = ['TERN', 'ERNE', 'WEAV', 'JDAW', 'BIRD', 'RAVN', 'OWLS', 'TRIV']
LABELS = {'air cargo': 'air_cargo', 'consumer devices': 'consumer_devices', 'advertising and cloud': 'ads_and_cloud',
          'merchant generation': 'merchant_power', 'terminals and storage': 'terminals', 'rail and manufacturing': 'industrial',
          'utility infrastructure and consumer goods': 'consumer', 'industrial products': 'industrial',
          'household and medical products': 'consumer'}


def q(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(p * len(xs)))] if xs else float('nan')


def mpp(xs):
    return f'{st.median(xs):.3f} [{q(xs, .1):.3f}, {q(xs, .9):.3f}]' if xs else 'n/a'


R, G, K = {}, {}, {}
for s in seeds:
    R[s] = json.loads(open(f'{H}/runs/{ARM}-{s}.jsonl').read().splitlines()[-1])
    G[s] = json.load(open(f'{H}/runs/{ARM}-{s}.seg.json'))
    K[s] = json.load(open(f'{H}/runs/{ARM}-{s}.idle.json'))
IND = {}
for row in K[seeds[0]]['rows']:
    IND.setdefault(row[1], row[2])
SEGIND = {}
for s in seeds:
    for x in G[s].get('slash', []):
        ind, key = x.split('|')
        SEGIND[tuple(key.split('/'))] = ind
for tk in EIGHT:
    SEGIND[(tk, 'main')] = IND[tk]
rows = {s: {} for s in seeds}
for s in seeds:
    for row in G[s]['rows']:
        for key in ('kappa', 'recs', 'targets'):  # PHP writes an empty map as []
            row[key] = row.get(key) or {}
        rows[s].setdefault(row['tk'], []).append(row)
SEGS = sorted({(tk, seg) for s in seeds for tk in EIGHT for row in rows[s].get(tk, []) for seg in row['kappa']}, key=lambda x: (EIGHT.index(x[0]), x[1] != 'main', x[1]))
win = lambda s, tk, y: [row for row in rows[s].get(tk, []) if row['kappa'] and y - 1 < row['t'] <= y]

print(f'## Long game: {ARM}, {len(seeds)} seeds x {Y} years (reports pooled over seeds; median [p10, p90])')
print('\n### 1-2. kappa and a_s by year')
print('segment | ' + ' | '.join(f'kappa y{y}' for y in YEARS) + ' | ' + ' | '.join(f'a_s y{y}' for y in YEARS) + ' | flags')
for tk, seg in SEGS:
    ks, ass, flags = [], [], set()
    for y in YEARS:
        k = [row['kappa'].get(seg, 0.0) for s in seeds for row in win(s, tk, y)]
        a = [row['recs'][seg]['as'] for s in seeds for row in win(s, tk, y) if seg in row['recs'] and row['kappa'].get(seg, 0) > 0]
        ks.append(mpp(k)); ass.append(mpp(a))
    for s in seeds:
        for row in rows[s].get(tk, []):
            v = row['kappa'].get(seg)
            if v is None:
                continue
            if v == 0.0:
                flags.add(f'sold s{s}')
            elif v < 0.03:
                flags.add(f'<0.03 s{s}')
            elif v > 0.9:
                flags.add(f'>0.9 s{s}')
            if seg in row['recs'] and v > 0 and row['recs'][seg]['as'] > 0.5:
                flags.add(f'a_s>0.5 s{s}')
    print(f'{tk}:{seg} | ' + ' | '.join(ks) + ' | ' + ' | '.join(ass) + ' | ' + (', '.join(sorted(flags)) or '-'))

print('\n### 3. Prices and loads (P_s, L_s not filed: firm KPIs, segment sales per plant, segment industry peer price)')
print('company | firm price level median / p5 | quarters with idle plant | segment: record revenue / capacity (~ P_s x L_s x actual/structural) median [p10, p90]; peer price median / p5')
for tk in EIGHT:
    ipl = [row['ipl'] for s in seeds for row in rows[s].get(tk, []) if row['kappa'] and row['ipl'] is not None]
    idle = [row['idle'] for s in seeds for row in rows[s].get(tk, []) if row['kappa'] and row['idle'] is not None]
    parts = []
    for t2, seg in SEGS:
        if t2 != tk:
            continue
        rel = []
        for s in seeds:
            for row in rows[s].get(tk, []):
                r = row['recs'].get(seg)
                if row['kappa'].get(seg, 0) > 0 and r and min(r['cap'], r['rev']) > 0:
                    rel.append(r['rev'] / r['cap'])
        peer = [row[4] for s in seeds for row in K[s]['rows'] if row[2] == SEGIND[(tk, seg)] and row[1] not in EIGHT and row[4] is not None]
        parts.append(f'{seg}: {mpp(rel) if rel else "no plant on ledger"}; peer {st.median(peer):.3f} / {q(peer, .05):.3f}' if peer else f'{seg}: {mpp(rel) if rel else "no plant on ledger"}; no listed peer')
    print(f'{tk} | {st.median(ipl):.3f} / {q(ipl, .05):.3f} | {sum(1 for x in idle if x > 1e-9) / len(idle):.2f} | ' + ' ; '.join(parts))

print('\n### 4. Nominal revenue growth per year by decade: segment record revenue vs its industry\'s listed peers (median over seeds)')
dec = [(0, 10)] + [(a, a + 10) for a in range(10, Y, 10)]
print('segment | ' + ' | '.join(f'y{a}-{b} seg / peers' for a, b in dec))
for tk, seg in SEGS:
    cells = []
    for a, b in dec:
        g, p = [], []
        for s in seeds:
            w0 = [row['recs'][seg]['rev'] for row in win(s, tk, max(a, 1)) if seg in row['recs'] and row['kappa'].get(seg, 0) > 0]
            w1 = [row['recs'][seg]['rev'] for row in win(s, tk, b) if seg in row['recs'] and row['kappa'].get(seg, 0) > 0]
            span = b - max(a, 1)
            if w0 and w1 and st.fmean(w0) > 0 and st.fmean(w1) > 0:
                g.append(math.log(st.fmean(w1) / st.fmean(w0)) / span)
            peers = [t for t, ind in IND.items() if ind == SEGIND[(tk, seg)] and t not in EIGHT]
            r0 = sum(x['rev'] for t in peers for x in R[s]['final'][t]['q'] if max(a, 1) - 1 < x['t'] <= max(a, 1))
            r1 = sum(x['rev'] for t in peers for x in R[s]['final'][t]['q'] if b - 1 < x['t'] <= b)
            if r0 > 0 and r1 > 0:
                p.append(math.log(r1 / r0) / span)
        cells.append(f'{st.median(g):+.3f} / {st.median(p):+.3f}' if g and p else (f'{st.median(g):+.3f} / -' if g else 'n/a'))
    print(f'{tk}:{seg} | ' + ' | '.join(cells))

print('\n### 5. Deals per decade (all seeds): whole-segment sales, slices, acquisitions by segment')
for a, b in dec:
    whole, slices, acq = [], Counter(), Counter()
    for s in seeds:
        for d in G[s].get('deals', []):
            if d['tk'] not in EIGHT or not (a < d['t'] <= b):
                continue
            m = re.search(r' sold its (.+?) business for ', d['d'])
            if m and m.group(1).lower() in LABELS:
                prev = [row for row in rows[s].get(d['tk'], []) if row['t'] <= d['t'] and row['kappa']]
                seg = LABELS[m.group(1).lower()]
                whole.append(f"{d['tk']}:{seg} s{s} y{d['t']:.1f} k{prev[-1]['kappa'].get(seg, float('nan')):.3f}" if prev else f"{d['tk']}:{seg} s{s}")
            elif ' sold its ' in d['d']:
                slices[d['tk']] += 1
            elif ' executed a ' in d['d']:
                m = re.search(r' for its (.+?) business', d['d'])
                acq[f"{d['tk']}:{LABELS.get(m.group(1).lower(), m.group(1)) if m else 'main'}"] += 1
    print(f'y{a}-{b}: whole {whole or "none"}; slices {dict(sorted(slices.items())) or "none"}; acquisitions {dict(sorted(acq.items()))}')

print(f'\n### 6. Identities, reports in year {Y}')
e = {'sum kappa': 0.0, 'rev': 0.0}
n = 0
neg = 0
for s in seeds:
    for tk in EIGHT:
        for row in win(s, tk, Y):
            n += 1
            e['sum kappa'] = max(e['sum kappa'], abs(sum(row['kappa'].values()) - 1))
            neg += sum(1 for v in row['kappa'].values() if v < 0)
            if len({v['tick'] for k, v in row['recs'].items() if row['kappa'].get(k, 0) > 0}) == 1 and row['rev'] > 0:
                live = sum(v['rev'] for k, v in row['recs'].items() if row['kappa'].get(k, 0) > 0)
                e['rev'] = max(e['rev'], abs(live - 4 * row['rev']) / (4 * row['rev']))
sl = [x for s in seeds for x in G[s].get('slash', []) if x.split('|')[1].split('/')[0] not in EIGHT]
lk = sum(G[s]['leaks']['momentum'] for s in seeds)
print(f'{n} reports: max |sum kappa - 1| {e["sum kappa"]:.1e}; max |sum live record rev - 4 rev| / 4 rev {e["rev"]:.1e}; negative kappa {neg}; TICKER/seg records outside the eight {len(sl)}; unsegmented reports with a segment key {lk}')
print('max kappa of any segment in any report (company all one segment?): ' + ', '.join(
    f"{tk} {max((max(row['kappa'].values()) for s in seeds for row in rows[s].get(tk, []) if row['kappa']), default=float('nan')):.3f}" for tk in EIGHT))
alive = {tk: sum(1 for s in seeds if not R[s]['final'][tk]['dead']) for tk in EIGHT}
print(f'alive at y{Y} (of {len(seeds)}): {alive}; defaults/reorgs among the eight: ' + str({tk: sum((tk in (R[s]["defaults"] or {})) + sum(1 for x in R[s]["reorgs"] if x["t"] == tk) for s in seeds) for tk in EIGHT}))
