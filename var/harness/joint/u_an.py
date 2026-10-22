#!/usr/bin/env python3
"""u_an.py <seeds> [A B]: Batch U, covenant-share recap target (default rpBC vs rpRC), paired by seed on the replayed
recNS macros. Breach quarter = (debt - cash) / TTM EBITDA >= covL (the sector ebitda_limit the engine carries), or
TTM EBITDA <= 0 with positive net debt, from the fourth quarter on. Debt-funded acquisition = a quarter where goodwill
rises and debt rises. ROE = mean TTM NI / mean equity over the trailing 4 quarters from year 2. Payout = (div+bb)/NI
over the run. Board = alive >= 70 quarters, excluding SWAN TIER CROW VULT POOL RIVR; cap from quarter 3 to the last
common one (t_an.py definition)."""
import json, math, os, statistics as st, sys, collections

H = os.path.dirname(os.path.abspath(__file__))
FOCUS = ['CASC', 'FALC']
SIX = ['CASC', 'FALC', 'EIDR', 'WADE', 'REDW', 'DIPR']
EXCL = {'SWAN', 'TIER', 'CROW', 'VULT', 'POOL', 'RIVR'}
FINS = {'LAKE', 'SWAN', 'KING', 'PERE', 'CORV', 'RIVR', 'PLVR', 'SAFE', 'DOVE', 'TALN', 'STRK', 'ROOK', 'VULT', 'CROW',
        'POOL', 'ACC', 'TIER', 'EGRT', 'PTRL', 'BNTH'}
RANK = ['AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C', 'D']
nan = float('nan')
src = open(f'{H}/p_an.py').read()
ns = {'__file__': f'{H}/p_an.py'}
exec(src.split('\nargs = sys.argv')[0], ns)
index_stats = ns['index_stats']


def seedlist(s):
    out = []
    for part in s.split(','):
        a, _, b = part.partition('-'); out += list(range(int(a), int(b or a) + 1))
    return out


def low(r): return r is not None and r.rstrip('+-') in RANK and RANK.index(r.rstrip('+-')) >= 4


def recap(es): return sum(float(e['d'].split('$')[1].split('B')[0]) for e in es if 'bonds for recapitalization' in e['d'])


def per_seed(arm, s, absurd):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
    fin, ev = r['final'], r['events']
    o = {}
    for tk in SIX:
        q = fin[tk]['q']; es = ev.get(tk, [])
        nde, br = [], 0
        for i in range(3, len(q)):
            e4 = sum(x['ebitda'] for x in q[i - 3:i + 1]); nd = q[i]['debt'] - q[i]['cash']
            if e4 > 0:
                nde.append(nd / e4); br += nd / e4 >= q[i]['covL']
            else:
                br += nd > 0
        T = []
        for i in range(7, len(q)):
            w = q[i - 3:i + 1]; me = st.mean(x['eq'] for x in w)
            if me > 0 and q[i]['t'] >= 2: T.append(sum(x['ni'] for x in w) / me)
        ni = sum(x['ni'] for x in q)
        o[f'{tk} BB-or-below share %'] = 100 * sum(low(x['rating']) for x in q) / len(q)
        o[f'{tk} payout (div+bb)/NI'] = sum(x['div'] + x['bb'] for x in q) / ni if ni > 0 else nan
        o[f'{tk} ROE %'] = 100 * st.mean(T) if T else nan
        if tk in FOCUS:
            acq = [(q[i]['gw'] - q[i - 1]['gw'], q[i]['debt'] - q[i - 1]['debt']) for i in range(1, len(q))
                   if q[i]['gw'] > q[i - 1]['gw'] * 1.001 + 1e6]
            o[f'{tk} breach quarters'] = br
            o[f'{tk} reorganisations'] = sum(1 for x in (r['reorgs'] or []) if x['t'] == tk)
            o[f'{tk} Ch11 lines'] = sum('Chapter 11' in e['d'] for e in es)
            o[f'{tk} payment defaults'] = (r['defaults'] or {}).get(tk, 0)
            o[f'{tk} events of default'] = sum('triggering an event of default' in e['d'] for e in es)
            o[f'{tk} peak ND/EBITDA'] = max(nde) if nde else nan
            o[f'{tk} median ND/EBITDA'] = st.median(nde) if nde else nan
            o[f'{tk} recap bonds $B'] = recap(es)
            o[f'{tk} recap bond issues'] = sum('bonds for recapitalization' in e['d'] for e in es)
            o[f'{tk} debt-funded acquisitions'] = sum(1 for a, d in acq if d > 0)
            o[f'{tk} acquisitions (gw rises)'] = len(acq)
            o[f'{tk} buybacks $B'] = sum(x['bb'] for x in q) / 1e9
    names = {tk: v['q'] for tk, v in fin.items() if tk not in EXCL and len(v['q']) >= 70}
    n = min(len(x) for x in names.values())
    cap = lambda i: sum(x[i]['price'] * x[i]['shares'] for x in names.values())
    yrs = st.mean(x[n - 1]['t'] for x in names.values()) - st.mean(x[3]['t'] for x in names.values())
    o['board mkt-cap CAGR ex funds/POOL/RIVR %'] = 100 * ((cap(n - 1) / cap(3)) ** (1 / yrs) - 1)
    o['Ch11/run'] = len(r['reorgs'] or []); o['payment-default firms/run'] = len(r['defaults'] or {})
    o['rescues/run'] = sum('stave off bankruptcy' in x['d'] for es in ev.values() for x in es)
    o['failures/run'] = len(r['dead'] or [])
    ni = sum(x['ni'] for v in fin.values() for x in v['q'])
    o['board payout (div+bb)/NI'] = sum(x['div'] + x['bb'] for v in fin.values() for x in v['q']) / ni
    o['board recap bonds $B'] = sum(recap(es) for es in ev.values())
    o.update(index_stats(f'{H}/runs/{arm}-{s}.ticks.csv', r['tpy']))
    rc = {tk: recap(ev.get(tk, [])) for tk in fin}
    for tk, v in fin.items():
        fq = v['q']
        if any(x['eq'] < 0 for x in fq): absurd['negative equity'].add(f'{tk}@{s}')
        if any(not math.isfinite(x['price']) or x['price'] <= 0 for x in fq): absurd['non-positive price'].add(f'{tk}@{s}')
        for i in range(3, len(fq)):
            e4 = sum(x['ebitda'] for x in fq[i - 3:i + 1])
            if tk not in FINS and e4 > 0 and (fq[i]['debt'] - fq[i]['cash']) / e4 > 10:
                absurd['non-financial ND/TTM EBITDA > 10'].add(f'{tk}@{s}'); break
    if os.path.getsize(f'{H}/runs/{arm}-{s}.anom.jsonl') > 0: absurd['anomaly file non-empty'].add(str(s))
    return o, rc


