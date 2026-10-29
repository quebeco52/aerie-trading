"""Per firm and revenue line: how far the active mix weight drifts from its opening weight, over all seeds."""
import glob, json, statistics as st, sys
from collections import defaultdict

arm = sys.argv[1] if len(sys.argv) > 1 else 'base'
stats = defaultdict(lambda: {'max': [], 'min': [], 'run': [], 'mean': []})
models = {}
for path in sorted(glob.glob(f'runs/{arm}-s*.json')):
    d = json.load(open(path))
    for t, f in d['firms'].items():
        models[t] = f['model']
        q = f['q']
        if len(q) < 8:
            continue
        if not isinstance(q[0]['w'], dict):
            continue
        for k, w0 in q[0]['w'].items():
            if w0 <= 0:
                continue
            r = [(row['w'].get(k, 0) if isinstance(row['w'], dict) else 0) / w0 for row in q]
            run = best = 0
            for x in r:
                run = run + 1 if (x > 1.25 or x < 0.8) else 0
                best = max(best, run)
            s = stats[(t, k)]
            s['max'].append(max(r)); s['min'].append(min(r)); s['run'].append(best); s['mean'].append(st.mean(r)); s['w0'] = w0

rows = []
for (t, k), s in stats.items():
    worst = max(max(s['max']) - 1, 1 - min(s['min']))
    rows.append((worst, t, k, s))
rows.sort(reverse=True)
flag = [r for r in rows if r[0] > 0.25]
print(f"{len(rows)} firm-lines, {len({r[1] for r in rows})} firms; {len(flag)} lines in {len({r[1] for r in flag})} firms move >25% off their opening weight")
for worst, t, k, s in flag:
    print(f"{t:5} {models[t]:28} {k:28} w0 {s['w0']:.3f}  min {min(s['min']):.2f} max {max(s['max']):.2f}  "
          f"mean/seed {' '.join(f'{m:.2f}' for m in s['mean'])}  longest run off ±25%: {max(s['run'])}q")
