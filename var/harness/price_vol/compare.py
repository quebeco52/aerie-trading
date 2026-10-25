#!/usr/bin/env python3
"""compare.py <seeds> [names]: HEAD probe arm (runs/probe-*.pv.json) against the 98549819 arm (runs/old-*.csv/json).

Per seed and arm, the board median of realised/configured vol at 1 and 21 ticks, raw and with the market jump's
realised variance swapped for its model variance (the jump is one draw shared by every name, ~4 a year, so a few lucky
or unlucky jumps move the whole board). names = common (default, names in both trees) or all. Arms do not pair (the
run is not reproducible on a seed), so the difference's se is sqrt(se_a^2 + se_b^2)."""
import csv, json, math, statistics as st, sys
from pathlib import Path
H = Path(__file__).resolve().parent
sys.path.insert(0, str(H.parent / 'lib'))
from stats import mean_se  # noqa: E402

seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
which = sys.argv[2] if len(sys.argv) > 2 else 'common'
TPY, SKIP, HS = 360, 360, (1, 21)


def wv(n, s, ss, h):
    return (ss / n - (s / n) ** 2) * TPY / h


def old_seed(s):
    meta = json.loads((H / 'runs' / f'old-{s}.json').read_text())
    sig = {t: m['sigma'] for t, m in meta['meta'].items()}
    sysj = {t: a['sysJ'] / a['n'] for t, a in meta['audit'].items()}
    ser = {}
    with open(H / 'runs' / f'old-{s}.csv') as f:
        for row in csv.DictReader(f):
            t = int(row['t'])
            if t > SKIP:
                ser.setdefault(row['tk'], {})[t] = (float(row['tot']), float(row['jS']))
    out = {}
    for h in HS:
        res = {}
        for tk, d in ser.items():
            tot, js = [], []
            for start in range(SKIP + 1, max(d) + 1, h):
                ts = range(start, start + h)
                if all(t in d for t in ts):
                    tot.append(sum(d[t][0] for t in ts)); js.append(sum(d[t][1] for t in ts))
            if len(tot) < 20:
                continue
            v = wv(len(tot), sum(tot), sum(x * x for x in tot), h)
            vj = wv(len(js), sum(js), sum(x * x for x in js), h)
            res[tk] = (v, v - vj + sysj.get(tk, 0.0), sig[tk])
        out[h] = res
    return out


def head_seed(s):
    r = json.loads((H / 'runs' / f'probe-{s}.pv.json').read_text())
    p = r['probe']
    D, j = len(p['names']), p['names'].index('jS')
    out = {}
    for h in HS:
        res = {}
        for tk, w in p['win'][str(h)].items():
            n = w['n']
            if n < 20:
                continue
            t = sum(w['S'][:14]); tt = sum(w['M'][a * D + b] for a in range(14) for b in range(14))
            v = wv(n, t, tt, h)
            vj = wv(n, w['S'][j], w['M'][j * D + j], h)
            res[tk] = (v, v - vj + p['audit'][tk]['sysJ'] / p['audit'][tk]['n'], r['sigma'][tk])
        out[h] = res
    return out


arms = {'98549819': {}, 'HEAD': {}}
for s in seeds:
    if (H / 'runs' / f'old-{s}.csv').exists():
        arms['98549819'][s] = old_seed(s)
    if (H / 'runs' / f'probe-{s}.pv.json').exists():
        arms['HEAD'][s] = head_seed(s)
common = set.intersection(*[set(d[1]) for a in arms.values() for d in a.values()])
print(f'seeds {sys.argv[1]}, names {which} ({len(common)} common), 10y x 360 ticks, first year dropped')
print(f'{"metric":<34} {"98549819":>16} {"HEAD":>16} {"HEAD - old (se)":>17} {"t":>5}')
for h in HS:
    for k, lab in ((0, 'raw'), (1, 'jump at model var')):
        for stat in ('median ratio', 'share > 1.1x', 'mean excess var'):
            vals = {}
            for arm, d in arms.items():
                xs = []
                for s, o in d.items():
                    rows = [v for tk, v in o[h].items() if which == 'all' or tk in common]
                    rat = [math.sqrt(max(0.0, v[k])) / v[2] for v in rows]
                    xs.append(st.median(rat) if stat == 'median ratio' else st.mean(x > 1.1 for x in rat) if stat == 'share > 1.1x'
                              else st.mean(v[k] - v[2] ** 2 for v in rows))
                vals[arm] = mean_se(xs)
            (na, ma, sa), (nb, mb, sb) = vals['98549819'], vals['HEAD']
            se = math.sqrt(sa ** 2 + sb ** 2)
            print(f'{f"h{h} {lab}: {stat}":<34} {ma:>8.3f} ({sa:.3f}) {mb:>8.3f} ({sb:.3f}) {mb - ma:>+8.3f} ({se:.3f}) {(mb - ma) / se if se else float("nan"):>5.1f}')
print(f'n = {len(arms["98549819"])} / {len(arms["HEAD"])} seeds')
