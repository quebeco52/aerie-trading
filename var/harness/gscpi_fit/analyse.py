#!/usr/bin/env python3
"""GSCPI fit (pure Python: no numpy on this machine). Monthly rows from runs/<arm>-<seed>.jsonl, burn-in BURN years dropped.
  analyse.py step1 <arm> <seeds> <factory loading>   delivery loading fit + delivery series moments
  analyse.py step2 <arm> <seeds>                      composite fit; writes fit.json
  analyse.py verify <armF> <armH> <seeds>             index moments of armF, paired guards armF - armH
seeds: 1-16 or 1,4,9."""
import json, math, sys, os

H = os.path.dirname(os.path.abspath(__file__))
BURN = 3.0
CU0 = 0.785
SHIP = (78.8 - 50.0) / (1.4586 / 2.0)


def seeds(s):
    if '-' in s:
        a, b = s.split('-'); return list(range(int(a), int(b) + 1))
    return [int(x) for x in s.split(',')]


def load(arm, ss):
    out = {}
    for s in ss:
        rows = [json.loads(l) for l in open(f'{H}/runs/{arm}-{s}.jsonl')]
        rows = [r for r in rows if r['t'] > BURN + 1e-9]
        out[s] = {k: [r[k] for r in rows] for k in rows[0]}
    return out


def mean(x): return sum(x) / len(x)
def sd(x, ddof=0):
    m = mean(x); return math.sqrt(sum((v - m) ** 2 for v in x) / (len(x) - ddof))
def cov(x, y):
    mx, my = mean(x), mean(y); return sum((a - mx) * (b - my) for a, b in zip(x, y)) / len(x)
def corr(x, y): return cov(x, y) / (sd(x) * sd(y))


def per_seed(d, f): return [f(v) for v in d.values()]
def pooled(d, f): return [x for v in d.values() for x in f(v)]


def ar1_series(series_list):
    """Pooled within-seed lag-1 autocorrelation."""
    num = den = 0.0
    for x in series_list:
        m = mean(x); y = [v - m for v in x]
        num += sum(a * b for a, b in zip(y[1:], y[:-1])); den += sum(v * v for v in y)
    return num / den


def kc(r): return r / max(0.01, 1.0 - r)
def lnk(cu): return [math.log(kc(r) / kc(CU0)) for r in cu]
def lnt(t): return [math.log(max(0.01, v)) for v in t]


def step1(arm, ss, fl):
    d = load(arm, ss)
    lk = pooled(d, lambda v: lnk(v['cu'])); lt = pooled(d, lambda v: lnt(v['tight']))
    print(f'arm {arm}, n={len(ss)} seeds, months={len(lk)}, burn-in {BURN} y')
    print(f'sd ln K(CU)/K(.785) = {sd(lk):.4f}  mean {mean(lk):+.4f}   sd ln tightness = {sd(lt):.4f}  mean {mean(lt):+.4f}   corr {corr(lk, lt):+.3f}')
    print(f'CU: mean {mean(pooled(d, lambda v: v["cu"])):.4f} sd {sd(pooled(d, lambda v: v["cu"])):.4f} max {max(pooled(d, lambda v: v["cu"])):.4f};'
          f' tightness max {max(pooled(d, lambda v: v["tight"])):.3f}')
    print(f'DELIVERY_FACTORY_LOADING = {SHIP:.3f} x {sd(lt):.4f} / {sd(lk):.4f} = {SHIP * sd(lt) / sd(lk):.3f}')
    for key, name in (('delr', 'delivery raw'), ('del', 'delivery EMA')):
        x = pooled(d, lambda v: v[key]); mx = per_seed(d, lambda v: max(v[key]))
        print(f'{name}: mean {mean(x):.2f} sd {sd(x):.2f} min {min(x):.2f} max {max(x):.2f}  AR1(m) {ar1_series(per_seed(d, lambda v: v[key])):.3f}'
              f'  seeds>78.8 {sum(m > 78.8 for m in mx)}/{len(ss)}  months>78.8 {sum(v > 78.8 for v in x) / len(x):.4f}  months>60 {sum(v > 60 for v in x) / len(x):.4f}')
    a = [fl * v for v in lk]; b = [SHIP * v for v in lt]
    tgt = [50.0 + u + w for u, w in zip(a, b)]
    x = pooled(d, lambda v: v['delr'])
    res = [p - q for p, q in zip(x, tgt)]
    print(f'target (loading {fl}): mean {mean(tgt):.2f} sd {sd(tgt):.2f}; factory term sd {sd(a):.2f}, shipping term sd {sd(b):.2f}')
    print(f'variance share explained by target: 1 - var(resid)/var(raw) = {1 - sd(res) ** 2 / sd(x) ** 2:.3f}; corr^2 = {corr(x, tgt) ** 2:.3f}')


