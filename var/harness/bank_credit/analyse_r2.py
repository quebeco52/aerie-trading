#!/usr/bin/env python3
"""Round 2 (household default refit, segment PDs, card/POOL systematic charge-offs, capital-target refi gate, NIM net
of the duration squeeze): BEFORE = HEAD c2f29f4, AFTER = working tree, each on its OWN live macro path (same seed,
mt_srand(seed)), runs/r2/{before,after}-<seed>.jsonl. Timestep check from runs/r2/tpy/{arm}-{tpy}.jsonl.

Cells: mean (se) across seeds; diff = per-seed AFTER - BEFORE (same seed, different macro and board paths).
Macro moments drop the first BURN quarters. Recession window and 'cost net of an ordinary quarter' as analyse.py.
"""
import glob, json, math, os, re, statistics as st
import analyse as A

H = A.H
R2 = f'{H}/runs/r2'
LENDERS = ['LAKE', 'RIVR', 'PLVR', 'TALN', 'STRK', 'POOL']
BANKS = ['LAKE', 'RIVR', 'PLVR']
BURN = 4
STEEP = 0.01


def load(arm):
    out = {}
    for f in glob.glob(f'{R2}/{arm}-*.jsonl'):
        m = re.search(r'/(?:before|after)-(\d+)\.jsonl$', f)
        lines = open(f).read().strip().splitlines()
        if m and lines:
            out[int(m.group(1))] = json.loads(lines[-1])
    return out


def ms(xs, d=3):
    xs = [x for x in xs if x is not None and isinstance(x, (int, float)) and math.isfinite(x)]
    if not xs:
        return 'n/a'
    se = st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float('nan')
    return f'{st.mean(xs):.{d}f} ({se:.{d}f})'


def macro_stats(rec):
    q = rec['macro_q'][BURN:]
    rdr = [100 * x['rdr'] for x in q]
    resgap = [math.log(x['res'] / x['rwt']) for x in q]
    ugap = [x['u'] - x['nairu'] for x in q]
    return {
        'rdr_mean': st.mean(rdr), 'rdr_sd': st.pstdev(rdr), 'rdr_p95': A.pct(rdr, 0.95),
        'corr_resgap': A.corr(rdr, resgap), 'corr_ugap': A.corr(rdr, ugap),
        'cdr_mean': 100 * st.mean(x['cdr'] for x in q), 'cdr_sd': 100 * st.pstdev([x['cdr'] for x in q]),
        'resgap_sd': st.pstdev(resgap), 'ugap_mean': 100 * st.mean(ugap), 'resgap_mean': st.mean(resgap),
        'z_house': 2.65 * st.mean(resgap), 'z_unemp': -18.3 * st.mean(ugap),
        'z_factor': st.mean(x['rcf'] for x in q) if q[0].get('rcf') is not None else float('nan'),
    }


