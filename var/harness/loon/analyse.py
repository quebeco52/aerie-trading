"""LOON operating moments, arm a (base) vs b (working tree), paired by seed. Usage: python3 -I analyse.py <seeds e.g. 1-8>."""
import json, math, os, sys

H = os.path.dirname(os.path.abspath(__file__))
GDP0 = 12.0e12  # MacroEngine::DISTRICT_GDP_USD, nominal GDP at the opening (index ~1.0)
T0 = 2.0
lo, hi = (int(x) for x in sys.argv[1].split('-'))
ARMS = sys.argv[2:] or ['a', 'b']  # first arm is the reference, each later arm is paired against it
seeds = list(range(lo, hi + 1))


def cq(t):
    return (int(math.floor(t * 4 + 1e-6)) - 1) % 4


def mean(xs):
    xs = [x for x in xs if x is not None]
    return sum(xs) / len(xs) if xs else float('nan')


def seed_stats(arm, s):
    r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').readline())
    f = r['final']['LOON']
    qs = [q for q in f['q'] if q['t'] > T0 and q['rev'] > 0]
    m = lambda k: mean([q[k] / q['rev'] for q in qs])
    om_by = [mean([q['ebit'] / q['rev'] for q in qs if cq(q['t']) == c]) for c in range(4)]
    sur = lambda q: (q['reps'] - q['ceps']) / abs(q['ceps']) if q.get('ceps') and q.get('reps') is not None and abs(q['ceps']) > 1e-9 else None
    su_by = [mean([sur(q) for q in qs if cq(q['t']) == c]) for c in range(4)]
    # EPS surprise in margin points: (reported - consensus EPS) x shares / revenue; robust to consensus EPS near zero.
    spp = lambda q: (q['reps'] - q['ceps']) * q['shares'] / q['rev'] if q.get('ceps') is not None and q.get('reps') is not None else None
    sp_by = [mean([spp(q) for q in qs if cq(q['t']) == c]) for c in range(4)]
    last = [q for q in f['q'] if q['t'] > r['years'] - 1.0]
    ttm = sum(q['ebitda'] for q in last[-4:])
    lq = f['q'][-1]
    ni = sum(q['ni'] for q in qs)
    gate = [q for q in qs if 'g_roic' in q]
    return {
        'EBIT margin': m('ebit'), 'EBITDA margin': m('ebitda'), 'Capex/rev': m('capex'), 'D&A/rev': m('dep'),
        'Capex/rev, median quarter': sorted(q['capex'] / q['rev'] for q in qs)[len(qs) // 2],
        'Capex/D&A': sum(q['capex'] for q in qs) / sum(q['dep'] for q in qs),
        'Q4 - mean(Q1-3) EBIT margin': om_by[3] - mean(om_by[:3]),
        **{f'EBIT margin Q{c+1}': om_by[c] for c in range(4)},
        **{f'EPS surprise Q{c+1}': su_by[c] for c in range(4)},
        'EPS surprise Q4 - mean(Q1-3)': su_by[3] - mean(su_by[:3]),
        **{f'EPS surprise margin-pp Q{c+1}': sp_by[c] for c in range(4)},
        'EPS surprise pp Q4 - mean(Q1-3)': sp_by[3] - mean(sp_by[:3]),
        'EPS surprise pp Q1 - mean(Q2-4)': sp_by[0] - mean(sp_by[1:]),
        'Revenue last yr ($bn)': sum(q['rev'] for q in last[-4:]) / 1e9,
        'Mkt cap / nominal GDP (%)': 100 * lq['price'] * lq['shares'] / (GDP0 * lq['ngdp']),
        'Gross debt/EBITDA (last)': lq['debt'] / ttm if ttm > 0 else float('nan'),
        'Net debt/EBITDA (last)': (lq['debt'] - lq['cash']) / ttm if ttm > 0 else float('nan'),
        **{f'Revenue growth y{y}->{y+1} (%)': 100 * (sum(q['rev'] for q in f['q'] if y < q['t'] <= y + 1) / sum(q['rev'] for q in f['q'] if y - 1 < q['t'] <= y) - 1) for y in range(int(r['years']) - 4, int(r['years']))},
        'Net PP&E growth last 4y (%/yr)': 100 * ((lq['ppe'] / [q for q in f['q'] if q['t'] <= r['years'] - 4][-1]['ppe']) ** 0.25 - 1),
        'Payout (div/NI)': sum(q['div'] for q in qs) / ni if ni > 0 else float('nan'),
        'Growth gate open (share of q)': mean([1.0 if q['g_roic'] >= q['g_hurdle'] and q['g_reinv'] > 0 else 0.0 for q in gate]),
        'Gate ROIC - hurdle (pp)': 100 * mean([q['g_roic'] - q['g_hurdle'] for q in gate]),
        '_dead': f['dead'], '_qid': max(q.get('qid') or 0 for q in f['q']),
        '_ratings': sorted({q['rating'] for q in f['q'] if q.get('rating')}),
        '_path': ''.join({'AAA': 'A', 'AA': 'A', 'A': 'A', 'BBB': '3', 'BB': '2', 'B': '1', 'CCC': 'c', 'CC': 'c', 'C': 'c', 'D': 'D'}.get(q.get('rating'), '?') for q in f['q'][3::4]),
    }


D = {arm: {s: seed_stats(arm, s) for s in seeds} for arm in ARMS}
ref = ARMS[0]
keys = [k for k in D[ref][seeds[0]] if not k.startswith('_')]
pct = lambda k: 'margin' in k or '/rev' in k or 'surprise' in k
n = len(seeds)
se = lambda v: math.sqrt(sum((x - sum(v) / len(v)) ** 2 for x in v) / (len(v) - 1) / len(v))
print(f'LOON, seeds {lo}-{hi} (n={n}), quarters reported after year {T0:g}; pct quantities in %; diffs vs {ref}')
print(f"{'quantity':34s}" + ''.join(f"{arm + ' mean':>10s} {'(se)':>6s}" for arm in ARMS) + ''.join(f"{arm + '-' + ref:>9s} {'(se)':>6s} {'t':>5s}" for arm in ARMS[1:]))
for k in keys:
    sc = 100 if pct(k) else 1
    v = {arm: [D[arm][s][k] * sc for s in seeds] for arm in ARMS}
    line = f'{k:34s}' + ''.join(f'{sum(v[a]) / n:10.2f} {se(v[a]):6.2f}' for a in ARMS)
    for a in ARMS[1:]:
        d = [y - x for x, y in zip(v[ref], v[a])]
        md, sd = sum(d) / n, se(d)
        line += f'{md:9.2f} {sd:6.2f} {(md / sd if sd > 0 else float("nan")):5.1f}'
    print(line)
for arm in ARMS:
    X = D[arm]
    print(f"{arm}: dead {sum(X[s]['_dead'] for s in seeds)}/{n}, max quarters in default {max(X[s]['_qid'] for s in seeds)}, "
          f"seeds reaching B/CCC {sum(1 for s in seeds if set(X[s]['_ratings']) & {'B', 'CCC', 'CC', 'C', 'D'})}, ratings seen {sorted(set(x for s in seeds for x in X[s]['_ratings']))}")
    print(f"  rating at each year end (A=A or better, 3=BBB, 2=BB, 1=B, c=CCC): " + ' '.join(X[s]['_path'] for s in seeds))