def pc1(c):
    v = [1.0, 1.0, 1.0]
    for _ in range(500):
        w = [sum(c[i][j] * v[j] for j in range(3)) for i in range(3)]
        n = math.sqrt(sum(x * x for x in w)); v = [x / n for x in w]
    lam = sum(v[i] * sum(c[i][j] * v[j] for j in range(3)) for i in range(3))
    return v, lam


def step2(arm, ss):
    d = load(arm, ss)
    names = ('fr', 'inv', 'del'); sign = {'fr': 1.0, 'inv': -1.0, 'del': 1.0}
    mu = {k: mean(pooled(d, lambda v: v[k])) for k in names}
    sg = {k: sd(pooled(d, lambda v: v[k])) for k in names}
    print(f'arm {arm}, n={len(ss)} seeds, months={len(pooled(d, lambda v: v["fr"]))}, burn-in {BURN} y')
    for k in names:
        print(f'input {k} (EMA): mean {mu[k]:.6g} sd {sg[k]:.6g}  AR1(m) {ar1_series(per_seed(d, lambda v: v[k])):.3f}')
    z = {k: [sign[k] * (x - mu[k]) / sg[k] for x in pooled(d, lambda v: v[k])] for k in names}
    best = None
    print('demand purge: OLS slope of z on the gap (gap in fractions), R2 in brackets')
    for g in ('og', 'gg', 'dg'):
        x = pooled(d, lambda v: v[g]); res = {}
        for k in names:
            res[k] = (cov(x, z[k]) / sd(x) ** 2, corr(x, z[k]) ** 2)
        avg = mean([r[1] for r in res.values()])
        print(f'  {g}: ' + '  '.join(f'{k} {res[k][0]:+8.3f} ({res[k][1]:.3f})' for k in names) + f'   avg R2 {avg:.3f}   gap mean {mean(x):+.4f} sd {sd(x):.4f}')
        if best is None or avg > best[1]:
            best = (g, avg, res)
    g, _, res = best
    x = pooled(d, lambda v: v[g])
    p = [[zk - res[k][0] * xv for zk, xv in zip(z[k], x)] for k in names]
    print(f'chosen regressor {g}; purged z sd ' + '  '.join(f'{k} {sd(p[i]):.3f}' for i, k in enumerate(names))
          + '; means ' + '  '.join(f'{k} {mean(p[i]):+.3f}' for i, k in enumerate(names)))
    c = [[corr(p[i], p[j]) for j in range(3)] for i in range(3)]
    print('corr of purged z (fr, inv, del):')
    for row in c: print('   ' + '  '.join(f'{v:+.3f}' for v in row))
    v1, lam = pc1(c)
    if sum(v1) < 0: v1 = [-x for x in v1]
    share = lam / 3.0
    load1 = [x / sum(v1) for x in v1]
    print('PC1 loadings (sum 1): ' + '  '.join(f'{k} {load1[i]:.3f}' for i, k in enumerate(names)) + f'   var share {share:.3f}')
    wts = load1 if share >= 0.5 else [1 / 3] * 3
    comp = [sum(wts[i] * p[i][t] for i in range(3)) for t in range(len(x))]
    n0 = 0; series = []
    for v in d.values():
        n = len(v['t']); series.append(comp[n0:n0 + n]); n0 += n
    print(f'weights used {[round(w, 4) for w in wts]} ({"PC1" if share >= 0.5 else "equal: PC1 share < 0.5"}); '
          f'weighted composite sd {sd(comp):.4f} mean {mean(comp):+.4f} AR1(m) {ar1_series(series):.3f}')
    fit = {'mean': mu, 'sd': sg, 'regressor': g, 'beta': {k: res[k][0] for k in names},
           'weight': dict(zip(names, wts)), 'weighted_sd': sd(comp), 'pc1_share': share, 'gap_mean': mean(x)}
    json.dump(fit, open(f'{H}/fit.json', 'w'), indent=1)