def lender_stats(rec, t):
    f = rec['final'][t]
    q = f['q']
    if not q:
        return None
    gross = [x['ea'] + x['nco'] for x in q]
    nco = [4 * x['nco'] / g if g > 0 else float('nan') for x, g in zip(q, gross)]
    ttc = st.mean(x['ttc'] for x in q)
    excess = [x['prov'] - x['ttc'] / 4 * g for x, g in zip(q, gross)]
    exr = [e / g if g > 0 else 0.0 for e, g in zip(excess, gross)]
    spans = A.windows([x['gap'] for x in q])
    inw = set(i for a, b in spans for i in range(a, b + 1))
    base = [exr[i] for i in range(len(q)) if i not in inw]
    bm = st.mean(base) if base else 0.0
    wex = [100 * (sum(excess[a:b + 1]) / gross[a] - bm * (b - a + 1)) for a, b in spans if gross[a] > 0]
    roe = []
    for i in range(3, len(q)):
        eq = st.mean(x['eq'] for x in q[i - 3:i + 1])
        roe.append(sum(x['ni'] for x in q[i - 3:i + 1]) / eq if eq > 0 else float('nan'))
    roe = [r for r in roe if math.isfinite(r)]
    cet1 = [x['cet1'] for x in q if x['cet1'] is not None]
    rc = [i for i in range(4, len(q)) if q[i - 4]['res'] > 0 and q[i]['res'] > 0]
    nim = [x['nim'] for x in q]
    slope = [x['y10'] - x['y2'] for x in q]
    fin = [i for i in range(len(q)) if nim[i] is not None and math.isfinite(nim[i])]
    inv = [i for i in fin if slope[i] < 0]
    stp = [i for i in fin if slope[i] > STEEP]
    ev = [e for e in rec.get('events', {}).get(t, [])]
    flat = [v for x in q for v in x.values() if isinstance(v, float)]
    return {
        'ttc': 100 * ttc,
        'nco_mean': 100 * st.mean(nco),
        'nco_ttc': st.mean(nco) / ttc if ttc > 0 else float('nan'),
        'win_excess': st.mean(wex) if wex else None,
        'windows': len(spans),
        'corr_cdr': A.corr(nco, [x['cdr'] for x in q]),
        'corr_rdr': A.corr(nco, [x['rdr'] for x in q]),
        'corr_res': A.corr(nco, [x['res'] for x in q]),
        'corr_res_chg': A.corr([nco[i] for i in rc], [math.log(q[i]['res'] / q[i - 4]['res']) for i in rc]),
        'allow_peak': 100 * max(x['allow'] / g for x, g in zip(q, gross) if g > 0),
        'roe_mean': 100 * st.mean(roe) if roe else None,
        'roe_p5': 100 * A.pct(roe, 0.05) if roe else None,
        'cet1_min': 100 * min(cet1) if cet1 else None,
        'dead': 1 if (t in (rec['dead'] or {}) or f['dead']) else 0,
        'seizure': sum(1 for x in q if 'bank_seizure' in x['ev']),
        'reorg': sum(1 for r in rec['reorgs'] if r['t'] == t),
        'defaults': (rec['defaults'] or {}).get(t, 0),
        'shut': sum(1 for e in ev if 'Shut out of the bond market' in e['d']),
        'nim_mean': 100 * st.mean(nim[i] for i in fin) if fin else None,
        'd_nim': 100 * (st.mean(nim[i] for i in stp) - st.mean(nim[i] for i in inv)) if inv and stp else None,
        'nonfinite': sum(1 for v in flat if not math.isfinite(v)),
    }


def asdict(x):
    return x if isinstance(x, dict) else {}


def board_stats(rec):
    bl = asdict(rec.get('board_lines'))
    return {
        'deaths': len(rec['dead'] or {}),
        'reorgs': len(rec['reorgs']),
        'pay_def_firms': len(asdict(rec.get('defaults_all'))),
        'shut_all': sum(v.get('shut', 0) for v in bl.values()),
        'shut_firms': sum(1 for v in bl.values() if v.get('shut', 0) > 0),
        'nonfinite': sum(asdict(rec.get('nonfinite')).values()),
    }


def row(label, b, a, d=3):
    seeds = sorted(set(b) & set(a))
    bb = [b[s] for s in seeds]
    aa = [a[s] for s in seeds]
    dd = [a[s] - b[s] for s in seeds if a[s] is not None and b[s] is not None and math.isfinite(a[s]) and math.isfinite(b[s])]
    print(f'{label:46s} {ms(bb, d):>18s} {ms(aa, d):>18s} {ms(dd, d):>18s}  {len(dd)}')


def hdr(title):
    print(f'\n{title}\n{"quantity":46s} {"BEFORE c2f29f4":>18s} {"AFTER tree":>18s} {"AFTER-BEFORE":>18s}  n')


