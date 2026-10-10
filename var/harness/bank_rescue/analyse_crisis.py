#!/usr/bin/env python3
"""analyse_crisis.py <seeds e.g. 1-16> [years=100]: per-crisis slump statistics, NEW vs OFF, paired by seed."""
import json, math, statistics as st, sys, os
H = os.path.dirname(os.path.abspath(__file__))
a, _, b = sys.argv[1].partition('-')
SEEDS = list(range(int(a), int(b or a) + 1))
YEARS = float(sys.argv[2]) if len(sys.argv) > 2 else 100.0
BURN, DEEP, CLAMP = 5.0, -0.04, -0.12 + 0.01

def load(arm, s):
    q, c = [], []
    for line in open(f'{H}/runs_macro/{arm}-{s}.jsonl'):
        r = json.loads(line)
        (q if r['k'] == 'q' else c).append(r)
    return [r for r in q if r['t'] > BURN + 1e-9], c

def runs(q):
    """(start_t, end_index_exclusive, length) of each run of gap < DEEP."""
    out, i = [], 0
    while i < len(q):
        if q[i]['gap'] < DEEP:
            j = i
            while j < len(q) and q[j]['gap'] < DEEP: j += 1
            out.append((q[i]['t'], q[j - 1]['t'], j - i)); i = j
        else: i += 1
    return out

def longest_upto(q, tmax):
    best = run = 0
    for r in q:
        if r['t'] > tmax: break
        run = run + 1 if r['gap'] < DEEP else 0
        best = max(best, run)
    return best

def per_seed(arm, s):
    q, c = load(arm, s)
    rs = runs(q)
    crises = []
    for x in c:
        t = x['t']
        if t < BURN or t > YEARS - 8: continue
        # runs that start in (t-0.25, t+2], or are under way when the crisis lands
        l1 = max([n for (ts, te, n) in rs if (t - 0.25 < ts <= t + 2) or (ts <= t <= te + 0.25)] or [0])
        l2 = sum(1 for r in q if t < r['t'] <= t + 8 and r['gap'] < CLAMP)
        pre = x['debt'] - x['rescue'] if arm == 'new' else x['debt']
        peak = max([r['debt'] for r in q if t < r['t'] <= t + 5] or [x['debt']])
        crises.append(dict(l1=l1, l2=l2, rescue=x['rescue'], peak=peak, rise=peak - pre))
    ncr = sum(1 for x in c if x['t'] >= BURN)
    return dict(crises=crises, ncr=ncr, longest=max([n for *_, n in rs] or [0]), longest40=longest_upto(q, 40.0))

def pct(v, p):
    v = sorted(v); k = (len(v) - 1) * p; f = math.floor(k)
    return v[f] + (v[min(f + 1, len(v) - 1)] - v[f]) * (k - f)

def paired(xa, xb):
    d = [x - y for x, y in zip(xa, xb)]
    if len(d) < 2: return float('nan'), float('nan'), float('nan'), len(d)
    m, se = st.mean(d), st.stdev(d) / math.sqrt(len(d))
    return m, se, (m / se if se > 0 else float('nan')), len(d)

D = {arm: {s: per_seed(arm, s) for s in SEEDS} for arm in ('new', 'off')}
print(f'seeds {SEEDS[0]}-{SEEDS[-1]} (n={len(SEEDS)}), {YEARS:.0f}y, 252 tpy, burn-in 5y; per-crisis windows need t<= {YEARS-8:.0f}y')
for arm in ('new', 'off'):
    n = sum(D[arm][s]['ncr'] for s in SEEDS)
    print(f'{arm}: crises per century {100 * n / (len(SEEDS) * (YEARS - BURN)):.2f}  (total {n})')
hdr = f"{'per crisis':<34}{'arm':<5}{'n':>4}{'mean':>7}{'med':>6}{'p90':>6}{'max':>5}{'>=20q':>7}"
print(hdr)
for key, lab in (('l1', 'T1 longest run gap<-4% (q)'), ('l2', 'T2 quarters at clamp in 8y')):
    for arm in ('new', 'off'):
        v = [c[key] for s in SEEDS for c in D[arm][s]['crises']]
        sh = sum(1 for x in v if x >= 20) / len(v) if v else float('nan')
        print(f'{lab:<34}{arm:<5}{len(v):>4}{st.mean(v):>7.2f}{pct(v, .5):>6.1f}{pct(v, .9):>6.1f}{max(v):>5}{sh:>7.1%}')
print(f"{'paired NEW-OFF of per-seed means':<34}{'diff':>8}{'se':>7}{'t':>7}{'n':>4}")
for key, lab in (('l1', 'T1'), ('l2', 'T2')):
    both = [s for s in SEEDS if D['new'][s]['crises'] and D['off'][s]['crises']]
    xa = [st.mean(c[key] for c in D['new'][s]['crises']) for s in both]
    xb = [st.mean(c[key] for c in D['off'][s]['crises']) for s in both]
    m, se, t, n = paired(xa, xb)
    print(f'{lab:<34}{m:>8.2f}{se:>7.2f}{t:>7.2f}{n:>4}')
v = [c for s in SEEDS for c in D['new'][s]['crises']]
r = [c['rescue'] for c in v]
print(f"T3 NEW rescue/GDP: n={len(r)} median {pct(r, .5):.3f} p90 {pct(r, .9):.3f} at cap {sum(1 for x in r if x >= 0.3759) / len(r):.1%}")
for arm in ('new', 'off'):
    w = [c for s in SEEDS for c in D[arm][s]['crises']]
    print(f"T3 {arm} gross debt peak 5y: median {pct([c['peak'] for c in w], .5):.3f} p90 {pct([c['peak'] for c in w], .9):.3f}; "
          f"rise over pre-crisis median {pct([c['rise'] for c in w], .5):+.3f} p90 {pct([c['rise'] for c in w], .9):+.3f}")
print('whole run per seed: longest run gap<-4% (q)')
for key, lab in (('longest', f'{YEARS - BURN:.0f}y'), ('longest40', 'first 40y (test window)')):
    xa = [D['new'][s][key] for s in SEEDS]; xb = [D['off'][s][key] for s in SEEDS]
    m, se, t, n = paired(xa, xb)
    print(f"  {lab:<24} new mean {st.mean(xa):5.1f} share>=20 {sum(x >= 20 for x in xa) / n:5.1%} | off mean {st.mean(xb):5.1f} share>=20 "
          f"{sum(x >= 20 for x in xb) / n:5.1%} | diff {m:+.2f} se {se:.2f} t {t:.2f} n {n}")
