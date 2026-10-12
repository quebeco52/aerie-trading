#!/usr/bin/env python3
"""Per-seed sanity table for BOWR and FNCH from runs/live-SEED.jsonl. Usage: analyse.py [seeds...]"""
import json, sys, math, os
import minnp as np
H = os.path.dirname(os.path.abspath(__file__))
seeds = [int(s) for s in sys.argv[1:]] or [1, 2, 3]


def splits(q):
    """Split factor per quarter from share jumps of 1.5x or more; buybacks and issuance are smaller."""
    f = [1.0]
    for a, b in zip(q, q[1:]):
        r = b['shares'] / a['shares'] if a['shares'] > 0 else 1.0
        f.append(round(r) if r >= 1.5 else (1.0 / round(1.0 / r) if r <= 0.67 else 1.0))
    return f


def corr(x, y):
    return np.corr(x, y)
    m = np.logical_and(np.isfinite(x), np.isfinite(y))
    return float(np.corrcoef(x[m], y[m])[0, 1]) if m.sum() > 3 else float('nan')


def adj_returns(q):
    f = splits(q)
    out = dict()
    for i, (a, b) in enumerate(zip(q, q[1:])):
        if a['price'] > 0:
            out[int(round(b['t'] * 4))] = b['price'] * f[i + 1] / a['price'] - 1.0
    return out


def yoy(v):
    return [v[i] / v[i - 4] - 1.0 if v[i - 4] else float('nan') for i in range(4, len(v))]


def load(s):
    r = json.loads(open(os.path.join(H, 'runs', os.environ.get('ARM', 'live') + '-' + str(s) + '.jsonl')).readline())
    op = json.load(open(os.path.join(H, 'runs', os.environ.get('ARM', 'live') + '-' + str(s) + '.open.json')))
    return r, op


def firm(fin, op, t, board, rets):
    q = fin[t]['q']
    om = [x["om"] for x in q]
    rev = [x['rev'] for x in q]
    d = dict(n=len(q), p5=np.percentile(om, 5), p50=np.median(om), p95=np.percentile(om, 95),
             loss=float(np.mean([x['ni'] < 0 for x in q])),
             bad=int(sum(1 for x in rev if not math.isfinite(x) or x < 0) + sum(1 for x in om if not math.isfinite(x))),
             dead=fin[t]['dead'], px_x=fin[t]['px'] * math.prod(splits(q)) / op[t]['px0'])
    rt = rets.get(t, dict())
    ks = sorted(k for k in rt if k in board)
    d['beta_corr'] = corr([rt[k] for k in ks], [board[k] for k in ks])
    if t == 'BOWR':
        h = [x['hsm'] for x in q if x['hsm'] is not None]
        d['hsm'] = (min(h), float(np.median(h)), max(h))
        # Land is plant: its write-down is read against the opening net PP&E.
        wd = [(x.get('lwd', 0.0) / p['ppe']) if p['ppe'] > 0 else 0.0 for p, x in zip(q, q[1:])]
        d['wd_n'] = sum(1 for x in q if x.get('lwd', 0.0) > 0)
        d['wd_max'] = max(wd) if wd else 0.0
        d['wd_cum_peak'] = sum(x.get('lwd', 0.0) for x in q) / max(x['ppe'] for x in q)
        d['c_hpt'] = corr([x['hsm'] for x in q], [x['hpt'] for x in q])
        d['hpt_rng'] = (min(x['hpt'] for x in q), max(x['hpt'] for x in q))
    if t == 'FNCH':
        d['c_gold'] = corr(yoy(rev), yoy([x['gold'] for x in q]))
        d['c_met'] = corr(yoy(rev), yoy([x['metals'] for x in q]))
    return d


rows = dict()
for s in seeds:
    r, op = load(s)
    fin = r['final']
    rets = dict((t, adj_returns(fin[t]['q'])) for t in fin if len(fin[t]['q']) > 1)
    pool = dict()
    for t, dd in rets.items():
        for k, v in dd.items():
            pool.setdefault(k, []).append(v)
    board = dict((k, np.mean(v)) for k, v in pool.items())
    out = dict(fail=len(r['dead']))
    for t in ('BOWR', 'FNCH', 'CNDR'):
        out[t] = firm(fin, op, t, board, rets)
    rows[s] = out


def fmt(v):
    return '%.3f' % v if isinstance(v, float) else str(v)


def line(label, f):
    vals = [f(rows[s]) for s in seeds]
    num = [v for v in vals if isinstance(v, (int, float)) and not isinstance(v, bool)]
    pooled = '%.3f' % np.mean(num) if len(num) == len(vals) else ''
    print(label.ljust(34) + ''.join(fmt(v).rjust(10) for v in vals) + pooled.rjust(10))


print('seed'.ljust(34) + ''.join(str(s).rjust(10) for s in seeds) + 'pooled'.rjust(10))
for T in ('BOWR', 'FNCH'):
    print(T)
    line(' quarters', lambda r: r[T]['n'])
    for k in ('p5', 'p50', 'p95'):
        line(' op margin ' + k, lambda r, k=k: float(r[T][k]))
    line(' loss-quarter share', lambda r: float(r[T]['loss']))
    if T == 'BOWR':
        for i, k in enumerate(('min', 'median', 'max')):
            line(' home_sales_margin ' + k, lambda r, i=i: float(r[T]['hsm'][i]))
        line(' write-down quarters', lambda r: r[T]['wd_n'])
        line(' max land write-down / opening plant', lambda r: float(r[T]['wd_max']))
        line(' sum land write-downs / peak plant', lambda r: float(r[T]['wd_cum_peak']))
        line(' corr(hsm, house px/trend)', lambda r: r[T]['c_hpt'])
        line(' house px/trend min', lambda r: float(r[T]['hpt_rng'][0]))
        line(' house px/trend max', lambda r: float(r[T]['hpt_rng'][1]))
    else:
        line(' corr(YoY rev, YoY gold)', lambda r: r[T]['c_gold'])
        line(' corr(YoY rev, YoY ind. metals)', lambda r: r[T]['c_met'])
        line(' CNDR corr(ret, board EW)', lambda r: r['CNDR']['beta_corr'])
    line(' corr(ret, board EW)', lambda r: r[T]['beta_corr'])
    line(' dead', lambda r: r[T]['dead'])
    line(' price y20 / seed', lambda r: float(r[T]['px_x']))
    line(' NaN/neg revenue or margin', lambda r: r[T]['bad'])
print('Board')
line(' failures', lambda r: r['fail'])