def main():
    before, after = load('before'), load('after')
    seeds = sorted(set(before) & set(after))
    r0 = after[seeds[0]]
    print(f'round 2: seeds {seeds[0]}-{seeds[-1]} (n={len(seeds)}), {r0["years"]} y, tpy {r0["tpy"]}, live macro in each arm '
          f'(own path); macro moments drop first {BURN} quarters; recession = gap EMA < {A.GAP_CUT} + {A.TAIL}q')
    mb = {s: macro_stats(before[s]) for s in seeds}
    ma = {s: macro_stats(after[s]) for s in seeds}
    hdr('MACRO (quarterly samples)')
    for k, lab in [('rdr_mean', 'retail default EMA mean, %'), ('rdr_sd', 'retail default EMA sd, pp'), ('rdr_p95', 'retail default EMA p95, %'),
                   ('corr_resgap', 'corr(retail EMA, ln(res EMA / trend))'), ('corr_ugap', 'corr(retail EMA, u - NAIRU)'),
                   ('cdr_mean', 'corporate default EMA mean, %'), ('cdr_sd', 'corporate default EMA sd, pp'),
                   ('resgap_sd', 'sd ln(res EMA / trend)'), ('resgap_mean', 'mean ln(res EMA / trend)'), ('ugap_mean', 'mean u - NAIRU, pp'),
                   ('z_house', 'AFTER Z: 2.65 x mean house gap'), ('z_unemp', 'AFTER Z: -18.3 x mean u gap'), ('z_factor', 'AFTER Z: mean OU factor')]:
        row(lab, {s: mb[s][k] for s in seeds}, {s: ma[s][k] for s in seeds})

    rows = [('ttc', 'TTC, %'), ('nco_mean', 'mean NCO, % book'), ('nco_ttc', 'NCO / TTC'), ('win_excess', 'recession cost net of ordinary qtr, % book'),
            ('windows', 'recession windows / seed'), ('corr_cdr', 'corr(NCO, corp default EMA)'), ('corr_rdr', 'corr(NCO, retail default EMA)'),
            ('corr_res', 'corr(NCO, residential EMA)'), ('corr_res_chg', 'corr(NCO, 4q dlog residential)'),
            ('allow_peak', 'allowance peak, % book'), ('roe_mean', 'ROE TTM mean, %'), ('roe_p5', 'ROE TTM p5, %'),
            ('cet1_min', 'CET1 min, %'), ('dead', 'failures / seed'), ('seizure', 'seizures / seed'), ('reorg', 'reorganisations / seed'),
            ('defaults', 'payment-default audits / seed'), ('shut', 'bond-market shut-outs / seed'), ('nim_mean', 'reported NIM mean, %'),
            ('d_nim', 'reported NIM steep - inverted, pp'), ('nonfinite', 'non-finite fields')]
    lb = {t: {s: lender_stats(before[s], t) for s in seeds} for t in LENDERS}
    la = {t: {s: lender_stats(after[s], t) for s in seeds} for t in LENDERS}
    for t in LENDERS:
        hdr(t)
        for k, lab in rows:
            if k in ('cet1_min', 'nim_mean', 'd_nim') and t not in BANKS:
                continue
            if k in ('corr_res', 'corr_res_chg') and t not in ('PLVR', 'POOL', 'LAKE'):
                continue
            bv = {s: (lb[t][s] or {}).get(k) for s in seeds}
            av = {s: (la[t][s] or {}).get(k) for s in seeds}
            row(lab, bv, av, 2 if k not in ('ttc', 'nco_mean') else 3)
        for name, d in (('BEFORE', lb[t]), ('AFTER', la[t])):
            v = [x for x in d.values() if x]
            print(f'  {name}: NCO/TTC range {min(x["nco_ttc"] for x in v):.2f}..{max(x["nco_ttc"] for x in v):.2f}; ROE p5 range '
                  f'{min(x["roe_p5"] for x in v if x["roe_p5"] is not None):.1f}..{max(x["roe_p5"] for x in v if x["roe_p5"] is not None):.1f}; '
                  f'failed seeds {[s for s in seeds if d[s] and d[s]["dead"]]}; shut-out seeds {sum(1 for s in seeds if d[s] and d[s]["shut"])}')

    bb = {s: board_stats(before[s]) for s in seeds}
    ba = {s: board_stats(after[s]) for s in seeds}
    hdr('BOARD')
    for k, lab in [('deaths', 'bankruptcies / seed (all firms)'), ('reorgs', 'reorganisations / seed'), ('pay_def_firms', 'firms with payment-default audits / seed'),
                   ('shut_all', 'bond-market shut-outs / seed (all firms)'), ('shut_firms', 'firms shut out / seed'), ('nonfinite', 'non-finite macro/price/equity/cash samples')]:
        row(lab, {s: bb[s][k] for s in seeds}, {s: ba[s][k] for s in seeds}, 2)
    for name, recs in (('BEFORE', before), ('AFTER', after)):
        deaths, shut = {}, {}
        for s in seeds:
            for k in recs[s]['dead']:
                deaths[k] = deaths.get(k, 0) + 1
            for k, v in asdict(recs[s].get('board_lines')).items():
                if v.get('shut'):
                    shut[k] = shut.get(k, 0) + v['shut']
        print(f'  {name} deaths by ticker: {dict(sorted(deaths.items()))}')
        print(f'  {name} shut-outs by ticker (total over seeds): {dict(sorted(shut.items(), key=lambda kv: -kv[1]))}')
        print(f'  {name} non-finite: {[(s, recs[s]["nonfinite"]) for s in seeds if asdict(recs[s].get("nonfinite"))]}')

    # Timestep check, macro only.
    print('\nTIMESTEP (macro only, no equity cap; 50 y, quarterly samples after year 1): sd of retail default EMA, pp')
    print(f'{"arm":8s} {"tpy 90":>16s} {"tpy 360":>16s} {"360 - 90":>16s} {"ratio 360/90":>14s}  n')
    for arm in ('before', 'after'):
        d = {}
        for tpy in (90, 360):
            fn = f'{R2}/tpy/{arm}-{tpy}.jsonl'
            if os.path.exists(fn):
                d[tpy] = {r['seed']: r for r in map(json.loads, open(fn))}
        if len(d) < 2:
            continue
        ss = sorted(set(d[90]) & set(d[360]))
        sd = {tpy: {s: 100 * st.pstdev(d[tpy][s]['rdr'][4:]) for s in ss} for tpy in d}
        mn = {tpy: {s: 100 * st.mean(d[tpy][s]['rdr'][4:]) for s in ss} for tpy in d}
        diff = [sd[360][s] - sd[90][s] for s in ss]
        if all('rdr_raw' in d[t][s] for t in d for s in ss):
            raw = {tpy: {s: 100 * st.pstdev(d[tpy][s]['rdr_raw'][4:]) for s in ss} for tpy in d}
            print(f'{arm + " raw":8s} {ms(list(raw[90].values())):>16s} {ms(list(raw[360].values())):>16s} '
                  f'{ms([raw[360][s] - raw[90][s] for s in ss]):>16s} {st.mean(raw[360].values()) / st.mean(raw[90].values()):14.3f}  {len(ss)}')
        print(f'{arm:8s} {ms(list(sd[90].values())):>16s} {ms(list(sd[360].values())):>16s} {ms(diff):>16s} '
              f'{st.mean(sd[360].values()) / st.mean(sd[90].values()):14.3f}  {len(ss)}')
        print(f'{"  mean %":8s} {ms(list(mn[90].values())):>16s} {ms(list(mn[360].values())):>16s} {ms([mn[360][s] - mn[90][s] for s in ss]):>16s}')
        nf = sum(d[t][s]['nonfinite'] for t in d for s in ss)
        if nf:
            print(f'  non-finite: {nf}')


if __name__ == '__main__':
    main()
