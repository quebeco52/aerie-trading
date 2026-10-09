#!/usr/bin/env python3
"""PLVR round: PLVR, LAKE and RIVR on the working tree (runs/after-*.jsonl, replay of macro/path-<seed>.gz recorded
by `run.sh rec` from the same tree). RIVR/LAKE are also compared, unpaired, with the v4 batch (runs/v4/after-*.jsonl,
v2 macro paths, board without PLVR).

Cells are mean (se) across seeds. Curve buckets read the 2s10s on the EMAs the NIM squeeze reads (yield10yEma -
yield2yEma): inverted < 0, steep > +1%. The response is the per-seed bucket mean difference (steep - inverted),
on seeds with quarters in both buckets.
"""
import glob, json, math, os, re, statistics as st
import analyse as A

H = A.H
BANKS = ['PLVR', 'LAKE', 'RIVR']
STEEP = 0.01


def load(pattern):
    out = {}
    for f in glob.glob(pattern):
        m = re.search(r'-(\d+)\.jsonl$', f)
        lines = open(f).read().strip().splitlines()
        if m and lines:
            out[int(m.group(1))] = json.loads(lines[-1])
    return out


def fmt(xs, d=2):
    xs = [x for x in xs if x is not None and isinstance(x, (int, float)) and math.isfinite(x)]
    if not xs:
        return 'n/a', 0
    se = st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float('nan')
    return f'{st.mean(xs):.{d}f} ({se:.{d}f})', len(xs)


def ols(x, y):
    mx, my = st.mean(x), st.mean(y)
    sxx = sum((a - mx) ** 2 for a in x)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / sxx if sxx > 0 else float('nan')


def plvr_extra(rec, t):
    f = rec['final'][t]
    q = f['q']
    o = rec['opening'][t]
    gross = [x['ea'] + x['nco'] for x in q]
    nco_rate = [4 * x['nco'] / g for x, g in zip(q, gross)]
    slope = [x['y10'] - x['y2'] for x in q]
    roe_q = [4 * x['ni'] / x['eq'] if x['eq'] > 0 else float('nan') for x in q]
    nii_y = [4 * x['nii'] / g for x, g in zip(q, gross)]
    inv = [i for i, s in enumerate(slope) if s < 0]
    stp = [i for i, s in enumerate(slope) if s > STEEP]

    def bucket(series):
        if not inv or not stp:
            return None
        return st.mean(series[i] for i in stp) - st.mean(series[i] for i in inv)

    nim = [x['nim'] if x['nim'] is not None else float('nan') for x in q]
    om = [x['om'] for x in q]
    # Pre-provision margin: the NIM squeeze lands in the margin, the credit cycle in the provision; strip the latter.
    ppm = [(x['ebit'] + x['prov']) / x['rev'] if x['rev'] > 0 else float('nan') for x in q]
    bank_spread = [x['y10'] - x['y2'] - x['ib'] for x in q]
    res_chg = [math.log(q[i]['res'] / q[i - 4]['res']) if i >= 4 and q[i - 4]['res'] > 0 and q[i]['res'] > 0 else None for i in range(len(q))]
    rc = [i for i in range(len(q)) if res_chg[i] is not None]
    shut = [e['t'] for e in rec.get('events', {}).get(t, []) if 'Shut out of the bond market' in e['d']]
    fin = [i for i in range(len(q)) if math.isfinite(nim[i])]
    pb_open = o['px1'] * o['sh1'] / o['eq']
    last = q[-1]
    flat = [v for x in q for v in x.values() if isinstance(v, float)]
    reorg = sum(1 for r in rec['reorgs'] if r['t'] == t)
    return {
        'cet1_seed': 100 * o['cet1'],
        'cet1_q1': 100 * q[0]['cet1'],
        'pb_seed': o['px'] * o['sh'] / o['eq'],
        'pb_open': pb_open,
        'mcap_open': o['px1'] * o['sh1'] / 1e9,
        'pb_y20': f['px'] * f['sh'] / f['eq'] if f['eq'] > 0 else float('nan'),
        'mcap_y20': f['px'] * f['sh'] / 1e9,
        'pb_lastq': last['px'] * last['sh'] / last['eq'] if last['eq'] > 0 else float('nan'),
        'div_q1': q[0]['div'] / 1e9,
        'div_zero_q': sum(1 for x in q if x['div'] <= 0),
        'cash_dep_q1': 100 * q[0]['cash'] / max(1.0, q[0]['dep']),
        'nim_mean': 100 * st.mean(nim[i] for i in fin) if fin else float('nan'),
        'om_mean': 100 * st.mean(om),
        'inv_q': len(inv),
        'steep_q': len(stp),
        'd_om': None if bucket(om) is None else 100 * bucket(om),
        'd_nim': None if not inv or not stp else 100 * (st.mean(nim[i] for i in stp) - st.mean(nim[i] for i in inv)),
        'd_nii': None if bucket(nii_y) is None else 100 * bucket(nii_y),
        'd_roe': None if bucket(roe_q) is None else 100 * bucket(roe_q),
        'b_om': ols(slope, om),
        'd_ppm': None if bucket(ppm) is None else 100 * bucket(ppm),
        'b_ppm': ols(bank_spread, ppm),
        'corr_res_chg': A.corr([nco_rate[i] for i in rc], [res_chg[i] for i in rc]),
        'shut_n': len(shut),
        'shut_first': min(shut) if shut else None,
        'b_roe': ols(slope, roe_q),
        'b_nim': ols([slope[i] for i in fin], [nim[i] for i in fin]) if len(fin) > 2 else float('nan'),
        'corr_res': A.corr(nco_rate, [x['res'] for x in q]),
        'corr_cre': A.corr(nco_rate, [x['cre'] for x in q]),
        'corr_rdr': A.corr(nco_rate, [x['rdr'] for x in q]),
        'reorg': reorg,
        'events_n': len(rec.get('events', {}).get(t, [])),
        'nonfinite_all': sum(1 for v in flat if not math.isfinite(v)),
        'nim_null': sum(1 for x in q if x['nim'] is None),
    }


