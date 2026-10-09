#!/usr/bin/env python3
"""Paired before/after table for the commercial banks' credit ledger (runs/{before,after}-<seed>.jsonl).

Recession window: contiguous quarters with the output gap EMA below GAP_CUT, extended by TAIL quarters; overlapping
extended windows merge. Window cost = sum over the extended window of (provision - TTC/4 x gross book), as % of the
gross book in the window's first quarter. 'excess' subtracts the same number of quarters at the seed's own mean
(provision - TTC) rate outside every window, so it reads what a recession costs over an ordinary quarter.
"""
import glob, json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
GAP_CUT = float(os.environ.get('GAP_CUT', '-0.02'))
TAIL = int(os.environ.get('TAIL', '8'))
BANKS = ['LAKE', 'RIVR']


def load(arm):
    out = {}
    for f in glob.glob(f'{H}/runs/{arm}-*.jsonl'):
        seed = int(re.search(r'-(\d+)\.jsonl$', f).group(1))
        lines = open(f).read().strip().splitlines()
        if lines:
            out[seed] = json.loads(lines[-1])
    return out


def pct(xs, p):
    xs = sorted(xs)
    k = (len(xs) - 1) * p
    lo, hi = math.floor(k), math.ceil(k)
    return xs[lo] + (xs[hi] - xs[lo]) * (k - lo)


def corr(a, b):
    if len(a) < 3 or st.pstdev(a) == 0 or st.pstdev(b) == 0:
        return float('nan')
    return st.correlation(a, b)


def windows(gaps):
    inside = [g < GAP_CUT for g in gaps]
    spans = []
    i = 0
    while i < len(inside):
        if inside[i]:
            j = i
            while j + 1 < len(inside) and inside[j + 1]:
                j += 1
            end = min(len(inside) - 1, j + TAIL)
            if spans and i <= spans[-1][1] + 1:
                spans[-1][1] = max(spans[-1][1], end)
            else:
                spans.append([i, end])
            i = j + 1
        else:
            i += 1
    return spans


def bank_stats(rec, ticker):
    f = rec['final'][ticker]
    q = f['q']
    years = rec['years']
    gross = [x['ea'] + x['nco'] for x in q]
    ttc = q[0]['ttc']
    nco_rate = [4 * x['nco'] / g for x, g in zip(q, gross)]
    excess = [x['prov'] - x['ttc'] / 4 * g for x, g in zip(q, gross)]
    excess_rate = [e / g for e, g in zip(excess, gross)]
    spans = windows([x['gap'] for x in q])
    in_win = set(i for a, b in spans for i in range(a, b + 1))
    base = [excess_rate[i] for i in range(len(q)) if i not in in_win]
    base_mean = st.mean(base) if base else 0.0
    wcost, wexcess = [], []
    for a, b in spans:
        c = sum(excess[a:b + 1]) / gross[a]
        wcost.append(100 * c)
        wexcess.append(100 * (c - base_mean * (b - a + 1)))
    roe = []
    for i in range(3, len(q)):
        ni = sum(x['ni'] for x in q[i - 3:i + 1])
        eq = st.mean(x['eq'] for x in q[i - 3:i + 1])
        roe.append(ni / eq if eq > 0 else float('nan'))
    ev = {k: 0 for k in ['massive_credit_provision', 'elevated_loan_defaults', 'reserve_release', 'bank_seizure']}
    for x in q:
        for e in x['ev']:
            ev[e] = ev.get(e, 0) + 1
    flat = [v for x in q for v in x.values() if isinstance(v, float)]
    broken = {
        'nonfinite': sum(1 for v in flat if not math.isfinite(v)),
        'allow_neg': sum(1 for x in q if x['allow'] < 0),
        'book_min_x': min(g / gross[0] for g in gross),
        'book_end_x': gross[-1] / gross[0],
        'allow_cov_min': min(x['allow'] / max(1.0, x['ea']) for x in q) * 100,
    }
    return {
        'nco_mean': 100 * st.mean(nco_rate),
        'ttc': 100 * ttc,
        'nco_over_ttc': st.mean(nco_rate) / ttc,
        'prov_minus_ttc_mean': 100 * 4 * st.mean(excess_rate),
        'windows': len(spans),
        'win_cost': st.mean(wcost) if wcost else None,
        'win_excess': st.mean(wexcess) if wexcess else None,
        'peak_x': max(nco_rate) / ttc,
        'allow_mean': 100 * st.mean(x['allow'] / g for x, g in zip(q, gross)),
        'allow_peak': 100 * max(x['allow'] / g for x, g in zip(q, gross)),
        'corr_cdr': corr(nco_rate, [x['cdr'] for x in q]),
        'corr_gap': corr(nco_rate, [x['gap'] for x in q]),
        'roe_mean': 100 * st.mean(roe),
        'roe_p5': 100 * pct(roe, 0.05),
        'cet1_min': 100 * min(x['cet1'] for x in q if x['cet1'] is not None),
        'seizure': ev['bank_seizure'],
        'dead': 1 if ticker in (rec['dead'] or {}) or f['dead'] else 0,
        'defaults': (rec['defaults'] or {}).get(ticker, 0),
        'massive_py': ev['massive_credit_provision'] / years,
        'elevated_py': ev['elevated_loan_defaults'] / years,
        'release_py': ev['reserve_release'] / years,
        **broken,
    }


