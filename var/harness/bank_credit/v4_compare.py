#!/usr/bin/env python3
"""v3 (runs/v3/after-*.jsonl, multiplicative CECL) against v4 (runs/after-*.jsonl, additive CECL).

HEAD (runs/before-*) and v2 (runs/v2/after-*) shown for reference. Same replayed v2 macro paths in every arm.
Cells are mean (se) across seeds; v4-v3 is the paired difference. Max/worst rows give the extreme seed.
"""
import glob, json, math, re, statistics as st
import analyse as A
from v3_compare import extra, load, fmt

H = A.H
ROWS = [
    ('c_cdr', 'corr(allow % book, corp default EMA)'),
    ('c_rp', 'corr(allow % book, recession-prob EMA)'),
    ('allow_mean', 'allowance % book, mean'),
    ('allow_peak', 'allowance % book, peak'),
    ('rel_share', 'reserve_release qtrs raising allowance, % (per-seed)'),
    ('win_excess', 'window cost net of normal qtr, % book'),
    ('roe_mean', 'ROE TTM mean, %'),
    ('roe_p5', 'ROE TTM 5th pct, %'),
    ('cet1_min', 'CET1 minimum, %'),
    ('seizure', 'BANK_SEIZURE per seed'),
    ('nco_over_ttc', 'mean NCO / TTC'),
]
ARMS = ('HEAD', 'v2', 'v3', 'v4')


def main():
    data = {
        'HEAD': load(f'{H}/runs/before-*.jsonl'),
        'v2': load(f'{H}/runs/v2/after-*.jsonl'),
        'v3': load(f'{H}/runs/v3/after-*.jsonl'),
        'v4': load(f'{H}/runs/after-*.jsonl'),
    }
    seeds = sorted(set.intersection(*(set(d) for d in data.values())))
    v4 = data['v4']
    print(f'seeds {seeds[0]}-{seeds[-1]} (n={len(seeds)}), {v4[seeds[0]]["years"]} years, tpy {v4[seeds[0]]["tpy"]}, '
          f'replayed v2 macro paths; v4 replay flag {all(v4[s]["replay"] for s in seeds)}')
    for t in A.BANKS:
        arms = {a: {s: {**A.bank_stats(data[a][s], t), **extra(data[a][s], t)} for s in seeds} for a in ARMS}
        print(f'\n{t}\n{"quantity":52s} ' + ' '.join(f'{a:>16s}' for a in ARMS) + f' {"v4-v3 paired":>16s}  n')
        for key, label in ROWS:
            cells = [fmt([arms[a][s][key] for s in seeds])[0] for a in ARMS]
            pairs = [arms['v4'][s][key] - arms['v3'][s][key] for s in seeds
                     if arms['v4'][s][key] is not None and arms['v3'][s][key] is not None]
            d, n = fmt(pairs)
            print(f'{label:52s} ' + ' '.join(f'{c:>16s}' for c in cells) + f' {d:>16s}  {n}')
        pooled = []
        for a in ARMS:
            up = sum(arms[a][s]['rel_up'] for s in seeds)
            n = sum(arms[a][s]['rel_n'] for s in seeds)
            pooled.append(f'{a} {100 * up / n if n else float("nan"):.0f}% of {n}')
        print(f'{"reserve_release qtrs raising allowance, pooled":52s} ' + ', '.join(pooled))
        for a in ('v3', 'v4'):
            v = arms[a]
            pk = max(seeds, key=lambda s: v[s]['allow_peak'])
            wc = min(seeds, key=lambda s: v[s]['cet1_min'])
            print(f'  {a}: allow peak max {v[pk]["allow_peak"]:.2f} (seed {pk}), median {st.median(x["allow_peak"] for x in v.values()):.2f}; '
                  f'worst CET1 {v[wc]["cet1_min"]:.2f} (seed {wc}); roe_p5 range {min(x["roe_p5"] for x in v.values()):.2f}..'
                  f'{max(x["roe_p5"] for x in v.values()):.2f}; seizures total {sum(x["seizure"] for x in v.values())}')
        for s in (3,):
            print(f'  seed {s}: ' + '; '.join(
                f'{a} allow peak {arms[a][s]["allow_peak"]:.2f} CET1 min {arms[a][s]["cet1_min"]:.2f} '
                f'ROE p5 {arms[a][s]["roe_p5"]:.1f} seizures {arms[a][s]["seizure"]}' for a in ('v3', 'v4')))
    same = all([x['cdr'] for x in v4[s]['final']['LAKE']['q']] == [x['cdr'] for x in data['v3'][s]['final']['LAKE']['q']]
               for s in seeds)
    print(f'\nmacro corporate default EMA identical v3 vs v4 on every seed: {same}')


if __name__ == '__main__':
    main()
