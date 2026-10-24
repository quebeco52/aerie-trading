"""Revenue y/y (less the firm-seed mean) on the output gap, now and 4 quarters back, pooled over seeds, Newey-West SE
(4 lags, within seed). Plus GDWT's revenue y/y less its mean in each seed's worst-gap year. Usage: slopes.py arm..."""
import glob, json, statistics as st, sys
from pathlib import Path


def load(arm):
    ps = sorted(glob.glob(str(Path(__file__).parent / 'runs' / f'{arm}-s*.json')), key=lambda p: int(p.split('-s')[-1][:-5]))
    return [json.load(open(p)) for p in ps]


def slope(groups, lags=4):
    xs = [x for g in groups for x, _ in g]; ys = [y for g in groups for _, y in g]
    mx, my = st.fmean(xs), st.fmean(ys)
    sxx = sum((x - mx) ** 2 for x in xs)
    b = sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / sxx
    a = my - b * mx
    s = 0.0
    for g in groups:
        u = [(x - mx) * (y - a - b * x) for x, y in g]
        s += sum(v * v for v in u)
        for L in range(1, lags + 1):
            w = 1 - L / (lags + 1)
            s += 2 * w * sum(u[i] * u[i - L] for i in range(L, len(u)))
    return b, (s ** 0.5) / sxx


for arm in sys.argv[1:]:
    D = load(arm)
    for f, stream in (('GDWT', None), ('TSTN', None), ('AVCT', None), ('AVCT', 'audit_and_tax')):
        if f not in D[0]['q']:
            continue
        now, lag = [], []
        for d in D:
            q = d['q'][f]
            rev = [r['streams'][stream] if stream else r['rev'] for r in q]; gap = [r['gap_raw'] for r in q]
            yoy = [rev[i] / rev[i - 4] - 1 for i in range(4, len(q))]
            m = st.fmean(yoy)
            now.append([(gap[i], yoy[i - 4] - m) for i in range(4, len(q))])
            lag.append([(gap[i - 4], yoy[i - 4] - m) for i in range(4, len(q))])
        (b0, s0), (b4, s4) = slope(now), slope(lag)
        line = f'{arm:6s} {f + (" " + stream if stream else "")}: slope on gap now {b0:5.2f} ({s0:.2f}), gap 4q back {b4:5.2f} ({s4:.2f})'
        if stream is None and f in ('GDWT', 'AVCT'):
            worst = []
            for d in D:
                q = d['q'][f]; ny = len(q) // 4
                ann = [sum(r['rev'] for r in q[4 * y:4 * y + 4]) for y in range(ny)]
                g = [st.fmean(r['gap_raw'] for r in q[4 * y:4 * y + 4]) for y in range(ny)]
                gr = [ann[y] / ann[y - 1] - 1 for y in range(1, ny)]
                y = min(range(1, ny), key=lambda k: g[k])
                worst.append(f's{d["seed"]} gap {g[y] * 100:.1f}% rev {100 * (gr[y - 1] - st.fmean(gr)):+.1f}pp')
            line += ' | worst-gap year: ' + '; '.join(worst)
        print(line)
