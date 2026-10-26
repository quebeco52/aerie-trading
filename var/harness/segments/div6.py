#!/usr/bin/env python3
"""div6.py <seeds> A B: buy entry (5b-3), B vs A. Reads every firm's deal events ('deals': t, tk, d, kappa after,
builds before, chg = announcement move %, type, cap, style, segmented, industry), the VENTURE rows (with 'xrec': the
ledger record of each x: segment), the idle rows and the run output. A buy entry is a deal whose text says ", taking it
into <label>." Run div2.py / div4.py for build entries and the board. Prints the final tables only.
"""
import json, math, os, re, statistics as st, sys
from collections import Counter, defaultdict

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = sys.argv[2], sys.argv[3]
nan = float('nan')


def med(xs):
    xs = [x for x in xs if x is not None and x == x]
    return st.median(xs) if xs else nan


def q(xs, p):
    xs = sorted(x for x in xs if x is not None and x == x)
    return xs[min(len(xs) - 1, int(p * len(xs)))] if xs else nan


def ms(xs):
    xs = [x for x in xs if x is not None and x == x]
    return (st.fmean(xs), st.stdev(xs) / math.sqrt(len(xs)), len(xs)) if len(xs) > 1 else (nan, nan, len(xs))


def money(txt):
    m = re.search(r'\$([\d.,]+)\s*([BMT]?)', txt)
    return float(m.group(1).replace(',', '')) * {'B': 1e9, 'M': 1e6, 'T': 1e12, '': 1.0}[m.group(2)] if m else nan


def goodwill(txt):
    m = re.search(r'\$([\d.,]+)\s*([BMT]?) booked as goodwill', txt)
    return float(m.group(1).replace(',', '')) * {'B': 1e9, 'M': 1e6, 'T': 1e12, '': 1.0}[m.group(2)] if m else 0.0


D = {}
for a in (A, B):
    for s in seeds:
        g = json.load(open(f'{H}/runs/{a}-{s}.seg.json'))
        r = json.loads(open(f'{H}/runs/{a}-{s}.jsonl').read().splitlines()[-1])
        k = json.load(open(f'{H}/runs/{a}-{s}.idle.json'))['rows']
        D[a, s] = (g, r, k)
IND = {row[1]: row[2] for s in seeds for row in D[B, s][2]}

buys, builds, alldeals = [], [], {a: [] for a in (A, B)}
for a in (A, B):
    for s in seeds:
        g = D[a, s][0]
        for d in g['deals']:
            if ' executed a ' in d['d']:
                alldeals[a].append((s, d))
for s, d in alldeals[B]:
    if ', taking it into ' in d['d']:
        new = [k for k in d['kappa'] if k.startswith('x:')]
        V = D[B, s][0]['ventures']
        prev = [x for x in V if x['tk'] == d['tk'] and x['t'] < d['t']]
        held_before = set(prev[-1]['kappa']) if prev and d['t'] - prev[-1]['t'] < 0.3 else set()
        seg = next((k for k in new if k not in held_before), new[-1] if new else None)
        buys.append({'s': s, 'd': d, 'seg': seg})
for s in seeds:
    for row in D[B, s][0]['ventures']:
        if row['entry']:
            builds.append({'s': s, 'row': row})

# closeness for buys: from IndustryAdjacency via the PHP table is not in the run output; infer from the build entries' closeness of the same pair, else None
CLOSE = {}
for b in builds:
    CLOSE[(b['row']['industry'], b['row']['entry']['industry'])] = b['row']['entry']['closeness']
src = open(os.path.join(H, '..', '..', '..', 'src', 'Data', 'Company', 'IndustryAdjacency.php')).read()
LEVEL = {'CLOSE': 0.8, 'RELATED': 0.5, 'DISTANT': 0.25}
for m in re.finditer(r"\[\s*'([^']+)'\s*,\s*'([^']+)'\s*,\s*self::([A-Z]+)\s*\]", src):
    v = LEVEL.get(m.group(3))
    for pair in ((m.group(1), m.group(2)), (m.group(2), m.group(1))):
        CLOSE[pair] = max(CLOSE.get(pair, 0.0), v)


def closeness(home, target):
    return CLOSE.get((home, target))


print(f'## Buy entry, {B} vs {A}, n={len(seeds)} x 20 years')
print('\n### 1. Entries')
pb = Counter(b['s'] for b in buys)
print(f"buy entries {len(buys)} ({len(buys) / len(seeds) / 20:.2f}/yr; per seed {[pb[s] for s in seeds]}) by {len({b['d']['tk'] for b in buys})} companies; "
      f"build entries {len(builds)} ({len(builds) / len(seeds) / 20:.2f}/yr)")
