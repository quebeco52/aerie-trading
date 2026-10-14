"""ERNE and WING moments, arm a (base) vs b (working tree), paired by seed. Usage: python3 -I analyse.py <lo-hi>."""
import json, math, os, sys

H = os.path.dirname(os.path.abspath(__file__))
GDP0 = 12.0e12  # MacroEngine::DISTRICT_GDP_USD x nominal GDP index
T0 = 2.0
lo, hi = (int(x) for x in sys.argv[1].split('-'))
seeds = list(range(lo, hi + 1))
ARMS = ['a', 'b']
BAD = {'B', 'CCC', 'CC', 'C', 'D'}


def cq(t):
    return (int(math.floor(t * 4 + 1e-6)) - 1) % 4


def mean(xs):
    xs = [x for x in xs if x is not None]
    return sum(xs) / len(xs) if xs else float('nan')


def sd(xs):
    m = mean(xs)
    return math.sqrt(sum((x - m) ** 2 for x in xs) / (len(xs) - 1))


def load(arm, s):
    return json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())


def stats(r, extra):
    years = r['years']
    out = {}
    f = r['final']['ERNE']
    allq = f['q']
    qs = [q for q in allq if q['t'] > T0 and q['rev'] > 0]
    m = lambda k: mean([q[k] / q['rev'] for q in qs])
    lq = allq[-1]
    ttm = sum(q['ebitda'] for q in allq[-4:])
    out.update({
        'ERNE EBIT margin': m('ebit'), 'ERNE EBITDA margin': m('ebitda'), 'ERNE capex/rev': m('capex'), 'ERNE D&A/rev': m('dep'),
        'ERNE rev error sd (act-cons)/act': sd([(q['rev'] - q['crev']) / q['rev'] for q in qs if q.get('crev')]),
        'ERNE rev error mean (act-cons)/act': mean([(q['rev'] - q['crev']) / q['rev'] for q in qs if q.get('crev')]),
        'ERNE opening-year revenue ($bn)': sum(q['rev'] for q in allq[:4]) / 1e9,
        'ERNE mkt cap / GDP (%), y12': 100 * lq['price'] * lq['shares'] / (GDP0 * lq['ngdp']),
        'ERNE net debt/EBITDA, y12': (lq['debt'] - lq['cash']) / ttm if ttm > 0 else float('nan'),
    })
    sh = {round(e['t'], 3) for e in r['events'].get('ERNE', []) if e['d'] == 'SHORTAGE'}
    for q in qs:
        b = '<1' if q['gscpi'] < 1 else ('1-2' if q['gscpi'] <= 2 else '>2')
        extra.setdefault(b, [0, 0])
        extra[b][0] += 1
        extra[b][1] += 1 if round(q['t'], 3) in sh else 0
    extra.setdefault('gscpi', []).extend(q['gscpi'] for q in allq)
    out['ERNE shortage share of quarters'] = mean([1.0 if round(q['t'], 3) in sh else 0.0 for q in qs])
    w = [q for q in r['final']['WING']['q'] if q['t'] > T0 and q['rev'] > 0]
    out['WING sd q/q log revenue change'] = sd([math.log(b['rev'] / a['rev']) for a, b in zip(w, w[1:])])
    for c in range(4):
        out[f'WING EBIT margin Q{c+1}'] = mean([q['ebit'] / q['rev'] for q in w if cq(q['t']) == c])
    out['WING EBIT margin all'] = mean([q['ebit'] / q['rev'] for q in w])
    out['WING max-min quarter EBIT margin'] = max(out[f'WING EBIT margin Q{c+1}'] for c in range(4)) - min(out[f'WING EBIT margin Q{c+1}'] for c in range(4))
    dist = {}
    for t in ('ERNE', 'WING'):
        ff = r['final'][t]
        rat = {q.get('rating') for q in ff['q']}
        dist[t] = (ff['dead'], max(q.get('qid') or 0 for q in ff['q']), bool(rat & BAD), sorted(x for x in rat if x))
    out['_dist'] = dist
    return out


D, X = {}, {arm: {} for arm in ARMS}
for arm in ARMS:
    D[arm] = {s: stats(load(arm, s), X[arm]) for s in seeds}
keys = [k for k in D['a'][seeds[0]] if not k.startswith('_')]
pct = lambda k: 'margin' in k or '/rev' in k or 'error' in k or 'share of' in k
n = len(seeds)
se = lambda v: sd(v) / math.sqrt(len(v))
print(f'ERNE/WING, seeds {lo}-{hi} (n={n}), quarters reported after year {T0:g}; pct quantities in %; b - a paired by seed')
print(f"{'quantity':38s} {'a mean':>9s} {'(se)':>6s} {'b mean':>9s} {'(se)':>6s} {'b-a':>8s} {'(se)':>6s} {'t':>6s}")
for k in keys:
    sc = 100 if pct(k) else 1
    a = [D['a'][s][k] * sc for s in seeds]
    b = [D['b'][s][k] * sc for s in seeds]
    d = [y - x for x, y in zip(a, b)]
    t = mean(d) / se(d) if se(d) > 0 else float('nan')
    print(f'{k:38s} {mean(a):9.2f} {se(a):6.2f} {mean(b):9.2f} {se(b):6.2f} {mean(d):8.2f} {se(d):6.2f} {t:6.1f}')
print('ERNE shortage events per quarter by GSCPI EMA at the report (pooled quarters after year 2):')
for arm in ARMS:
    print(f'  {arm}: ' + '  '.join(f"{b}: {X[arm][b][1]}/{X[arm][b][0]} = {X[arm][b][1] / X[arm][b][0]:.3f}" for b in ('<1', '1-2', '>2') if b in X[arm]))
g = X['a']['gscpi']
print(f"GSCPI EMA over all reports: mean {mean(g):.2f}, sd {sd(g):.2f}, min {min(g):.2f}, max {max(g):.2f}")
for arm in ARMS:
    for t in ('ERNE', 'WING'):
        rows = [D[arm][s]['_dist'][t] for s in seeds]
        print(f"{arm} {t}: dead {sum(r[0] for r in rows)}/{n}, max quarters in default {max(r[1] for r in rows)}, "
              f"seeds reaching B or worse {sum(r[2] for r in rows)}, ratings seen {sorted(set(x for r in rows for x in r[3]))}")