def verify(af, ah, ss):
    f = load(af, ss); h = load(ah, ss)
    x = pooled(f, lambda v: v['sc']); e = pooled(f, lambda v: v['sce'])
    print(f'arm {af}, n={len(ss)} seeds x {len(x) // len(ss)} months, burn-in {BURN} y')
    print(f'raw index: mean {mean(x):+.3f} (target -0.30)  sd {sd(x):.3f} (0.475)  AR1(m) {ar1_series(per_seed(f, lambda v: v["sc"])):.3f} (0.79)'
          f'  share>1 {sum(v > 1 for v in x) / len(x):.4f}  share>2 {sum(v > 2 for v in x) / len(x):.4f}  max {max(x):.2f}')
    ps = per_seed(f, lambda v: sd(v['sc'])); pm = per_seed(f, lambda v: mean(v['sc']))
    print(f'per-seed raw sd {mean(ps):.3f} (range {min(ps):.3f}-{max(ps):.3f}); per-seed mean range {min(pm):+.3f}..{max(pm):+.3f}')
    print(f'EMA: mean {mean(e):+.3f} sd {sd(e):.3f} max {max(e):.2f}')
    qs = [('CPI inflation sd', lambda v: sd(v['infl'])), ('CPI inflation mean', lambda v: mean(v['infl'])),
          ('core goods infl sd', lambda v: sd(v['cg'])), ('PPI inflation sd', lambda v: sd(v['ppi'])),
          ('output gap sd', lambda v: sd(v['gap'])), ('mean max(0, index EMA)', lambda v: mean([max(0.0, y) for y in v['sce']]))]
    print(f'paired {af} - {ah}, n={len(ss)} (per-seed moments)')
    print(f'{"quantity":24s} {af:>10s} {ah:>10s} {"diff":>10s} {"se":>9s} {"t":>6s}')
    for name, fn in qs:
        a = [fn(f[s]) for s in ss]; b = [fn(h[s]) for s in ss]; dd = [p - q for p, q in zip(a, b)]
        se = sd(dd, 1) / math.sqrt(len(ss))
        print(f'{name:24s} {mean(a):10.5f} {mean(b):10.5f} {mean(dd):+10.5f} {se:9.5f} {mean(dd) / se:+6.2f}')


def rawdiag(arm, ss):
    """The same purge and weights from fit.json on the raw (non-EMA) inputs, each standardized on its own moments."""
    d = load(arm, ss); f = json.load(open(f'{H}/fit.json'))
    sign = {'fr': 1.0, 'inv': -1.0, 'del': 1.0}; raw = {'fr': 'frr', 'inv': 'invr', 'del': 'delr'}
    series = []
    for v in d.values():
        g = v[f['regressor']]; comp = [0.0] * len(g)
        for k in ('fr', 'inv', 'del'):
            x = pooled(d, lambda u: u[raw[k]]); m, s = mean(x), sd(x)
            for t in range(len(g)):
                comp[t] += f['weight'][k] * (sign[k] * (v[raw[k]][t] - m) / s - f['beta'][k] * g[t])
        series.append(comp)
    for k in ('fr', 'inv', 'del'):
        print(f'raw {k}: AR1(m) {ar1_series(per_seed(d, lambda u: u[raw[k]])):.3f}')
    print(f'composite on raw inputs: sd {sd([y for s in series for y in s]):.3f}  AR1(m) {ar1_series(series):.3f}')


RAW = {'fr': 'frr', 'inv': 'invr', 'del': 'delr'}
REG = {'fr': 'gg', 'inv': 'dg', 'del': 'og'}
SIGN = {'fr': 1.0, 'inv': -1.0, 'del': 1.0}
NAMES = ('fr', 'inv', 'del')


def lagcov(d, a, b):
    """Pooled within-seed cov(a_t, b_{t-1}); a, b map a seed's rows to a list."""
    num = 0.0; n = 0
    for v in d.values():
        x, y = a(v), b(v); mx, my = mean(x), mean(y)
        num += sum((x[t] - mx) * (y[t - 1] - my) for t in range(1, len(x))); n += len(x) - 1
    return num / n


