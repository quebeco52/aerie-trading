#!/usr/bin/env python3
"""Phase 1: lender cost base struck on earning assets (EarningsEngine + CommercialBank / CreditServices models).

Arms, seeds 1-16, 20 y, 360 tpy, same mt_srand(seed), both REPLAYING the macro path recorded by a live BEFORE run
(runs/p1/rec-<seed>):
  BEFORE = runs/p1/before-*  (working tree with the nine changed src files at HEAD 0071ab6 = HEAD src exactly)
  AFTER  = runs/p1/after-*   (working tree)
python3 analyse_p1.py > runs/p1/phase1_seeds1-16.out

Levels and slopes follow nii_sensitivity.py (x4 / gross `ea`, %): opex = rev - EBIT - prov, PPNR = EBIT + prov - int,
NIM = reported. fees = revenue less the net_interest_income stream (banks) / the lending stream (card lenders).
Regressors in pp: policyRateEma (now recorded), slope = y10 EMA - y2 EMA, outputGapEma. Slope DIFFERENCES come from
one stacked fit with arm interactions, clustered by seed (both arms of a seed in one cluster), so the pairing is in
the se. ROE / CET1 / P/B / payout stops from analyse_qw.lender(). Quarterly ROE = 4 x NI / mean(eq_prev, eq).
"""
import glob, json, math, re, statistics as st, sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import analyse_qw as QW
import nii_sensitivity as NS

P = f'{QW.A.H}/runs/p1'
LENDERS = QW.LENDERS
BANKS = {'LAKE', 'RIVR', 'PLVR'}
SEEDS = range(1, 17)
WORST = 8
LENDING_STREAMS = ('net_interest_income', 'revolving_lending', 'lending', 'net_interest', 'installment_lending', 'card_lending')


def load(arm):
    out = {}
    for f in glob.glob(f'{P}/{arm}-*.jsonl'):
        m = re.search(rf'/{arm}-(\d+)\.jsonl$', f)
        lines = open(f).read().strip().splitlines()
        if m and lines and int(m.group(1)) in SEEDS:
            out[int(m.group(1))] = json.loads(lines[-1])
    return out


def lending_key(streams):
    for k in LENDING_STREAMS:
        if k in streams:
            return k
    return None


def rows(rec, t):
    out = []
    for x in rec['final'][t]['q']:
        ea = x['ea']
        if ea <= 0:
            continue
        a = 400.0 / ea
        r = {
            'rev': a * x['rev'], 'int': a * x['int'], 'opex': a * (x['rev'] - x['ebit'] - x['prov']),
            'ppnr': a * (x['ebit'] + x['prov'] - x['int']), 'prov': a * x['prov'],
            'ii': a * (x['ii'] or 0.0), 'ppnr_ii': a * (x['ebit'] + x['prov'] - x['int'] + (x['ii'] or 0.0)),
            'pol_ema': 100 * x['pol_ema'], 'slope': 100 * (x['y10'] - x['y2']), 'gap': 100 * x['gap'],
        }
        k = lending_key(x.get('streams') or {})
        if k is not None:
            r['fees'] = a * (x['rev'] - x['streams'][k])
        if t in BANKS and x['nim'] is not None:
            r['nim'] = 100 * x['nim']
        out.append(r)
    return out


def qroe_sd(rec, t):
    q = rec['final'][t]['q']
    v = []
    for i in range(1, len(q)):
        eq = 0.5 * (q[i - 1]['eq'] + q[i]['eq'])
        if eq > 0:
            v.append(400.0 * q[i]['ni'] / eq)
    return st.stdev(v) if len(v) > 2 else float('nan')


def worst8(rs):
    m = st.median(r['ppnr'] for r in rs)
    w = sorted(rs, key=lambda r: r['gap'])[:WORST]
    return st.mean(r['ppnr'] for r in w) - m, m


def ms(xs, d):
    xs = [x for x in xs if QW.fin(x)]
    if not xs:
        return 'n/a'
    se = st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float('nan')
    return f'{st.mean(xs):.{d}f} ({se:.{d}f})'


