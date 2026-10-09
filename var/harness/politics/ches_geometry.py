import csv, collections, itertools, statistics as st, math, sys
sys.path.insert(0, '.')
rows = [r for r in csv.DictReader(open(__import__('os').path.join(__import__('os').path.dirname(__file__) or '.', 'ches_trend.csv'))) if r['eastwest'] == '1']
def num(v):
    try: return float(v)
    except ValueError: return None
AX = {'state': ('lrecon', -5.0), 'openness': ('eu_position', 3.0), 'council': ('antielite_salience', -5.0), 'environment': ('environment', -5.0)}
keep = [r for r in rows if all(num(r[v]) is not None for v, _ in AX.values()) and (num(r['seat']) or 0) > 0]
sysd = collections.defaultdict(list)
for r in keep: sysd[(r['country'], r['year'])].append(r)
pts, sds, ranges = [], collections.defaultdict(list), collections.defaultdict(list)
for k, rs in sysd.items():
    if len(rs) < 5: continue
    for a, (v, h) in AX.items():
        m = st.mean(num(r[v]) for r in rs)
        xs = [(num(r[v]) - m) / h for r in rs]
        sds[a].append(st.pstdev(xs)); ranges[a].append(max(xs) - min(xs))
    ms = {a: st.mean(num(r[v]) for r in rs) for a, (v, h) in AX.items()}
    for r in rs: pts.append({a: (num(r[v]) - ms[a]) / h for a, (v, h) in AX.items()} | {'fam': r['family']})
def corr(a, b, P):
    xa = [p[a] for p in P]; xb = [p[b] for p in P]; ma, mb = st.mean(xa), st.mean(xb)
    return sum((x - ma) * (y - mb) for x, y in zip(xa, xb)) / math.sqrt(sum((x - ma) ** 2 for x in xa) * sum((y - mb) ** 2 for y in xb))
print(f'CHES West 2014-2024: {len(sds["state"])} party systems, {len(pts)} parties (relative to each system\'s mean, Diet scale)')
for a in AX: print(f'  {a:9s} sd within a system {st.median(sds[a]):.2f}   range {st.median(ranges[a]):.2f}')
for a, b in itertools.combinations(AX, 2): print(f'  corr {a}-{b}: {corr(a, b, pts):+.2f}')
FAM = {'1': 'radical right', '6': 'radical left', '7': 'green'}
for f, name in FAM.items():
    P = [p for p in pts if p['fam'] == f]
    print(f'  {name:13s} n={len(P):3d}  ' + '  '.join(f'{a} {st.mean(p[a] for p in P):+.2f}' for a in AX))
from importlib import util
H = {'civic': (0.6, -0.1, -0.1, 0.34), 'vanguard': (-0.6, 0.0, 0.3, -0.48), 'iron_harbor': (0.1, -0.8, -0.4, -0.23), 'exchange': (0.0, 0.8, 0.4, 0.21), 'chartists': (0.0, 0.6, 0.8, 0.11), 'common_lot': (0.3, -0.5, -0.8, 0.04), 'tideline': (0.4, -0.2, -0.3, 0.8), 'new_horizon': (-0.5, 0.7, 0.3, -0.17)}
D = [dict(zip(AX, v)) for v in H.values()]
m = {a: st.mean(p[a] for p in D) for a in AX}
Dc = [{a: p[a] - m[a] for a in AX} for p in D]
print('Diet homes (8 parties, relative to their mean):')
for a in AX: print(f'  {a:9s} sd {st.pstdev([p[a] for p in Dc]):.2f}   range {max(p[a] for p in Dc) - min(p[a] for p in Dc):.2f}   mean {m[a]:+.2f}')
for a, b in itertools.combinations(AX, 2): print(f'  corr {a}-{b}: {corr(a, b, Dc):+.2f}')