bc = Counter()
for b in buys:
    tgt = b['seg'][2:] if b['seg'] else None
    homes = [k for k in b['d']['kappa'] if not k.startswith('x:')]
    home = b['d']['industry']
    bc[closeness(home, tgt)] += 1
print('closeness buy: ' + ', '.join(f'{k}: {v}' for k, v in sorted(bc.items(), key=lambda x: (x[0] is None, x[0] or 0), reverse=True))
      + ' | build: ' + ', '.join(f'{k}: {v}' for k, v in sorted(Counter(b['row']['entry']['closeness'] for b in builds).items(), reverse=True)))
print('(buy closeness from the adjacency table on the buyer\'s home industry; None = the pair hangs off a held segment industry)')

print('\n### 2. Who buys')
print('style: ' + ', '.join(f'{k} {v}' for k, v in Counter(b['d']['style'] for b in buys).most_common()) + '; deal type: ' + ', '.join(f'{k} {v}' for k, v in Counter(b['d']['type'] for b in buys).most_common()))
print(f"segmented buyers {sum(1 for b in buys if b['d']['segmented'])} of {len(buys)}")
trig = sum(1 for s, d in alldeals[B] if d['builds'] and not any(v > 0 for v in d['builds'].values()))
print(f"deals {len(alldeals[B])} ({len(alldeals[B]) / len(seeds) / 20:.1f}/yr; {A} {len(alldeals[A]) / len(seeds) / 20:.1f}/yr); with no own segment building at the deal (trigger) {trig}; "
      f"entry deals {len(buys)} = {len(buys) / max(1, len(alldeals[B])):.1%} of deals, {len(buys) / max(1, trig):.1%} of triggered deals")

print('\n### 3. Bought segment at entry')
rows = []
for b in buys:
    d, s, seg = b['d'], b['s'], b['seg']
    V = [x for x in D[B, s][0]['ventures'] if x['tk'] == d['tk'] and x['t'] >= d['t']]
    first = next((x for x in V if (x.get('xrec') or {}).get(seg)), None)
    price = money(d['d'])
    tot = sum(first['streams'].values()) if first else 0
    rows.append({'k': d['kappa'].get(seg), 'share': first['streams'].get(seg, 0) / tot if first and tot else None,
                 'as': first['xrec'][seg]['as'] if first else None, 'pc': price / d['cap'] if d['cap'] else None,
                 'gw': goodwill(d['d']) / price if price else None, 'offset': first['xrec'][seg]['offset'] if first else 'no record',
                 'lag': (first['t'] - d['t']) if first else None, 'size': price})
for key, lab in (('k', 'kappa'), ('share', 'share of parent revenue (first report)'), ('as', 'share of its industry market (record)'), ('pc', 'price / parent cap'), ('gw', 'goodwill / price')):
    xs = [r[key] for r in rows if r[key] is not None]
    print(f'{lab}: median {med(xs):.4f} p90 {q(xs, .9):.4f} max {max(xs, default=nan):.4f} (n {len(xs)})')
print('entered industries: ' + ', '.join(f'{k[2:] if k else k} {v}' for k, v in Counter(b['seg'] for b in buys).most_common(10)))
print(f"record entry_offset present: {sum(1 for r in rows if r['offset'] not in (None, 'no record'))}; no record by end: {sum(1 for r in rows if r['offset'] == 'no record')}; "
      f"first record report lag median {med([r['lag'] for r in rows]):.2f} y")

print('\n### 4. Fate of bought segments')
fate = defaultdict(list)
fails = tot = 0
sold = []
bolt = 0
for b in buys:
    d, s, seg = b['d'], b['s'], b['seg']
    V = [x for x in D[B, s][0]['ventures'] if x['tk'] == d['tk'] and x['t'] >= d['t']]
    for y in (4, 8, 12):
        x = next((x for x in V if abs(x['t'] - d['t'] - y) <= 0.13), None)
        if x:
            fate[y].append(x['kappa'].get(seg, 0.0))
    for x in V:
        bv = x['vk'].get(f'carry:segment_builds:{seg}')
        if bv is not None and x['kappa'].get(seg, 0) > 0:
            tot += 1
            fails += bv == 0
    for s2, d2 in alldeals[B]:
        if s2 == s and d2['tk'] == d['tk'] and d2['t'] > d['t'] and seg and seg[2:].lower()[:12] in d2['d'].lower() and 'taking it into' not in d2['d']:
            bolt += 1
    for x in D[B, s][0]['deals']:
        if x['tk'] == d['tk'] and ' sold its ' in x['d'] and x['t'] > d['t'] and x['t'] - d['t'] <= 10 and seg and x['kappa'].get(seg, 1) == 0:
            sold.append((round(x['t'] - d['t'], 1), closeness(d['industry'], seg[2:]), d['tk'], seg))
