"""Arms makers' orders per unit of defence spending: annual log changes of US national defence gross investment
(FDEFX less consumption A997RC1Q027SBEA: equipment, structures, R&D) on those of total defence purchases (FDEFX), FRED."""
import csv, math, os, statistics as st
H = os.path.dirname(os.path.abspath(__file__))
fred = lambda i: {r['observation_date']: float(r[i]) for r in csv.DictReader(open(os.path.join(H, 'fred', i + '.csv'))) if r[i] not in ('', '.')}
tot, con = fred('FDEFX'), fred('A997RC1Q027SBEA')
ann = {}
for k in tot:
    if k in con:
        a = ann.setdefault(int(k[:4]), [0.0, 0.0]); a[0] += tot[k]; a[1] += tot[k] - con[k]
for y0, y1 in ((1949, 2019), (1955, 2019), (1985, 2019)):
    X = [math.log(ann[y][0] / ann[y - 1][0]) for y in range(y0, y1 + 1)]
    Y = [math.log(ann[y][1] / ann[y - 1][1]) for y in range(y0, y1 + 1)]
    mx, my = st.mean(X), st.mean(Y)
    b = sum((a - mx) * (c - my) for a, c in zip(X, Y)) / sum((a - mx) ** 2 for a in X)
    e = [c - my - b * (a - mx) for a, c in zip(X, Y)]
    se = math.sqrt(sum(v * v for v in e) / (len(e) - 2) / sum((a - mx) ** 2 for a in X))
    print(f"{y0}-{y1}: d log defence investment = {b:.2f} ({se:.2f}) x d log total defence")
