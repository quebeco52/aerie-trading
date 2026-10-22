#!/usr/bin/env python3
"""s_an.py <seeds> [base arm...]: Batch S, final builds of the financial batches (default rpIPP vs rpX1 rpX2), r_an.py
unchanged otherwise; section 5 pairs rpX1 - rpF0 and rpX2 - rpF (Batch R prototypes) and prints keys past |t| 2.
ROE = sum NI / mean equity per year over
the quarters in [a, b) years. Payout from year 2; net payout subtracts issuance (positive share-count steps under
+20% x price). Acquisitions = quarters whose goodwill rises > max($0.1B, 0.5% of equity). Board = alive >= 70
quarters, excluding SWAN TIER CROW VULT POOL RIVR, cap from quarter 3 to the last common one (p_an.py definition)."""
import json, math, os, statistics as st, sys, collections, importlib.util

H = os.path.dirname(os.path.abspath(__file__))
LORE = {'LAKE': .21, 'SWAN': .22, 'KING': .25, 'PERE': .25, 'CORV': .24, 'RIVR': .15, 'PLVR': .11, 'SAFE': .175,
        'DOVE': .14, 'TALN': .25, 'STRK': .25, 'ROOK': .20, 'VULT': .23, 'CROW': .20, 'POOL': .29, 'ACC': .26,
        'TIER': .24, 'EGRT': .13, 'PTRL': .20, 'BNTH': .18}
HL = ['PERE', 'CORV', 'VULT', 'LAKE', 'KING', 'PTRL', 'RIVR', 'ACC', 'EGRT', 'DOVE', 'SAFE']
EXCL = {'SWAN', 'TIER', 'CROW', 'VULT', 'POOL', 'RIVR'}
nan = float('nan')


def seedlist(s):
    out = []
    for part in s.split(','):
        a, _, b = part.partition('-'); out += list(range(int(a), int(b or a) + 1))
    return out


def roe(q, a, b):
    w = [x for x in q if a <= x['t'] < b]
    if len(w) < 4: return nan
    me = st.mean(x['eq'] for x in w)
    return sum(x['ni'] for x in w) / me / (len(w) / 4) if me > 0 else nan


def issuance(q):
    s = 0.0
    for a, b in zip(q, q[1:]):
        if b['t'] >= 1 and a['shares'] > 0 and 1 < b['shares'] / a['shares'] <= 1.2:
            s += (b['shares'] - a['shares']) * b['price']
    return s


def acqs(q):
    return sum(1 for a, b in zip(q, q[1:]) if b['gw'] - a['gw'] > max(1e8, 0.005 * max(a['eq'], 0)))


def cagr(a, b, yrs):
    return 100 * ((b / a) ** (1 / yrs) - 1) if a > 0 and b > 0 and yrs > 0 else nan


# index_stats from p_an.py without running its main block
src = open(f'{H}/p_an.py').read()
ns = {'__file__': f'{H}/p_an.py'}
exec(src.split('\nargs = sys.argv')[0], ns)
index_stats = ns['index_stats']


