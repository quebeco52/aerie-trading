#!/usr/bin/env python3
"""recession.py <seeds> A B: earnings through recessions (fix F1), B vs A paired by seed. Reads the run's quarter log
(runs/<arm>-<seed>.jsonl: rev, ebit, ni, gap, exp (expected revenue), rating) and the architect's firm metadata
(META env or ../../../../scratchpad meta.json: model, cyc, fin). Groups: F1 = models with a durable demand share
(Apparel, InternetRetail, Tech, Construction, Railroad, PortTerminal, Tools); order-book cyclicals (HeavyManufacturing,
SpecialtyIndustrialMachinery, DefenseContractor, Shipping, SpaceSystems, Semiconductor, CommunicationEquipment,
Homebuilder, NuclearVendor); others. Betas as the architect's firms.py: slope of the annual (TTM) change on the change
in the annual-average gap. Episodes: board-mean gap falls >= 3 pp from a peak within 12 quarters to a local trough.
Prints the final tables only.
"""
import json, math, os, statistics as st, sys
from collections import defaultdict
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from stats import paired, fmt

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]
A, B = sys.argv[2], sys.argv[3]
META = json.load(open(os.environ['META']))
F1 = {'ApparelManufacturingBusinessModel', 'InternetRetailBusinessModel', 'TechBusinessModel', 'ConstructionBusinessModel',
      'RailroadBusinessModel', 'PortTerminalBusinessModel', 'ToolsAndAccessoriesBusinessModel'}
OB = {'HeavyManufacturingBusinessModel', 'SpecialtyIndustrialMachineryBusinessModel', 'DefenseContractorBusinessModel', 'ShippingBusinessModel',
      'SpaceSystemsBusinessModel', 'SemiconductorBusinessModel', 'CommunicationEquipmentBusinessModel', 'HomebuilderBusinessModel', 'NuclearVendorBusinessModel'}
nan = float('nan')
ORDER = ['AAA', 'AA+', 'AA', 'AA-', 'A+', 'A', 'A-', 'BBB+', 'BBB', 'BBB-', 'BB+', 'BB', 'BB-', 'B+', 'B', 'B-', 'CCC+', 'CCC', 'CCC-', 'CC', 'C', 'D']
BAD = set(ORDER[ORDER.index('B'):])


def group(t):
    m = META.get(t, {})
    if m.get('fin'):
        return 'fin'
    return 'F1' if m.get('model') in F1 else 'order-book' if m.get('model') in OB else 'other'


def ols(x, y):
    mx, my = st.fmean(x), st.fmean(y)
    sxx = sum((a - mx) ** 2 for a in x)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / sxx if sxx > 0 else nan


def med(v):
    v = [x for x in v if x == x]
    return st.median(v) if v else nan


def analyse(arm, s):
    F = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').read().splitlines()[-1])['final']
    out = {}
    betas = defaultdict(lambda: defaultdict(list))
    growth = defaultdict(list)
    for t, f in F.items():
        q = f['q']
        g = group(t)
        if len(q) < 40 or g == 'fin':
            continue
        rev = [x['rev'] for x in q]; ebit = [x['ebit'] for x in q]; ni = [x['ni'] for x in q]; gap = [x['gap'] for x in q]
        ttm = lambda v, i: sum(v[i - 3:i + 1])
        X, Yr, Ye, Yn = [], [], [], []
        for i in range(8, len(q), 4):
            r1, r0 = ttm(rev, i), ttm(rev, i - 4)
            if min(r1, r0) <= 0:
                continue
            X.append(st.fmean(gap[i - 3:i + 1]) - st.fmean(gap[i - 7:i - 3]))
            Yr.append(math.log(r1 / r0))
            e1, e0, n1, n0 = ttm(ebit, i), ttm(ebit, i - 4), ttm(ni, i), ttm(ni, i - 4)
            Ye.append((e1 - e0) / e0 if e0 > 0 else nan)
            Yn.append((n1 - n0) / n0 if n0 > 0 else nan)
        if len(X) < 6:
            continue
        hi = (META.get(t, {}).get('cyc') or 0) >= 1.0
        for key, Y in (('rev', Yr), ('ebit', Ye), ('ni', Yn)):
            ok = [(a, b) for a, b in zip(X, Y) if b == b]
            if len(ok) > 5:
                b_ = ols(*zip(*ok))
                betas[g][key].append(b_)
                if g == 'F1' and hi:
                    betas['F1 cyc>=1'][key].append(b_)
        r0 = sum(rev[:4]); r1 = sum(rev[-4:])
        if r0 > 0 and r1 > 0 and q[-1]['t'] > 19:
            growth[g].append(math.log(r1 / r0) / (q[-1]['t'] - q[3]['t']))
    for g, d in betas.items():
        for k, v in d.items():
            out[f'beta {k} [{g}] median'] = med(v)
    for g in ('F1', 'order-book', 'other'):
        out[f'revenue growth /yr [{g}] mean'] = st.fmean(growth[g]) if growth[g] else nan
    # board-level series
    full = [t for t, f in F.items() if len(f['q']) >= 79]
    n = min(len(F[t]['q']) for t in full)
    gap = [st.fmean(F[t]['q'][i]['gap'] for t in full) for i in range(n)]
    cyc = [t for t in full if group(t) != 'fin' and (META.get(t, {}).get('cyc') or 0) >= 1.2]
    f1 = [t for t in full if group(t) == 'F1']
    agg = lambda ts, i: sum(sum(F[t]['q'][j]['ni'] for j in range(i - 3, i + 1)) for t in ts)
    eps = []
    for i in range(8, n - 4):
        if gap[i] == min(gap[max(0, i - 6):i + 7]):
            p = max(range(max(4, i - 12), i), key=lambda j: gap[j])
            if gap[i] - gap[p] <= -0.03:
                row = {'t': F[full[0]]['q'][i]['t'], 'dgap': gap[i] - gap[p]}
                for lab, ts in (('cyclical', cyc), ('F1', f1)):
                    a0 = agg(ts, p)
                    win = list(range(p, min(n, i + 9)))
                    lo = min(win, key=lambda j: agg(ts, j))
                    row[lab] = agg(ts, lo) / a0 - 1 if a0 > 0 else nan
                    row[lab + ' lag q'] = lo - i
                eps.append(row)
    out['_eps'] = eps
    allq = [x for f in F.values() for x in f['q']]
    out['revenue beat rate (rev > expected)'] = sum(1 for x in allq if x.get('exp') and x['rev'] > x['exp']) / max(1, sum(1 for x in allq if x.get('exp')))
    out['B-or-worse share'] = sum(1 for x in allq if x['rating'] in BAD) / len(allq)
    R = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').read().splitlines()[-1])
    out['defaults+deaths'] = len(R['defaults']) + len(R['dead'])
    # board NI: annual d log, NI margin sd, lead-lag with the board price index from val.json
    bni = [sum(F[t]['q'][j]['ni'] for t in full for j in range(i - 3, i + 1)) for i in range(7, n, 4)]
    brev = [sum(F[t]['q'][j]['rev'] for t in full for j in range(i - 3, i + 1)) for i in range(7, n, 4)]
    out['sd board NI margin (annual)'] = st.pstdev([a / b for a, b in zip(bni, brev)])
    d = json.load(open(f'{H}/runs/{arm}-{s}.val.json'))
    rows = d['rows']
    q0 = next(i for i, (t, _) in enumerate(rows) if t >= 1.0 - 1e-6)
    dP, dN = [], []
    for k, i in enumerate(range(q0 + 4, len(rows) - 4, 4)):  # year 2 -> 3 onward, aligned with the TTM NI windows
        a, b = rows[i][1], rows[i + 4][1]
        w = x = 0.0
        for tk, v in a.items():
            u = b.get(tk)
            if u and v[5] > 0:
                w += v[5]
                x += v[5] * math.log(u[0] / v[0])
        dP.append(x / w)
    dN = [math.log(b / a) if a > 0 and b > 0 else nan for a, b in zip(bni, bni[1:])]
    out['_dP'], out['_dN'] = dP, dN
    return out