def step2raw(arm, ss):
    """Raw inputs, each purged on its own regressor; folds beta x mean(regressor) into the MEANs. Writes fit2.json."""
    d = load(arm, ss)
    print(f'arm {arm}, n={len(ss)} seeds, months={len(pooled(d, lambda v: v["frr"]))}, burn-in {BURN} y; raw inputs')
    mu = {}; sg = {}; beta = {}; z = {}
    for k in NAMES:
        x = pooled(d, lambda v: v[RAW[k]]); g = pooled(d, lambda v: v[REG[k]])
        mu[k], sg[k] = mean(x), sd(x)
        z[k] = [SIGN[k] * (a - mu[k]) / sg[k] for a in x]
        beta[k] = cov(g, z[k]) / sd(g) ** 2
        print(f'input {k}: mean {mu[k]:.6g} sd {sg[k]:.6g} AR1(m) {ar1_series(per_seed(d, lambda v: v[RAW[k]])):.3f};'
              f'  on {REG[k]}: beta {beta[k]:+.3f} R2 {corr(g, z[k]) ** 2:.3f}  (regressor mean {mean(g):+.4f} sd {sd(g):.4f});'
              f'  R2 on og/gg/dg ' + '/'.join(f'{corr(pooled(d, lambda v: v[r]), z[k]) ** 2:.3f}' for r in ('og', 'gg', 'dg')))
    p = [[zk - beta[k] * gv for zk, gv in zip(z[k], pooled(d, lambda v: v[REG[k]]))] for k in NAMES]
    print('purged z sd ' + '  '.join(f'{k} {sd(p[i]):.3f}' for i, k in enumerate(NAMES)) + '; means before fold ' + '  '.join(f'{k} {mean(p[i]):+.3f}' for i, k in enumerate(NAMES)))
    c = [[corr(p[i], p[j]) for j in range(3)] for i in range(3)]
    print('corr of purged z (fr, inv, del):')
    for row in c: print('   ' + '  '.join(f'{v:+.3f}' for v in row))
    v1, lam = pc1(c)
    if sum(v1) < 0: v1 = [-x for x in v1]
    share = lam / 3.0; load1 = [x / sum(v1) for x in v1]
    print('PC1 loadings (sum 1): ' + '  '.join(f'{k} {load1[i]:.3f}' for i, k in enumerate(NAMES)) + f'   var share {share:.3f}')
    wts = load1 if share >= 0.5 else [1 / 3] * 3
    comp = [sum(wts[i] * p[i][t] for i in range(3)) for t in range(len(p[0]))]
    print(f'weights used {[round(w, 4) for w in wts]} ({"PC1" if share >= 0.5 else "equal: PC1 share < 0.5"}); weighted sd {sd(comp):.4f} mean {mean(comp):+.4f}')
    fold = {}
    for k in NAMES:  # purged mean 0: (x - M)/s*sign - beta*gbar has mean 0 when M = xbar - sign*s*beta*gbar
        gbar = mean(pooled(d, lambda v: v[REG[k]]))
        fold[k] = mu[k] - SIGN[k] * sg[k] * beta[k] * gbar
    print('folded MEANs ' + '  '.join(f'{k} {fold[k]:.6g}' for k in NAMES))
    json.dump({'mean': fold, 'sample_mean': mu, 'sd': sg, 'beta': beta, 'weight': dict(zip(NAMES, wts)),
               'weighted_sd': sd(comp), 'pc1_share': share}, open(f'{H}/fit2.json', 'w'), indent=1)


def verify2(af, ah, ss):
    verify(af, ah, ss)
    d = load(af, ss); f = json.load(open(f'{H}/fit2.json'))
    zr = lambda v, k: [SIGN[k] * (a - f['mean'][k]) / f['sd'][k] for a in v[RAW[k]]]
    pur = lambda v, k: [a - f['beta'][k] * g for a, g in zip(zr(v, k), v[REG[k]])]
    pre = lambda v: [sum(f['weight'][k] * zr(v, k)[t] for k in NAMES) for t in range(len(v['t']))]
    post = lambda v: [sum(f['weight'][k] * pur(v, k)[t] for k in NAMES) for t in range(len(v['t']))]
    og = pooled(d, lambda v: v['og'])
    print(f'corr with outputGapEma: index {corr(pooled(d, lambda v: v["sc"]), og):+.3f}; weighted z before purge {corr(pooled(d, pre), og):+.3f}, after {corr(pooled(d, post), og):+.3f}')
    c = pooled(d, post); vc = sd(c) ** 2
    tot = 0.0
    for k in NAMES:
        w = f['weight'][k]
        contrib = w * lagcov(d, lambda v: post(v), lambda v: pur(v, k)) / vc  # w_k cov(c_t, p_k,t-1) / var(c)
        vshare = w * cov(c, pooled(d, lambda v: pur(v, k))) / vc
        tot += contrib
        print(f'  {k}: own AR1(m) {ar1_series(per_seed(d, lambda v: pur(v, k))):.3f}  variance share {vshare:.3f}  AR1 contribution {contrib:.3f}')
    print(f'  sum of contributions {tot:.3f} (composite AR1 {ar1_series(per_seed(d, post)):.3f})')


if __name__ == '__main__':
    m = sys.argv[1]
    if m == 'step1': step1(sys.argv[2], seeds(sys.argv[3]), float(sys.argv[4]))
    elif m == 'step2': step2(sys.argv[2], seeds(sys.argv[3]))
    elif m == 'rawdiag': rawdiag(sys.argv[2], seeds(sys.argv[3]))
    elif m == 'step2raw': step2raw(sys.argv[2], seeds(sys.argv[3]))
    elif m == 'verify2': verify2(sys.argv[2], sys.argv[3], seeds(sys.argv[4]))
    elif m == 'verify': verify(sys.argv[2], sys.argv[3], seeds(sys.argv[4]))
