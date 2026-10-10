"""Paired NEW-OLD (budget_size copy of stabiliser_double_count/paired.py, adds gsd = sd of annual real output growth, %) per-seed moments from the loopfit CreditMacroProbe outputs.

    python3 paired.py <old dir> <new dir>

Per seed (t > 10y): sd of the quarterly-average gap (as policy_engine_an), its ACF at 4/8/12/16q, and busts as
credit_engine_an counts them (quarterly gap below zero, trough past -2%, deep past -4%). Prints the pooled table, then
the paired difference mean, se and t across seeds.
"""
import glob, json, math, statistics as st, sys

LAGS = (4, 8, 12, 16)


def load(d, tag):
    by = {}
    for f in sorted(glob.glob(f'{d}/{tag}-[0-9]*.json')):
        for r in json.load(open(f)):
            if r['t'] > 10:
                by.setdefault(r['seed'], []).append(r)
    return by


def busts(g):
    out, i, n = [], 0, len(g)
    while i < n:
        if g[i] < 0:
            j = i
            while j < n and g[j] < 0:
                j += 1
            peak = min(g[i:j])
            if peak <= -0.02 and i > 0 and j < n:
                out.append(-peak)
            i = j
        else:
            i += 1
    return out


def moments(q):
    g = [100 * r['gap_avg'] for r in q]
    m = st.mean(g)
    v = st.mean([(x - m) ** 2 for x in g])
    acf = {L: st.mean([(g[i] - m) * (g[i - L] - m) for i in range(L, len(g))]) / v for L in LAGS}
    b = busts([r['gap'] for r in q])
    ly = [math.log(r['pot'] * (1 + r['gap_avg'])) for r in q]
    gy = [100 * (ly[i] - ly[i - 4]) for i in range(4, len(ly), 4)]
    return {'gsd': st.pstdev(gy), 'sd': math.sqrt(v), **{f'acf{L}': acf[L] for L in LAGS}, 'years': len(q) / 4, 'n_bust': len(b),
            'n_deep': sum(x > 0.04 for x in b), 'depth_sum': sum(b)}


arms = {a: {s: moments(q) for s, q in load(d, a).items()} for a, d in (('old', sys.argv[1]), ('new', sys.argv[2]))}
seeds = sorted(set(arms['old']) & set(arms['new']))
print(f"seeds paired: {len(seeds)}")
print(f"{'quantity':<16s} {'OLD':>8s} {'NEW':>8s} {'NEW-OLD':>8s} {'se':>7s} {'t':>6s}   US")
US = {'gsd': '~2.1 (FM +0.5 for 6pp)', 'sd': '2.33 (.26)', 'acf4': '.57', 'acf8': '.26', 'acf12': '.07', 'acf16': '.06'}
for k in ('gsd', 'sd', 'acf4', 'acf8', 'acf12', 'acf16'):
    o = [arms['old'][s][k] for s in seeds]
    n = [arms['new'][s][k] for s in seeds]
    d = [b - a for a, b in zip(o, n)]
    se = st.stdev(d) / math.sqrt(len(d))
    print(f"{k + ' (seed mean)':<16s} {st.mean(o):8.3f} {st.mean(n):8.3f} {st.mean(d):+8.3f} {se:7.3f} {st.mean(d) / se:+6.2f}   {US[k]}")


def pooled(a):
    y = sum(arms[a][s]['years'] for s in seeds)
    nb = sum(arms[a][s]['n_bust'] for s in seeds)
    return y / nb, 100 * sum(arms[a][s]['n_deep'] for s in seeds) / nb, 100 * sum(arms[a][s]['depth_sum'] for s in seeds) / nb, nb


po, pn = pooled('old'), pooled('new')
rate = lambda a, s: arms[a][s]['n_bust'] / arms[a][s]['years'] * 100
d = [rate('new', s) - rate('old', s) for s in seeds]
se = st.stdev(d) / math.sqrt(len(d))
print(f"{'busts 1 per y':<16s} {po[0]:8.1f} {pn[0]:8.1f}   per century {st.mean(d):+.2f} (se {se:.2f}, t {st.mean(d) / se:+.2f})   7.7")
print(f"{'deep >4% share':<16s} {po[1]:7.0f}% {pn[1]:7.0f}%   n busts {po[3]} / {pn[3]}   40%")
print(f"{'mean depth %':<16s} {po[2]:8.2f} {pn[2]:8.2f}   4.31")
