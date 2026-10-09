import csv, collections, statistics as st
rows = [r for r in csv.DictReader(open(__import__('os').path.join(__import__('os').path.dirname(__file__) or '.', 'ches_trend.csv'))) if r['eastwest'] == '1']
def num(v):
    try: return float(v)
    except ValueError: return None
keep = [r for r in rows if num(r['antielite_salience']) is not None and num(r['govt']) is not None and (num(r['seat']) or 0) > 0]
wave = collections.defaultdict(list)
for r in keep: wave[(r['country'], r['year'])].append(num(r['antielite_salience']))
mean = {k: st.mean(v) for k, v in wave.items()}
bins = collections.defaultdict(lambda: [0, 0.0])
for r in keep:
    x = -(num(r['antielite_salience']) - mean[(r['country'], r['year'])]) / 5.0   # Diet scale: + technocratic, - populist
    b = max(-1.2, min(0.8, round(x / 0.2) * 0.2))
    bins[b][0] += 1; bins[b][1] += num(r['govt']) > 0
print('Council axis (vs country mean)  parties  in government')
for b in sorted(bins): n, g = bins[b]; print(f'  {b:+.1f}  {n:5d}  {100*g/n:5.1f}%')
import math
pts = []
for r in keep:
    pts.append((-(num(r['antielite_salience']) - mean[(r['country'], r['year'])]) / 5.0, num(r['govt']) > 0))
def ll(c):
    out = [g for x, g in pts if x < c]; inn = [g for x, g in pts if x >= c]
    s = 0.0
    for grp in (out, inn):
        if not grp: return -1e9
        p = min(max(sum(grp) / len(grp), 1e-9), 1 - 1e-9)
        s += sum(math.log(p if g else 1 - p) for g in grp)
    return s
grid = [i / 100 for i in range(-80, 41)]
best = max(grid, key=ll)
print(f'step fit: cut {best:+.2f} below the mean;', 'rate beyond', f"{100*st.mean([g for x,g in pts if x < best]):.1f}%", 'inside', f"{100*st.mean([g for x,g in pts if x >= best]):.1f}%")
for c in (-0.5, -0.4, -0.3, -0.2, -0.1, 0.0): print(f'  cut {c:+.1f}: loglik {ll(c):.1f}')
a, b = 0.0, 0.0
for _ in range(50):
    ga = gb = haa = hab = hbb = 0.0
    for x, g in pts:
        p = 1 / (1 + math.exp(-(a + b * x))); w = p * (1 - p)
        ga += g - p; gb += (g - p) * x; haa += w; hab += w * x; hbb += w * x * x
    det = haa * hbb - hab * hab; a += (hbb * ga - hab * gb) / det; b += (haa * gb - hab * ga) / det
lin = sum(math.log(1 / (1 + math.exp(-(a + b * x))) if g else 1 - 1 / (1 + math.exp(-(a + b * x)))) for x, g in pts)
print(f'sliding (logit, 2 params): loglik {lin:.1f}  slope {b:.2f}/unit;  step (2 rates + cut): loglik {ll(best):.1f};  n={len(pts)}')
