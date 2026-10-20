#!/usr/bin/env python3
"""lender_an.py <seeds> <armA> <armB>: lenders and brokers, two unpaired arms (mean (se) over seeds).
Per firm: wholesale debt at years 0/5/10/20 (bn), trailing-4q ROE mean and within-run sd (NI TTM / mean equity of the
4 quarters, year 1 skipped), equity CAGR, EPS beat share (reps > ceps), NCO / (TTC x gross book at quarter open x 1/4),
summed over the run, and ROOK's interest-income share of revenue and corr(ROE, policy rate). Failures = runs where the
firm stops reporting before year 19. Needs the nco/ttc/ii report fields (JointHarnessTest 10-20 D1)."""
import collections, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arms = sys.argv[2:4]
FIRMS = {'LAKE': (.21, .21), 'RIVR': (.15, .15), 'PLVR': (.11, .11), 'TALN': (.25, .25), 'STRK': (.25, .25),
         'POOL': (.29, .29), 'EGRT': (.11, .13), 'BNTH': (.12, .18), 'ACC': (.26, .26), 'ROOK': (.20, .20)}


def se(x):
    return st.stdev(x) / math.sqrt(len(x)) if len(x) > 1 else float('nan')


def ms(x, f='{:.3f}'):
    x = [v for v in x if v is not None and not math.isnan(v)]
    return (f.format(st.mean(x)) + ' (' + f.format(se(x)) + ')') if x else 'n/a'


def at(q, y):
    return min(q, key=lambda r: abs(r['t'] - y))


def corr(a, b):
    if len(a) < 3 or st.pstdev(a) == 0 or st.pstdev(b) == 0:
        return float('nan')
    ma, mb = st.mean(a), st.mean(b)
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / len(a) / st.pstdev(a) / st.pstdev(b)


R = {arm: collections.defaultdict(lambda: collections.defaultdict(list)) for arm in arms}
for arm in arms:
    for s in seeds:
        d = json.loads(open(f"{H}/runs/{arm}-{s}.jsonl").readline())
        pol = [(r['t'], r['policy']) for r in map(json.loads, open(f"{H}/runs/{arm}-{s}.macro.jsonl")) if 'policy' in r]
        for t in FIRMS:
            q = d['final'].get(t, {}).get('q', [])
            P = R[arm][t]
            if not q:
                P['fail'].append(1.0)
                continue
            P['fail'].append(1.0 if q[-1]['t'] < 19.0 else 0.0)
            for y in (0, 5, 10, 20):
                P[f'wd{y}'].append(at(q, y)['wd'] / 1e9)
            roe, rate = [], []
            for i in range(4, len(q)):
                if q[i]['t'] <= 1.0:
                    continue
                eq = st.mean(r['eq'] for r in q[i - 3:i + 1])
                if eq > 0:
                    roe.append(sum(r['ni'] for r in q[i - 3:i + 1]) / eq)
                    rate.append(min(pol, key=lambda p: abs(p[0] - q[i]['t']))[1])
            if roe:
                P['roe'].append(st.mean(roe)); P['roesd'].append(st.pstdev(roe))
                P['rcorr'].append(corr(roe, rate))
            yrs = q[-1]['t'] - q[0]['t']
            if q[0]['eq'] > 0 and q[-1]['eq'] > 0 and yrs > 1:
                P['eqg'].append((q[-1]['eq'] / q[0]['eq']) ** (1 / yrs) - 1)
            b = [1.0 if r['reps'] > r['ceps'] else 0.0 for r in q if r['t'] > 1.0 and r.get('ceps', 0) != 0]
            if b:
                P['beat'].append(st.mean(b))
            if 'nco' in q[0]:
                num = sum(q[i]['nco'] for i in range(1, len(q)))
                den = sum(q[i]['ttc'] * q[i - 1]['ea'] / 4 for i in range(1, len(q)))
                if den > 0:
                    P['ncottc'].append(num / den)
                    P['ttc'].append(st.mean(r['ttc'] for r in q))
                    P['ncor'].append(st.mean(q[i]['nco'] * 4 / q[i - 1]['ea'] for i in range(1, len(q)) if q[i - 1]['ea'] > 0))
                rv = [r for r in q if r['rev'] > 0]
                if rv:
                    P['iish'].append(st.mean(r['ii'] / r['rev'] for r in rv))
                    P['rev'].append(st.mean(r['rev'] for r in rv) / 1e9); P['ii'].append(st.mean(r['ii'] for r in rv) / 1e9)

print(f"seeds {sys.argv[1]}, arms {arms[0]} | {arms[1]} (unpaired, mean (se) over seeds)")
rows = [('wd0', 'wholesale y0 bn', '{:.0f}'), ('wd5', 'wholesale y5 bn', '{:.0f}'), ('wd10', 'wholesale y10 bn', '{:.0f}'),
        ('wd20', 'wholesale y20 bn', '{:.0f}'), ('roe', 'ROE ttm mean', '{:.3f}'), ('roesd', 'ROE within-run sd', '{:.3f}'),
        ('eqg', 'equity CAGR', '{:.3f}'), ('beat', 'EPS beat share', '{:.2f}'), ('ttc', 'TTC rate', '{:.4f}'),
        ('ncor', 'NCO/gross ann.', '{:.4f}'), ('ncottc', 'NCO / TTC x gross', '{:.2f}'), ('rcorr', 'corr(ROE, policy)', '{:.2f}'),
        ('iish', 'int. income / rev', '{:.2f}'), ('rev', 'rev/q bn', '{:.2f}'), ('ii', 'int. income/q bn', '{:.2f}'),
        ('fail', 'failed share', '{:.2f}')]
for t, (ta, tb) in FIRMS.items():
    print(f"\n{t}  ROE target {ta:.2f} | {tb:.2f}")
    for k, lab, f in rows:
        a, b = R[arms[0]][t][k], R[arms[1]][t][k]
        if not a and not b:
            continue
        print(f"  {lab:20s} {ms(a, f):>22s} | {ms(b, f):>22s}")