def per_seed(arm, s, absurd):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
    fin, ev = r['final'], r['events']
    o = {}
    q = fin['TIER']['q']
    for lab, a, b in (('2-5', 1, 5), ('6-10', 5, 10), ('11-15', 10, 15), ('16-20', 15, 20)):
        o[f'TIER ROE y{lab}'] = roe(q, a, b)
    w = [x for x in q if x['t'] >= 1]; ni = sum(x['ni'] for x in w); dv = sum(x['div'] + x['bb'] for x in w)
    o['TIER (div+bb)/NI'] = dv / ni if ni > 0 else nan
    o['TIER (div+bb-iss)/NI'] = (dv - issuance(q)) / ni if ni > 0 else nan
    o['TIER D/E y20'] = q[-1]['debt'] / q[-1]['eq'] if q[-1]['eq'] > 0 else nan
    o['TIER equity CAGR %'] = cagr(q[0]['eq'], q[-1]['eq'], q[-1]['t'] - q[0]['t'])
    o['TIER acquisitions'] = acqs(q)
    e = [x['d'] for x in ev.get('TIER', [])]
    o['TIER rescues'] = sum('stave off bankruptcy' in d for d in e)
    o['TIER revolver draws'] = sum('revolving credit' in d and d.startswith('Drew') for d in e)
    o['TIER revolver quarters'] = sum(1 for x in q if (x.get('rev_drawn') or 0) > 0)
    o['TIER payment defaults'] = sum(1 for x in (r['defaults'] or []) if 'TIER' in str(x))
    rat = {y: next((x['rating'] for x in reversed(q) if x['t'] < y + 0.01), None) for y in (0.3, 5, 10, 15, 20)}
    for tk in LORE:
        if tk not in fin or len(fin[tk]['q']) < 70:
            o[f'{tk} ROE y2-20'] = nan; continue
        fq = fin[tk]['q']; w = [x for x in fq if x['t'] >= 1]; ni = sum(x['ni'] for x in w)
        o[f'{tk} ROE y2-20'] = roe(fq, 1, 20)
        o[f'{tk} payout'] = sum(x['div'] + x['bb'] for x in w) / ni if ni > 0 else nan
        o[f'{tk} eq CAGR'] = cagr(fq[0]['eq'], fq[-1]['eq'], fq[-1]['t'] - fq[0]['t'])
        for i in (0, 19, 79):
            j = min(i, len(fq) - 1)
            o[f'{tk} P/B q{i}'] = fq[j]['price'] * fq[j]['shares'] / fq[j]['eq'] if fq[j]['eq'] > 0 else nan
    # board
    names = {tk: v['q'] for tk, v in fin.items() if tk not in EXCL and len(v['q']) >= 70}
    n = min(len(x) for x in names.values())
    cap = lambda i: sum(x[i]['price'] * x[i]['shares'] for x in names.values())
    yrs = st.mean(x[n - 1]['t'] for x in names.values()) - st.mean(x[3]['t'] for x in names.values())
    o['board mkt-cap CAGR ex funds/POOL/RIVR %'] = cagr(cap(3), cap(n - 1), yrs)
    o['Ch11/run'] = len(r['reorgs'] or []); o['payment defaults/run'] = len(r['defaults'] or [])
    o['rescues/run'] = sum('stave off bankruptcy' in x['d'] for es in ev.values() for x in es)
    o['failures/run'] = len(r['dead'] or [])
    o['M&A (gw steps)/run'] = sum(acqs(v['q']) for v in fin.values())
    for lab, pick in (('y0', lambda x: x[0]), ('y20', lambda x: x[-1])):
        tot = sum(pick(v['q'])['price'] * pick(v['q'])['shares'] for v in fin.values() if v['q'] and (lab == 'y0' or len(v['q']) >= 70))
        f = sum(pick(v['q'])['price'] * pick(v['q'])['shares'] for tk, v in fin.items() if tk in LORE and v['q'] and (lab == 'y0' or len(v['q']) >= 70))
        o[f'financial cap weight {lab} %'] = 100 * f / tot
    o.update(index_stats(f'{H}/runs/{arm}-{s}.ticks.csv', r['tpy']))
    for tk, v in fin.items():
        fq = v['q']
        if any(x['eq'] < 0 for x in fq): absurd['negative equity'].add(f'{tk}@{s}')
        for y in range(1, 17):
            if tk in LORE and roe(fq, y, y + 4) > 0.40:
                absurd['financial ROE > 40% over a 4y window'].add(f'{tk}@{s}'); break
        if tk in LORE and cagr(fq[0]['eq'], fq[-1]['eq'], fq[-1]['t'] - fq[0]['t']) > 12:
            absurd['financial equity CAGR > 12%'].add(f'{tk}@{s}')
    return o, rat


args = sys.argv[1:]
seeds = seedlist(args[0] if args else '1-8')
base = args[1] if len(args) > 1 else 'rpIPP'
arms = args[2:] or ['rpX1', 'rpX2']
res = {a: {} for a in [base] + arms}; rats = {a: {} for a in res}
absurd = {a: collections.defaultdict(set) for a in res}
for a in res:
    for s in seeds:
        if os.path.exists(f'{H}/runs/{a}-{s}.jsonl'):
            res[a][s], rats[a][s] = per_seed(a, s, absurd[a])


def cell(a, k):
    ps = [(res[base][s][k], res[a][s][k]) for s in seeds if s in res[a] and s in res[base]
          and k in res[a][s] and k in res[base][s] and not math.isnan(res[a][s][k]) and not math.isnan(res[base][s][k])]
    if len(ps) < 2: return f'n={len(ps)}', nan
    d = [y - x for x, y in ps]; se = st.stdev(d) / math.sqrt(len(d)); m = st.mean(d)
    t = m / se if se > 0 else nan
    return f'{st.mean(y for _, y in ps):.3g} {m:+.3g} ({se:.2g}) t{t:+.1f}', len(ps)


