#!/usr/bin/env python3
"""Batch-2 table: b2 vs after (b1) and b2 vs before (main), paired on seeds. Usage: analyse2.py [seeds...]"""
import json, math, os, sys, statistics as st
H = os.environ.get('H') or os.path.dirname(os.path.abspath(__file__))
SEC = json.load(open(H + '/sectors.json'))
SCALE = ['AAA','AA+','AA','AA-','A+','A','A-','BBB+','BBB','BBB-','BB+','BB','BB-','B+','B','B-','CCC+','CCC','CCC-','CC','C','D']
W0 = 0.04


def rk(r):
    return SCALE.index(r) if r in SCALE else None


def load(arm, s):
    return json.loads(open(H + '/runs/' + arm + '-' + str(s) + '.jsonl').readline())


def eps_pairs(r):
    out = []
    for t, v in r['final'].items():
        for x in v['q']:
            if abs(x['ceps']) > 1e-9:
                out.append((x['reps'], x['ceps']))
    return out


def metrics(r):
    f, ev = r['final'], r['events']
    fy = sum(len(v['q']) for v in f.values()) / 4.0
    m = {}
    ep = eps_pairs(r)
    sur = [(a - c) / abs(c) for a, c in ep]
    m['T1 EPS beat rate'] = sum(a > c for a, c in ep) / len(ep)
    rv = [(x['rev'], x['crev']) for v in f.values() for x in v['q'] if x.get('crev', 0) > 0]
    rs = [(a - c) / c for a, c in rv]
    m['T1 revenue beat rate'] = sum(a > c for a, c in rv) / len(rv) if rv else float('nan')
    m['T1 sd revenue surprise (clip 50%)'] = st.pstdev([max(-0.5, min(0.5, x)) for x in rs]) if rs else float('nan')
    up = sum(0 <= x < 0.01 for x in sur); dn = sum(-0.01 <= x < 0 for x in sur)
    m['T2 n[0,1%)/n[-1%,0)'] = up / max(1, dn)
    lo = sum(-0.05 <= x < -0.01 for x in sur); hi = sum(0.01 <= x < 0.05 for x in sur)
    m['T2 n[-5,-1%)/n[1,5%)'] = lo / max(1, hi)
    rch = 0; zq = zbad = 0; opens = 0
    xs = []; ys = []
    for t, v in f.items():
        q = v['q']
        nonfin = not SEC.get(t, {}).get('fin', False)
        for i, x in enumerate(q):
            if x['qid'] and (i == 0 or not q[i - 1]['qid']):
                opens += 1
            if not SEC.get(t, {}).get('alt', False) and x.get('zone'):
                zq += 1
                zbad += x['zone'] in ('Grey', 'Distress')
            if i:
                a, b = rk(q[i - 1]['rating']), rk(x['rating'])
                if a is not None and b is not None and a != b:
                    rch += 1
        if nonfin:
            mg = [(x['ebit'] / x['rev'], x['gap']) for x in q if x['rev'] > 0]
            if len(mg) > 4:
                mu = st.mean(g[0] for g in mg)
                for g in mg:
                    ys.append(max(-1, min(1, g[0] - mu))); xs.append(g[1])
    m['T3 rating actions /fy'] = rch / fy
    m['T3 Zpp Grey+Distress share'] = zbad / max(1, zq)
    m['T4 Ch7+Ch11 /100fy'] = 100 * (len(r['dead']) + len(r['reorgs'])) / fy
    m['T4 default openings /100fy'] = 100 * opens / fy
    mx = st.mean(xs); my = st.mean(ys)
    m['T5 EBIT margin slope on gap'] = sum((a - mx) * (b - my) for a, b in zip(xs, ys)) / sum((a - mx) ** 2 for a in xs)
    warns = [(t, e['t']) for t, v in ev.items() for e in v if e['d'].startswith('Guidance cut')]
    m['T6 guidance warnings /100fy'] = 100 * len(warns) / fy
    ws = []
    for t, tw in warns:
        nxt = [x for x in f[t]['q'] if x['t'] >= tw]
        if nxt and abs(nxt[0]['ceps']) > 1e-9:
            ws.append((nxt[0]['reps'] - nxt[0]['ceps']) / abs(nxt[0]['ceps']))
    m['_ws'] = ws
    m['_warners'] = sorted({t for t, _ in warns})
    m['_ep'] = ep
    return m


def row(k, X, Y, S):
    d = [Y[s][k] - X[s][k] for s in S if not (math.isnan(Y[s][k]) or math.isnan(X[s][k]))]
    md = st.mean(d)
    se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
    return md, se, (md / se if se > 0 else float('nan')), len(d)


seeds = [int(a) for a in sys.argv[1:]] or list(range(1, 17))
M = {a: {} for a in ('before', 'after', 'b2')}
for s in seeds:
    try:
        got = {a: metrics(load(a, s)) for a in M}
    except (FileNotFoundError, json.JSONDecodeError, KeyError):
        continue
    for a in M:
        M[a][s] = got[a]
S = sorted(M['b2'])
print('seeds', S)
print('{:34s} {:>8s} {:>8s} {:>8s} | {:>8s} {:>7s} {:>6s} | {:>8s} {:>6s} {:>3s}'.format('quantity', 'before', 'b1', 'b2', 'b2-b1', 'SE', 't', 'b2-bef', 't', 'n'))
for k in M['b2'][S[0]]:
    if k.startswith('_'):
        continue
    v = [st.mean([M[a][s][k] for s in S if not math.isnan(M[a][s][k])] or [float('nan')]) for a in ('before', 'after', 'b2')]
    d1, se1, t1, n = row(k, M['after'], M['b2'], S)
    d0, se0, t0, _ = row(k, M['before'], M['b2'], S)
    print('{:34s} {:8.4f} {:8.4f} {:8.4f} | {:8.4f} {:7.4f} {:6.1f} | {:8.4f} {:6.1f} {:3d}'.format(k, *v, d1, se1, t1, d0, t0, n))
for a in M:
    ws = [w for s in S for w in M[a][s]['_ws']]
    who = {}
    for s in S:
        for t in M[a][s]['_warners']:
            who[t] = who.get(t, 0) + 1
    print(a, 'warnings', len(ws), 'median surprise after', round(st.median(ws), 3) if ws else None, 'warners(seeds)', who)
# walk-down that puts the b2 EPS beat rate at 75%: unshade c = u - W0|u|, re-shade at w
P = [(a, c / (1 - W0 * (1 if c > 0 else -1))) for s in S for a, c in M['b2'][s]['_ep']]
def beat(w):
    return sum(a > u - w * abs(u) for a, u in P) / len(P)
lo, hi = 0.0, 0.5
for _ in range(40):
    mid = (lo + hi) / 2
    lo, hi = (mid, hi) if beat(mid) < 0.75 else (lo, mid)
print('b2 beat at w=0 {:.3f}, w=0.04 {:.3f}; w for 75% beat = {:.4f}'.format(beat(0.0), beat(W0), hi))
per = []
for s in S:
    Ps = [(a, c / (1 - W0 * (1 if c > 0 else -1))) for a, c in M['b2'][s]['_ep']]
    l, h = 0.0, 0.5
    for _ in range(30):
        mid = (l + h) / 2
        b = sum(a > u - mid * abs(u) for a, u in Ps) / len(Ps)
        l, h = (mid, h) if b < 0.75 else (l, mid)
    per.append(h)
print('per-seed w: mean {:.4f} se {:.4f} range [{:.4f}, {:.4f}]'.format(st.mean(per), st.stdev(per) / math.sqrt(len(per)), min(per), max(per)))
