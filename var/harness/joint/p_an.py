#!/usr/bin/env python3
"""p_an.py <seeds> [armA armB]: fee-manager family capacity rule (d12). armB - armA paired by seed (default
rpD12 - rpSW, both replaying runs/recNS-<seed>.macro.gz). Board = o_an.py's definition (alive, >= 70 quarters, cap
from quarter index 3 to the last common one) excluding SWAN, TIER, CROW, VULT and POOL. Per fund: TTM ROE mean from
year 2, (div+bb)/NI, D/E y20, equity CAGR, addressable share end (equity / (1e12 x nominal GDP index x sam_ratio), no
secular drift), expansion / recap bond counts, loss-quarter share. TIER credit events, board sanity (k_an.py
definitions, EW/CW index from analyse.py), and absurdity scans."""
import csv, json, math, os, re, statistics as st, sys, collections

H = os.path.dirname(os.path.abspath(__file__))
FUNDS = {'SWAN': 1.125, 'TIER': 0.70, 'CROW': 0.10, 'VULT': 0.125}  # sam_ratio, InitialMarket.php
LORE = {'SWAN': 22, 'TIER': 24, 'CROW': 20, 'VULT': 23}
EXCL = set(FUNDS) | {'POOL'}


def seedlist(s):
    out = []
    for part in s.split(','):
        a, _, b = part.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


def cagr(a, b, yrs):
    return 100 * ((b / a) ** (1 / yrs) - 1) if a > 0 and b > 0 and yrs > 0 else float('nan')


def ttm_roe(q):
    out = []
    for i in range(7, len(q)):
        w = q[i - 3:i + 1]; me = st.mean(x['eq'] for x in w)
        if me > 0 and q[i]['t'] >= 2:
            out.append((q[i]['t'], sum(x['ni'] for x in w) / me))
    return out


def index_stats(path, tpy):
    with open(path) as f:
        rd = csv.reader(f); hdr = next(rd); tick = [list(map(float, r)) for r in rd]
    n = (len(hdr) - 1) // 2
    ew, cw = [], []
    for t in range(1, len(tick)):
        rs, ws, wr = [], 0.0, 0.0
        for j in range(n):
            a, b = tick[t - 1][1 + 2 * j], tick[t][1 + 2 * j]
            if a > 0 and math.isfinite(a):
                na, nb = tick[t - 1][2 + 2 * j], tick[t][2 + 2 * j]
                k = nb / na if na > 0 and abs(nb / na - 1) > 0.2 else 1.0
                r = (b * k / a - 1) if (b > 0 and math.isfinite(b)) else -1.0
                rs.append(r); w = a * na; ws += w; wr += w * r
        ew.append(math.log1p(sum(rs) / len(rs))); cw.append(math.log1p(wr / ws))
    yrs = len(ew) / tpy; o = {}
    for nm, ser in (('EW', ew), ('CW', cw)):
        o[f'{nm} index return %/y'] = 100 * (math.exp(sum(ser) / yrs) - 1)
        blk = [sum(ser[i:i + 21]) for i in range(0, len(ser) - 20, 21)]
        o[f'{nm} index vol %'] = 100 * st.stdev(blk) * math.sqrt(tpy / 21)
    return o


def per_seed(arm, s, absurd):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
    fin = r['final']
    mac = sorted((json.loads(l) for l in open(f'{H}/runs/{arm}-{s}.macro.jsonl') if l.strip()), key=lambda m: m['t'])
    names = {tk: v['q'] for tk, v in fin.items() if tk not in EXCL and len(v['q']) >= 70}
    n = min(len(q) for q in names.values())
    cap = lambda i: sum(q[i]['price'] * q[i]['shares'] for q in names.values())
    yrs = st.mean(q[n - 1]['t'] for q in names.values()) - st.mean(q[0]['t'] for q in names.values())
    o = {'board mkt-cap CAGR ex funds/POOL %': cagr(cap(3), cap(n - 1), yrs)}
    firm = {tk: cagr(q[3]['price'] * q[3]['shares'], q[n - 1]['price'] * q[n - 1]['shares'], q[n - 1]['t'] - q[3]['t'])
            for tk, q in names.items()}
    ev = r['events']
    for tk, sam in FUNDS.items():
        q = fin[tk]['q']; T = [v for _, v in ttm_roe(q)]; ni = sum(x['ni'] for x in q)
        e = [x['d'] for x in ev.get(tk, [])]
        o[f'{tk} TTM ROE y2+ %'] = 100 * st.mean(T) if T else float('nan')
        o[f'{tk} (div+bb)/NI'] = sum(x['div'] + x['bb'] for x in q) / ni if ni > 0 else float('nan')
        o[f'{tk} D/E y20'] = q[-1]['debt'] / q[-1]['eq'] if q[-1]['eq'] > 0 else float('nan')
        o[f'{tk} equity CAGR %'] = cagr(q[0]['eq'], q[-1]['eq'], q[-1]['t'] - q[0]['t'])
        o[f'{tk} share open'] = q[0]['eq'] / (1e12 * sam)
        o[f'{tk} share end'] = q[-1]['eq'] / (1e12 * mac[-1]['nominal_gdp'] * sam)
        o[f'{tk} expansion bonds'] = sum(bool(re.match(r'Issued \$[\d.]+B in bonds for expansion', d)) for d in e)
        o[f'{tk} recap bonds'] = sum(bool(re.match(r'Issued \$[\d.]+B in bonds for recapitali', d)) for d in e)
        o[f'{tk} loss-quarter share'] = sum(x['ni'] < 0 for x in q) / len(q)
    o['TIER payment defaults'] = (r['defaults'] or {}).get('TIER', 0)
    o['TIER rescues'] = sum('stave off bankruptcy' in d['d'] and 'Issued new shares' in d['d'] for d in ev.get('TIER', []))
    o['TIER Ch11'] = sum(x['t'] == 'TIER' for x in (r['reorgs'] or []))
    o['TIER year-1 NI $B'] = sum(x['ni'] for x in fin['TIER']['q'] if x['t'] < 1) / 1e9
    o['Ch11/run'] = len(r['reorgs'] or []); o['payment defaults/run'] = len(r['defaults'] or {})
    o['rescue raises/run'] = sum('stave off bankruptcy' in e['d'] and 'Issued new shares' in e['d']
                                 for es in ev.values() for e in es)
    o['failures/run'] = len(r['dead'] or [])
    o.update(index_stats(f'{H}/runs/{arm}-{s}.ticks.csv', r['tpy']))
    # absurdity scans over every firm
    for tk, v in fin.items():
        q = v['q']
        if any(x['eq'] < 0 for x in q):
            absurd[arm]['negative equity'].add(f'{tk}@{s}')
        run = 0
        for _, roe in ttm_roe(q):
            run = run + 1 if roe > 0.40 else 0
            if run >= 8:
                absurd[arm]['TTM ROE > 40% for 8+ quarters'].add(f'{tk}@{s}'); break
        for i in range(11, len(q)):
            w = q[i - 11:i + 1]; ni = sum(x['ni'] for x in w)
            if ni > 0 and sum(x['div'] + x['bb'] for x in w) / ni > 1.5 and q[i]['t'] >= 3:
                absurd[arm]['3y (div+bb)/NI > 1.5'].add(f'{tk}@{s}'); break
    return o, firm


