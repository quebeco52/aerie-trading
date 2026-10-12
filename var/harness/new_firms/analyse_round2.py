#!/usr/bin/env python3
"""Per-seed sanity table for PELI (health plan) and EGRT (custody bank). Usage: ARM=<arm> python3 analyse_round2.py [seeds...]

Run without -I: it imports minnp.py beside it.
"""
import json, math, os, sys
import minnp as np

H = os.path.dirname(os.path.abspath(__file__))
ARM = os.environ.get('ARM', 'live')
seeds = [int(s) for s in sys.argv[1:]] or [1, 2, 3]


def load(s):
    r = json.loads(open(os.path.join(H, 'runs', f'{ARM}-{s}.jsonl')).readline())
    op = json.load(open(os.path.join(H, 'runs', f'{ARM}-{s}.open.json')))
    return r, op


def splits(q):
    f = [1.0]
    for a, b in zip(q, q[1:]):
        r = b['shares'] / a['shares'] if a['shares'] > 0 else 1.0
        f.append(round(r) if r >= 1.5 else (1.0 / round(1.0 / r) if r <= 0.67 else 1.0))
    return f


def returns(q):
    f = splits(q)
    return {int(round(b['t'] * 4)): b['price'] * f[i + 1] / a['price'] - 1.0 for i, (a, b) in enumerate(zip(q, q[1:])) if a['price'] > 0}


def yoy(v):
    return [v[i] / v[i - 4] - 1.0 if v[i - 4] else float('nan') for i in range(4, len(v))]


def growth(v, lag=4):
    """Year-on-year log change of a level series."""
    return [math.log(v[i] / v[i - lag]) if v[i] > 0 and v[i - lag] > 0 else float('nan') for i in range(lag, len(v))]


rows = {}
for s in seeds:
    r, op = load(s)
    fin = r['final']
    rets = {t: returns(f['q']) for t, f in fin.items() if len(f['q']) > 1}
    pool = {}
    for dd in rets.values():
        for k, v in dd.items():
            pool.setdefault(k, []).append(v)
    board = {k: float(np.mean(v)) for k, v in pool.items()}
    out = {'fail': len(r['dead'])}
    for t in ('PELI', 'EGRT', 'LAKE'):
        q = fin[t]['q']
        om = [x['om'] for x in q]
        rev = [x['rev'] for x in q]
        rt = rets.get(t, {})
        ks = sorted(k for k in rt if k in board)
        d = {
            'n': len(q), 'p5': np.percentile(om, 5), 'p50': np.median(om), 'p95': np.percentile(om, 95),
            'loss': float(np.mean([x['ni'] < 0 for x in q])),
            'bad': sum(1 for x in rev if not math.isfinite(x) or x < 0) + sum(1 for x in om if not math.isfinite(x)),
            'dead': fin[t]['dead'], 'px': fin[t]['px'] * math.prod(splits(q)) / op[t]['px0'],
            'beta': np.corr([rt[k] for k in ks], [board[k] for k in ks]),
            'roe': [4.0 * x['ni'] / x['eq'] for x in q if x['eq'] > 0],
            'rev_x': rev[-1] / rev[0] if rev[0] else float('nan'),
        }
        if t == 'PELI':
            m = [x['mcr'] for x in q if x.get('mcr') is not None]
            d['mcr'] = (min(m), float(np.median(m)), max(m))
            d['reb_n'] = sum(1 for x in q if x.get('reb', 0.0) > 0.0)
            d['reb_mean'] = float(np.mean([x.get('reb', 0.0) for x in q]))
            # Membership is revenue over the premium level; read it as revenue growth against the unemployment gap.
            d['c_ugap'] = np.corr(yoy(rev), [x['ugap'] - y['ugap'] for x, y in zip(q[4:], q)])
            d['c_reimb_mcr'] = np.corr([x['reimb'] for x in q], [x.get('mcr') or float('nan') for x in q])
        if t == 'EGRT':
            d['c_ewr'] = np.corr(growth(rev), growth([x['ewr'] for x in q]))
            d['fund'] = (min(x['fund'] for x in q), max(x['fund'] for x in q))
        out[t] = d
    rows[s] = out


def fmt(v):
    return '%.3f' % v if isinstance(v, float) else str(v)


def line(label, f):
    vals = [f(rows[s]) for s in seeds]
    num = [v for v in vals if isinstance(v, (int, float)) and not isinstance(v, bool)]
    pooled = '%.3f' % float(np.mean(num)) if len(num) == len(vals) else ''
    print(label.ljust(36) + ''.join(fmt(v).rjust(10) for v in vals) + pooled.rjust(10))


print('seed'.ljust(36) + ''.join(str(s).rjust(10) for s in seeds) + 'pooled'.rjust(10))
for T in ('PELI', 'EGRT'):
    print(T)
    for k in ('p5', 'p50', 'p95'):
        line(' op margin ' + k, lambda r, k=k: float(r[T][k]))
    for k, p in (('p5', 5), ('p50', 50), ('p95', 95)):
        line(' ROE ' + k, lambda r, p=p: float(np.percentile(r[T]['roe'], p)))
    line(' loss-quarter share', lambda r: float(r[T]['loss']))
    if T == 'PELI':
        for i, k in enumerate(('min', 'median', 'max')):
            line(' medical cost ratio ' + k, lambda r, i=i: float(r[T]['mcr'][i]))
        line(' rebate quarters', lambda r: r[T]['reb_n'])
        line(' mean rebate / premium', lambda r: r[T]['reb_mean'])
        line(' corr(YoY rev, YoY d unemployment gap)', lambda r: r[T]['c_ugap'])
        line(' corr(reimbursement update, MCR)', lambda r: r[T]['c_reimb_mcr'])
    else:
        line(' corr(YoY log rev, YoY log equity/trend)', lambda r: r[T]['c_ewr'])
        line(' fund/GDP min', lambda r: float(r[T]['fund'][0]))
        line(' fund/GDP max', lambda r: float(r[T]['fund'][1]))
        line(' LAKE corr(ret, board EW)', lambda r: r['LAKE']['beta'])
    line(' corr(ret, board EW)', lambda r: r[T]['beta'])
    line(' revenue y20 / y0', lambda r: float(r[T]['rev_x']))
    line(' price y20 / seed', lambda r: float(r[T]['px']))
    line(' dead', lambda r: r[T]['dead'])
    line(' NaN/neg revenue or margin', lambda r: r[T]['bad'])
print('Board')
line(' failures', lambda r: r['fail'])
