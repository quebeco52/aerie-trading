#!/usr/bin/env python3
"""q_an.py <seeds> [A B]: batch Q (DIPR independent-power book leverage norm 1.5 -> 0.50), default rpD12 rpIPP.
Paired by seed (diff = B - A, se = sd(diff)/sqrt(n)) unless noted.
1. DIPR failures: reorganisations (harness reorgs), Chapter 11 lines, events of default ('triggering an event of
   default'), cures, operator checks in payment default; seeds listed.
2. DIPR credit: debt-rising quarters (debt up > 2% q/q, quarter index >= 12) with power above its 3-year trend
   (mean of the 12 prior quarters' power index) and the debt-increase-weighted power deviation there; acquisitions
   (quarters where goodwill rises: count, goodwill added, debt added that quarter); ND / TTM EBITDA median and peak
   (from quarter 4, TTM EBITDA > 0); share of quarters rated BB or below; TTM ROE mean from year 2 (i_an.py); 20y
   (div+bb)/NI; D/E at the last row.
3. Board ex DIPR: market-cap CAGR (k_an.py definition), Ch11/run, payment defaults/run, rescue raises/run, other
   names' failures and reorganisations; EW/CW index return and 21-tick vol (analyse.py definition).
4. Other cyclicals: pooled share of debt-rising quarters (as 2.) with EBIT above its own 3-year mean (12 prior q)."""
import json, math, os, re, statistics as st, sys, csv

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
A, B = (sys.argv[2:4] if len(sys.argv) > 3 else ['rpD12', 'rpIPP'])
CYC = ['SINK', 'FNCH', 'FALC', 'CNDR', 'CASC', 'FULM', 'ALBT', 'BOWR', 'WING', 'CHUF']
ORDER = ['AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C', 'D']
TPY = 360


def low(r):
    base = (r or 'D').rstrip('+-')
    return (ORDER.index(base) if base in ORDER else 9) >= ORDER.index('BB')


def rising(q, key):
    """(debt increase, value vs trailing 12q mean) at debt-rising quarters from index 12."""
    out = []
    for i in range(12, len(q)):
        d0, d1 = q[i - 1]['debt'], q[i]['debt']
        if d0 > 0 and d1 / d0 - 1 > 0.02:
            tr = st.mean(x[key] for x in q[i - 12:i])
            out.append((d1 - d0, q[i][key], tr))
    return out


def index_stats(p):
    with open(p + '.ticks.csv') as f:
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
    o = {}
    yrs = len(ew) / TPY
    for nm, ser in (('ew', ew), ('cw', cw)):
        o[nm + '_r'] = 100 * (math.exp(sum(ser) / yrs) - 1)
        blk = [sum(ser[i:i + 21]) for i in range(0, len(ser) - 20, 21)]
        o[nm + '_v'] = 100 * st.stdev(blk) * math.sqrt(TPY / 21)
    return o


def per_seed(arm, s):
    p = f'{H}/runs/{arm}-{s}'
    r = json.loads(open(p + '.jsonl').readline())
    fin, ev = r['final'], r['events']
    o = {}
    de = ev.get('DIPR', [])
    o['reorg'] = sum(1 for x in (r['reorgs'] or []) if x['t'] == 'DIPR')
    o['ch11'] = sum(1 for e in de if 'Chapter 11' in e['d'])
    o['eod'] = sum(1 for e in de if 'triggering an event of default' in e['d'])
    o['cure'] = sum(1 for e in de if 'cured its event of default' in e['d'])
    o['pdchk'] = (r['defaults'] or {}).get('DIPR', 0)
    q = fin['DIPR']['q']
    R = rising(q, 'power')
    o['n_rise'] = len(R)
    o['pw_up'] = 100 * sum(1 for d, v, t in R if v > t) / len(R) if R else float('nan')
    o['pw_dev'] = 100 * sum(d * (v / t - 1) for d, v, t in R) / sum(d for d, v, t in R) if R else float('nan')
    acq = [(q[i]['gw'] - q[i - 1]['gw'], q[i]['debt'] - q[i - 1]['debt']) for i in range(1, len(q))
           if q[i]['gw'] > q[i - 1]['gw'] * 1.001 + 1e6]
    o['acq_n'] = len(acq); o['acq_gw'] = sum(a for a, d in acq) / 1e9
    o['acq_debt'] = sum(max(d, 0) for a, d in acq) / 1e9
    nde = []
    for i in range(3, len(q)):
        e4 = sum(x['ebitda'] for x in q[i - 3:i + 1])
        if e4 > 0:
            nde.append((q[i]['debt'] - q[i]['cash']) / e4)
    o['nde_med'] = st.median(nde); o['nde_pk'] = max(nde)
    o['bb'] = 100 * sum(low(x['rating']) for x in q) / len(q)
    T = []
    for i in range(7, len(q)):
        w = q[i - 3:i + 1]; me = st.mean(x['eq'] for x in w)
        if me > 0 and q[i]['t'] >= 2:
            T.append(sum(x['ni'] for x in w) / me)
    o['roe'] = 100 * st.mean(T)
    ni = sum(x['ni'] for x in q)
    o['pay'] = sum(x['div'] + x['bb'] for x in q) / ni if ni > 0 else float('nan')
    o['de20'] = q[-1]['debt'] / q[-1]['eq'] if q[-1]['eq'] > 0 else float('nan')
    # board ex DIPR
    c0 = c1 = 0.0; t0 = []; t1 = []
    for tk, v in fin.items():
        qq = v['q']
        if tk == 'DIPR' or len(qq) < 70:
            continue
        c0 += qq[0]['price'] * qq[0]['shares']; c1 += qq[-1]['price'] * qq[-1]['shares']
        t0.append(qq[0]['t']); t1.append(qq[-1]['t'])
    o['mcap'] = 100 * ((c1 / c0) ** (1 / (st.mean(t1) - st.mean(t0))) - 1)
    o['b_ch11'] = len(r['reorgs'] or [])
    o['b_pdef'] = len(r['defaults'] or {})
    o['b_resc'] = sum(1 for es in ev.values() for e in es
                      if 'stave off bankruptcy' in e['d'] and 'Issued new shares' in e['d'])
    oth = [x['t'] for x in (r['reorgs'] or []) if x['t'] != 'DIPR'] + [t for t in (r['dead'] or []) if t != 'DIPR']
    o['oth_fail'] = len(oth); o['oth_names'] = oth
    o['oth_pdef'] = [t for t in (r['defaults'] or {}) if t != 'DIPR']
    o.update(index_stats(p))
    # other cyclicals
    up = n = 0
    for tk in CYC:
        if tk in fin:
            Rc = rising(fin[tk]['q'], 'ebit')
            n += len(Rc); up += sum(1 for d, v, t in Rc if v > t)
    o['cyc_n'] = n; o['cyc_up'] = 100 * up / n if n else float('nan')
    return o


