"""Asymmetry targets: python3 var/harness/asym_fit.py -> asym_fit.json

[1] Premium: sign-split local projection of the CBO gap on excess bond premium innovations (the credit_fit.py step 9
    spec with the innovation split into max(e,0) and min(e,0); Barnichon, Matthes & Ziegenbein 2022, eq. 2).
    Ex-2020/21 (the pandemic quarters are not a credit shock). Favorable responses are reported per +1pp of EASING.
[2] Monetary: published estimates (the Romer-Romer shock files could not be fetched), per 100bp funds-rate shock:
    Barnichon & Matthes (2018, JME) Fig. 4/7, recursive 1959-2007: peak unemployment +0.22 [.16,.28] contractionary,
    ~+0.07 expansionary (narrative .35/.12, sign .37/.14); Tenreyro & Thwaites (2016, AEJ:Macro) Fig. II: GDP peak
    about -1% in expansions, insignificant (~0) in recessions (worst 20% of 7q growth), linear about -0.5% at 2-3y.
[3] BM's recursive funds-rate response to that shock (Fig. 3, digitized), the path the engine is forced along.
"""
import csv, json, math, os

H = os.path.dirname(os.path.abspath(__file__))
F = json.load(open(os.path.join(H, 'credit_data.json')))['fred']
src = open(os.path.join(H, 'credit_fit.py')).read()
exec(src[src.index('# --- Series'):src.index('# --- 1. Premium dynamics')])

qk = [k for k in qs if k in E]
Y, X, KK = [], [], []
for i in range(2, len(qk)):
    k, p, pp = qk[i], qk[i - 1], qk[i - 2]
    Y.append(E[k])
    X.append([1.0, E[p], E[pp], gap[k], gap[p], gap[pp]])
    KK.append(k)
_, _, _, ei = ols(Y, X)
inn = dict(zip(KK, ei))
pandemic = lambda k: '2020-01' <= k <= '2021-10'
sign = {}
for h in [0, 1, 2, 4, 6, 8, 10, 12, 16]:
    Y, X = [], []
    for i in range(2, len(qk) - h):
        k = qk[i]
        if k in inn and not pandemic(k) and not pandemic(qk[i + h]):
            e = 100 * inn[k]
            Y.append(100 * (gap[qk[i + h]] - gap[qk[i - 1]]))
            X.append([1.0, max(e, 0.0), min(e, 0.0), 100 * gap[qk[i - 1]], 100 * (gap[qk[i - 1]] - gap[qk[i - 2]]), 100 * E[qk[i - 1]]])
    b, se, _, _ = ols(Y, X, nw_lags=h + 1)
    sign[str(h)] = {'adverse': b[1], 'adverse_se': se[1], 'favorable': -b[2], 'favorable_se': se[2]}
print('[1] gap per +1pp ADVERSE premium innovation:  ' + '  '.join(f"{h}q {v['adverse']:+.2f}({v['adverse_se']:.2f})" for h, v in sign.items()))
print('    gap per 1pp FAVORABLE innovation:          ' + '  '.join(f"{h}q {v['favorable']:+.2f}({v['favorable_se']:.2f})" for h, v in sign.items()))

monetary = {
    'source': 'Barnichon & Matthes (2018) recursive 1959-2007, Fig. 4 and 7; 90% band half-width taken as 1.645 se',
    'u_peak_tight': 0.22, 'u_peak_tight_se': (0.28 - 0.16) / 2 / 1.645,
    'u_peak_ease': 0.07, 'u_peak_ease_se': 0.10 / 1.645,
    'ratio_by_scheme': {'recursive': 0.07 / 0.22, 'narrative': 0.12 / 0.35, 'sign': 0.14 / 0.37},
    'tt_gdp_peak_expansion': -1.0, 'tt_gdp_peak_recession': 0.0, 'tt_gdp_peak_linear': -0.5,
    'tt_source': 'Tenreyro & Thwaites (2016) Fig. II, per 1pp shock, recession = worst 20% of 7q GDP growth',
}
print(f"[2] BM per 100bp: unemployment peak tightening {monetary['u_peak_tight']:.2f} (se {monetary['u_peak_tight_se']:.3f}), easing {monetary['u_peak_ease']:.2f} (se {monetary['u_peak_ease_se']:.3f}); "
      f"ratio by scheme {', '.join(f'{k} {v:.2f}' for k, v in monetary['ratio_by_scheme'].items())}")
# BM Fig. 3, recursive column, funds rate per 100bp shock, quarters 0-16 (digitized; the FAIR fit is one smooth Gaussian tail)
bm_path = [1.00, 0.66, 0.64, 0.62, 0.59, 0.56, 0.52, 0.48, 0.44, 0.40, 0.36, 0.32, 0.28, 0.24, 0.20, 0.17, 0.14]
print(f"[3] BM recursive funds-rate path (per 100bp): {bm_path}")
json.dump({'premium_sign': sign, 'monetary': monetary, 'bm_rate_path': bm_path}, open(os.path.join(H, 'asym_fit.json'), 'w'), indent=1)
print('wrote asym_fit.json')
