#!/usr/bin/env python3
"""old_ratio.py <seeds> [tag]: realised/configured vol from the 98549819 probe CSVs (runs/old-<seed><tag>.csv,
column tot = the tracker's tick total return), on the same definition as analyse.py section 1: non-overlapping
h-tick windows after the first TPY ticks, pooled per name over seeds. Prints board median ratio, per-seed mean (se),
share > 1.1x and the excess variance over sigma^2 (board mean) at 1, 21, 63 ticks."""
import csv, json, math, statistics as st, sys
from pathlib import Path
H = Path(__file__).resolve().parent
sys.path.insert(0, str(H.parent / 'lib'))
from stats import mean_se  # noqa: E402

seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
tag = sys.argv[2] if len(sys.argv) > 2 else ''
TPY, SKIP, HS = 360, 360, (1, 21, 63)
pooled = {h: {} for h in HS}
perseed = {h: [] for h in HS}
sigma = {}
for s in seeds:
    sigma.update({tk: m['sigma'] for tk, m in json.loads((H / 'runs' / f'old-{s}{tag}.json').read_text())['meta'].items()})
    series = {}
    with open(H / 'runs' / f'old-{s}{tag}.csv') as f:
        for row in csv.DictReader(f):
            t = int(row['t'])
            if t > SKIP:
                series.setdefault(row['tk'], {})[t] = float(row['tot'])
    for h in HS:
        rs = []
        for tk, ser in series.items():
            acc = []
            for start in range(SKIP + 1, max(ser) + 1, h):
                ts = range(start, start + h)
                if all(t in ser for t in ts):
                    acc.append(sum(ser[t] for t in ts))
            if len(acc) < 4:
                continue
            a = pooled[h].setdefault(tk, [0, 0.0, 0.0])
            a[0] += len(acc); a[1] += sum(acc); a[2] += sum(x * x for x in acc)
            n, sm, ss = len(acc), sum(acc), sum(x * x for x in acc)
            rs.append(math.sqrt(max(0.0, (ss / n - (sm / n) ** 2) * TPY / h)) / sigma[tk])
        perseed[h].append(st.median(rs))
print(f'98549819 tree, own probe, seeds {sys.argv[1]}{tag}; tot = tick total return (pre-split, dividend added back)')
print(f'{"h":>4} {"med ratio":>9} {"seed-mean (se)":>16} {">1.1x":>6} {"mean ex var":>11} {"names":>5}')
for h in HS:
    v = {tk: (a[2] / a[0] - (a[1] / a[0]) ** 2) * TPY / h for tk, a in pooled[h].items()}
    rat = {tk: math.sqrt(max(0.0, x)) / sigma[tk] for tk, x in v.items()}
    n, m, se = mean_se(perseed[h])
    print(f'{h:>4} {st.median(rat.values()):>9.3f} {m:>9.3f} ({se:.3f}) {st.mean(x > 1.1 for x in rat.values()):>6.0%} '
          f'{st.mean(v[t] - sigma[t] ** 2 for t in v):>+11.4f} {len(rat):>5}')
