#!/usr/bin/env python3
"""v2 (runs/v2/after-*.jsonl) against v3 (runs/after-*.jsonl), HEAD (runs/before-*.jsonl) for reference.

Same replayed macro paths in every arm. Cells are mean (se) across seeds; v3-v2 is the paired difference.
'release raised allowance' = share of reserve_release-tagged quarters whose allowance level rose on the quarter
(pooled over seeds, and the per-seed mean with its se).
"""
import glob, json, math, os, re, statistics as st
import analyse as A

H = A.H


def load(pattern):
    out = {}
    for f in glob.glob(pattern):
        seed = int(re.search(r'-(\d+)\.jsonl$', f).group(1))
        lines = open(f).read().strip().splitlines()
        if lines:
            out[seed] = json.loads(lines[-1])
    return out


def extra(rec, t):
    q = rec['final'][t]['q']
    gross = [x['ea'] + x['nco'] for x in q]
    ab = [x['allow'] / g for x, g in zip(q, gross)]
    rel = [i for i in range(1, len(q)) if 'reserve_release' in q[i]['ev']]
    up = [1 if q[i]['allow'] > q[i - 1]['allow'] else 0 for i in rel]
    return {
        'c_cdr': A.corr(ab, [x['cdr'] for x in q]),
        'c_rp': A.corr(ab, [x['rp'] for x in q]),
        'rel_n': len(rel), 'rel_up': sum(up),
        'rel_share': 100 * sum(up) / len(rel) if rel else None,
    }


def fmt(xs):
    xs = [x for x in xs if x is not None and math.isfinite(x)]
    if not xs:
        return 'n/a', 0
    se = st.stdev(xs) / math.sqrt(len(xs)) if len(xs) > 1 else float('nan')
    return f'{st.mean(xs):.3f} ({se:.3f})', len(xs)


ROWS = [
    ('c_cdr', 'corr(allow % book, corp default EMA)'),
    ('c_rp', 'corr(allow % book, recession-prob EMA)'),
    ('allow_mean', 'allowance % book, mean'),
    ('allow_peak', 'allowance % book, peak'),
    ('rel_share', 'reserve_release qtrs raising allowance, % (per-seed)'),
    ('nco_over_ttc', 'mean NCO / TTC'),
    ('win_excess', 'window cost net of normal qtr, % book'),
    ('roe_mean', 'ROE TTM mean, %'),
    ('roe_p5', 'ROE TTM 5th pct, %'),
    ('cet1_min', 'CET1 minimum, %'),
    ('seizure', 'BANK_SEIZURE per seed'),
    ('dead', 'failures per seed'),
    ('massive_py', 'massive_credit_provision / bank-yr'),
    ('elevated_py', 'elevated_loan_defaults / bank-yr'),
    ('release_py', 'reserve_release / bank-yr'),
]


def main():
    head = load(f'{H}/runs/before-*.jsonl')
    v2 = load(f'{H}/runs/v2/after-*.jsonl')
    v3 = load(f'{H}/runs/after-*.jsonl')
    seeds = sorted(set(head) & set(v2) & set(v3))
    print(f'seeds {seeds[0]}-{seeds[-1]} (n={len(seeds)}), {v3[seeds[0]]["years"]} years, tpy {v3[seeds[0]]["tpy"]}, '
          f'replayed v2 macro paths; v3 replay flag {all(v3[s]["replay"] for s in seeds)}')
    for t in A.BANKS:
        arms = {}
        for name, d in (('HEAD', head), ('v2', v2), ('v3', v3)):
            arms[name] = {s: {**A.bank_stats(d[s], t), **extra(d[s], t)} for s in seeds}
        print(f'\n{t}\n{"quantity":52s} {"HEAD":>16s} {"v2":>16s} {"v3":>16s} {"v3-v2 paired":>16s}  n')
        for key, label in ROWS:
            cells = [fmt([arms[a][s][key] for s in seeds])[0] for a in ('HEAD', 'v2', 'v3')]
            pairs = [arms['v3'][s][key] - arms['v2'][s][key] for s in seeds
                     if arms['v3'][s][key] is not None and arms['v2'][s][key] is not None]
            d, n = fmt(pairs)
            print(f'{label:52s} {cells[0]:>16s} {cells[1]:>16s} {cells[2]:>16s} {d:>16s}  {n}')
        pooled = []
        for a in ('HEAD', 'v2', 'v3'):
            up = sum(arms[a][s]['rel_up'] for s in seeds)
            n = sum(arms[a][s]['rel_n'] for s in seeds)
            pooled.append(f'{a} {100 * up / n if n else float("nan"):.0f}% of {n}')
        print(f'{"reserve_release qtrs raising allowance, pooled":52s} ' + ', '.join(pooled))
        for a in ('v2', 'v3'):
            v = arms[a]
            print(f'  {a} ranges: allow_peak {min(x["allow_peak"] for x in v.values()):.2f}..{max(x["allow_peak"] for x in v.values()):.2f}, '
                  f'c_cdr {min(x["c_cdr"] for x in v.values()):+.2f}..{max(x["c_cdr"] for x in v.values()):+.2f}, '
                  f'roe_p5 {min(x["roe_p5"] for x in v.values()):.2f}..{max(x["roe_p5"] for x in v.values()):.2f}, '
                  f'cet1_min {min(x["cet1_min"] for x in v.values()):.2f}..{max(x["cet1_min"] for x in v.values()):.2f}')
    same = all(
        [x['cdr'] for x in v3[s]['final']['LAKE']['q']] == [x['cdr'] for x in v2[s]['final']['LAKE']['q']] for s in seeds)
    print(f'\nmacro corporate default EMA identical v2 vs v3 on every seed: {same}')


if __name__ == '__main__':
    main()
