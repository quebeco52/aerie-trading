import json, math, statistics as st, sys
gas = """2.014 1.966 2.137 2.191 1.989 1.969 1.973 1.928 1.950 2.071 2.115 2.310
2.390 2.511 2.859 3.136 3.024 2.789 2.975 2.801 2.692 2.689 2.539 2.544
2.777 2.999 3.169 3.101 2.780 2.516 2.645 2.968 3.034 2.778 2.522 2.475
2.669 2.922 2.901 2.685 2.708 2.688 2.853 2.861 2.604 2.490 2.451 2.520
2.548 2.695 2.714 2.801 2.762 2.849 2.713 2.631 2.605 2.179 1.937 1.423
1.271 1.603 1.678 1.734 1.884 1.945 1.860 1.574 1.353 1.307 1.246 1.181
1.021 0.936 1.214 1.337 1.434 1.487 1.358 1.429 1.393 1.489 1.317 1.566
1.593 1.543 1.523 1.621 1.519 1.432 1.518 1.638 1.753 1.652 1.757 1.702
1.857 1.765 1.820 1.965 2.091 2.002 2.043 2.053 2.045 1.969 1.546 1.357
1.353 1.470 1.817 2.006 1.881 1.722 1.854 1.690 1.681 1.646 1.636 1.630
1.587 1.453 0.838 0.546 0.830 1.095 1.166 1.244 1.176 1.143 1.128 1.298
1.500 1.686 1.945 1.958 2.038 2.118 2.205 2.215 2.199 2.425 2.280 2.129
2.400 2.648 3.197 3.180 3.750 4.049 3.283 2.793 2.578 2.837 2.456 2.157
2.534 2.416 2.536 2.616 2.447 2.427 2.674 2.902 2.824 2.305 2.094 2.019
2.146 2.325 2.545 2.595 2.385 2.275 2.373 2.301 1.981 2.023 1.950 1.936"""
ulsd = """2.035 1.998 2.125 2.267 2.093 2.066 2.042 2.093 2.130 2.252 2.324 2.446
2.601 2.793 3.081 3.231 3.001 3.015 3.117 2.974 2.937 2.960 3.046 2.878
3.034 3.178 3.270 3.217 2.947 2.667 2.879 3.143 3.186 3.157 2.997 2.960
3.044 3.200 3.007 2.879 2.843 2.861 2.981 3.040 3.014 2.935 2.857 2.953
2.913 2.973 2.917 2.932 2.904 2.921 2.842 2.821 2.709 2.500 2.314 1.784
1.531 1.824 1.711 1.776 1.917 1.802 1.624 1.463 1.444 1.418 1.351 1.089
0.958 0.999 1.127 1.199 1.378 1.460 1.343 1.379 1.400 1.544 1.427 1.596
1.593 1.608 1.502 1.558 1.482 1.386 1.486 1.601 1.777 1.756 1.851 1.867
1.996 1.895 1.895 2.029 2.181 2.102 2.098 2.114 2.210 2.282 1.962 1.687
1.774 1.908 1.939 2.012 1.983 1.807 1.873 1.788 1.895 1.876 1.838 1.899
1.770 1.552 1.124 0.804 0.838 1.083 1.190 1.196 1.082 1.107 1.211 1.403
1.523 1.744 1.824 1.813 1.971 2.068 2.071 2.015 2.152 2.455 2.324 2.195
2.550 2.810 3.676 3.953 3.934 4.286 3.629 3.535 3.357 3.963 3.344 2.975
3.207 2.771 2.690 2.518 2.302 2.351 2.597 3.050 3.265 3.009 2.662 2.385
2.569 2.707 2.598 2.562 2.372 2.385 2.415 2.237 2.072 2.165 2.156 2.141"""
wti = """78.33 76.39 81.20 84.29 73.74 75.34 76.32 76.60 75.24 81.89 84.25 89.15
89.17 88.58 102.86 109.53 100.90 96.26 97.30 86.33 85.52 86.32 97.16 98.56
100.27 102.20 106.16 103.32 94.66 82.30 87.90 94.13 94.51 89.49 86.53 87.86
94.76 95.31 92.94 92.02 94.51 95.77 104.67 106.57 106.29 100.54 93.86 97.63
94.62 100.82 100.80 102.07 102.18 105.79 103.59 96.54 93.21 84.40 75.79 59.29
47.22 50.58 47.82 54.45 59.27 59.82 50.90 42.87 45.48 46.22 42.44 37.19
31.68 30.32 37.55 40.75 46.71 48.76 44.65 44.72 45.18 49.78 45.66 51.97
52.50 53.47 49.33 51.06 48.48 45.18 46.63 48.04 49.82 51.58 56.64 57.88
63.70 62.23 62.73 66.25 69.98 67.87 70.98 68.06 70.23 70.75 56.96 49.52
51.38 54.95 58.15 63.86 60.83 54.66 57.35 54.81 56.95 53.96 57.03 59.88
57.52 50.54 29.21 16.55 28.56 38.31 40.71 42.34 39.63 39.40 40.94 47.02
52.00 59.04 62.33 61.72 65.17 71.38 72.49 67.73 71.65 81.48 79.15 71.71
83.22 91.64 108.50 101.78 109.55 114.84 101.62 93.67 84.26 87.55 84.37 76.44
78.12 76.83 73.28 79.45 71.58 70.25 76.07 81.39 89.43 85.64 77.69 71.90
74.15 77.25 81.28 85.35 80.02 79.77 81.80 76.68 70.24 71.99 69.95 70.12"""
f = lambda t: [float(x) for x in t.split()]
g, d, w = f(gas), f(ulsd), f(wti)
real = [(2 * a * 42 + b * 42) / 3 - c for a, b, c in zip(g, d, w)]

