import statistics as st, importlib.util, sys, io, contextlib
spec = importlib.util.spec_from_file_location('cr', sys.argv[1])
mod = importlib.util.module_from_spec(spec)
with contextlib.redirect_stdout(io.StringIO()):
    sys.argv = [sys.argv[1], sys.argv[2]]
    spec.loader.exec_module(mod)
real = mod.real  # monthly GC 3-2-1 vs WTI, Jan 2010 .. Dec 2024
annual = {2010 + y: st.mean(real[12 * y:12 * y + 12]) for y in range(15)}
wti = {2010 + y: st.mean(mod.w[12 * y:12 * y + 12]) for y in range(15)}
# Valero reported refining margin per barrel of throughput (earnings releases, full year)
vlo = {2018: 10.32, 2019: 9.65, 2020: 5.32, 2021: 9.04, 2022: 21.82, 2023: 17.55, 2024: 10.62}
xs = [annual[y] for y in vlo]; ys = [vlo[y] for y in vlo]
mx, my = st.mean(xs), st.mean(ys)
b = sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / sum((x - mx) ** 2 for x in xs)
a = my - b * mx
r = sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / (sum((x - mx) ** 2 for x in xs) * sum((y - my) ** 2 for y in ys)) ** 0.5
for y in vlo:
    print(f'{y}: GC 3-2-1 ${annual[y]:5.2f}  WTI ${wti[y]:6.2f}  Valero margin/bbl ${vlo[y]:5.2f}  capture {vlo[y]/annual[y]:.2f}')
print(f'OLS: margin/bbl = {a:.2f} + {b:.3f} x crack   (R = {r:.2f}, n = {len(xs)})')
