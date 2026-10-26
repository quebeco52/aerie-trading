#!/usr/bin/env python3
"""buy2x.py <seeds> ARM: the extra buy-entry checks for 5b-3 after the fixes (run div6.py for the base tables).
Closeness against any held industry; buy entries per firm-year by style; the pays trigger (deals and firm-reports with
no segment paying); bought segments' builds/pays at their first two reports; deals into an x: segment before its first
report. Needs the probe's deal 'pays' and the CAP_PROBE pays dict (index 17). Prints the final tables only.
"""
import json, os, re, statistics as st, sys
from collections import Counter, defaultdict

H = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(H, '..', '..', '..', 'src', 'Data', 'Company')
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
ARM = sys.argv[2]

LEVEL = {'CLOSE': 0.8, 'RELATED': 0.5, 'DISTANT': 0.25}
CLOSE = {}
for m in re.finditer(r"\[\s*'([^']+)'\s*,\s*'([^']+)'\s*,\s*self::([A-Z]+)\s*\]", open(os.path.join(SRC, 'IndustryAdjacency.php')).read()):
    for pair in ((m.group(1), m.group(2)), (m.group(2), m.group(1))):
        CLOSE[pair] = max(CLOSE.get(pair, 0.0), LEVEL[m.group(3)])
PLAN = defaultdict(set)
seg_src = open(os.path.join(SRC, 'Segments.php')).read()
heads = [(m.start(), m.group(1)) for m in re.finditer(r"'([A-Z]{3,5})' => \['main' =>", seg_src)]
for (i, tk), (j, _) in zip(heads, heads[1:] + [(len(seg_src), '')]):
    PLAN[tk].update(re.findall(r"'industry' => '([^']+)'", seg_src[i:j]))
LABEL = {}
for m in re.finditer(r"'label' => '([^']+)', 'industry' => '([^']+)'", seg_src):
    LABEL[m.group(1).lower()] = m.group(2)

G = {s: json.load(open(f'{H}/runs/{ARM}-{s}.seg.json')) for s in seeds}
buys = []
for s in seeds:
    for d in G[s]['deals']:
        if ', taking it into ' in d['d']:
            new = [k for k in d['kappa'] if k.startswith('x:')]
            V = [x for x in G[s]['ventures'] if x['tk'] == d['tk'] and x['t'] < d['t']]
            before = set(V[-1]['kappa']) if V and d['t'] - V[-1]['t'] < 0.3 else set()
            seg = next((k for k in new if k not in before), new[-1] if new else None)
            held = {d['industry']} | PLAN[d['tk']] | {k[2:] for k in before if k.startswith('x:')}
            c = max([CLOSE.get((h, seg[2:]), 0.0) for h in held] or [0.0]) if seg else 0.0
            buys.append({'s': s, 'd': d, 'seg': seg, 'close': c})
builds = [(s, r) for s in seeds for r in G[s]['ventures'] if r['entry']]

print(f'## Buy entry extras, {ARM}, n={len(seeds)}')
print('closeness (any held industry) buy: ' + ', '.join(f'{k}: {v}' for k, v in sorted(Counter(b['close'] for b in buys).items(), reverse=True))
      + ' | build: ' + ', '.join(f'{k}: {v}' for k, v in sorted(Counter(r['entry']['closeness'] for s, r in builds).items(), reverse=True)))

fy = Counter()
for s in seeds:
    for row in G[s]['cap']:
        fy[row[11]] += 0.25
bs = Counter(b['d']['style'] for b in buys)
print('buy entries per firm-year by style: ' + ', '.join(f'{k} {bs[k] / fy[k]:.4f} ({bs[k]} in {fy[k]:.0f})' for k in sorted(fy)))

deals = [(s, d) for s in seeds for d in G[s]['deals'] if ' executed a ' in d['d']]
trig = [(s, d) for s, d in deals if d['pays'] and not any(v > 0 for v in d['pays'].values())]
print(f'deals {len(deals)}; with no segment paying at the deal {len(trig)} ({len(trig) / max(1, len(deals)):.1%}); entries {len(buys)} = {len(buys) / max(1, len(trig)):.0%} of those')
nr = Counter()
nt = Counter()
ni = Counter()
tot = 0
for s in seeds:
    for row in G[s]['cap']:
        p = row[17] if len(row) > 17 else {}
        if not p:
            continue
        tot += 1
        if not any(v > 0 for v in p.values()):
            nr['all'] += 1
            nt[row[1]] += 1
            ni[row[16]] += 1
print(f"firm-reports with a pays flag: {tot}; none paying {nr['all']} ({nr['all'] / max(1, tot):.1%}); top firms " + ', '.join(f'{k} {v}' for k, v in nt.most_common(6))
      + '; top industries ' + ', '.join(f'{k} {v}' for k, v in ni.most_common(5)))

first2 = Counter()
n2 = 0
for b in buys:
    if not b['seg']:
        continue
    V = [x for x in G[b['s']]['ventures'] if x['tk'] == b['d']['tk'] and x['t'] > b['d']['t']][:2]
    for x in V:
        n2 += 1
        first2['builds0'] += x['vk'].get(f"carry:segment_builds:{b['seg']}") == 0
        first2['pays0'] += x['vk'].get(f"carry:segment_pays:{b['seg']}") == 0
print(f"bought segments' first two reports ({n2}): builds = 0 in {first2['builds0']}, pays = 0 in {first2['pays0']}")

pre = []
for b in buys:
    if not b['seg']:
        continue
    first = next((x['t'] for x in G[b['s']]['ventures'] if x['tk'] == b['d']['tk'] and x['t'] > b['d']['t']), None)
    for d in G[b['s']]['deals']:
        m = re.search(r' for its (.+?) business', d['d'])
        if d['tk'] == b['d']['tk'] and m and b['d']['t'] < d['t'] and (first is None or d['t'] < first) and b['seg'][2:].lower().startswith(m.group(1).lower()[:10]):
            pre.append((b['s'], d['tk'], d['t'], d['d'][:100]))
print(f'deals into an x: segment before its first report: {len(pre)} {pre[:3]}')
