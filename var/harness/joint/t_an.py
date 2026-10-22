#!/usr/bin/env python3
"""t_an.py <seeds> [A B]: Batch T, lender calibration fix (default rpX2 vs rpBC), paired by seed on the replayed
recNS macros. ROE = sum NI / mean equity per year over quarters in [a, b) years. Provisions/assets = annual provision
over mean total assets; allowance/book = allowance over earning assets (`ea`), mean over the window. Payout =
(div + bb) / NI from year 2. Board = alive >= 70 quarters, excluding the fee funds (SWAN TIER CROW VULT), POOL, RIVR,
TALN and STRK; cap from quarter 3 to the last common one."""
import json, math, os, statistics as st, sys, collections

H = os.path.dirname(os.path.abspath(__file__))
LEND = {'TALN': .25, 'STRK': .25, 'LAKE': .21, 'PLVR': .11, 'RIVR': .15, 'EGRT': .13, 'BNTH': .18, 'POOL': .29}
FOCUS = ['TALN', 'STRK']
EXCL = {'SWAN', 'TIER', 'CROW', 'VULT', 'POOL', 'RIVR', 'TALN', 'STRK'}
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


def win(q, a, b): return [x for x in q if a <= x['t'] < b]


def roe(q, a, b):
    w = win(q, a, b)
    if len(w) < 4: return nan
    me = st.mean(x['eq'] for x in w)
    return sum(x['ni'] for x in w) / me / (len(w) / 4) if me > 0 else nan


def cagr(a, b, yrs):
    return 100 * ((b / a) ** (1 / yrs) - 1) if a > 0 and b > 0 and yrs > 0 else nan


def per_seed(arm, s, absurd):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
    fin, ev = r['final'], r['events']
    o, rat = {}, {}
    for tk in LEND:
        if tk not in fin or len(fin[tk]['q']) < 70:
            o[f'{tk} ROE y2-20'] = nan; continue
        q = fin[tk]['q']; w = win(q, 1, 20)
        o[f'{tk} ROE y2-20'] = roe(q, 1, 20)
        e = [x['d'] for x in ev.get(tk, [])]
        o[f'{tk} rescues'] = sum('stave off bankruptcy' in d for d in e)
        o[f'{tk} revolver quarters'] = sum(1 for x in q if (x.get('rev_drawn') or 0) > 0)
        o[f'{tk} defaults+Ch11'] = (r['defaults'] or {}).get(tk, 0) + sum(1 for x in (r['reorgs'] or []) if x.get('t') == tk)
        rs = [RANK.index(x['rating']) for x in q if x.get('rating') in RANK]
        o[f'{tk} worst rating idx'] = max(rs) if rs else nan
        o[f'{tk} end rating idx'] = rs[-1] if rs else nan
        rat[tk] = (q[-1].get('rating'), RANK[max(rs)] if rs else None)
        if tk in FOCUS:
            o[f'{tk} ROE y1'] = roe(q, 0, 1)
            o[f'{tk} prov/assets %'] = 100 * sum(x['prov'] for x in w) / (len(w) / 4) / st.mean(x['ta'] for x in w)
            o[f'{tk} allow/book %'] = 100 * st.mean(x['allow'] / x['ea'] for x in w if x['ea'])
            o[f'{tk} eq/assets y2-20 %'] = 100 * st.mean(x['eq'] / x['ta'] for x in w)
            o[f'{tk} eq/assets y20 %'] = 100 * q[-1]['eq'] / q[-1]['ta']
            ni = sum(x['ni'] for x in w)
            o[f'{tk} payout'] = sum(x['div'] + x['bb'] for x in w) / ni if ni > 0 else nan
            o[f'{tk} eq CAGR %'] = cagr(q[0]['eq'], q[-1]['eq'], q[-1]['t'] - q[0]['t'])
    names = {tk: v['q'] for tk, v in fin.items() if tk not in EXCL and len(v['q']) >= 70}
    n = min(len(x) for x in names.values())
    cap = lambda i: sum(x[i]['price'] * x[i]['shares'] for x in names.values())
    yrs = st.mean(x[n - 1]['t'] for x in names.values()) - st.mean(x[3]['t'] for x in names.values())
    o['board mkt-cap CAGR ex funds/POOL/RIVR/TALN/STRK %'] = cagr(cap(3), cap(n - 1), yrs)
    for lab, pick in (('y0', lambda x: x[0]), ('y20', lambda x: x[-1])):
        tot = sum(pick(v['q'])['price'] * pick(v['q'])['shares'] for v in fin.values() if v['q'] and (lab == 'y0' or len(v['q']) >= 70))
        f = sum(pick(v['q'])['price'] * pick(v['q'])['shares'] for tk, v in fin.items() if tk in FINS and v['q'] and (lab == 'y0' or len(v['q']) >= 70))
        o[f'financial cap weight {lab} %'] = 100 * f / tot
    o.update(index_stats(f'{H}/runs/{arm}-{s}.ticks.csv', r['tpy']))
    o['Ch11/run'] = len(r['reorgs'] or []); o['payment-default firms/run'] = len(r['defaults'] or {})
    o['rescues/run'] = sum('stave off bankruptcy' in x['d'] for es in ev.values() for x in es)
    o['failures/run'] = len(r['dead'] or [])
    for tk, v in fin.items():
        fq = v['q']
        if any(x['eq'] < 0 for x in fq): absurd['negative equity'].add(f'{tk}@{s}')
        if any(not math.isfinite(x['price']) or x['price'] <= 0 for x in fq): absurd['non-positive price'].add(f'{tk}@{s}')
        if tk in FINS:
            for y in range(1, 17):
                if roe(fq, y, y + 4) > 0.40: absurd['financial ROE > 40% over 4y'].add(f'{tk}@{s}'); break
            if cagr(fq[0]['eq'], fq[-1]['eq'], fq[-1]['t'] - fq[0]['t']) > 12: absurd['financial equity CAGR > 12%'].add(f'{tk}@{s}')
            if fq[-1]['ta'] and fq[-1]['eq'] / fq[-1]['ta'] < 0.03 and tk in LEND: absurd['lender eq/assets < 3% at y20'].add(f'{tk}@{s}')
    if os.path.getsize(f'{H}/runs/{arm}-{s}.anom.jsonl') > 0: absurd['anomaly file non-empty'].add(str(s))
    return o, rat


