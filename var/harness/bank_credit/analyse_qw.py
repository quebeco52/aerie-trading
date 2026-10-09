#!/usr/bin/env python3
"""Bank quick-wins round: loss rates on the loan share (0.78) of earning assets, RWA density from the loan mix,
BANK_SEIZURE -> CAPITAL_BELOW_MINIMUM.

Arms, seeds 1-16, 20 y, 360 tpy, same mt_srand(seed), both REPLAYING the macro path recorded by a live working-tree
run (runs/qw/rec-<seed>):
  BEFORE = runs/qw/before-*  (working tree with the five quick-wins files reverted to HEAD 41b4bde)
  AFTER  = runs/qw/after-*   (working tree)
python3 analyse_qw.py > runs/qw/quickwins_seeds1-16.out

Book = gross earning assets (ea + nco at the report), loans = 0.78 x book. Recession cost net of an ordinary quarter
as analyse.py / analyse_r2.py. ROE = TTM NI / mean equity of the four quarters. Payout stop binding: CET1 at the report
below capitalRequirement(macro) + CCyB EMA, the test getRegulatoryDividendCap() applies.
"""
import glob, json, math, re, statistics as st
import analyse as A

Q = f'{A.H}/runs/qw'
LENDERS = ['LAKE', 'RIVR', 'PLVR', 'TALN', 'STRK', 'POOL']
LOAN_SHARE = 0.78
SEEDS = range(1, 17)


def load(arm):
    out = {}
    for f in glob.glob(f'{Q}/{arm}-*.jsonl'):
        m = re.search(rf'/{arm}-(\d+)\.jsonl$', f)
        lines = open(f).read().strip().splitlines()
        if m and lines and int(m.group(1)) in SEEDS:
            out[int(m.group(1))] = json.loads(lines[-1])
    return out


def fin(x):
    return x is not None and isinstance(x, (int, float)) and math.isfinite(x)


def ms(xs, d):
    xs = [x for x in xs if fin(x)]
    if not xs:
        return 'n/a'
    se = st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float('nan')
    return f'{st.mean(xs):.{d}f} ({se:.{d}f})'


def lender(rec, t):
    f = rec['final'][t]
    q = f['q']
    gross = [x['ea'] + x['nco'] for x in q]
    nco = [4 * x['nco'] / g for x, g in zip(q, gross) if g > 0]
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
    allow_loans = [x['allow'] / (LOAN_SHARE * g) for x, g in zip(q, gross) if g > 0]
    pb = [x['px'] * x['sh'] / x['eq'] for x in q if x['eq'] > 0]
    stop = sum(1 for x in q if x['cet1'] is not None and x.get('req') is not None and x['cet1'] < x['req'] + x['ccyb'])
    below_min = sum(1 for x in q if 'bank_seizure' in x['ev'] or 'capital_below_minimum' in x['ev'])
    rwd = [x['rwd'] for x in q if x.get('rwd') is not None]
    flat = [v for x in q for v in x.values() if isinstance(v, float)]
    return {
        'nco_ea': 100 * st.mean(nco),
        'nco_loans': 100 * st.mean(nco) / LOAN_SHARE,
        'ttc': 100 * ttc,
        'nco_ttc': st.mean(nco) / ttc if ttc > 0 else float('nan'),
        'allow_mean': 100 * st.mean(allow_loans),
        'allow_peak': 100 * max(allow_loans),
        'win_excess': st.mean(wex) if wex else None,
        'windows': len(spans),
        'cet1_mean': 100 * st.mean(cet1),
        'cet1_min': 100 * min(cet1),
        'stop_q': stop,
        'below_min': below_min,
        'roe_mean': 100 * st.mean(roe),
        'roe_p5': 100 * A.pct(roe, 0.05),
        'pb_mean': st.mean(pb),
        'rwd_mean': st.mean(rwd) if rwd else None,
        'dead': 1 if (t in (rec['dead'] or {}) or f['dead']) else 0,
        'nonfinite': sum(1 for v in flat if not math.isfinite(v)),
        'nq': len(q),
        'nim_mean': 100 * st.mean(x['nim'] for x in q if fin(x['nim'])) if any(fin(x['nim']) for x in q) else None,
        'prov_ea': 100 * st.mean(4 * x['prov'] / g for x, g in zip(q, gross) if g > 0),
        'eq_end_open': q[-1]['eq'] / rec['opening'][t]['eq'],
    }


