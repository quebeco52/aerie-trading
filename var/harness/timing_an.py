"""Engine policy timing vs timing_fit.json: python3 timing_an.py <dir> <tag> -> J_timing on the cross-correlation shape (each lag over 2q)."""
import glob, json, math, os, sys
H = os.path.dirname(os.path.abspath(__file__))
US = json.load(open(os.path.join(H, 'timing_fit.json')))
segs = []
for f in sorted(glob.glob(f"{sys.argv[1]}/{sys.argv[2]}-[0-9]*.json")):
    by = {}
    for r in json.load(open(f)):
        by.setdefault(r['seed'], []).append(r)
    segs += [rs[20:] for rs in by.values()]
J, cells, C = 0.0, [], {}
for k in (0, 2, 4, 8):
    A, B = [], []
    for rs in segs:
        # within-seed demeaning, as each US moment is one history
        p = [r['policy'] for r in rs]; g = [r['gap'] for r in rs]
        pa = p[k:]; gb = g[:len(g) - k]
        mp = sum(pa) / len(pa); mg = sum(gb) / len(gb)
        A += [x - mp for x in pa]; B += [y - mg for y in gb]
    C[k] = sum(a * b for a, b in zip(A, B)) / math.sqrt(sum(a * a for a in A) * sum(b * b for b in B))
for k, u in US.items():
    r = C[int(k)] / C[2]
    J += ((r - u['ratio']) / u['se']) ** 2
    cells.append(f"{k}q {r:+.2f}/{u['ratio']:+.2f}")
print(f"timing (corr over its 2q value) engine/US: {'  '.join(cells)} | J_timing {J:.2f}")
