#!/usr/bin/env python3
"""v_an.py <seeds> [A B]: Batch V, covenant cap on debt-funded acquisitions (default rpRC vs rpMA), paired by seed on
the replayed recNS macros. Acquisition = a quarter where goodwill rises (> 0.1% and > $1M; u_an.py definition).
Debt-funded acquisition = such a quarter where debt also rises; its $ is that quarter's debt rise (a proxy: the run
output has no deal records). Breach quarter, ROE, payout, board set and cap CAGR as u_an.py. Board BB share = share of
board-firm quarters rated BB or below."""
import json, math, os, statistics as st, sys, collections

H = os.path.dirname(os.path.abspath(__file__))
FOCUS = ['FALC', 'CASC']
DOLLAR = ['FALC', 'CASC', 'SINK', 'HUMM', 'IBHI']
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


def acqs(q):
    return [(q[i]['gw'] - q[i - 1]['gw'], q[i]['debt'] - q[i - 1]['debt']) for i in range(1, len(q))
            if q[i]['gw'] > q[i - 1]['gw'] * 1.001 + 1e6]


def per_seed(arm, s, absurd):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
    fin, ev = r['final'], r['events']
    o, cnt = {}, {}
    for tk, v in fin.items():
        a = acqs(v['q'])
        cnt[tk] = len(a)
        o[f'{tk} dfa $B'] = sum(d for _, d in a if d > 0) / 1e9
        o[f'{tk} dfa n'] = sum(1 for _, d in a if d > 0)
    o['board acquisitions'] = sum(cnt.values())
    o['board debt-funded acquisitions'] = sum(o[f'{tk} dfa n'] for tk in fin)
    o['board debt-funded acq $B'] = sum(o[f'{tk} dfa $B'] for tk in fin)
    o['board ex-fin debt-funded acq $B'] = sum(o[f'{tk} dfa $B'] for tk in fin if tk not in FINS)
    o['board goodwill added $B'] = sum(g for v in fin.values() for g, _ in acqs(v['q'])) / 1e9
    for tk in FOCUS:
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
        o[f'{tk} breach quarters'] = br
        o[f'{tk} BB-or-below share %'] = 100 * sum(low(x['rating']) for x in q) / len(q)
        o[f'{tk} reorganisations'] = sum(1 for x in (r['reorgs'] or []) if x['t'] == tk)
        o[f'{tk} Ch11 lines'] = sum('Chapter 11' in e['d'] for e in es)
        o[f'{tk} payment defaults'] = (r['defaults'] or {}).get(tk, 0)
        o[f'{tk} peak ND/EBITDA'] = max(nde) if nde else nan
        o[f'{tk} median ND/EBITDA'] = st.median(nde) if nde else nan
        o[f'{tk} ROE %'] = 100 * st.mean(T) if T else nan
        o[f'{tk} payout (div+bb)/NI'] = sum(x['div'] + x['bb'] for x in q) / ni if ni > 0 else nan
    names = {tk: v['q'] for tk, v in fin.items() if tk not in EXCL and len(v['q']) >= 70}
    n = min(len(x) for x in names.values())
    cap = lambda i: sum(x[i]['price'] * x[i]['shares'] for x in names.values())
    yrs = st.mean(x[n - 1]['t'] for x in names.values()) - st.mean(x[3]['t'] for x in names.values())
    o['board mkt-cap CAGR ex funds/POOL/RIVR %'] = 100 * ((cap(n - 1) / cap(3)) ** (1 / yrs) - 1)
    o['Ch11/run'] = len(r['reorgs'] or []); o['payment-default firms/run'] = len(r['defaults'] or {})
    o['rescues/run'] = sum('stave off bankruptcy' in x['d'] for es in ev.values() for x in es)
    o['board BB-or-below share %'] = 100 * (sum(low(x['rating']) for v in names.values() for x in v)
                                           / sum(len(v) for v in names.values()))
    o.update(index_stats(f'{H}/runs/{arm}-{s}.ticks.csv', r['tpy']))
    for tk, v in fin.items():
        fq = v['q']
        if any(x['eq'] < 0 for x in fq): absurd['negative equity'].add(f'{tk}@{s}')
        if any(not math.isfinite(x['price']) or x['price'] <= 0 for x in fq): absurd['non-positive price'].add(f'{tk}@{s}')
    if os.path.getsize(f'{H}/runs/{arm}-{s}.anom.jsonl') > 0: absurd['anomaly file non-empty'].add(str(s))
    return o, cnt