ROWS = [
    ('nco_ea', 'NCO, % earning assets', 3),
    ('nco_loans', 'NCO, % loans (EA x 0.78)', 3),
    ('ttc', 'TTC rate, % earning assets', 3),
    ('nco_ttc', 'NCO / TTC', 3),
    ('allow_mean', 'allowance, % loans, mean', 2),
    ('allow_peak', 'allowance, % loans, peak', 2),
    ('windows', 'recession windows / seed', 2),
    ('win_excess', 'recession cost net of ordinary qtr, % book', 2),
    ('cet1_mean', 'CET1 mean, %', 2),
    ('cet1_min', 'CET1 min (per-seed), %', 2),
    ('stop_q', 'payout-stop quarters / seed (of 80)', 2),
    ('below_min', 'BANK_SEIZURE / CAPITAL_BELOW_MINIMUM / seed', 2),
    ('roe_mean', 'ROE TTM mean, %', 2),
    ('roe_p5', 'ROE TTM p5, %', 2),
    ('pb_mean', 'P/B mean', 3),
    ('prov_ea', 'provision, % earning assets / yr', 3),
    ('nim_mean', 'reported NIM mean, %', 3),
    ('eq_end_open', 'book equity y20 / opening', 3),
    ('rwd_mean', 'RWA / earning assets mean (AFTER only)', 3),
    ('dead', 'failures / seed', 2),
    ('nonfinite', 'non-finite quarter fields', 0),
]


def row(label, b, a, d):
    seeds = sorted(set(b) & set(a))
    dd = [a[s] - b[s] for s in seeds if fin(a[s]) and fin(b[s])]
    print(f'{label:46s} {ms([b[s] for s in seeds], d):>18s} {ms([a[s] for s in seeds], d):>18s} {ms(dd, d):>18s}  {len(dd):2d}')


def main():
    before, after, rec = load('before'), load('after'), load('rec')
    seeds = sorted(set(before) & set(after))
    same = [s for s in seeds if before[s]['macro_q'] == after[s]['macro_q']]
    r0 = after[seeds[0]]
    secs = [r[s]['secs'] for r in (before, after, rec) for s in r]
    print(f'Bank quick wins: BEFORE = working tree with the five files at HEAD 41b4bde, AFTER = working tree; '
          f'seeds {seeds[0]}-{seeds[-1]} (n={len(seeds)}), {r0["years"]} y, tpy {r0["tpy"]}, replay {r0["replay"]}')
    print(f'macro path identical in both arms on {len(same)}/{len(seeds)} seeds; run wall time per run '
          f'{min(secs):.0f}-{max(secs):.0f} s (mean {st.mean(secs):.0f}); BEFORE model file {before[seeds[0]]["bank_model_file"]}')
    print('Cells: mean (se) across seeds; diff = per-seed AFTER - BEFORE on the same seed and macro path.')
    for t in LENDERS:
        b = {s: lender(before[s], t) for s in seeds}
        a = {s: lender(after[s], t) for s in seeds}
        ob, oa = before[seeds[0]]['opening'][t], after[seeds[0]]['opening'][t]
        print(f'\n{t}  (opening CET1 {100 * ob["cet1"]:.2f} -> {100 * oa["cet1"]:.2f}%, TTC {100 * ob["ttc"]:.3f} -> {100 * oa["ttc"]:.3f}% of EA)')
        print(f'{"quantity":46s} {"BEFORE":>18s} {"AFTER":>18s} {"AFTER-BEFORE":>18s}   n')
        for k, lab, d in ROWS:
            row(lab, {s: b[s][k] for s in seeds}, {s: a[s][k] for s in seeds}, d)
    print('\nBOARD')
    print(f'{"quantity":46s} {"BEFORE":>18s} {"AFTER":>18s} {"AFTER-BEFORE":>18s}   n')
    nf = lambda r: sum((r.get('nonfinite') or {}).values()) if isinstance(r.get('nonfinite'), dict) else 0
    row('failed (bankrupt) firms / seed, all firms', {s: len(before[s]['dead'] or {}) for s in seeds}, {s: len(after[s]['dead'] or {}) for s in seeds}, 2)
    row('reorganisations / seed', {s: len(before[s]['reorgs']) for s in seeds}, {s: len(after[s]['reorgs']) for s in seeds}, 2)
    row('firms with payment-default audits / seed', {s: len(before[s]['defaults_all'] or {}) for s in seeds}, {s: len(after[s]['defaults_all'] or {}) for s in seeds}, 2)
    row('non-finite board samples (macro, price, equity, cash)', {s: nf(before[s]) for s in seeds}, {s: nf(after[s]) for s in seeds}, 0)
    for name, recs in (('BEFORE', before), ('AFTER', after), ('REC (live)', rec)):
        deaths = {}
        for s in sorted(recs):
            for k, y in (recs[s]['dead'] or {}).items():
                deaths.setdefault(k, []).append((s, y))
        print(f'  {name}: deaths (seed, year) {deaths}')


if __name__ == '__main__':
    main()
