#!/usr/bin/env python3
"""analyse_shortage.py [seeds] [armA] [armB]: shortage-capex check, armB - armA paired on seeds (default base, fix).

Firm-quarter tables are non-financial firms ('fin' false). Year 20 = each firm's last four reports. Window = t >= 10.
"""
import json, math, os, statistics as st, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
seeds = [int(s) for s in (sys.argv[1] if len(sys.argv) > 1 else '1,2,3,4').split(',')]
A, B = (sys.argv[2], sys.argv[3]) if len(sys.argv) > 3 else ('base', 'fix')
NAMED = (os.environ.get('NAMED') or 'LOON,SKUA,SGRB,GULL,PHIL,HUMM,WEAV,SILC').split(',')
BAD = {'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D', 'SD'}


def pct(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(math.ceil(p * len(xs))) - 1)]


def run(arm, s):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
    k = json.load(open(f'{H}/runs/{arm}-{s}.idle.json'))
    kpi = {(row[1], row[0]): row for row in k['rows']}
    out, firm = {}, {}
    win = []
    for tk, f in r['final'].items():
        q = f['q']
        if not q:
            continue
        fin = q[0]['fin']
        for x in q:
            kr = kpi.get((tk, x['t']))
            x['pl'] = kr[4] if kr and kr[4] is not None else None
            x['idle'] = kr[3] if kr and kr[3] is not None else 0.0
            x['cpi'] = kr[5] if kr and len(kr) > 5 else 1.0
        y20 = q[-4:]
        ebitda = sum(x['ebitda'] for x in y20)
        firm[tk] = {
            'fin': fin,
            'pl20': st.fmean(x['pl'] for x in y20 if x['pl'] is not None) if any(x['pl'] is not None for x in y20) else None,
            'cx20': sum(x['capex'] for x in y20) / max(1.0, sum(x['rev'] for x in y20)),
            'nd20': (y20[-1]['debt'] - y20[-1]['cash']) / ebitda if ebitda > 0 else None,
            'rev20': sum(x['rev'] for x in y20),
            'real20': sum(x['rev'] for x in y20) / st.fmean(x['cpi'] for x in y20) * q[0]['cpi'] / max(1.0, k.get('opening', {}).get(tk, 0.0)),
            'cap20': 0.0 if f['dead'] else y20[-1]['price'] * y20[-1]['shares'],
        }
        if not fin:
            win += [x for x in q if x['t'] >= 10.0]
    allq = [x for f in r['final'].values() for x in f['q']]
    nf = [x for f in r['final'].values() if f['q'] and not f['q'][0]['fin'] for x in f['q']]
    pls = [x['pl'] for x in win if x['pl'] is not None]
    nds = [v['nd20'] for v in firm.values() if not v['fin'] and v['nd20'] is not None]
    out['pl p95 (y10-20)'] = pct(pls, 0.95)
    out['pl p99 (y10-20)'] = pct(pls, 0.99)
    out['pl max (y10-20)'] = max(pls)
    out['share pl > 1.05 (y10-20)'] = sum(1 for p in pls if p > 1.05) / len(pls)
    out['pl min (all)'] = min(x['pl'] for x in nf if x['pl'] is not None)
    out['idle>0 firm-quarters'] = sum(1 for x in nf if x['idle'] > 1e-12)
    out['capex/revenue (y10-20)'] = sum(x['capex'] for x in win) / sum(x['rev'] for x in win)
    out['median net debt/EBITDA y20'] = st.median(nds)
    out['p90 net debt/EBITDA y20'] = pct(nds, 0.9)
    w_all = [x for x in allq if x['t'] >= 10.0]
    out['payout (div+bb)/NI (y10-20)'] = sum(x['div'] + x['bb'] for x in w_all) / sum(x['ni'] for x in w_all)
    out['board cap y20 ($tn)'] = sum(v['cap20'] for v in firm.values()) / 1e12
    out['median op margin'] = st.median(x['om'] for x in allq)
    out['defaults+deaths'] = len(r['defaults']) + len(r['dead'])
    out['share B or worse'] = sum(1 for x in allq if x['rating'] in BAD) / len(allq)
    out['SKYL real rev multiple y20'] = firm['SKYL']['real20'] if 'SKYL' in firm else float('nan')
    ev = [f'{tk} default {n}q' for tk, n in r['defaults'].items()] if isinstance(r['defaults'], dict) else [str(e) for e in r['defaults']]
    ev += [f"{e['t']} reorg y{e['year']}" for e in r['reorgs']] + [f'dead {json.dumps(e)}' for e in r['dead']]
    out['_events'] = ev
    return out, firm


ra = {s: run(A, s) for s in seeds}
rb = {s: run(B, s) for s in seeds}
print(f'quantity | {A} | {B} | {B}-{A} (se) t n')
for k in [k for k in ra[seeds[0]][0] if not k.startswith('_')]:
    a = [ra[s][0][k] for s in seeds]
    b = [rb[s][0][k] for s in seeds]
    print(f'{k} | {st.fmean(a):.4g} | {st.fmean(b):.4g} | {fmt(paired(a, b), 4)}')

print(f'\nnamed firm, year 20 | price level {A} | {B} | diff (se) t n || capex/rev {A} | {B} || ND/EBITDA {A} | {B}')
for tk in NAMED:
    if tk not in ra[seeds[0]][1]:
        print(f'{tk}: not on the board')
        continue
    g = lambda r, key: [r[s][1][tk][key] for s in seeds]
    pa, pb = g(ra, 'pl20'), g(rb, 'pl20')
    ok = all(v is not None for v in pa + pb)
    nd = lambda xs: 'n/a' if any(v is None for v in xs) else f'{st.fmean(xs):.2f}'
    print(f'{tk} | {st.fmean(pa):.3f} | {st.fmean(pb):.3f} | {fmt(paired(pa, pb), 3) if ok else "-"} || '
          f'{st.fmean(g(ra, "cx20")):.3f} | {st.fmean(g(rb, "cx20")):.3f} || {nd(g(ra, "nd20"))} | {nd(g(rb, "nd20"))}')

moves = []
for tk, v in ra[seeds[0]][1].items():
    if v['fin'] or any(tk not in rb[s][1] for s in seeds):
        continue
    d = [math.log(max(1.0, rb[s][1][tk]['rev20']) / max(1.0, ra[s][1][tk]['rev20'])) for s in seeds]
    moves.append((st.fmean(d), tk, paired([0.0] * len(d), d)))
moves.sort()
print(f'\nyear-20 revenue, log({B}/{A}), mean over seeds (se)')
for m in moves[-5:][::-1] + moves[:5]:
    print(f'{m[1]} | {m[0]:+.3f} ({m[2].se:.3f})')

print('\nevents (arm, seed: firm ...)')
for arm, rr in ((A, ra), (B, rb)):
    for s in seeds:
        if rr[s][0]['_events']:
            print(f"{arm} seed {s}: {'; '.join(rr[s][0]['_events'])}")