def corr(x, y):
    p = [(a, b) for a, b in zip(x, y) if a == a and b == b]
    if len(p) < 4:
        return nan
    mx, my = st.fmean(a for a, b in p), st.fmean(b for a, b in p)
    sx = math.sqrt(sum((a - mx) ** 2 for a, b in p))
    sy = math.sqrt(sum((b - my) ** 2 for a, b in p))
    return sum((a - mx) * (b - my) for a, b in p) / (sx * sy)


R = {(a, s): analyse(a, s) for a in (A, B) for s in seeds}
print(f'## Recession earnings, {B} vs {A}, seeds {seeds} (per-seed medians/means; paired {B} - {A})')
keys = sorted({k for v in R.values() for k in v if not k.startswith('_')})
for k in keys:
    a = [R[A, s].get(k, nan) for s in seeds]
    b = [R[B, s].get(k, nan) for s in seeds]
    ok = [(x, y) for x, y in zip(a, b) if x == x and y == y]
    print(f'{k} | {st.fmean([x for x, y in ok]) if ok else nan:.4g} | {st.fmean([y for x, y in ok]) if ok else nan:.4g} | ' + (fmt(paired([x for x, y in ok], [y for x, y in ok]), 4) if len(ok) > 1 else 'n/a'))
print('\n### Episodes (board-mean gap down >= 3 pp): peak-to-trough TTM NI change, lag of the NI trough after the gap trough (q)')
for arm in (A, B):
    eps = [e for s in seeds for e in R[arm, s]['_eps']]
    print(f"{arm}: n {len(eps)}; cyclical NI {med([e['cyclical'] for e in eps]):+.3f} (range {min([e['cyclical'] for e in eps], default=nan):+.3f}..{max([e['cyclical'] for e in eps], default=nan):+.3f}), lag {med([e['cyclical lag q'] for e in eps]):+.1f} q; "
          f"F1 NI {med([e['F1'] for e in eps]):+.3f} (range {min([e['F1'] for e in eps], default=nan):+.3f}..{max([e['F1'] for e in eps], default=nan):+.3f}), lag {med([e['F1 lag q'] for e in eps]):+.1f} q; dgap median {med([e['dgap'] for e in eps]):+.3f}")
print('\n### Board earnings vs prices (annual, pooled seed-years)')
for arm in (A, B):
    dP = sum((R[arm, s]['_dP'][:len(R[arm, s]['_dN'])] for s in seeds), [])
    dN = sum((R[arm, s]['_dN'][:len(R[arm, s]['_dP'])] for s in seeds), [])
    lead = sum((list(zip(R[arm, s]['_dN'][:-1], R[arm, s]['_dP'][1:len(R[arm, s]['_dN'])])) for s in seeds), [])
    lag = sum((list(zip(R[arm, s]['_dP'][:-1], R[arm, s]['_dN'][1:len(R[arm, s]['_dP'])])) for s in seeds), [])
    print(f'{arm}: corr(d log NI_t, d log P_t) {corr(dN, dP):+.3f}; NI leads (NI_t, P_t+1) {corr(*zip(*lead)):+.3f}; P leads (P_t, NI_t+1) {corr(*zip(*lag)):+.3f}')
