#!/usr/bin/env python3
"""l_an.py <armA> <seedsA> <armB> <seedsB>: batch L (SWAN closed at capacity, fund leverage off SWAN's own D/E; POOL
capital target 0.1964). Unpaired arms with their own seed sets. Per firm (SWAN, POOL): TTM ROE (as i_an.py), equity
and revenue CAGR, D/E y0/5/20, (div+bb)/NI, div yield, div and bb $B, split-adjusted share ratio (as j_an.py), cash/eq,
addressable share equity / (1T x nominal GDP index x sam_ratio) peak and end (secular sector drift left out), recap and
expansion bond issues, end/worst rating, EPS beat, quarterly NI sd / mean NI, hedge-fund event counts. Board: k_an.py C
metrics. Rows print mean [min..max] per arm and diff (se) t, se = sqrt(sa^2/na + sb^2/nb)."""
import collections, json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))


def seedlist(s):
    out = []
    for part in s.split(','):
        a, _, b = part.partition('-')
        out += list(range(int(a), int(b or a) + 1))
    return out


A, SA, B, SB = sys.argv[1], seedlist(sys.argv[2]), sys.argv[3], seedlist(sys.argv[4])
ORDER = ['AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C', 'D']
SRC = open(f'{H}/../../../src/Data/Company/InitialMarket.php').read()
TAM = 1e12


def lore(tk, key):
    i = SRC.index(f"'ticker' => '{tk}'"); j = SRC.find("'ticker' =>", i + 10)
    m = re.search(rf"'{key}' => ([0-9.]+)", SRC[i:j if j > 0 else None])
    return float(m.group(1)) if m else float('nan')


def rank(r):
    base = (r or 'D').rstrip('+-')
    return 3 * (ORDER.index(base) if base in ORDER else 9) + ((r.endswith('-') - r.endswith('+')) if r else 0)


def share_ratio(q):
    r = 1.0
    for x, y in zip(q, q[1:]):
        k = y['shares'] / x['shares'] if x['shares'] else 1.0
        for n in (2, 3, 4, 5, 10):
            if abs(k / n - 1) < 0.03:
                k /= n; break
            if abs(k * n - 1) < 0.03:
                k *= n; break
        r *= k
    return r


def cagr(a, b, yrs):
    return (b / a) ** (1 / yrs) - 1 if a > 0 and b > 0 and yrs > 0 else float('nan')


def firm(r, macro, tk):
    q = r['final'][tk]['q']; o = {}
    T = []
    for i in range(7, len(q)):
        w = q[i - 3:i + 1]; me = st.mean(x['eq'] for x in w)
        if me > 0:
            T.append((q[i]['t'], sum(x['ni'] for x in w) / me))
    o['roe'] = st.mean(v for t, v in T)
    o['roe23'] = st.mean([v for t, v in T if 2 <= t < 4] or [float('nan')])
    o['roeL'] = st.mean([v for t, v in T if t >= q[-1]['t'] - 2] or [float('nan')])
    yrs = q[-1]['t'] - q[0]['t']
    o['eqg'] = cagr(q[0]['eq'], q[-1]['eq'], yrs)
    o['revg'] = cagr(sum(x['rev'] for x in q[:4]), sum(x['rev'] for x in q[-4:]), q[-2]['t'] - q[1]['t'])
    for y in (0, 5, 20):
        x = (min(q, key=lambda z: abs(z['t'] - y)) if y else q[0]) if y < 20 else q[-1]
        o[f'de{y}'] = x['debt'] / x['eq'] if x['eq'] > 0 else float('nan')
    ni = sum(x['ni'] for x in q)
    o['pay'] = sum(x['div'] + x['bb'] for x in q) / ni if ni > 0 else float('nan')
    o['dy'] = st.mean(4 * x['div'] / (x['price'] * x['shares']) for x in q if x['price'] * x['shares'] > 0)
    o['div'] = sum(x['div'] for x in q) / 1e9; o['bb'] = sum(x['bb'] for x in q) / 1e9
    o['tot'] = o['div'] + o['bb']
    o['sh'] = share_ratio(q)
    o['ce'] = st.mean(x['cash'] / x['eq'] for x in q if x['eq'] > 0)
    sam = lore(tk, 'sam_ratio'); ms = sorted(macro, key=lambda m: m['t'])
    shr = []
    for x in q:
        m = min(ms, key=lambda z: abs(z['t'] - x['t']))
        shr.append(x['eq'] / (TAM * m['nominal_gdp'] * sam))
    o['shpk'] = max(shr); o['shend'] = shr[-1]; o['sh0'] = shr[0]
    for key, pat in (('rc', 'recapitalization'), ('ex', 'expansion')):
        ev = [float(m.group(1)) for e in r['events'].get(tk, [])
              for m in [re.match(rf'Issued \$([\d.]+)B in bonds for {pat}', e['d'])] if m]
        o[key + '_n'] = len(ev); o[key + '_b'] = sum(ev)
    qb = [x for x in q if x['t'] > 1.0 and x.get('rev', 0) > 0 and x['ceps'] != 0]
    o['beat'] = sum(x['reps'] > x['ceps'] for x in qb) / len(qb) if qb else float('nan')
    nis = [x['ni'] for x in q[1:]]
    o['nisd'] = st.stdev(nis) / st.mean(nis) if st.mean(nis) > 0 else float('nan')
    ev = collections.Counter(x['evt'] for x in q)
    o['mc'] = ev.get('Hf Margin Call', 0); o['blow'] = ev.get('Hf Directional Blowup', 0)
    o['_end'] = q[-1]['rating']; o['_worst'] = max((x['rating'] for x in q), key=rank)
    return o