def bmean(k):
    v = [res[base][s][k] for s in seeds if s in res[base] and k in res[base][s] and not math.isnan(res[base][s][k])]
    return f'{st.mean(v):.3g}' if v else 'nan'


def table(keys, title):
    print(f'\n## {title}\n| quantity | {base} | ' + ' | '.join(f'{a}: mean diff (se) t' for a in arms) + ' | n |')
    print('|---|---|' + '---|' * len(arms) + '---|')
    for k in keys:
        cs = [cell(a, k) for a in arms]
        print(f'| {k} | {bmean(k)} | ' + ' | '.join(c for c, _ in cs) + f' | {cs[0][1]} |')


print(f'Batch S, seeds {",".join(map(str, seeds))}, each arm - {base} paired by seed (replay of recNS macros)')
first = res[base][seeds[0]]
table([k for k in first if k.startswith('TIER') and not k.endswith(('ROE y2-20', 'payout'))], 'TIER (lore ROE 0.24)')
print('\nTIER rating path (modal over seeds at years 0.3/5/10/15/20):')
for a in res:
    path = []
    for y in (0.3, 5, 10, 15, 20):
        c = collections.Counter(rats[a][s][y] for s in rats[a]); path.append('/'.join(f'{r}{n}' for r, n in c.most_common(2)))
    print(f'  {a}: ' + ' -> '.join(path))
table([f'{tk} ROE y2-20' for tk in LORE], 'financials ROE y2-20 (NI / mean equity); lore: '
      + ', '.join(f'{tk} {v}' for tk, v in LORE.items()))
table([f'{tk} {m}' for tk in HL for m in ('payout', 'eq CAGR', 'P/B q0', 'P/B q19', 'P/B q79')], 'highlighted financials')
print('\n## all financials, arm means: ROE y2-20 / payout / P/B q0 q19 q79')
print('| tk | lore | ' + ' | '.join(res) + ' |\n|---|---|' + '---|' * len(res))
for tk in LORE:
    row = []
    for a in res:
        f = lambda k: st.mean([res[a][s][k] for s in res[a] if k in res[a][s] and not math.isnan(res[a][s][k])] or [nan])
        row.append(f"{f(f'{tk} ROE y2-20'):.3f} / {f(f'{tk} payout'):.2f} / {f(f'{tk} P/B q0'):.2f} {f(f'{tk} P/B q19'):.2f} {f(f'{tk} P/B q79'):.2f}")
    print(f'| {tk} | {LORE[tk]} | ' + ' | '.join(row) + ' |')
table([k for k in first if not k.startswith(tuple(LORE))], 'board sanity')
print('\n## absurdity scan (firm@seed)')
for a in res:
    print(f'  {a}: ' + '; '.join(f'{k}: {sorted(v)}' for k, v in absurd[a].items()) or 'none')

# 5. final builds against the Batch R prototypes, paired by seed (same replayed macro), keys past |t| 2
print('\n## final build vs Batch R prototype, paired by seed: keys with |t| >= 2 (mean proto / mean final / diff (se) t)')
for new, proto in (('rpX1', 'rpF0'), ('rpX2', 'rpF')):
    rp, ab = {}, collections.defaultdict(set)
    for s in seeds:
        if os.path.exists(f'{H}/runs/{proto}-{s}.jsonl') and s in res.get(new, {}):
            rp[s], _ = per_seed(proto, s, ab)
    rows = []
    for k in res[new][seeds[0]]:
        ps = [(rp[s][k], res[new][s][k]) for s in rp if k in rp[s] and k in res[new][s]
              and not math.isnan(rp[s][k]) and not math.isnan(res[new][s][k])]
        if len(ps) < 3: continue
        d = [y - x for x, y in ps]; se = st.stdev(d) / math.sqrt(len(d)); m = st.mean(d)
        if se > 0 and abs(m / se) >= 2 or se == 0 and m != 0:
            rows.append(f'| {k} | {st.mean(x for x, _ in ps):.3g} | {st.mean(y for _, y in ps):.3g} | {m:+.3g} ({se:.2g}) '
                        f't{(m / se if se else float("inf")):+.1f} | {len(ps)} |')
    print(f'\n### {new} - {proto}: {len(rows)} keys\n| quantity | {proto} | {new} | diff (se) t | n |\n|---|---|---|---|---|')
    print('\n'.join(rows) or '| none | | | | |')
