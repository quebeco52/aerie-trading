"""US core PCE inflation and fed funds responses to a +1% Fernald TFP level shock (same distributed lag as tfp_real.py). python3 var/harness/tfp_policy_real.py"""
import json, math, os, sys
H = '/home/quebeco/Projects/Code/Private/aerie-trading/var/harness'
src = open(os.path.join(H, 'tfp_real.py')).read()
d = json.load(open(os.path.join(H, 'tfp_data.json')))
F, R = d['fernald'], d['fred']
import statistics as st
ns = {"st": st, "math": math}
exec(src[src.index('def ols'):src.index('keys = sorted(F)')], ns)
ols = ns['ols']
qfun = src[src.index('def quarterly'):src.index('\n\n', src.index('def quarterly'))] if 'def quarterly' in src else None
exec(qfun, ns); quarterly = ns['quarterly']
K = 20
keys = sorted(F)
shock = {k: F[k]['tfpu'] / 4 for k in keys}
p = quarterly(R['PCEPILFE'])
pk = sorted(p)
infl4 = {pk[i]: 100 * math.log(p[pk[i]] / p[pk[i - 4]]) for i in range(4, len(pk))}
def lagged(target, start, own=0):
    X, y = [], []
    ks = [k for k in keys if k in target and k >= start]
    for i in range(max(K, own), len(ks)):
        X.append([1.0] + [shock[ks[i - j]] for j in range(K + 1)] + [target[ks[i - 4 - j]] for j in range(own) if ks[i-4-j] in target] if own else [1.0] + [shock[ks[i - j]] for j in range(K + 1)])
        y.append(target[ks[i]])
    b, V = ols(X, y)
    return [b[h + 1] for h in range(K + 1)], [math.sqrt(V[h + 1][h + 1]) for h in range(K + 1)]
for start in ('1960-01-01', '1985-01-01'):
    irf, se = lagged(infl4, start)
    print(f'core PCE 4q inflation from {start[:4]}: ' + ' '.join(f'q{h}:{irf[h]:+.2f}({se[h]:.2f})' for h in range(0, K + 1, 2)))

# Federal funds rate (quarterly average) on the same shocks.
ff = {}
for line in open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fedfunds.csv')).read().split('\n')[1:]:
    if not line.strip():
        continue
    dte, v = line.split(',')
    y, m = int(dte[:4]), int(dte[5:7])
    q = f'{y}-{((m - 1) // 3) * 3 + 1:02d}-01'
    ff.setdefault(q, []).append(float(v))
ffq = {k: sum(v) / len(v) for k, v in ff.items() if len(v) == 3}
def lagged_window(target, start, end):
    X, y = [], []
    ks = [k for k in keys if k in target and start <= k < end]
    for i in range(K, len(ks)):
        X.append([1.0] + [shock[ks[i - j]] for j in range(K + 1)])
        y.append(target[ks[i]])
    b, V = ols(X, y)
    return [b[h + 1] for h in range(K + 1)], [math.sqrt(V[h + 1][h + 1]) for h in range(K + 1)]
for start, end in (('1960-01-01', '2008-10-01'), ('1985-01-01', '2008-10-01')):
    irf, se = lagged_window(ffq, start, end)
    print(f'fed funds {start[:4]}-{end[:4]}: ' + ' '.join(f'q{h}:{irf[h]:+.2f}({se[h]:.2f})' for h in range(0, K + 1, 2)))
gapd = {k: 100 * math.log(R['GDPC1'][k] / R['GDPPOT'][k]) for k in keys if k in R['GDPC1'] and k in R['GDPPOT']}
irf, se = lagged_window(gapd, '1960-01-01', '2008-10-01')
print('CBO gap 1960-2008: ' + ' '.join(f'q{h}:{irf[h]:+.2f}({se[h]:.2f})' for h in range(0, K + 1, 2)))
