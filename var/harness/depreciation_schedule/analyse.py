#!/usr/bin/env python3
"""analyse.py [H] [seeds]: paired table, schedule vs units-of-production depreciation. Pairs base/new (replayed macro)
and rec/newlive (live macro). Groups: ops = non-financial, non-producer firms; prod = Mining + OilGasProducer models."""
import json, math, sys, statistics as st

H = sys.argv[1] if len(sys.argv) > 1 else '/tmp/claude-1000/depsched'
SEEDS = [int(s) for s in (sys.argv[2] if len(sys.argv) > 2 else '1,2,3,4').split(',')]
PROD = {'MiningBusinessModel', 'OilGasProducerBusinessModel'}
clip = lambda x, a=-1.0, b=1.0: max(a, min(b, x))


def mean(xs):
    xs = [x for x in xs if x is not None and math.isfinite(x)]
    return sum(xs) / len(xs) if xs else float('nan')


def load(arm, seed):
    with open(f'{H}/runs/{arm}-{seed}.jsonl') as f:
        return json.loads(f.readline())


def board(r):
    caps, bad, pxmax = {}, 0, 0.0
    for f in r['final'].values():
        if not math.isfinite(f['px']) or f['px'] < 0:
            bad += 1
        for q in f['q']:
            k = round(q['t'] * 4)
            caps[k] = caps.get(k, 0.0) + q['price'] * q['shares']
            if not math.isfinite(q['price']) or not math.isfinite(q['ebit']):
                bad += 1
        ps = [q['price'] for q in f['q'] if q['price'] > 0]
        if ps:
            pxmax = max(pxmax, max(ps) / ps[0])
    ks = sorted(caps)[1:-1]  # drop the partial first and last quarters
    s = [caps[k] for k in ks]
    peak, mdd = s[0], 0.0
    for v in s:
        peak = max(peak, v)
        mdd = max(mdd, 1 - v / peak)
    return {'cap_end/start': s[-1] / s[0], 'max_drawdown': mdd, 'failures': len(r['dead']) + len(r['reorgs']),
            'defaults': len(r['defaults']), 'nan/neg': bad, 'max px/px0': pxmax}


def firm_metrics(f, years):
    q = [x for x in f['q'] if x['rev'] > 0 and x.get('dp')]
    if len(q) < 8:
        return None
    m = [clip(x['ebit'] / x['rev']) for x in q]
    out = {
        'D/rev': mean([x['dep'] / x['rev'] for x in q]),
        'util/seas': mean([x['dp'][0] / max(0.01, x['dp'][1]) for x in q]),
        'D/scheduleD': mean([x['dep'] / x['dp'][2] for x in q if x['dp'][2] > 0]),
        'EBIT margin': mean(m),
        'sd EBIT margin': st.pstdev(m),
        '|surprise|/px %': 100 * mean([abs(x['reps'] - x['ceps']) / x['price'] for x in q if x['price'] > 0]),
        'ann var EMA x1e4': 1e4 * mean([x['ave'] for x in q]),
        'PPE growth %/yr': 100 * ((q[-1]['ppe'] / q[0]['ppe']) ** (4 / max(1, len(q) - 1)) - 1) if q[0]['ppe'] > 0 and q[-1]['ppe'] > 0 else None,
        'capex/D': sum(x['capex'] for x in q) / max(1e-9, sum(x['dep'] for x in q)),
        'ROIC %': 100 * mean([x['roic'] for x in q[4:]]),
        'distress share %': 100 * mean([1.0 if x['zone'] == 'Distress' else 0.0 for x in q if x['zone'] is not None]),
        'int cover (med)': st.median([clip(x['ebit'] / x['int'], -50, 50) for x in q if x['int'] > 0]) if any(x['int'] > 0 for x in q) else None,
    }
    seas = f['seas']
    if max(seas) - min(seas) > 0.01:
        hi = [clip(x['ebit'] / x['rev']) for x in q if abs(x['dp'][1] - max(seas)) < 1e-9]
        lo = [clip(x['ebit'] / x['rev']) for x in q if abs(x['dp'][1] - min(seas)) < 1e-9]
        out['seasonal amp pp'] = 100 * (mean(hi) - mean(lo)) if hi and lo else None
    return out


def group(r, prod):
    rows = [firm_metrics(f, r['years']) for f in r['final'].values()
            if not f['fin'] and ((f['model'] in PROD) == prod)]
    rows = [x for x in rows if x]
    keys = list(dict.fromkeys(k for x in rows for k in x))
    g = {k: mean([x.get(k) for x in rows]) for k in keys}
    g['n firms'] = len(rows)
    g['n seasonal'] = sum(1 for x in rows if x.get('seas' + 'onal amp pp') is not None)
    return g


def pair(a, b, label, fn):
    A = [fn(load(a, s)) for s in SEEDS]
    B = [fn(load(b, s)) for s in SEEDS]
    print(f'\n== {label}: {a} vs {b}, seeds {SEEDS} ==')
    print(f"{'quantity':22s} {a:>10s} {b:>10s} {'delta':>10s} {'se':>9s} {'t':>6s}")
    for k in A[0]:
        xa = [d.get(k, float('nan')) for d in A]
        xb = [d.get(k, float('nan')) for d in B]
        d = [y - x for x, y in zip(xa, xb)]
        se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
        dm = mean(d)
        t = dm / se if se and se > 0 else float('nan')
        print(f'{k:22s} {mean(xa):10.4g} {mean(xb):10.4g} {dm:10.3g} {se:9.2g} {t:6.1f}')


pair('base', 'new', 'board sanity (replayed macro)', board)
pair('rec', 'newlive', 'board sanity (live macro)', board)
pair('base', 'new', 'ops firms (non-fin, non-producer), replayed macro', lambda r: group(r, False))
pair('base', 'new', 'producers (control), replayed macro', lambda r: group(r, True))
pair('rec', 'newlive', 'ops firms, live macro', lambda r: group(r, False))
