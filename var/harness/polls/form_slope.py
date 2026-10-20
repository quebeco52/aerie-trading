"""Slope of an outgoing coalition's leading-party vote change on the campaign growth gap (pooled OLS per arm; per-seed
paired NEW - HEAD). Both in share units, so the new channel should add ~0.253. python3 form_slope.py <runs dir>"""
import json, glob, sys, os, statistics as st, math
d = sys.argv[1]
def pts(path):
    el = sorted((json.loads(l) for l in open(path)), key=lambda r: r['t'])
    el = [r for r in el if not r.get('fall')]
    out = []
    for a, b in zip(el, el[1:]):
        if len(b['outgoing']) < 2:
            continue
        pm = max(b['outgoing'], key=lambda p: (a['seats'][p], a['shares'][p]))
        out.append((b['growthGap'], b['shares'][pm] - a['shares'][pm]))
    return out
def ols(xy):
    mx = st.mean(x for x, _ in xy); my = st.mean(y for _, y in xy)
    sxx = sum((x - mx) ** 2 for x, _ in xy); b = sum((x - mx) * (y - my) for x, y in xy) / sxx
    res = sum((y - my - b * (x - mx)) ** 2 for x, y in xy) / (len(xy) - 2)
    return b, math.sqrt(res / sxx), math.sqrt(sxx / len(xy))
seeds = sorted(int(os.path.basename(f)[4:-6]) for f in glob.glob(f"{d}/new_*.jsonl"))
for arm in ('head', 'new'):
    xy = [p for s in seeds for p in pts(f"{d}/{arm}_{s}.jsonl")]
    b, se, sdx = ols(xy)
    print(f"{arm:5s} pooled slope {b:+.3f} (se {se:.3f}), n {len(xy)} coalition terms, sd(growth gap) {sdx:.4f}")
dd = [ols(pts(f"{d}/new_{s}.jsonl"))[0] - ols(pts(f"{d}/head_{s}.jsonl"))[0] for s in seeds]
print(f"paired per-seed slope NEW - HEAD {st.mean(dd):+.3f} (se {st.stdev(dd) / math.sqrt(len(dd)):.3f}), n {len(dd)}")
