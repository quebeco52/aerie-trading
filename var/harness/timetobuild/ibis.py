#!/usr/bin/env python3
"""ibis.py <seeds> [A] [B] [traj_seed]: IBIS plant/capacity check plus board sanity, B - A paired on seeds.

Year 6 = reports with 5 <= t < 6; year 20 = the last four reports; window y6-y20 = t >= 5. Env TAG as analyse.py.
"""
import json, math, os, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
seeds = [int(s) for s in sys.argv[1].split(',')]
A, B = (sys.argv[2], sys.argv[3]) if len(sys.argv) > 3 else ('base', 'ttb')
TS = int(sys.argv[4]) if len(sys.argv) > 4 else seeds[0]
TAG = os.environ.get('TAG', '')
BAD = {'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D', 'SD'}
TK = 'IBIS'


def load(arm, s):
    r = json.loads(open(f'{H}/runs/{arm}-{s}{TAG}.jsonl').readline())
    mac = [json.loads(l) for l in open(f'{H}/runs/{arm}-{s}{TAG}.macro.jsonl')]
    return r, mac


def mentions(lst):
    return any(TK in json.dumps(e) for e in (lst if not isinstance(lst, dict) else list(lst)))


def run(arm, s):
    r, mac = load(arm, s)
    f = r['final'][TK]
    q = f['q']
    o = {}
    y6 = [x for x in q if 5 <= x['t'] < 6]
    y20 = q[-4:]
    w = [x for x in q if x['t'] >= 5]
    o['IBIS ln(PPE y20/y6)'] = math.log(y20[-1]['ppe'] / y6[-1]['ppe'])
    o['IBIS ln(rev y20/y6)'] = math.log(sum(x['rev'] for x in y20) / sum(x['rev'] for x in y6))
    o['IBIS PPE y6 $bn'] = y6[-1]['ppe'] / 1e9
    o['IBIS PPE y20 $bn'] = y20[-1]['ppe'] / 1e9
    o['IBIS rev y20 $bn/q'] = st.fmean(x['rev'] for x in y20) / 1e9
    o['IBIS annual rev/netPPE y20'] = sum(x['rev'] for x in y20) / y20[-1]['ppe']
    o['IBIS capex/dep y6-20'] = sum(x['capex'] for x in w) / sum(x['dep'] for x in w)
    o['IBIS CIP/netPPE mean'] = st.fmean(x['cip'] / x['ppe'] for x in w if x['ppe'] > 0)
    o['IBIS idle mean y6-20'] = st.fmean((x['idle'] or 0) for x in w)
    o['IBIS idle max'] = max((x['idle'] or 0) for x in q)
    o['IBIS median om y6-20'] = st.median(x['om'] for x in w)
    o['IBIS end cap $bn'] = 0.0 if f['dead'] else q[-1]['price'] * q[-1]['shares'] / 1e9
    o['IBIS ever B-or-worse/fail'] = float(f['dead'] or any(x['rating'] in BAD for x in q)
                                           or mentions(r['defaults']) or mentions(r['reorgs']))
    # board sanity
    nfq = [x for g in r['final'].values() if g['q'] and not g['q'][0]['fin'] for x in g['q']]
    o['board end cap $tn'] = sum(0.0 if g['dead'] else g['q'][-1]['price'] * g['q'][-1]['shares']
                                 for g in r['final'].values() if g['q']) / 1e12
    o['board idle>0.001 share'] = sum(1 for x in nfq if (x['idle'] or 0) > 0.001) / len(nfq)
    o['board defaults+deaths+reorgs'] = len(r['defaults']) + len(r['dead']) + len(r['reorgs'])
    real = [m['nominal_gdp'] / m['defl'] for m in mac]
    o['real GDP growth %/y'] = 100 * math.log(real[-1] / real[0]) / (mac[-1]['t'] - mac[0]['t'])
    return o


ra = {s: run(A, s) for s in seeds}
rb = {s: run(B, s) for s in seeds}
print(f'n={len(seeds)} seeds; quantity | {A} | {B} | {B}-{A} (se) t n | {B} range')
for k in ra[seeds[0]]:
    a = [ra[s][k] for s in seeds]
    b = [rb[s][k] for s in seeds]
    print(f'{k} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)} | {min(b):.3g}..{max(b):.3g}')
print('\nper seed IBIS ln(PPE y20/y6) A/B, rev/PPE A/B, idle mean A/B:')
for s in seeds:
    print(f'  s{s}: {ra[s]["IBIS ln(PPE y20/y6)"]:+.2f}/{rb[s]["IBIS ln(PPE y20/y6)"]:+.2f}  '
          f'{ra[s]["IBIS annual rev/netPPE y20"]:.2f}/{rb[s]["IBIS annual rev/netPPE y20"]:.2f}  '
          f'{ra[s]["IBIS idle mean y6-20"]:.3f}/{rb[s]["IBIS idle mean y6-20"]:.3f}')
print(f'\nseed {TS} IBIS trajectory (year-end report, $bn/q): year | rev ppe capex dep idle om [{A}] || [{B}]')
qa, qb = load(A, TS)[0]['final'][TK]['q'], load(B, TS)[0]['final'][TK]['q']
for y in range(2, 21, 2):
    row = []
    for q in (qa, qb):
        xs = [x for x in q if y - 1 <= x['t'] < y]
        x = xs[-1] if xs else q[-1]
        row.append(f"{x['rev'] / 1e9:5.1f} {x['ppe'] / 1e9:6.1f} {x['capex'] / 1e9:5.2f} {x['dep'] / 1e9:5.2f} "
                   f"{(x['idle'] or 0):.3f} {x['om']:+.2f}")
    print(f'  y{y:2d} | {row[0]} || {row[1]}')
