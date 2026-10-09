"""Engine impulse responses (TfpImpulseProbeTest pairs) against the US responses in tfp_fit.json.

    python3 tfp_irf_an.py <dir> <prefix>
"""
import glob, json, math, os, statistics as st, sys
D, prefix = sys.argv[1], sys.argv[2]
fit = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'tfp_fit.json')))
runs = {}
shock = None
for f in glob.glob(f'{D}/{prefix}-*.json'):
    d = json.load(open(f)); shock = d['shock']; runs.update(d['runs'])
def irf(key, transform=lambda r: r):
    H = min(len(v['base']) for v in runs.values())
    return [st.mean((transform(v['shock'][q])[key] - transform(v['base'][q])[key]) / shock for v in runs.values()) for q in range(H)]
out = lambda r: {'y': math.log(r['potential'] * (1 + r['gap']))}
g, s, u, pi, y, r_, w = irf('gap'), irf('supply'), irf('u'), irf('infl'), irf('y', out), irf('rstar'), irf('rwg')
gi, gs = fit['irf']['gap']; ui, us = fit['irf']['u']; oi, os_ = fit['irf']['output']
print(f'{len(runs)} seeds; responses per +1% TFP level shock (pp); data in brackets with se')
print(' q | gap total [data]      | supply  demand | u [data]           | infl   | output [data]      | r*     real wage gap')
chi = 0.0
for q in range(0, min(len(g), len(gi)), 2):
    chi += ((g[q] - gi[q]) / gs[q]) ** 2
    print(f'{q:2d} | {g[q]:+.2f} [{gi[q]:+.2f}±{gs[q]:.2f}] | {s[q]:+.2f}  {g[q] - s[q]:+.2f} | {u[q]:+.2f} [{ui[q]:+.2f}±{us[q]:.2f}] | {pi[q]:+.2f} | {y[q]:+.2f} [{oi[q]:+.2f}±{os_[q]:.2f}] | {r_[q]:+.2f}  {w[q]:+.2f}')
print(f'gap chi2 over the even quarters shown: {chi:.1f}')
