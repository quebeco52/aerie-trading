"""Compare arms of gapsplit_run.sh: python3 var/harness/gapsplit_cmp.py <dir> <arm> [<arm> ...]
TFP impulse responses against US distributed lags (tfp_fit.json + tfp_infl.py), then scan statistics off the
dump-shaped runs: gap shape, unemployment persistence and Okun fit, and how far the rule strays from a rule written
on what a player can see (unemployment gap via Okun, the rule's own inflation measure)."""
import glob, json, math, os, statistics as st, sys
D, arms = sys.argv[1], sys.argv[2:]
H = '/home/quebeco/Projects/Code/Private/aerie-trading/var/harness'
fit = json.load(open(os.path.join(H, 'tfp_fit.json')))['irf']
# US 1960+ core PCE 4q inflation and 1960-2008 fed funds on the same Fernald shocks (tfp_infl.py), even quarters 0..20.
US_INFL = [(-0.24, .17), (-0.28, .16), (-0.20, .16), (-0.17, .16), (-0.09, .16), (0.07, .16), (0.19, .16), (0.25, .16), (0.31, .16), (0.33, .16), (0.25, .16)]
US_FF = [(-0.32, .30), (-0.54, .30), (-0.53, .30), (-0.38, .30), (-0.42, .30), (-0.29, .30), (-0.16, .30), (-0.15, .30), (-0.19, .30), (-0.09, .30), (-0.32, .30)]
def sd(x): m = sum(x) / len(x); return math.sqrt(sum((v - m) ** 2 for v in x) / len(x))
def acf_pooled(series, k):
    num = den = 0.0
    for x in series:
        m = sum(x) / len(x)
        num += sum((x[i] - m) * (x[i - k] - m) for i in range(k, len(x)))
        den += sum((v - m) ** 2 for v in x)
    return num / den
def corr(a, b):
    ma, mb = sum(a) / len(a), sum(b) / len(b)
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / math.sqrt(sum((x - ma) ** 2 for x in a) * sum((y - mb) ** 2 for y in b))

print('=== TFP impulse, +1% level shock (pp); US in brackets ===')
for arm in arms:
    runs = {}
    for f in glob.glob(f'{D}/{arm}tfp-*.json'):
        d = json.load(open(f)); shock = d['shock']; runs.update(d['runs'])
    irf = lambda key: [st.mean((v['shock'][q][key] - v['base'][q][key]) / shock for v in runs.values()) for q in range(24)]
    g, s, u, pi, pol = irf('gap'), irf('supply'), irf('u'), irf('infl'), irf('policy')
    chi = {'gap': 0.0, 'u': 0.0, 'infl': 0.0, 'ff': 0.0}
    print(f'-- {arm} ({len(runs)} seeds)')
    print('  q | gap   [US]        | u     [US]        | infl  [US]        | policy [US]')
    for i, q in enumerate(range(0, 21, 2)):
        if q >= len(g): break
        gi, gs = fit['gap'][0][q], fit['gap'][1][q]; ui, us = fit['u'][0][q], fit['u'][1][q]
        chi['gap'] += ((g[q] - gi) / gs) ** 2; chi['u'] += ((u[q] - ui) / us) ** 2
        chi['infl'] += ((pi[q] - US_INFL[i][0]) / US_INFL[i][1]) ** 2; chi['ff'] += ((pol[q] - US_FF[i][0]) / US_FF[i][1]) ** 2
        print(f' {q:2d} | {g[q]:+.2f} [{gi:+.2f}±{gs:.2f}] | {u[q]:+.2f} [{ui:+.2f}±{us:.2f}] | {pi[q]:+.2f} [{US_INFL[i][0]:+.2f}±{US_INFL[i][1]:.2f}] | {pol[q]:+.2f} [{US_FF[i][0]:+.2f}±{US_FF[i][1]:.2f}]')
    print('  chi2 over the 11 even quarters: ' + '  '.join(f'{k} {v:.1f}' for k, v in chi.items()))

