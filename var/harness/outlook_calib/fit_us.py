"""Per-industry and per-category fits of permanent (random walk) vs transitory share variance, US BEA 1997-2025.

python3 -I fit_us.py [spec]   spec: main | nogap | fade | pre2020
"""
import json, math, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from kalman import fit_pooled, half_life, moment_q, ratio_interval, steady_gain  # noqa: E402

D = json.load(open(os.path.join(HERE, 'data', 'us_shares.json')))
YEARS = D['years']
SPEC = sys.argv[1] if len(sys.argv) > 1 else 'main'

from categories import CATEGORIES  # noqa: E402


def prep(y):
    y = list(y)
    if SPEC == 'pre2020':
        return y[:YEARS.index(2019) + 1], D['gap'][:YEARS.index(2019) + 1]
    # 2020-2021 lockdown years held out as missing: a disaster the game draws elsewhere, not demand news.
    for yr in (2020, 2021):
        y[YEARS.index(yr)] = None
    return y, D['gap']


def fit(names):
    ys, gaps = [], None
    for n in names:
        y, gaps = prep(D['industries'][n])
        ys.append(y)
    X = () if SPEC in ('nogap',) else (gaps,)
    fade = math.log(2) / 8.0 if SPEC == 'fade' else 0.0
    return ys, fit_pooled(ys, X, fade)


def describe(names, label):
    ys, (ratio, ll, rs, prof) = fit(names)
    lo, hi = ratio_interval(prof)
    etas = [math.sqrt(ratio * r) for r in rs]
    epss = [math.sqrt(r) for r in rs]
    pre = [D['industries'][n][:YEARS.index(2019) + 1] for n in names]
    mq = [moment_q(y)[0] for y in pre]
    mom = math.sqrt(max(0.0, sum(mq) / len(mq)))
    drift = [(y[YEARS.index(2019)] - y[0]) / 22.0 for y in pre]
    return {
        'label': label, 'n': len(names), 'ratio': ratio, 'ratio_lo': lo, 'ratio_hi': hi,
        'eta_med': sorted(etas)[len(etas) // 2], 'eta_rms': math.sqrt(sum(e * e for e in etas) / len(etas)),
        'eps_med': sorted(epss)[len(epss) // 2], 'hl': half_life(ratio), 'hl_lo': half_life(hi), 'hl_hi': half_life(lo),
        'mom_eta_rms': mom, 'etas': dict(zip(names, etas)), 'drift': dict(zip(names, drift)),
    }


def main():
    print(f'spec={SPEC}')
    print('\nPer industry (own q/r):')
    print(f"{'industry':42s} {'drift':>7s} {'eta':>6s} {'eps':>6s} {'q/r':>8s} {'HL':>5s} {'mom_eta':>7s}")
    per = {}
    for n in sorted(D['industries']):
        r = describe([n], n)
        per[n] = r
        print(f"{n:42s} {r['drift'][n]:7.3f} {r['eta_med']:6.3f} {r['eps_med']:6.3f} {r['ratio']:8.3f} {r['hl']:5.1f} {r['mom_eta_rms']:7.3f}")
    print('\nPer category (common q/r, own scale):')
    print(f"{'category':14s} {'n':>2s} {'eta_med':>7s} {'eta_rms':>7s} {'eps_med':>7s} {'q/r':>7s} {'95% q/r':>15s} {'HL':>5s} {'HL 95%':>11s} {'mom_eta':>7s}")
    cats = {}
    for cat, names in CATEGORIES.items():
        names = [n for n in names if n in D['industries']]
        r = describe(names, cat)
        cats[cat] = r
        print(f"{cat:14s} {r['n']:2d} {r['eta_med']:7.3f} {r['eta_rms']:7.3f} {r['eps_med']:7.3f} {r['ratio']:7.3f} "
              f"{r['ratio_lo']:6.3f}-{r['ratio_hi']:7.3f} {r['hl']:5.1f} {r['hl_lo']:4.1f}-{r['hl_hi']:5.1f} {r['mom_eta_rms']:7.3f}")
    json.dump({'per': per, 'cats': cats}, open(os.path.join(HERE, 'data', f'fit_us_{SPEC}.json'), 'w'), indent=1)


if __name__ == '__main__':
    main()