res = {arm: [per_seed(arm, s) for s in seeds] for arm in (A, B)}
rows = [('1. DIPR failures', None), ('reorg', 'reorganisations/run'), ('ch11', 'Chapter 11 lines/run'),
        ('eod', 'events of default/run'), ('cure', 'cured defaults/run'), ('pdchk', 'operator checks in default/run'),
        ('2. DIPR borrowing and credit', None), ('n_rise', 'debt-rising quarters (q>=12)'),
        ('pw_up', 'share of them with power > 3y trend %'), ('pw_dev', 'amount-weighted power deviation %'),
        ('acq_n', 'acquisitions (goodwill rises)/run'), ('acq_gw', 'goodwill added $B/run'),
        ('acq_debt', 'debt added in those quarters $B/run'), ('nde_med', 'ND/TTM EBITDA median'),
        ('nde_pk', 'ND/TTM EBITDA peak'), ('bb', 'quarters rated BB or below %'), ('roe', 'TTM ROE y2+ %'),
        ('pay', '(div+bb)/NI 20y'), ('de20', 'D/E y20'),
        ('3. Board ex DIPR', None), ('mcap', 'board mkt-cap CAGR ex DIPR %'), ('b_ch11', 'Ch11/run (all names)'),
        ('b_pdef', 'names in payment default/run (all)'), ('b_resc', 'rescue raises/run'),
        ('oth_fail', 'other names failed or reorganised/run'), ('ew_r', 'EW index return %/y'),
        ('ew_v', 'EW index vol 21t %'), ('cw_r', 'CW index return %/y'), ('cw_v', 'CW index vol 21t %'),
        ('4. Other cyclicals', None), ('cyc_n', 'debt-rising quarters, 10 cyclicals'),
        ('cyc_up', 'share with EBIT > own 3y mean %')]
print(f'| quantity | {A} mean | {B} mean | diff (se) | t | n |')
print('|---|---|---|---|---|---|')
for k, lab in rows:
    if lab is None:
        print(f'| **{k}** | | | | | |'); continue
    pr = [(a[k], b[k]) for a, b in zip(res[A], res[B]) if not (math.isnan(a[k]) or math.isnan(b[k]))]
    a = [x for x, y in pr]; b = [y for x, y in pr]; d = [y - x for x, y in pr]
    se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
    t = st.mean(d) / se if se > 0 else float('nan')
    print(f'| {lab} | {st.mean(a):.3g} | {st.mean(b):.3g} | {st.mean(d):+.3g} ({se:.2g}) | {t:+.2f} | {len(d)} |')
print()
for arm in (A, B):
    L = res[arm]
    seedlist = lambda k: [s for s, x in zip(seeds, L) if x[k] > 0]
    print(f'{arm}: DIPR reorg seeds {seedlist("reorg")}, EoD seeds {seedlist("eod")}, cure seeds {seedlist("cure")}, '
          f'acquisition seeds {seedlist("acq_n")}')
    print(f'  other failures: {[(s, x["oth_names"]) for s, x in zip(seeds, L) if x["oth_names"]]}')
    print(f'  other payment defaults: {[(s, x["oth_pdef"]) for s, x in zip(seeds, L) if x["oth_pdef"]]}')
    print(f'  end D/E per seed: {" ".join(f"{x["de20"]:.2f}" for x in L)}')
# pooled DIPR power timing over all debt-rising quarters
for arm in (A, B):
    allR = []
    for s in seeds:
        q = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())['final']['DIPR']['q']
        allR += rising(q, 'power')
    if allR:
        print(f'{arm} pooled DIPR: {len(allR)} debt-rising q, {100 * sum(v > t for d, v, t in allR) / len(allR):.0f}% '
              f'power > trend, weighted dev {100 * sum(d * (v / t - 1) for d, v, t in allR) / sum(d for d, v, t in allR):+.1f}%')