for y in (4, 8, 12):
    print(f'+{y} y: kappa median {med(fate[y]):.3f} p90 {q(fate[y], .9):.3f} (n {len(fate[y])})')
print(f'gate fails (builds 0) in {fails} of {tot} bought-segment reports ({fails / max(1, tot):.1%}); whole sales within 10 y: {len(sold)} {sold[:6]}; by closeness {dict(Counter(x[1] for x in sold))}; bolt-ons into bought segments {bolt}')

print('\n### 5. Announcement move (event change %)')
ent = [float(b['d']['chg']) for b in buys if b['d']['chg'] is not None]
own = [float(d['chg']) for s, d in alldeals[B] if d['chg'] is not None and 'taking it into' not in d['d']]
for lab, xs in (('entry deals', ent), ('own-market deals', own)):
    m, se, n = ms(xs)
    print(f'{lab}: mean {m:+.3f}% (se {se:.3f}, n {n})')

print('\n### 6. Supply neutrality: entered industry\'s incumbent price level, 2 reports before vs after the buy')
dd = []
for b in buys:
    if not b['seg']:
        continue
    ind = b['seg'][2:]
    for a in (B, A):
        rows_ = [r for r in D[a, b['s']][2] if r[2] == ind and r[4] is not None and r[1] != b['d']['tk']]
        pre = [r[4] for r in rows_ if b['d']['t'] - 0.5 <= r[0] < b['d']['t']]
        post = [r[4] for r in rows_ if b['d']['t'] < r[0] <= b['d']['t'] + 0.5]
        if pre and post:
            dd.append((a, st.fmean(post) - st.fmean(pre)))
for a in (B, A):
    m, se, n = ms([x for aa, x in dd if aa == a])
    print(f'{a}: incumbent price change across the buy {m:+.4f} (se {se:.4f}, n {n})')

print('\n### 7. Deals per year and identities')
zero = n = 0
norec = []
e1 = e2 = 0.0
for s in seeds:
    for x in D[B, s][0]['ventures']:
        n += 1
        zero += any(k.startswith('carry:segment_pending:') and v == 0 for k, v in x['vk'].items())
        if x['rev'] > 0:
            e1 = max(e1, abs(sum(x['streams'].values()) - x['rev']) / x['rev'])
        if x['kappa']:
            e2 = max(e2, abs(sum(x['kappa'].values()) - 1))
    by = defaultdict(list)
    for x in D[B, s][0]['ventures']:
        by[x['tk']].append(x)
    for tk, xs in by.items():
        for i, x in enumerate(xs[1:], 1):
            for seg in x['kappa']:
                if seg.startswith('x:') and x['kappa'][seg] > 0 and seg in xs[i - 1]['kappa'] and not (x.get('xrec') or {}).get(seg):
                    norec.append((s, tk, x['t'], seg))
print(f'venture rows {n}: sum lines = revenue {e1:.1e}; sum kappa {e2:.1e}; zero pending keys {zero}; x: segment without a ledger record after its first report {len(norec)} {norec[:3]}')

print('\n### 8. Oddities')
big = sorted(((r['size'] / 1e9, b['d']['tk'], b['s'], b['seg'], r['as']) for r, b in zip(rows, buys) if r['size'] == r['size']), reverse=True)[:5]
print('largest buys (bn, ticker, seed, segment, market share): ' + '; '.join(f'{p:.1f} {tk} s{s} {seg} {asv if asv is None else round(asv, 3)}' for p, tk, s, seg, asv in big))
rep = Counter((b['s'], b['d']['tk']) for b in buys)
print(f"firms with more than one buy entry in a seed: {[(k, v) for k, v in rep.items() if v > 1]}")
quick = [(b['s'], b['d']['tk'], b['seg']) for b in buys for x in D[B, b['s']][0]['deals'] if x['tk'] == b['d']['tk'] and ' sold its ' in x['d'] and 0 < x['t'] - b['d']['t'] <= 1.0 and b['seg'] and x['kappa'].get(b['seg'], 1) == 0]
print(f'bought then sold within a year: {quick}')