def ms(xs):
    xs = [x for x in xs if x is not None and math.isfinite(x)]
    if not xs:
        return 'n/a', 0
    se = st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float('nan')
    return f'{st.mean(xs):8.3f} ({se:.3f})', len(xs)


ROWS = [
    ('nco_mean', 'mean annual NCO, % gross book'),
    ('ttc', 'TTC rate funded, %'),
    ('nco_over_ttc', 'mean NCO / TTC'),
    ('prov_minus_ttc_mean', 'mean (provision - TTC), %/yr'),
    ('windows', 'recession windows per seed'),
    ('win_cost', 'window cost (prov-TTC), % book'),
    ('win_excess', 'window cost over normal qtr, % book'),
    ('peak_x', 'peak annualised NCO / TTC'),
    ('allow_mean', 'allowance / gross book mean, %'),
    ('allow_peak', 'allowance / gross book peak, %'),
    ('corr_cdr', 'corr(NCO rate, corp default EMA)'),
    ('corr_gap', 'corr(NCO rate, output gap EMA)'),
    ('roe_mean', 'ROE TTM mean, %'),
    ('roe_p5', 'ROE TTM 5th pct, %'),
    ('cet1_min', 'CET1 minimum, %'),
    ('seizure', 'BANK_SEIZURE events per seed'),
    ('dead', 'bankruptcies per seed'),
    ('defaults', 'payment-default audits per seed'),
    ('massive_py', 'massive_credit_provision / bank-yr'),
    ('elevated_py', 'elevated_loan_defaults / bank-yr'),
    ('release_py', 'reserve_release / bank-yr'),
    ('nonfinite', 'non-finite fields per seed'),
    ('allow_neg', 'negative-allowance quarters per seed'),
    ('allow_cov_min', 'min allowance / book, %'),
    ('book_min_x', 'min gross book / opening'),
    ('book_end_x', 'end gross book / opening'),
]


def main():
    before, after = load('before'), load('after')
    seeds = sorted(set(before) & set(after))
    yrs = before[seeds[0]]['years'] if seeds else 0
    print(f'seeds {seeds[0]}-{seeds[-1]} (n={len(seeds)}), {yrs} years, tpy {before[seeds[0]]["tpy"]}, replayed macro; '
          f'recession = gap EMA < {GAP_CUT} + {TAIL}q tail; cells = mean (se) across seeds')
    for t in BANKS:
        sb = {s: bank_stats(before[s], t) for s in seeds}
        sa = {s: bank_stats(after[s], t) for s in seeds}
        print(f'\n{t}\n{"quantity":40s} {"BEFORE (HEAD)":>18s} {"AFTER (tree)":>18s} {"AFTER-BEFORE":>18s}  n')
        for key, label in ROWS:
            b, nb = ms([sb[s][key] for s in seeds])
            a, na = ms([sa[s][key] for s in seeds])
            d, nd = ms([sa[s][key] - sb[s][key] for s in seeds if sa[s][key] is not None and sb[s][key] is not None])
            print(f'{label:40s} {b:>18s} {a:>18s} {d:>18s}  {nd}')
        for name, ss in (('BEFORE', sb), ('AFTER', sa)):
            print(f'  {name} ranges: win_cost {min((v["win_cost"] for v in ss.values() if v["win_cost"] is not None), default=float("nan")):.2f}'
                  f'..{max((v["win_cost"] for v in ss.values() if v["win_cost"] is not None), default=float("nan")):.2f}, '
                  f'peak_x {min(v["peak_x"] for v in ss.values()):.2f}..{max(v["peak_x"] for v in ss.values()):.2f}, '
                  f'roe_p5 {min(v["roe_p5"] for v in ss.values()):.2f}..{max(v["roe_p5"] for v in ss.values()):.2f}, '
                  f'cet1_min {min(v["cet1_min"] for v in ss.values()):.2f}..{max(v["cet1_min"] for v in ss.values()):.2f}')

    # The macro's own long-run default-rate EMAs (identical in both arms on a replayed path), against the baselines.
    for key, base in (('rdr', 2.5), ('cdr', 1.6)):
        per = [100 * st.mean(x[key] for x in after[s]['final']['LAKE']['q']) for s in seeds]
        same = all(abs(100 * st.mean(x[key] for x in before[s]['final']['LAKE']['q']) - v) < 1e-9 for s, v in zip(seeds, per))
        m, n = ms(per)
        print(f'macro long-run mean {key} EMA, %: {m} n={n}, range {min(per):.3f}..{max(per):.3f}, baseline {base}; '
              f'arms identical: {same}')


if __name__ == '__main__':
    main()