def stats(x, months_per_step=1):
    s = sorted(x); n = len(x); m = st.mean(x)
    ac = sum((x[i] - m) * (x[i - 1] - m) for i in range(1, n)) / sum((v - m) ** 2 for v in x)
    hl = math.log(0.5) / math.log(ac) * months_per_step / 12 if 0 < ac < 1 else float('nan')
    q = [st.mean([x[i] for i in range(n) if (i % 12) // 3 == k]) / m for k in range(4)]
    yoy = [x[i] - x[i - 12] for i in range(12, n)]
    return dict(mean=m, sd=st.pstdev(x), cv=st.pstdev(x) / m, p5=s[n // 20], p95=s[-n // 20], min=min(x), max=max(x), ac1=ac, halflife_y=hl, seas=q, yoy_sd=st.pstdev(yoy))

def show(name, s):
    print(f"{name:22s} mean {s['mean']:5.1f} sd {s['sd']:5.1f} CV {s['cv']:.2f} p5 {s['p5']:5.1f} p95 {s['p95']:5.1f} min {s['min']:5.1f} max {s['max']:5.1f} | monthly AR1 {s['ac1']:.2f} half-life {s['halflife_y']:.2f}y | YoY sd ${s['yoy_sd']:.1f} | Q1..Q4 {' '.join(f'{v:.2f}' for v in s['seas'])}")

show('REAL GC 3-2-1 2010-24', stats(real))
rows = json.load(open(sys.argv[1]))
for seed in sorted(set(r['seed'] for r in rows)):
    rr = [r for r in rows if r['seed'] == seed]
    show(f'sim seed {seed} (spot)', stats([r['crack'] for r in rr]))
allc = [r['crack'] for r in rows]
def corr(a, b):
    ma, mb = st.mean(a), st.mean(b); return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / math.sqrt(sum((x - ma) ** 2 for x in a) * sum((y - mb) ** 2 for y in b))
print(f"\nsim, all seeds: corr(crack, global gap) {corr(allc, [r['global_gap'] for r in rows]):+.2f} | corr(crack, crude) {corr(allc, [r['crude'] for r in rows]):+.2f} | corr(crack, catastrophe index) {corr(allc, [r['cat'] for r in rows]):+.2f}")
gg = [r['global_gap'] for r in rows]; inv = [r['inventory'] for r in rows]
print(f"target terms: demand factor 1+1.2*gap spans {1 + 1.2 * min(gg):.3f}..{1 + 1.2 * max(gg):.3f} (x22 = ${22 * 1.2 * (max(gg) - min(gg)):.2f} total swing); inventory {min(inv):.1f}..{max(inv):.1f} -> tightness term max ${max(0, (100 - min(inv)) / 100) * 15:.2f}")
print(f"real: corr(crack, WTI) {corr(real, w):+.2f}")

brent = f("""76.17 73.75 78.83 84.82 75.95 74.76 75.58 77.04 77.84 82.67 85.28 91.45
96.52 103.72 114.64 123.26 114.99 113.83 116.97 110.22 112.83 109.55 110.77 107.87
110.69 119.33 125.45 119.75 110.34 95.16 102.62 113.36 112.86 111.71 109.06 109.49
112.96 116.05 108.47 102.25 102.56 102.92 107.93 111.28 111.60 109.08 107.79 110.76
108.12 108.90 107.48 107.76 109.54 111.80 106.77 101.61 97.09 87.43 79.44 62.34
47.76 58.10 55.89 59.52 64.08 61.48 56.56 46.52 47.62 48.43 44.27 38.01
30.70 32.18 38.21 41.58 46.74 48.25 44.95 45.84 46.57 49.52 44.73 53.31
54.58 54.87 51.59 52.31 50.33 46.37 48.48 51.70 56.15 57.51 62.71 64.37
69.08 65.32 66.02 72.11 76.98 74.41 74.25 72.53 78.89 81.03 64.75 57.36
59.41 63.96 66.14 71.23 71.32 64.22 63.92 59.04 62.83 59.71 63.21 67.31
63.65 55.66 32.01 18.38 29.38 40.27 43.24 44.74 40.91 40.19 42.69 49.99
54.77 62.28 65.41 64.81 68.53 73.16 75.17 70.75 74.49 83.54 81.05 74.17
86.51 97.13 117.25 104.58 113.34 122.71 111.93 100.45 89.76 93.33 91.42 80.92
82.50 82.59 78.43 84.64 75.47 74.84 80.11 86.15 93.72 90.60 82.94 77.63
80.12 83.48 85.41 89.94 81.75 82.25 85.15 80.36 74.02 75.63 74.35 73.86""")
realb = [(2 * a * 42 + b * 42) / 3 - c for a, b, c in zip(g, d, brent)]
print()
show('REAL GC 3-2-1 vs BRENT', stats(realb))
pos = [x for x in realb if x > 0]
print(f"vs Brent: months <= 0: {sum(1 for x in realb if x <= 0)}; log sd {st.pstdev([math.log(x) for x in pos]):.3f}; corr(crack, Brent) {corr(realb, brent):+.2f}")
lx = [math.log(x) for x in pos]; lb = [math.log(b) for x, b in zip(realb, brent) if x > 0]
mx, mb = st.mean(lx), st.mean(lb)
print(f"elasticity of crack to Brent (log-log OLS): {sum((a-mb)*(c-mx) for a, c in zip(lb, lx)) / sum((a-mb)**2 for a in lb):.2f}")
# monthly seasonal profile (mean by calendar month / overall), and a first-harmonic fit A*cos(2*pi*(t - phi))
prof = [st.mean([realb[i] for i in range(len(realb)) if i % 12 == mth]) / st.mean(realb) for mth in range(12)]
c1 = sum((p - 1) * math.cos(2 * math.pi * (m + 0.5) / 12) for m, p in enumerate(prof)) * 2 / 12
s1 = sum((p - 1) * math.sin(2 * math.pi * (m + 0.5) / 12) for m, p in enumerate(prof)) * 2 / 12
amp = math.hypot(c1, s1); phase = (math.atan2(s1, c1) / (2 * math.pi)) % 1
print('monthly profile:', ' '.join(f'{p:.2f}' for p in prof))
print(f'first harmonic: amplitude {amp:.3f}, peak at year fraction {phase:.2f} (~month {phase*12:.1f})')
# the same, excluding 2020-2022 (pandemic collapse and war spike)
keep = [x for i, x in enumerate(realb) if not (120 <= i < 156)]
print(f"excluding 2020-22: mean {st.mean(keep):.1f} sd {st.pstdev(keep):.1f} CV {st.pstdev(keep)/st.mean(keep):.2f} log sd {st.pstdev([math.log(x) for x in keep if x > 0]):.3f}")

print('\n--- WTI-based (the refiner model prices crude off WTI) ---')
m = st.mean(real)
prof = [st.mean([real[i] for i in range(len(real)) if i % 12 == mth]) / m for mth in range(12)]
c1 = sum((p - 1) * math.cos(2 * math.pi * (k + 0.5) / 12) for k, p in enumerate(prof)) * 2 / 12
s1 = sum((p - 1) * math.sin(2 * math.pi * (k + 0.5) / 12) for k, p in enumerate(prof)) * 2 / 12
amp = math.hypot(c1, s1); phase = (math.atan2(s1, c1) / (2 * math.pi)) % 1
print('monthly profile:', ' '.join(f'{p:.2f}' for p in prof))
print(f'first harmonic: amplitude {amp:.3f}, peak at year fraction {phase:.2f} (~month {phase*12+0.5:.1f})')
# de-seasonalise with that harmonic, then measure log dispersion and persistence of what is left
des = [x / (1 + amp * math.cos(2 * math.pi * ((i % 12 + 0.5) / 12 - phase))) for i, x in enumerate(real)]
ld = [math.log(x) for x in des]
mu = st.mean(ld); ac = sum((ld[i]-mu)*(ld[i-1]-mu) for i in range(1, len(ld))) / sum((v-mu)**2 for v in ld)
kappa = -math.log(ac) * 12
print(f'de-seasonalised: log sd {st.pstdev(ld):.3f}; monthly AR1 {ac:.3f} -> kappa {kappa:.2f}/yr (half-life {math.log(2)/kappa:.2f}y); implied log-OU sigma = sd*sqrt(2k) = {st.pstdev(ld)*math.sqrt(2*kappa):.2f}')
keep = [ld[i] for i in range(len(ld)) if not (120 <= i < 156)]
print(f'excluding 2020-22: log sd {st.pstdev(keep):.3f}')
