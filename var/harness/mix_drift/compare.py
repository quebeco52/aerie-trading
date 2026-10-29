"""Before/after arms on the same seeds: weight drift left, and each firm's revenue and income paired by seed."""
import glob, json, math, statistics as st, sys

a, b = (sys.argv[1], sys.argv[2]) if len(sys.argv) > 2 else ('before', 'after')
def load(arm):
    return {json.load(open(p))['seed']: json.load(open(p))['firms'] for p in sorted(glob.glob(f'runs/{arm}-s*.json'))}
A, B = load(a), load(b)
seeds = sorted(set(A) & set(B))

# Weight drift left in the after arm (largest |w/w0 - 1| over the run).
drift = []
for s in seeds:
    for t, f in B[s].items():
        q = [r for r in f['q'] if isinstance(r['w'], dict)]
        if len(q) < 8: continue
        for k, w0 in q[0]['w'].items():
            if w0 > 0:
                drift.append((max(abs(r['w'].get(k, 0) / w0 - 1) for r in q), t, k))
drift.sort(reverse=True)
print(f"{b}: lines still >5% off their opening weight: {sum(1 for d in drift if d[0] > 0.05)} of {len(drift)}; worst {drift[:6]}")

def per_firm(firms):
    out = {}
    for t, f in firms.items():
        q = f['q']
        if len(q) < 8: continue
        out[t] = (sum(r['rev'] for r in q), sum(r['ni'] for r in q), q[-1]['px'], f['model'])
    return out
rows = {}
board = {'rev': [], 'ni': []}
for s in seeds:
    fa, fb = per_firm(A[s]), per_firm(B[s])
    board['rev'].append(math.log(sum(v[0] for v in fb.values()) / sum(v[0] for v in fa.values())))
    board['ni'].append(sum(v[1] for v in fb.values()) / sum(v[1] for v in fa.values()) - 1)
    for t in set(fa) & set(fb):
        rows.setdefault(t, {'rev': [], 'ni': [], 'px': [], 'model': fa[t][3]})
        rows[t]['rev'].append(math.log(fb[t][0] / fa[t][0]) if fa[t][0] > 0 and fb[t][0] > 0 else 0)
        rows[t]['ni'].append((fb[t][1] - fa[t][1]) / max(abs(fa[t][1]), 1))
        rows[t]['px'].append(math.log(fb[t][2] / fa[t][2]) if fa[t][2] > 0 and fb[t][2] > 0 else 0)
def ms(x):
    return st.mean(x), (st.stdev(x) / math.sqrt(len(x)) if len(x) > 1 else float('nan'))
m, se = ms(board['rev']); n, sn = ms(board['ni'])
print(f"board 20y revenue {100*m:+.2f}% (SE {100*se:.2f}), net income {100*n:+.2f}% (SE {100*sn:.2f}), n={len(seeds)}")
print('firm  model                        20y revenue        20y net income      end price')
for t, r in sorted(rows.items(), key=lambda kv: -abs(st.mean(kv[1]['rev']))):
    (rv, rs), (nv, ns), (pv, ps) = ms(r['rev']), ms(r['ni']), ms(r['px'])
    if abs(rv) < 0.02 and t not in ('TIER', 'CLAW', 'VULT', 'ROOK', 'COMM', 'ERNE', 'GULL', 'WELL', 'HONY'):
        continue
    print(f"{t:5} {r['model']:28} {100*rv:+6.1f}% ±{100*rs:4.1f}   {100*nv:+7.1f}% ±{100*ns:5.1f}   {100*pv:+6.1f}% ±{100*ps:4.1f}")