args = sys.argv[1:]
seeds = seedlist(args[0] if args else '1-16')
A, B = (args[1:3] + ['rpSW', 'rpD12'][len(args[1:3]):])
seeds = [s for s in seeds if all(os.path.exists(f'{H}/runs/{a}-{s}.jsonl') for a in (A, B))]
absurd = {a: collections.defaultdict(set) for a in (A, B)}
ra, rb, fd = {}, {}, []
for s in seeds:
    ra[s], fa = per_seed(A, s, absurd); rb[s], fb = per_seed(B, s, absurd)
    fd.append({tk: fb[tk] - fa[tk] for tk in fa if tk in fb and not math.isnan(fa[tk] - fb[tk])})

print(f'seeds {",".join(map(str, seeds))} (n={len(seeds)}), {B} - {A} paired by seed; lore ROE '
      + ', '.join(f'{k} {v}%' for k, v in LORE.items()) + '\n')
print('| quantity | A mean | B mean | B-A mean (se) | t | diff range |\n|---|---|---|---|---|---|')
for k in ra[seeds[0]]:
    pairs = [(ra[s][k], rb[s][k]) for s in seeds if not (math.isnan(ra[s][k]) or math.isnan(rb[s][k]))]
    d = [b - a for a, b in pairs]
    if len(d) < 2:
        print(f'| {k} | n={len(d)} |'); continue
    se = st.stdev(d) / math.sqrt(len(d))
    t = f'{st.mean(d) / se:+.1f}' if se > 0 else '-'
    print(f'| {k} | {st.mean(a for a, _ in pairs):.3g} | {st.mean(b for _, b in pairs):.3g} | {st.mean(d):+.3g} ({se:.2g})'
          f' | {t} | {min(d):+.3g}..{max(d):+.3g} |')

lower = [sum(v < 0 for v in f.values()) / len(f) for f in fd]
med = [st.median(f.values()) for f in fd]
pooled = [v for f in fd for v in f.values()]
print(f'\nper-firm mkt-cap CAGR (board ex funds/POOL), {B} - {A}: firms/seed {st.mean(len(f) for f in fd):.0f}; share lower'
      f' in {B} {100 * st.mean(lower):.0f}% (per-seed {100 * min(lower):.0f}-{100 * max(lower):.0f}%, se'
      f' {100 * st.stdev(lower) / math.sqrt(len(lower)):.1f}); median diff pooled {st.median(pooled):+.3f}pp, mean of'
      f' per-seed medians {st.mean(med):+.3f} (se {st.stdev(med) / math.sqrt(len(med)):.3f})')
tks = sorted(set.intersection(*(set(f) for f in fd)))
avg = sorted((st.mean(f[tk] for f in fd), tk) for tk in tks)
print('largest mean diff: lowest ' + ', '.join(f'{tk} {v:+.2f}' for v, tk in avg[:5])
      + '; highest ' + ', '.join(f'{tk} {v:+.2f}' for v, tk in avg[-5:]))
print('\nabsurdity scan (firm@seed; counts per arm, funds/POOL hits, and firms flagged in only one arm):')
for k in ('negative equity', 'TTM ROE > 40% for 8+ quarters', '3y (div+bb)/NI > 1.5'):
    a, b = absurd[A][k], absurd[B][k]
    fund = lambda s: sorted(x for x in s if x.split('@')[0] in EXCL)
    only = lambda s, o: sorted(x for x in s - o)
    print(f'  {k}: {A} {len(a)}, {B} {len(b)}; fund hits {A} {fund(a)} {B} {fund(b)};'
          f' only {A} {only(a, b)[:10]}; only {B} {only(b, a)[:10]}')