args = sys.argv[1:]
seeds = seedlist(args[0] if args else '1-8')
A, B = (args[1], args[2]) if len(args) > 2 else ('rpRC', 'rpMA')
res, cnts = {A: {}, B: {}}, {A: {}, B: {}}
absurd = {A: collections.defaultdict(set), B: collections.defaultdict(set)}
for a in res:
    for s in seeds:
        if os.path.exists(f'{H}/runs/{a}-{s}.jsonl'):
            res[a][s], cnts[a][s] = per_seed(a, s, absurd[a])
both = [s for s in seeds if s in res[A] and s in res[B]]


def stats(ps):
    d = [y - x for x, y in ps]; m = st.mean(d); se = st.stdev(d) / math.sqrt(len(d))
    t = f't{m / se:+.1f}' if se > 0 else ('t=0' if m == 0 else 't inf')
    return d, m, se, t


def row(k):
    ps = [(res[A][s].get(k, 0.0), res[B][s].get(k, 0.0)) for s in both]
    ps = [p for p in ps if not math.isnan(p[0]) and not math.isnan(p[1])]
    if len(ps) < 2: return f'| {k} | | | n={len(ps)} | | |'
    d, m, se, t = stats(ps)
    return (f'| {k} | {st.mean(x for x, _ in ps):.4g} | {st.mean(y for _, y in ps):.4g} | {m:+.3g} ({se:.2g}) {t} '
            f'| {min(d):+.3g}..{max(d):+.3g} | {len(ps)} |')


def table(keys, title):
    print(f'\n## {title}\n| quantity | {A} | {B} | diff (se) t | diff range | n |\n|---|---|---|---|---|---|')
    for k in keys: print(row(k))


print(f'Batch V, seeds {",".join(map(str, both))}: {B} - {A}, paired by seed (replay of recNS macros)')
mv = []
for tk in cnts[A][both[0]]:
    ps = [(cnts[A][s].get(tk, 0), cnts[B][s].get(tk, 0)) for s in both]
    d, m, se, t = stats(ps)
    mv.append((m, tk, st.mean(x for x, _ in ps), st.mean(y for _, y in ps), se, t))
table(['board acquisitions', 'board debt-funded acquisitions', 'board debt-funded acq $B', 'board ex-fin debt-funded acq $B', 'board goodwill added $B'],
      '1a. board acquisitions per run')
print(f'\n## 1b. acquisitions per run, five largest drops\n| firm | {A} | {B} | diff (se) t |\n|---|---|---|---|')
for m, tk, a, b, se, t in sorted(mv)[:5]:
    print(f'| {tk} | {a:.2f} | {b:.2f} | {m:+.2f} ({se:.2f}) {t} |')
table([f'{tk} dfa {u}' for tk in DOLLAR for u in ('n', '$B')], '1c. debt-funded acquisitions per run (n, $B debt rise)')
table([f'{tk} {m}' for tk in FOCUS for m in ('breach quarters', 'BB-or-below share %', 'reorganisations', 'Ch11 lines',
                                              'payment defaults', 'peak ND/EBITDA', 'median ND/EBITDA', 'ROE %', 'payout (div+bb)/NI')],
      '2. FALC / CASC covenant distress')
table(['board mkt-cap CAGR ex funds/POOL/RIVR %', 'Ch11/run', 'payment-default firms/run', 'rescues/run',
       'board BB-or-below share %', 'EW index return %/y', 'EW index vol %', 'CW index return %/y', 'CW index vol %'],
      '3. board')
print('\n## 4. firms with >= 2 acquisitions/run in A and none in B')
stop = [f'{tk} ({a:.1f} -> {b:.1f})' for m, tk, a, b, se, t in mv if a >= 2 and b == 0]
print('  ' + (', '.join(stop) or 'none'))
up = [f'{tk} ({a:.1f} -> {b:.1f})' for m, tk, a, b, se, t in sorted(mv, reverse=True)[:3] if m > 0]
print('  largest rises: ' + (', '.join(up) or 'none'))
print('\n## absurdity scan (firm@seed)')
for a in res:
    print(f'  {a}: ' + ('; '.join(f'{k}: {sorted(v)}' for k, v in absurd[a].items()) or 'none'))