def prow(label, b, a, d, seeds):
    dd = [a[s] - b[s] for s in seeds if QW.fin(a.get(s)) and QW.fin(b.get(s))]
    print(f'{label:44s} {ms([b.get(s) for s in seeds], d):>17s} {ms([a.get(s) for s in seeds], d):>17s} {ms(dd, d):>17s}  {len(dd):2d}'
          + (f'  range [{min(dd):+.{d}f}, {max(dd):+.{d}f}]' if dd else ''))


def main():
    before, after, rec = load('before'), load('after'), load('rec')
    seeds = sorted(set(before) & set(after))
    same = [s for s in seeds if before[s]['macro_q'] == after[s]['macro_q']]
    r0 = after[seeds[0]]
    secs = [r[s]['secs'] for r in (before, after, rec) for s in r]
    own = lambda recs, wt: all(all(('scratchpad' not in v['file']) == wt for v in recs[s]['class_files'].values()) for s in recs)
    print(f'Phase 1 lender cost base on EA: BEFORE = HEAD 0071ab6 src, AFTER = working tree; seeds {seeds[0]}-{seeds[-1]} '
          f'(n={len(seeds)}), {r0["years"]} y, tpy {r0["tpy"]}, replay {r0["replay"]}')
    print(f'macro path identical in both arms on {len(same)}/{len(seeds)} seeds; wall per run {min(secs):.0f}-{max(secs):.0f} s; '
          f'BEFORE loads its own tree on all seeds: {own(before, False)}; AFTER loads the working tree: {own(after, True)}')
    print(f'cost hook AFTER: {r0["cost_hook"]}; BEFORE: {before[seeds[0]]["cost_hook"]}')
    print('Cells: mean (se) across seeds; diff = per-seed AFTER - BEFORE, same seed and macro path.')

    for t in LENDERS:
        rb = {s: rows(before[s], t) for s in seeds}
        ra = {s: rows(after[s], t) for s in seeds}
        lb = {s: QW.lender(before[s], t) for s in seeds}
        la = {s: QW.lender(after[s], t) for s in seeds}
        sk = sorted((after[seeds[0]]['final'][t]['q'][-1].get('streams') or {}).keys())
        print(f'\n{t}  (streams {sk})')
        print(f'{"quantity":44s} {"BEFORE":>17s} {"AFTER":>17s} {"AFTER-BEFORE":>17s}   n')
        print('-- 1. levels, % of earning assets, annualized')
        for k, lab in [('rev', 'revenue'), ('opex', 'opex (rev - EBIT - prov)'), ('int', 'interest expense'),
                       ('ppnr', 'PPNR (EBIT + prov - int)'), ('ii', 'interest income below EBIT'), ('ppnr_ii', 'PPNR + interest income'),
                       ('nim', 'reported NIM'), ('fees', 'fees (rev - lending stream)'), ('prov', 'provision')]:
            f = lambda R: {s: st.mean(r[k] for r in R[s]) for s in seeds if any(k in r for r in R[s])}
            b, a = f(rb), f(ra)
            if b or a:
                prow(lab, b, a, 3, seeds)
        print('-- 3. capital and returns')
        for k, lab, d in [('roe_mean', 'ROE TTM mean, %', 2), ('roe_p5', 'ROE TTM p5, % (indicative)', 2),
                          ('cet1_mean', 'CET1 mean, %', 2), ('cet1_min', 'CET1 min per seed, % (indicative)', 2),
                          ('pb_mean', 'P/B mean', 3), ('stop_q', 'payout-stop quarters / seed (indicative)', 2),
                          ('dead', 'failed / seed', 2), ('nq', 'report quarters', 1)]:
            prow(lab, {s: lb[s][k] for s in seeds}, {s: la[s][k] for s in seeds}, d, seeds)
        print('-- 4. quarterly ROE volatility')
        prow('sd of quarterly ROE (x4) within seed, pp', {s: qroe_sd(before[s], t) for s in seeds}, {s: qroe_sd(after[s], t) for s in seeds}, 2, seeds)
        print(f'-- 5. PPNR in the {WORST} worst-gap quarters less seed median, pp of EA')
        wb = {s: worst8(rb[s]) for s in seeds}
        wa = {s: worst8(ra[s]) for s in seeds}
        prow('worst-8 minus median', {s: wb[s][0] for s in seeds}, {s: wa[s][0] for s in seeds}, 3, seeds)
        prow('  as % of seed median', {s: 100 * wb[s][0] / wb[s][1] for s in seeds}, {s: 100 * wa[s][0] / wa[s][1] for s in seeds}, 1, seeds)

        print('-- 2. OLS y = a + b pol_ema + c slope + d gap, pooled, CR1 se clustered by seed; diff from the stacked interaction fit')
        print(f'   {"y":10s} {"BEFORE b_pol":>16s} {"AFTER b_pol":>16s} {"diff b_pol":>16s} {"BEFORE b_slope":>16s} {"AFTER b_slope":>16s} {"BEFORE b_gap":>16s} {"AFTER b_gap":>16s} {"R2 B/A":>9s}')
        xs = ['pol_ema', 'slope', 'gap']
        for y, lab in [('opex', 'opex/EA'), ('nim', 'NIM'), ('ppnr', 'PPNR/EA'), ('ppnr_ii', 'PPNR+ii/EA'), ('rev', 'revenue/EA'), ('fees', 'fees/EA'), ('int', 'int exp/EA')]:
            db = [(s, r) for s in seeds for r in rb[s]]
            da = [(s, r) for s in seeds for r in ra[s]]
            if not any(y in r for s, r in da):
                continue
            bb, sb, *_, r2b = NS.ols_cluster(db, y, xs)
            ba, sa, *_, r2a = NS.ols_cluster(da, y, xs)
            stack = [(s, dict(r, D=0.0, Dp=0.0, Ds=0.0, Dg=0.0)) for s, r in db] + \
                    [(s, dict(r, D=1.0, Dp=r['pol_ema'], Ds=r['slope'], Dg=r['gap'])) for s, r in da if y in r]
            bs, ss, *_ = NS.ols_cluster(stack, y, xs + ['D', 'Dp', 'Ds', 'Dg'])
            c = lambda b, s: f'{b:+.3f} ({s:.3f})'
            print(f'   {lab:10s} {c(bb[0], sb[0]):>16s} {c(ba[0], sa[0]):>16s} {c(bs[4], ss[4]):>16s} {c(bb[1], sb[1]):>16s} {c(ba[1], sa[1]):>16s} '
                  f'{c(bb[2], sb[2]):>16s} {c(ba[2], sa[2]):>16s} {r2b:4.2f}/{r2a:4.2f}')

    print('\nBOARD')
    print(f'{"quantity":44s} {"BEFORE":>17s} {"AFTER":>17s} {"AFTER-BEFORE":>17s}   n')
    nf = lambda r: sum((r.get('nonfinite') or {}).values()) if isinstance(r.get('nonfinite'), dict) else 0
    nfq = lambda r: sum(QW.lender(r, t)['nonfinite'] for t in LENDERS)
    prow('bankrupt firms / seed, all firms', {s: len(before[s]['dead'] or {}) for s in seeds}, {s: len(after[s]['dead'] or {}) for s in seeds}, 2, seeds)
    prow('reorganisations / seed', {s: len(before[s]['reorgs']) for s in seeds}, {s: len(after[s]['reorgs']) for s in seeds}, 2, seeds)
    prow('firms with payment-default audits / seed', {s: len(before[s]['defaults_all'] or {}) for s in seeds}, {s: len(after[s]['defaults_all'] or {}) for s in seeds}, 2, seeds)
    prow('non-finite board samples', {s: nf(before[s]) for s in seeds}, {s: nf(after[s]) for s in seeds}, 0, seeds)
    prow('non-finite lender quarter fields', {s: nfq(before[s]) for s in seeds}, {s: nfq(after[s]) for s in seeds}, 0, seeds)
    for name, recs in (('BEFORE', before), ('AFTER', after), ('REC (live, BEFORE tree)', rec)):
        deaths = {}
        for s in sorted(recs):
            for k, y in (recs[s]['dead'] or {}).items():
                deaths.setdefault(k, []).append((s, y))
        print(f'  {name}: deaths (seed, year) {deaths}')


if __name__ == '__main__':
    main()
