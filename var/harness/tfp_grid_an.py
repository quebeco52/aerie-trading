"""Score the absorption-speed grid (irfgrid.sh) on the engine's TOTAL gap response against US data (tfp_fit.json).

    python3 tfp_grid_an.py <irf dir>
"""
import glob, json, os, re, statistics as st, sys
D = sys.argv[1]
fit = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'tfp_fit.json')))
gi, gs = fit['irf']['gap']; ui, us = fit['irf']['u']
cells = {}
for f in glob.glob(f'{D}/g-*.json'):
    ky, kp, seed = re.match(r'.*/g-([\d.]+)-([\d.]+)-(\d+)\.json', f).groups()
    d = json.load(open(f))
    cells.setdefault((float(ky), float(kp)), {}).update(d['runs'])
rows = []
for (ky, kp), runs in sorted(cells.items()):
    H = min(len(v['base']) for v in runs.values())
    g = [st.mean((v['shock'][q]['gap'] - v['base'][q]['gap']) / 0.01 for v in runs.values()) for q in range(H)]
    u = [st.mean((v['shock'][q]['u'] - v['base'][q]['u']) / 0.01 for v in runs.values()) for q in range(H)]
    n = min(H, len(gi))
    chi_g = sum(((g[q] - gi[q]) / gs[q]) ** 2 for q in range(n))
    chi_u = sum(((u[q] - ui[q]) / us[q]) ** 2 for q in range(n))
    rows.append((chi_g, ky, kp, len(runs), g, u, chi_u))
for chi_g, ky, kp, n, g, u, chi_u in sorted(rows)[:16]:
    print(f"ky {ky:4.2f} kp {kp:4.2f} ({n} seeds): gap chi2 {chi_g:6.1f}  u chi2 {chi_u:6.1f}  gap " + ' '.join(f"{g[q]:+.2f}" for q in range(0, 21, 2)))
print('data gap           ' + ' '.join(f"{gi[q]:+.2f}" for q in range(0, 21, 2)))