args = sys.argv[1:]
seeds = seedlist(args[0] if args else '1-8')
A, B = (args[1], args[2]) if len(args) > 2 else ('rpBC', 'rpRC')
res, rcs = {A: {}, B: {}}, {A: {}, B: {}}
absurd = {A: collections.defaultdict(set), B: collections.defaultdict(set)}
for a in res:
    for s in seeds:
        if os.path.exists(f'{H}/runs/{a}-{s}.jsonl'):
            res[a][s], rcs[a][s] = per_seed(a, s, absurd[a])
both = [s for s in seeds if s in res[A] and s in res[B]]


def stats(ps):
    d = [y - x for x, y in ps]; m = st.mean(d); se = st.stdev(d) / math.sqrt(len(d))
    t = f't{m / se:+.1f}' if se > 0 else ('t=0' if m == 0 else 't inf')
    return d, m, se, t


def row(k):
    ps = [(res[A][s][k], res[B][s][k]) for s in both if not math.isnan(res[A][s][k]) and not math.isnan(res[B][s][k])]
    if len(ps) < 2: return f'| {k} | | | n={len(ps)} | | |'
    d, m, se, t = stats(ps)
    return (f'| {k} | {st.mean(x for x, _ in ps):.4g} | {st.mean(y for _, y in ps):.4g} | {m:+.3g} ({se:.2g}) {t} '
            f'| {min(d):+.3g}..{max(d):+.3g} | {len(ps)} |')


def table(keys, title):
    print(f'\n## {title}\n| quantity | {A} | {B} | diff (se) t | diff range | n |\n|---|---|---|---|---|---|')
    for k in keys: print(row(k))


print(f'Batch U, seeds {",".join(map(str, both))}: {B} - {A}, paired by seed (replay of recNS macros)')
table([f'{tk} {m}' for tk in FOCUS for m in ('breach quarters', 'reorganisations', 'Ch11 lines', 'payment defaults',
                                              'events of default', 'peak ND/EBITDA', 'median ND/EBITDA', 'recap bonds $B',
                                              'recap bond issues', 'debt-funded acquisitions', 'acquisitions (gw rises)',
                                              'buybacks $B')], '1. CASC / FALC covenant distress')
table([f'{tk} {m}' for tk in SIX for m in ('BB-or-below share %', 'payout (div+bb)/NI', 'ROE %')],
      '2. six firms: rating, payout, ROE')
board = ['board mkt-cap CAGR ex funds/POOL/RIVR %', 'Ch11/run', 'payment-default firms/run', 'rescues/run',
         'failures/run', 'board payout (div+bb)/NI', 'board recap bonds $B', 'EW index return %/y', 'EW index vol %',
         'CW index return %/y', 'CW index vol %']
table(board, '3. board')
mv = []
for tk in rcs[A][both[0]]:
    ps = [(rcs[A][s].get(tk, 0), rcs[B][s].get(tk, 0)) for s in both]
    d, m, se, t = stats(ps)
    mv.append((abs(m), tk, st.mean(x for x, _ in ps), st.mean(y for _, y in ps), m, se, t))
print(f'\n## recap-bond $B per run, largest moves\n| firm | {A} | {B} | diff (se) t |\n|---|---|---|---|')
for _, tk, a, b, m, se, t in sorted(mv, reverse=True)[:12]:
    print(f'| {tk} | {a:.1f} | {b:.1f} | {m:+.1f} ({se:.1f}) {t} |')
print('\n## absurdity scan (firm@seed)')
for a in res:
    print(f'  {a}: ' + ('; '.join(f'{k}: {sorted(v)}' for k, v in absurd[a].items()) or 'none'))
