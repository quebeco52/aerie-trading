"""analyse.py <arm> <seeds a-b> [arm2]: world-factor moments per seed (quarterly samples, 3-year burn-in), mean and se.
Targets (1993-2019 real data, own calc): corr(metals yoy, world activity yoy) 0.58, metals yoy log sd 0.231,
corr(oil yoy, world activity yoy) 0.55; world gap sd 1.8%, AR(1) ~0.85, corr with the mainland gap 0.51.
With a second arm, also paired differences (arm - arm2)."""
import json, math, os, statistics as st, sys
H = os.path.dirname(os.path.abspath(__file__))
def seeds(spec):
    a, b = spec.split('-') if '-' in spec else (spec, spec)
    return range(int(a), int(b) + 1)
def corr(x, y):
    return st.correlation(x, y) if len(x) > 2 and st.pstdev(x) > 0 and st.pstdev(y) > 0 else float('nan')
def stats(arm, seed):
    rows = [json.loads(l) for l in open(f'{H}/runs/{arm}-{seed}.jsonl')]
    q = [r for i, r in enumerate(rows) if i % 3 == 2 and r['t'] >= 3.0]
    wg = [r['wg'] if r.get('wg') is not None else r['gg'] for r in q]
    yoy = lambda v: [v[i] - v[i - 4] for i in range(4, len(v))]
    lm, lo, lf = [math.log(r['metals']) for r in q], [math.log(r['oil']) for r in q], [math.log(r['freight']) for r in q]
    gg = [r['gg'] for r in q]
    return {
        'corr_metals_world': corr(yoy(lm), yoy(wg)),
        'corr_metals_global': corr(yoy(lm), yoy(gg)),
        'metals_yoy_sd': st.pstdev(yoy(lm)),
        'corr_oil_world': corr(yoy(lo), yoy(wg)),
        'corr_oil_global': corr(yoy(lo), yoy(gg)),
        'oil_yoy_sd': st.pstdev(yoy(lo)),
        'freight_yoy_sd': st.pstdev(yoy(lf)),
        'world_sd': st.pstdev(wg), 'world_ar1': corr(wg[1:], wg[:-1]),
        'corr_world_mainland': corr(wg, [r['fg'] for r in q]),
        'mainland_sd': st.pstdev([r['fg'] for r in q]), 'global_sd': st.pstdev(gg),
        'gscpi_ema_sd': st.pstdev([r['sce'] for r in q]),
        'cpi_sd': st.pstdev([r['infl'] for r in q]), 'ppi_sd': st.pstdev([r['ppi'] for r in q]),
    }
arm, spec = sys.argv[1], sys.argv[2]
other = sys.argv[3] if len(sys.argv) > 3 else None
res = {s: stats(arm, s) for s in seeds(spec)}
ores = {s: stats(other, s) for s in seeds(spec)} if other else None
print(f'arm {arm}' + (f' vs {other}' if other else '') + f', seeds {spec}, n={len(res)}')
for k in next(iter(res.values())):
    v = [r[k] for r in res.values()]
    line = f'{k:22s} {st.mean(v):8.4f} (se {st.pstdev(v) / math.sqrt(len(v)):.4f})'
    if ores:
        d = [res[s][k] - ores[s][k] for s in res]
        se = st.pstdev(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
        line += f' | {other} {st.mean(ores[s][k] for s in ores):8.4f} | diff {st.mean(d):+.4f} se {se:.4f} t {st.mean(d) / se if se else float("nan"):+.1f}'
    print(line)