ROWS = [
    ('nco_over_ttc', 'mean NCO / TTC', 2),
    ('ttc', 'TTC rate, %', 3),
    ('nco_mean', 'mean annual NCO, % book', 3),
    ('win_excess', 'recession window cost net of normal qtr, % book', 2),
    ('windows', 'recession windows per seed', 2),
    ('allow_mean', 'allowance % book, mean', 2),
    ('allow_peak', 'allowance % book, peak', 2),
    ('roe_mean', 'ROE TTM mean, %', 2),
    ('roe_p5', 'ROE TTM 5th pct, %', 2),
    ('cet1_seed', 'CET1 at seed, %', 2),
    ('cet1_q1', 'CET1 first report, %', 2),
    ('cet1_min', 'CET1 minimum, %', 2),
    ('seizure', 'BANK_SEIZURE events per seed', 2),
    ('dead', 'bankruptcies per seed', 2),
    ('reorg', 'reorganisations per seed', 2),
    ('defaults', 'payment-default audits per seed', 2),
    ('pb_seed', 'P/B at seed', 2),
    ('pb_open', 'P/B after tick 1', 2),
    ('mcap_open', 'market cap after tick 1, $bn', 0),
    ('pb_y20', 'P/B year 20', 2),
    ('mcap_y20', 'market cap year 20, $bn', 0),
    ('div_q1', 'dividend paid first quarter, $bn', 2),
    ('div_zero_q', 'quarters with no dividend per seed', 2),
    ('cash_dep_q1', 'cash / deposits first quarter, %', 2),
    ('nim_mean', 'reported NIM mean, %', 2),
    ('om_mean', 'operating margin mean, %', 2),
    ('inv_q', 'quarters 2s10s < 0 per seed', 1),
    ('steep_q', 'quarters 2s10s > +1% per seed', 1),
    ('d_om', 'op. margin steep - inverted, pp', 2),
    ('d_roe', 'ROE (qtr x4) steep - inverted, pp', 2),
    ('d_nim', 'reported NIM steep - inverted, pp', 3),
    ('d_nii', 'NII / book steep - inverted, pp', 3),
    ('b_om', 'd op. margin / d 2s10s (OLS within seed)', 2),
    ('d_ppm', 'pre-provision margin steep - inverted, pp', 2),
    ('b_ppm', 'd pre-prov. margin / d (2s10s - interbank)', 2),
    ('b_roe', 'd ROE / d 2s10s (OLS within seed)', 2),
    ('corr_res', 'corr(NCO rate, residential property EMA)', 2),
    ('corr_res_chg', 'corr(NCO rate, 4q log change residential EMA)', 2),
    ('corr_cre', 'corr(NCO rate, commercial property EMA)', 2),
    ('corr_rdr', 'corr(NCO rate, retail default EMA)', 2),
    ('corr_cdr', 'corr(NCO rate, corporate default EMA)', 2),
    ('shut_n', 'bond-market shut-outs (forced repay) per seed', 2),
    ('shut_first', 'first shut-out, year (seeds with one)', 1),
    ('allow_neg', 'negative-allowance quarters', 2),
    ('nonfinite_all', 'non-finite fields', 2),
    ('nim_null', 'quarters with no NIM', 2),
]
V4_KEYS = ['nco_over_ttc', 'win_excess', 'allow_peak', 'roe_mean', 'roe_p5', 'cet1_min', 'seizure', 'corr_cdr']