def board(r):
    fin = r['final']; o = {}
    c0 = c1 = 0.0; t0 = []; t1 = []
    for tk, v in fin.items():
        q = v['q']
        if tk in ('SWAN', 'POOL') or len(q) < 70:
            continue
        c0 += q[0]['price'] * q[0]['shares']; c1 += q[-1]['price'] * q[-1]['shares']
        t0.append(q[0]['t']); t1.append(q[-1]['t'])
    o['mcap'] = (c1 / c0) ** (1 / (st.mean(t1) - st.mean(t0))) - 1
    o['fail'] = len(r['dead'] or []); o['ch11'] = len(r['reorgs'] or []); o['pdef'] = len(r['defaults'] or [])
    o['resc'] = sum(1 for es in r['events'].values() for e in es
                    if 'stave off bankruptcy' in e['d'] and 'Issued new shares' in e['d'])
    pd = pn = 0.0; yd, yc = collections.defaultdict(float), collections.defaultdict(float)
    for tk, v in fin.items():
        q = v['q']
        if not q:
            continue
        ni = sum(x['ni'] for x in q)
        if ni > 0:
            pn += ni; pd += sum(x['div'] + x['bb'] for x in q)
        for x in q:
            yd[int(x['t'])] += x['div']
        for y in set(int(x['t']) for x in q):
            last = [x for x in q if int(x['t']) == y][-1]; yc[y] += last['price'] * last['shares']
    o['pay'] = pd / pn; o['cwdy'] = st.mean([yd[y] / yc[y] for y in yc if yc[y] > 0 and y < 20])
    return o


def load(arm, s):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
    macro = [json.loads(l) for l in open(f'{H}/runs/{arm}-{s}.macro.jsonl') if l.strip()]
    return r, macro


data = {}
for key, arm, seeds in (('A', A, SA), ('B', B, SB)):
    rs = [load(arm, s) for s in seeds]
    data[key] = {'SWAN': [firm(r, m, 'SWAN') for r, m in rs], 'POOL': [firm(r, m, 'POOL') for r, m in rs],
                 'board': [board(r) for r, m in rs]}


def row(lab, key, grp, mult=1.0, f='{:.3g}', t=True):
    a = [mult * x[key] for x in data['A'][grp] if not math.isnan(x[key])]
    b = [mult * x[key] for x in data['B'][grp] if not math.isnan(x[key])]
    cell = lambda v: (f + ' [' + f + '..' + f + ']').format(st.mean(v), min(v), max(v)) if v else 'n/a'
    out = f'| {lab} | {cell(a)} | {cell(b)} |'
    if t and len(a) > 1 and len(b) > 1:
        d = st.mean(b) - st.mean(a); se = math.sqrt(st.variance(a) / len(a) + st.variance(b) / len(b))
        out += f' {d:+.3g} ({se:.2g}) | {d / se if se > 0 else float("nan"):+.1f} |'
    else:
        out += ' | |'
    print(out + f' {len(a)}/{len(b)} |')


FIRM_ROWS = [('TTM ROE y2+ %', 'roe', 100), ('TTM ROE y2-3 %', 'roe23', 100), ('TTM ROE last 2y %', 'roeL', 100),
             ('addressable share y0', 'sh0', 1), ('addressable share peak', 'shpk', 1),
             ('addressable share end', 'shend', 1), ('equity CAGR %', 'eqg', 100), ('revenue CAGR %', 'revg', 100),
             ('D/E y0', 'de0', 1), ('D/E y5', 'de5', 1), ('D/E y20', 'de20', 1), ('(div+bb)/NI', 'pay', 1),
             ('total payout $B', 'tot', 1), ('dividends $B', 'div', 1), ('buybacks $B', 'bb', 1),
             ('div yield %', 'dy', 100), ('shares end/start split-adj', 'sh', 1), ('cash/equity mean', 'ce', 1),
             ('recap bonds n', 'rc_n', 1), ('recap bonds $B', 'rc_b', 1), ('expansion bonds n', 'ex_n', 1),
             ('expansion bonds $B', 'ex_b', 1), ('EPS beat %', 'beat', 100), ('quarterly NI sd / mean NI', 'nisd', 1),
             ('margin-call quarters', 'mc', 1), ('blow-up quarters', 'blow', 1)]
hdr = f'| quantity | {A} {sys.argv[2]} mean [range] | {B} {sys.argv[4]} mean [range] | diff (se) | t | n |'
for tk in ('SWAN', 'POOL'):
    print(f"\n{tk} (lore ROE {lore(tk, 'baseline_roe'):.2f}, sam_ratio {lore(tk, 'sam_ratio')})\n{hdr}\n|---|---|---|---|---|---|")
    for lab, k, m in FIRM_ROWS:
        if tk == 'POOL' and k in ('sh0', 'shpk', 'shend', 'mc', 'blow'):
            continue
        row(lab, k, tk, m)
    for key, arm in (('A', A), ('B', B)):
        print(f"{arm} ratings end: {' '.join(x['_end'] for x in data[key][tk])}; worst: "
              f"{' '.join(x['_worst'] for x in data[key][tk])}")
print(f'\nBoard\n{hdr}\n|---|---|---|---|---|---|')
for lab, k, m in [('mkt-cap CAGR ex SWAN/POOL %', 'mcap', 100), ('failures/run', 'fail', 1), ('Ch11/run', 'ch11', 1),
                  ('payment defaults/run', 'pdef', 1), ('rescue raises/run', 'resc', 1),
                  ('board (div+bb)/NI+', 'pay', 1), ('CW dividend yield %', 'cwdy', 100)]:
    row(lab, k, 'board', m)