print('\n=== Scan (32 seeds x 80y, first 10y dropped) ===')
cols = ['gap sd', 'ACF4', 'ACF8', 'ACF12', 'skew', 'u-gap sd', 'u ACF4', 'u ACF8', 'corr(u,gap)', 'corr(u,demand)', 'Okun slope',
        'infl sd', 'at floor', 'inverted', 'stray>1pp', 'stray sd', 'hawk@slack']
res = {}
for arm in arms:
    by = {}
    for f in glob.glob(f'{D}/{arm}diag-*.ndjson'):
        for line in open(f):
            r = json.loads(line)
            if r['total_time'] > 10 and r.get('quarter_diagnostics'):
                by.setdefault(r['seed'], []).append(r)
    gaps = [[r['quarter_diagnostics']['averages']['outputGap'] * 100 for r in rs] for rs in by.values()]
    ugap = [[(r['unemployment_rate'] - r['nairu']) * 100 for r in rs] for rs in by.values()]
    allg = [x for s in gaps for x in s]; allu = [x for s in ugap for x in s]
    alld = [(r['output_gap'] - r['productivity_supply_gap']) * 100 for rs in by.values() for r in rs]
    allge = [r['output_gap'] * 100 for rs in by.values() for r in rs]
    m = sum(allg) / len(allg); skew = sum((x - m) ** 3 for x in allg) / len(allg) / sd(allg) ** 3
    b = sum((x - sum(allge) / len(allge)) * (y - sum(allu) / len(allu)) for x, y in zip(allge, allu)) / sum((x - sum(allge) / len(allge)) ** 2 for x in allge)
    infl = [r['quarter_diagnostics']['averages']['inflation'] * 100 for rs in by.values() for r in rs]
    floor = sum(r['quarter_diagnostics']['policy']['constraints'].get('lowerBound', 0) for rs in by.values() for r in rs if isinstance(r['quarter_diagnostics']['policy']['constraints'], dict)) / len(allg)
    inv = sum(r['yield_10y'] < r['yield_2y'] for rs in by.values() for r in rs) / len(allg)
    # Observable rule: the same rule with the gap read off unemployment through Okun's coefficient.
    stray, hawk = [], 0
    for rs in by.values():
        for r in rs:
            p = r['quarter_diagnostics']['policy']['terms']
            okun_gap = -(r['unemployment_rate'] - r['nairu']) / 0.5
            s = p['outputGap'] + p.get('productivitySeenThrough', 0.0) - 1.5 * okun_gap  # the rule's whole gap response
            stray.append(s * 100)
            neutral = r['natural_rate'] + 0.02
            if r['unemployment_rate'] >= r['nairu'] and r['quarter_diagnostics']['averages']['policyRate'] > neutral + 0.01 and r['supercore_inflation_ema'] < 0.022:
                hawk += 1
    res[arm] = [sd(allg), acf_pooled(gaps, 4), acf_pooled(gaps, 8), acf_pooled(gaps, 12), skew, sd(allu), acf_pooled(ugap, 4), acf_pooled(ugap, 8),
                corr(allu, allg), corr(allu, alld), b, sd(infl), floor, inv, sum(abs(x) > 1 for x in stray) / len(stray), sd(stray), hawk / len(allg)]
print(f"{'':16s}" + ''.join(f'{a:>10s}' for a in arms))
for i, c in enumerate(cols):
    print(f'{c:16s}' + ''.join(f'{res[a][i]:10.3f}' for a in arms))
print('US refs: gap sd 1.63 (1985-2019), ACF4/8/12 .57/.26/.07 (1949-2019); u-gap sd 1.46-1.57, ACF4/8 .74-.80/.44-.45, corr(u,gap) -0.89, Okun slope -0.6 (1949+) / -0.8 (1985+)')
