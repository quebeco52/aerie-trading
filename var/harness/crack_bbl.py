"""CASC per barrel vs Valero per barrel: operating income per barrel of throughput removes the crude-price denominator."""
import contextlib, glob, importlib.util, io, json, math, statistics as st, sys
H = sys.argv[1] if len(sys.argv) > 1 else '.'
ARMS = sys.argv[2].split(',') if len(sys.argv) > 2 else ['rec4', 'rec5']
spec = importlib.util.spec_from_file_location('cr', f'{H}/crack_real.py')
mod = importlib.util.module_from_spec(spec)
with contextlib.redirect_stdout(io.StringIO()):
    sys.argv = ['x', f'{H}/crack_probe_new.json']
    spec.loader.exec_module(mod)
real, w = mod.real, mod.w
crack = {2010 + y: st.mean(real[12 * y:12 * y + 12]) for y in range(15)}
wti = {2010 + y: st.mean(w[12 * y:12 * y + 12]) for y in range(15)}
# Valero refining segment, full year (earnings releases, exhibit 99.01): margin/bbl, opex/bbl, D&A/bbl, adjusted OI/bbl,
# revenue $M, throughput kb/d
V = {2018: (10.32, 3.82, 1.75, 4.75, 113118, 2986), 2019: (9.65, 3.98, 1.92, 3.75, 103764, 2952),
     2020: (5.32, 4.22, 2.28, -1.18, 60848, 2555), 2021: (9.04, 5.00, 2.13, 1.91, 106961, 2787),
     2022: (21.82, 5.11, 2.09, 14.62, 168210, 2953), 2023: (17.55, 4.79, 2.16, 10.60, 136488, 2979),
     2024: (10.62, 4.64, 2.24, 3.74, 123863, 2912)}


def ols(xs, ys):
    mx, my = st.mean(xs), st.mean(ys)
    b = sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / sum((x - mx) ** 2 for x in xs)
    r = sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / math.sqrt(sum((x - mx) ** 2 for x in xs) * sum((y - my) ** 2 for y in ys))
    return my - b * mx, b, r


print('Valero refining, per barrel of throughput')
for y, (m, o, d, oi, rev, tp) in V.items():
    rpb = rev / (tp * 365 / 1000)
    print(f'  {y}: crack ${crack[y]:5.2f} WTI ${wti[y]:6.2f} | margin ${m:5.2f} costs ${o + d:4.2f} OI ${oi:6.2f} | revenue/bbl ${rpb:6.1f} OI% {100 * oi / rpb:5.1f}')
a, b, r = ols([crack[y] for y in V], [V[y][3] for y in V])
print(f'  OLS: OI/bbl = {a:.2f} + {b:.3f} x crack (R = {r:.2f}); break-even crack ${-a / b:.1f}; costs/bbl mean ${st.mean(V[y][1] + V[y][2] for y in V):.2f}')
print(f'  real annual corr(crack, WTI) {ols([wti[y] for y in range(2010, 2025)], [crack[y] for y in range(2010, 2025)])[2]:+.2f}; monthly {ols(w, real)[2]:+.2f}')

bins = [(0, 12), (12, 20), (20, 28), (28, 36), (36, 99)]
for arm in ARMS:
    rows = []
    for f in glob.glob(f'{H}/runs2/{arm}-*.jsonl'):
        d = json.loads(open(f).readline())
        for x in d['final']['CASC']['q']:
            if x['rev'] > 0:
                p = x['crude'] / 100.0 * 72.0
                R = p + x['capture'] * x['crack']
                rows.append((x['crack'], x['om'] * R, x['om'], x['crude'], x['ebit'] < 0))
    if not rows:
        continue
    a2, b2, r2 = ols([c for c, *_ in rows], [o for _, o, *_ in rows])
    print(f'\n{arm}: n={len(rows)} quarters | OI/bbl = {a2:.2f} + {b2:.3f} x crack (R = {r2:.2f}); break-even ${-a2 / b2:.1f} | loss quarters {100 * sum(z[4] for z in rows) / len(rows):.1f}% | sim corr(crack, crude) {ols([z[3] for z in rows], [z[0] for z in rows])[2]:+.2f}')
    for lo, hi in bins:
        sel = [z for z in rows if lo <= z[0] < hi]
        vs = [f'{y} ${V[y][3]:.2f}' for y in V if lo <= crack[y] < hi]
        if sel:
            print(f'  crack ${lo:>2}-{hi:<2}: OI/bbl median ${st.median(z[1] for z in sel):6.2f} (Valero line ${a + b * st.median(z[0] for z in sel):6.2f}) margin {100 * st.median(z[2] for z in sel):5.1f}%  share {100 * len(sel) / len(rows):4.1f}%  Valero: {", ".join(vs) or "-"}')
