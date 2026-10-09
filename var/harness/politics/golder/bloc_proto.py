"""Prototype: blocs as a seat-weighted two-means on the economic plane, carried over from the last vote, led by each bloc's largest party."""
import json, glob, collections, sys
D = json.load(open('diet.json'))
P = D['parties']; AX = ('state', 'openness')

def pos(p, positions):
    return {a: D['fixed'].get(p, {}).get(a, positions.get(p, {}).get(a, D['home'][p][a])) for a in AX}

def declare(seats, positions, prev, weighted=True):
    X = {p: pos(p, positions) for p in P}
    order = sorted(P, key=lambda p: (-seats[p], P.index(p)))
    if prev:
        assign = {p: prev[p] for p in P}
    else:
        a, b = order[0], order[1]
        assign = {p: (a if sum((X[p][x] - X[a][x]) ** 2 for x in AX) <= sum((X[p][x] - X[b][x]) ** 2 for x in AX) else b) for p in P}
    for _ in range(100):
        groups = collections.defaultdict(list)
        for p, g in assign.items(): groups[g].append(p)
        if len(groups) < 2: return declare(seats, positions, None, weighted)
        cent = {}
        for g, ms in groups.items():
            w = [seats[m] if weighted else 1.0 for m in ms]
            if sum(w) == 0: w = [1.0] * len(ms)
            cent[g] = {x: sum(wi * X[m][x] for wi, m in zip(w, ms)) / sum(w) for x in AX}
        new = {}
        for p in P:
            d = {g: sum((X[p][x] - c[x]) ** 2 for x in AX) for g, c in cent.items()}
            best = min(d, key=lambda g: (d[g], g != assign[p]))
            new[p] = best
        if new == assign: break
        assign = new
    groups = collections.defaultdict(list)
    for p, g in assign.items(): groups[g].append(p)
    out = {}
    for ms in groups.values():
        leader = max(ms, key=lambda p: (seats[p], -P.index(p)))
        for m in ms: out[m] = leader
    return out

weighted = '--unweighted' not in sys.argv
rows = collections.defaultdict(list)
for f in glob.glob('../formation/run18_*.jsonl'):
    for l in open(f):
        r = json.loads(l)
        if not r.get('fall'): rows[r['s']].append(r)
sw = seen = lead_change = votes = 0
shapes = collections.Counter(); leaders = collections.Counter(); membership = collections.defaultdict(collections.Counter)
for s, rs in rows.items():
    rs.sort(key=lambda r: r['t']); prev = None; lag = D['seed']
    for r in rs:
        b = declare(lag, r['positions'], prev, weighted)
        if prev:
            votes += 1
            lead_change += set(prev.values()) != set(b.values())
            for p in P:
                if b[p] != p and prev[p] != p:
                    seen += 1
                    # same side = same bloc as the party's previous bloc-mates' majority; compare by leader side of civic
                    sw += (b[p] == b['civic']) != (prev[p] == prev['civic'])
        shapes[tuple(sorted(tuple(sorted(m for m in P if b[m] == l)) for l in set(b.values())))] += 1
        leaders[tuple(sorted(set(b.values())))] += 1
        for p in P: membership[p][b[p] == b['civic']] += 1
        prev = b; lag = r['seats']
n = sum(shapes.values())
print(f"{'weighted' if weighted else 'unweighted'}: {n} votes; a party switches side at {sw / seen:.3f} of votes; leadership changes at {lead_change / votes:.3f} of votes")
print('leaders:', [(k, round(v / n, 3)) for k, v in leaders.most_common(5)])
for k, v in shapes.most_common(5): print(f'  {v / n:.3f}', ' | '.join(','.join(x[:4] for x in g) for g in k))
print('share of votes on the civic side:', {p: round(membership[p][True] / n, 2) for p in P})
