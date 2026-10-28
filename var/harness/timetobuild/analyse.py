#!/usr/bin/env python3
"""analyse.py <seeds> [A] [B]: time-to-build check, B - A paired on seeds (default base, ttb). Prints the table only.

Firm-quarter quantities are non-financial firms ('fin' false), every report. Year 20 = each firm's last four reports.
"""
import json, math, os, statistics as st, sys
from collections import defaultdict
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt, mean_se

H = os.path.dirname(os.path.abspath(__file__))
seeds = [int(s) for s in sys.argv[1].split(',')]
A, B = (sys.argv[2], sys.argv[3]) if len(sys.argv) > 3 else ('base', 'ttb')
TAG = os.environ.get('TAG', '')
BAD = {'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D', 'SD'}


def pct(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, max(0, int(math.ceil(p * len(xs))) - 1))] if xs else float('nan')


def runs_of(flags):
    """Lengths of consecutive True runs."""
    out, n = [], 0
    for f in flags + [False]:
        if f:
            n += 1
        elif n:
            out.append(n)
            n = 0
    return out


def run(arm, s):
    r = json.loads(open(f'{H}/runs/{arm}-{s}{TAG}.jsonl').readline())
    mac = [json.loads(l) for l in open(f'{H}/runs/{arm}-{s}{TAG}.macro.jsonl')]
    o, ev = {}, []
    HALF = max(m['t'] for m in mac) / 2
    nfq, allq, firms = [], [], {}
    for tk, f in r['final'].items():
        q = f['q']
        if not q:
            continue
        allq += q
        if not q[0]['fin']:
            nfq += q
            firms[tk] = f
    # 1. construction
    o['CIP>0 share'] = sum(1 for x in nfq if x['cip'] > 0) / len(nfq)
    rat = [x['cip'] / x['ppe'] for x in nfq if x['ppe'] > 0]
    o['CIP/netPPE median'] = st.median(rat)
    o['CIP/netPPE p90'] = pct(rat, 0.9)
    o['firms ever building'] = sum(1 for f in firms.values() if any(x['cip'] > 0 for x in f['q']))
    # 2. idle
    o['idle>0.001 share'] = sum(1 for x in nfq if (x['idle'] or 0) > 0.001) / len(nfq)
    o['idle max'] = max((x['idle'] or 0) for x in nfq)
    o['firms ever idle'] = sum(1 for f in firms.values() if any((x['idle'] or 0) > 0.001 for x in f['q']))
    idle_ind = sorted({x['ind'] for x in nfq if (x['idle'] or 0) > 0.001})
    # 3. industry price level
    pls = [x['pl'] for x in nfq if x['pl'] is not None]
    for p in (0.01, 0.05, 0.95, 0.99):
        o[f'pl p{int(p * 100)}'] = pct(pls, p)
    o['pl outside +-5%'] = sum(1 for p in pls if abs(p - 1) > 0.05) / len(pls)
    ind = defaultdict(lambda: defaultdict(list))
    for x in nfq:
        if x['pl'] is not None:
            ind[x['ind']][round(x['t'] * 4)].append(x['pl'])
    xs, ys, side_runs, glut_runs = [], [], [], []
    for series in ind.values():
        v = [st.fmean(series[k]) - 1.0 for k in sorted(series)]
        xs += v[:-1]
        ys += v[1:]
        side_runs += runs_of([d > 0 for d in v]) + runs_of([d <= 0 for d in v])
        glut_runs += runs_of([d < -0.05 for d in v]) or [0]
    mx, my = st.fmean(xs), st.fmean(ys)
    o['pl AR(1) industry'] = sum((a - mx) * (b - my) for a, b in zip(xs, ys)) / sum((a - mx) ** 2 for a in xs)
    o['pl mean run one side (q)'] = st.fmean(side_runs)
    o['longest glut <0.95 (q)'] = max(glut_runs)
    # 4. board health
    last = {tk: f for tk, f in r['final'].items() if f['q']}
    o['end board cap $tn'] = sum(0.0 if f['dead'] else f['q'][-1]['price'] * f['q'][-1]['shares'] for f in last.values()) / 1e12
    o['median op margin'] = st.median(x['om'] for x in nfq)
    w = [x for x in nfq if x['t'] >= HALF]
    o['capex/revenue 2nd half'] = sum(x['capex'] for x in w) / sum(x['rev'] for x in w)
    nds = []
    for f in firms.values():
        y20 = f['q'][-4:]
        e = sum(x['ebitda'] for x in y20)
        if e > 0 and not f['dead']:
            nds.append((y20[-1]['debt'] - y20[-1]['cash']) / e)
    o['ND/EBITDA y20 median'] = st.median(nds)
    o['ND/EBITDA y20 p90'] = pct(nds, 0.9)
    wa = [x for x in allq if x['t'] >= HALF]
    o['payout 2nd half'] = sum(x['div'] + x['bb'] for x in wa) / sum(x['ni'] for x in wa)
    o['B-or-worse share'] = sum(1 for x in allq if x['rating'] in BAD) / len(allq)
    nd_ = len(r['defaults']) if not isinstance(r['defaults'], dict) else len(r['defaults'])
    o['defaults+deaths+reorgs'] = nd_ + len(r['dead']) + len(r['reorgs'])
    ev = [f'{tk} default' for tk in r['defaults']] if isinstance(r['defaults'], dict) else [f'default {json.dumps(e)[:60]}' for e in r['defaults']]
    ev += [f"{e.get('t', '?')} reorg y{e.get('year', '?')}" for e in r['reorgs']] + [f'dead {json.dumps(e)[:60]}' for e in r['dead']]
    # 5. growth
    yr = defaultdict(float)
    for x in allq:
        yr[int(x['t'] - 1e-9)] += x['rev']
    ks = sorted(yr)
    o['board revenue growth %/y'] = 100 * math.log(yr[ks[-1]] / yr[ks[0]]) / (ks[-1] - ks[0])
    real = [m['nominal_gdp'] / m['defl'] for m in mac]
    o['real GDP growth %/y'] = 100 * math.log(real[-1] / real[0]) / (mac[-1]['t'] - mac[0]['t'])
    o['mean output gap %'] = 100 * st.fmean(m['gap'] for m in mac)
    o['sd output gap %'] = 100 * st.pstdev(m['gap'] for m in mac)
    # 6. absurd
    long_cip, stall = [], []
    for tk, f in firms.items():
        q = f['q']
        rr = runs_of([x['icap'] > 0 and x['cip'] / x['icap'] > 0.25 for x in q])
        if rr and max(rr) >= 8:
            long_cip.append(f'{tk}:{max(rr)}q')
        for i in range(len(q) - 12):
            seg = q[i:i + 13]
            if all(x['capex'] > x['dep'] for x in seg[1:]) and seg[-1]['ppe'] <= seg[0]['ppe'] and seg[-1]['cip'] < 0.5 * seg[-1]['ppe']:
                stall.append(tk)
                break
    o['firms CIP>25% IC >=8q'] = len(long_cip)
    o['firms PPE flat 12q while capex>dep'] = len(stall)
    o['max CIP/netPPE'] = max(rat)
    return o, ev, idle_ind, long_cip, stall


ra = {s: run(A, s) for s in seeds}
rb = {s: run(B, s) for s in seeds}
print(f'n={len(seeds)} seeds; quantity | {A} | {B} | {B}-{A} (se) t n')
for k in ra[seeds[0]][0]:
    a = [ra[s][0][k] for s in seeds]
    b = [rb[s][0][k] for s in seeds]
    print(f'{k} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)}')
for arm, rr in ((A, ra), (B, rb)):
    print(f'\n[{arm}] industries ever idle:', sorted({i for s in seeds for i in rr[s][2]}))
    for s in seeds:
        e, lc, sl = rr[s][1], rr[s][3], rr[s][4]
        if e or lc or sl:
            print(f'[{arm}] seed {s}: events {"; ".join(e) or "-"} | CIP>25%IC {",".join(lc) or "-"} | PPE stall {",".join(sl) or "-"}')
