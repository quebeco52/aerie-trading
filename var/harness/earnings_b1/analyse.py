#!/usr/bin/env python3
"""Paired before/after table for the earnings-engine fix batch. Usage: analyse.py [seeds...]"""
import json, math, os, sys, glob, re, statistics as st
H = os.environ.get('H') or os.path.dirname(os.path.abspath(__file__))
SEC = json.load(open(H + '/sectors.json'))
SCALE = ['AAA','AA+','AA','AA-','A+','A','A-','BBB+','BBB','BBB-','BB+','BB','BB-','B+','B','B-','CCC+','CCC','CCC-','CC','C','D']


def rk(r):
    return SCALE.index(r) if r in SCALE else None


def metrics(path):
    r = json.loads(open(path).readline())
    f, ev = r['final'], r['events']
    fy = sum(len(v['q']) for v in f.values()) / 4.0
    m = {}
    lines = [(t, e) for t, v in ev.items() for e in v]
    m['T1 emergency offerings /100fy'] = 100 * sum(1 for _, e in lines if 'emergency stock offering' in e['d'].lower()) / fy
    m['T2 Ch7 /100fy'] = 100 * len(r['dead']) / fy
    m['T2 Ch11 /100fy'] = 100 * len(r['reorgs']) / fy
    m['T2 Ch7+Ch11 /100fy'] = m['T2 Ch7 /100fy'] + m['T2 Ch11 /100fy']
    opens = 0; rch = up = dn = 0; zq = zbad = 0
    cuts = {True: 0, False: 0}; fyg = {True: 0.0, False: 0.0}
    ratio = []; atlim = 0; nrev = 0; growth = []
    for t, v in f.items():
        q = v['q']
        for i, x in enumerate(q):
            fyg[bool(x['fin'])] += 0.25
            if x['qid'] and (i == 0 or not q[i - 1]['qid']):
                opens += 1
            if not SEC.get(t, {}).get('alt', False) and x.get('zone'):
                zq += 1
                zbad += x['zone'] in ('Grey', 'Distress')
            if x['debt'] > 0 and x['rev_commit'] > 0:
                ratio.append(x['rev_drawn'] / x['debt']); nrev += 1
                atlim += x['rev_drawn'] >= 0.95 * x['rev_commit']
            if i:
                p = q[i - 1]
                a, b = rk(p['rating']), rk(x['rating'])
                if a is not None and b is not None and a != b:
                    rch += 1; up += b < a; dn += b > a
                if p['div'] > 0 and x['div'] < 0.97 * p['div'] and x['tni'] > 0:
                    cuts[bool(x['fin'])] += 1
        if len(q) >= 8 and not v['dead']:
            r0 = sum(y['rev'] for y in q[:4]); r1 = sum(y['rev'] for y in q[-4:])
            if r0 > 0 and r1 > 0:
                growth.append(math.log(r1 / r0) / ((q[-1]['t'] - q[3]['t']) or 1))
    m['T2 default openings /100fy'] = 100 * opens / fy
    m['T3 rating actions /fy'] = rch / fy
    m['T3 upgrades /fy'] = up / fy
    m['T3 downgrades /fy'] = dn / fy
    m['T3 Zpp Grey+Distress share'] = zbad / max(1, zq)
    m['div cuts deposit-takers /100fy'] = 100 * cuts[True] / max(1e-9, fyg[True])
    m['div cuts others /100fy'] = 100 * cuts[False] / max(1e-9, fyg[False])
    warns = [(t, e['t']) for t, e in lines if e['d'].startswith('Guidance cut')]
    m['guidance warnings /100fy'] = 100 * len(warns) / fy
    sur = []
    for t, tw in warns:
        nxt = [x for x in f[t]['q'] if x['t'] >= tw]
        if nxt and abs(nxt[0]['ceps']) > 1e-9:
            sur.append(max(-2, min(2, (nxt[0]['reps'] - nxt[0]['ceps']) / abs(nxt[0]['ceps']))))
    m['EPS surprise after warning (mean)'] = st.mean(sur) if sur else float('nan')
    m['revolver drawn/debt (median)'] = st.median(ratio) if ratio else 0.0
    m['revolver at >=95% of limit share'] = atlim / max(1, nrev)
    m['revenue log growth /yr (firm mean)'] = st.mean(growth) if growth else float('nan')
    m['_fy'] = fy
    return m


seeds = [int(a) for a in sys.argv[1:]] or list(range(1, 49))
B, A = {}, {}
for s in seeds:
    try:
        B[s] = metrics(H + '/runs/before-' + str(s) + '.jsonl')
        A[s] = metrics(H + '/runs/after-' + str(s) + '.jsonl')
    except (FileNotFoundError, json.JSONDecodeError):
        B.pop(s, None)
S = sorted(set(B) & set(A))
print('seeds', S, 'firm-years per run before', round(st.mean(B[s]['_fy'] for s in S)), 'after', round(st.mean(A[s]['_fy'] for s in S)))
print('{:38s} {:>9s} {:>9s} {:>9s} {:>8s} {:>6s} {:>3s}  range(delta)'.format('quantity', 'BEFORE', 'AFTER', 'delta', 'SE', 't', 'n'))
for k in B[S[0]]:
    if k.startswith('_'):
        continue
    d = [A[s][k] - B[s][k] for s in S if not (math.isnan(A[s][k]) or math.isnan(B[s][k]))]
    if not d:
        continue
    b = st.mean(B[s][k] for s in S if not math.isnan(B[s][k]))
    a = st.mean(A[s][k] for s in S if not math.isnan(A[s][k]))
    md = st.mean(d)
    se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
    t = md / se if se and se > 0 else float('nan')
    print('{:38s} {:9.4f} {:9.4f} {:9.4f} {:8.4f} {:6.1f} {:3d}  [{:.3f}, {:.3f}]'.format(k, b, a, md, se, t, len(d), min(d), max(d)))