def main():
    cur = load(f'{H}/runs/after-*.jsonl')
    v4 = load(f'{H}/runs/v4/after-*.jsonl')
    seeds = sorted(cur)
    r0 = cur[seeds[0]]
    print(f'working tree with PLVR, seeds {seeds[0]}-{seeds[-1]} (n={len(seeds)}), {r0["years"]} years, tpy {r0["tpy"]}, '
          f'replay {all(cur[s]["replay"] for s in seeds)}; recession = gap EMA < {A.GAP_CUT} + {A.TAIL}q tail; '
          f'2s10s on yield EMAs, steep > {STEEP:.0%}')
    stats = {t: {s: {**A.bank_stats(cur[s], t), **plvr_extra(cur[s], t)} for s in seeds} for t in BANKS}
    print(f'\n{"quantity":48s} ' + ' '.join(f'{t:>18s}' for t in BANKS) + '   n')
    for key, label, d in ROWS:
        cells = [fmt([stats[t][s][key] for s in seeds], d) for t in BANKS]
        print(f'{label:48s} ' + ' '.join(f'{c[0]:>18s}' for c in cells) + f'   {"/".join(str(c[1]) for c in cells)}')
    print()
    for t in BANKS:
        v = stats[t]
        wc = min(seeds, key=lambda s: v[s]['cet1_min'])
        rp = min(seeds, key=lambda s: v[s]['roe_p5'])
        print(f'{t}: worst CET1 {v[wc]["cet1_min"]:.2f}% (seed {wc}); CET1 min range {min(x["cet1_min"] for x in v.values()):.2f}..'
              f'{max(x["cet1_min"] for x in v.values()):.2f}; worst ROE p5 {v[rp]["roe_p5"]:.1f}% (seed {rp}); seizures total '
              f'{sum(x["seizure"] for x in v.values())} on seeds {[s for s in seeds if v[s]["seizure"]]}; deaths '
              f'{sum(x["dead"] for x in v.values())}; P/B y20 range {min(x["pb_y20"] for x in v.values()):.2f}..{max(x["pb_y20"] for x in v.values()):.2f}; '
              f'allow peak max {max(x["allow_peak"] for x in v.values()):.2f}')
    for t in BANKS:
        evs = [(s, e) for s in seeds for e in cur[s].get('events', {}).get(t, [])]
        print(f'{t} distress lines: {len(evs)}' + ('' if not evs else '; first: ' + '; '.join(f's{s} y{e["t"]}: {e["d"][:90]}' for s, e in evs[:4])))
    deaths = {}
    for s in seeds:
        for k in cur[s]['dead']:
            deaths[k] = deaths.get(k, 0) + 1
    print(f'board deaths (all firms) over {len(seeds)} seeds: {deaths}')

    vs = sorted(v4)
    print(f'\nunpaired vs v4 batch (runs/v4/after-*, n={len(vs)}, v2 macro paths, no PLVR): diff se = sqrt(se1^2 + se2^2)')
    for t in ('RIVR', 'LAKE'):
        print(f'{t}\n{"quantity":48s} {"v4 (no PLVR)":>18s} {"now (PLVR)":>18s} {"now - v4":>18s}')
        old = {s: A.bank_stats(v4[s], t) for s in vs}
        for key in V4_KEYS:
            a = [old[s][key] for s in vs if old[s][key] is not None]
            b = [stats[t][s][key] for s in seeds if stats[t][s][key] is not None]
            sa = st.stdev(a) / math.sqrt(len(a))
            sb = st.stdev(b) / math.sqrt(len(b))
            label = next(l for k, l, _ in ROWS if k == key)
            print(f'{label:48s} {fmt(a)[0]:>18s} {fmt(b)[0]:>18s} {st.mean(b) - st.mean(a):10.2f} ({math.hypot(sa, sb):.2f})')


if __name__ == '__main__':
    main()