args = sys.argv[1:]
seeds = seedlist(args[0] if args else '1-8')
A, B = (args[1], args[2]) if len(args) > 2 else ('rpX2', 'rpBC')
res, rats, absurd = {A: {}, B: {}}, {A: {}, B: {}}, {A: collections.defaultdict(set), B: collections.defaultdict(set)}
for a in res:
    for s in seeds:
        if os.path.exists(f'{H}/runs/{a}-{s}.jsonl'):
            res[a][s], rats[a][s] = per_seed(a, s, absurd[a])
both = [s for s in seeds if s in res[A] and s in res[B]]


def row(k):
    ps = [(res[A][s][k], res[B][s][k]) for s in both if k in res[A][s] and k in res[B][s]
          and not math.isnan(res[A][s][k]) and not math.isnan(res[B][s][k])]
    if len(ps) < 2: return f'| {k} | | | n={len(ps)} | |'
    d = [y - x for x, y in ps]; m = st.mean(d); se = st.stdev(d) / math.sqrt(len(d))
    t = f't{m / se:+.1f}' if se > 0 else ('t=0' if m == 0 else 't inf')
    return (f'| {k} | {st.mean(x for x, _ in ps):.4g} | {st.mean(y for _, y in ps):.4g} | {m:+.3g} ({se:.2g}) {t} '
            f'| {min(d):+.3g}..{max(d):+.3g} | {len(ps)} |')


def table(keys, title):
    print(f'\n## {title}\n| quantity | {A} | {B} | diff (se) t | diff range | n |\n|---|---|---|---|---|---|')
    for k in keys: print(row(k))


print(f'Batch T, seeds {",".join(map(str, both))}: {B} - {A}, paired by seed (replay of recNS macros)')
table([f'{tk} ROE y2-20' for tk in LEND], 'target 1: lender ROE y2-20; lore ' + ', '.join(f'{k} {v}' for k, v in LEND.items()))
table([f'{tk} {m}' for tk in FOCUS for m in ('ROE y1', 'prov/assets %', 'allow/book %', 'eq/assets y2-20 %',
                                              'eq/assets y20 %', 'payout', 'eq CAGR %')], 'TALN / STRK detail')
table([f'{tk} {m}' for tk in LEND for m in ('worst rating idx', 'end rating idx', 'rescues', 'revolver quarters',
                                             'defaults+Ch11')], f'target 2: lender ratings and credit events (rating idx: {", ".join(f"{r}={i}" for i, r in enumerate(RANK[:6]))})')
print('\nend / worst rating by seed:')
for a in res:
    for tk in LEND:
        print(f'  {a} {tk}: ' + ' '.join(f'{rats[a][s][tk][0]}/{rats[a][s][tk][1]}' for s in both if tk in rats[a][s]))
first = res[A][both[0]]
table([k for k in first if not k.startswith(tuple(LEND))], 'target 3: board')
print('\n## absurdity scan (firm@seed)')
for a in res:
    print(f'  {a}: ' + ('; '.join(f'{k}: {sorted(v)}' for k, v in absurd[a].items()) or 'none'))
