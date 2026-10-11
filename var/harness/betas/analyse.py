"""analyse.py <arm> [seeds...]: opening betas/P/E and realised monthly betas against the cap-weighted board."""
import json, sys, statistics as st
D = '/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/betas/runs/'
arm = sys.argv[1]; seeds = sys.argv[2:] or ['1', '2', '3']
WATCH = ['BIRD', 'WADE', 'PLZA', 'LOON', 'LYRE', 'SWFT', 'OSPR', 'VULT', 'SAFE', 'CORM', 'CRAN']
per = {}
for s in seeds:
    d = json.load(open(D + f'{arm}-{s}.json'))
    snaps = d['snap']; tick = list(snaps[0].keys())
    rm, ri = [], {t: [] for t in tick}
    for a, b in zip(snaps[:-1], snaps[1:]):
        ca = sum(v[0] * v[1] for v in a.values() if not v[2]); cb = sum(b[t][0] * b[t][1] for t in a if not a[t][2] and not b[t][2])
        ca2 = sum(a[t][0] * a[t][1] for t in a if not a[t][2] and not b[t][2])
        rm.append(cb / ca2 - 1)
        for t in tick:
            if a[t][2] or b[t][2] or a[t][0] <= 0: ri[t].append(None); continue
            k = b[t][1] / a[t][1]
            ri[t].append((b[t][0] * b[t][1]) / (a[t][0] * a[t][1]) - 1 if (k > 1.5 or k < 0.67) else b[t][0] / a[t][0] - 1)
    for t in tick:
        pairs = [(x, y) for x, y in zip(rm, ri[t]) if y is not None]
        if len(pairs) < 24: continue
        mx = st.mean(p[0] for p in pairs); my = st.mean(p[1] for p in pairs)
        cxy = sum((p[0]-mx)*(p[1]-my) for p in pairs); cxx = sum((p[0]-mx)**2 for p in pairs); cyy = sum((p[1]-my)**2 for p in pairs)
        b = cxy / cxx; r2 = cxy*cxy/(cxx*cyy) if cyy > 0 else 0
        o = d['open'][t]
        per.setdefault(t, []).append({'b': b, 'r2': r2, 'lev': st.mean(d['lev'][t]) if d['lev'].get(t) else None, 'o': o})
    caps = d['open']
    W = sum(o['cap'] for o in caps.values())
    print(f"seed {s}: cap-weighted opening levered beta {sum(o['cap']*o['blev'] for o in caps.values())/W:.3f}; stored {sum(o['cap']*o['beta'] for o in caps.values())/W:.3f}; board monthly vol {st.pstdev(rm)*12**0.5:.3f}")
rows = []
for t, L in per.items():
    o = L[0]['o']
    rows.append((t, o['ind'], o['fin'], o['beta'], o['blev'], o['nde'], o['pe'], st.mean(x['lev'] for x in L), st.mean(x['b'] for x in L), st.mean(x['r2'] for x in L)))
r2s = [r[9] for r in rows]
print(f"firms {len(rows)}  median R2 {st.median(r2s):.3f}  R2<0.1: {sum(1 for x in r2s if x < 0.1)}  median realised beta {st.median(r[8] for r in rows):.3f}")
pes = [r[6] for r in rows if r[6]]
print(f"board median opening P/E {st.median(pes):.1f}  (non-fin {st.median(r[6] for r in rows if r[6] and not r[2]):.1f}, fin {st.median(r[6] for r in rows if r[6] and r[2]):.1f})")
# slope of realised on mean levered across firms
xs = [r[7] for r in rows]; ys = [r[8] for r in rows]; mx, my = st.mean(xs), st.mean(ys)
sl = sum((x-mx)*(y-my) for x, y in zip(xs, ys)) / sum((x-mx)**2 for x in xs)
print(f"cross-section: realised = {my - sl*mx:+.3f} + {sl:.3f} x mean-levered;  mean levered {mx:.3f} mean realised {my:.3f}")
print(f"{'tk':5} {'industry':32} fin stored blev0 nd/e   pe0   levMean realB  R2")
for r in sorted(rows, key=lambda r: r[0]):
    flag = '*' if r[0] in WATCH else ' '
    print(f"{r[0]:5}{flag}{r[1][:32]:32} {'F' if r[2] else ' '}  {r[3]:+.2f}  {r[4]:+.2f} {r[5] if r[5] is not None else 0:5.2f} {r[6] or 0:5.1f}  {r[7]:+.2f}  {r[8]:+.2f}  {r[9]:.2f}")
if len(sys.argv) > 1:
    json.dump({r[0]: {'stored': r[3], 'blev0': r[4], 'nde': r[5], 'pe0': r[6], 'lev': r[7], 'realB': r[8], 'r2': r[9]} for r in rows}, open(D + f'summary-{arm}.json', 'w'))
