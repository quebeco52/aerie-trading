"""Credit-pricing gates, paired across seeds: credit.py <armA> <armB> [TICKER ...]

Ratings shares, defaults, reorganisations, icr < 1 and payment-default quarters, junk firm-quarters (TreasuryEngine's
junk test, recorded at report time) and newsworthy forced-deleveraging sweeps, board median interest/debt by rating, then
named firms at y0/y10/y20 (seed means; firm paths do not pair across arms).
"""
import glob
import json
import statistics as st
import sys
from collections import Counter, defaultdict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, mean_se  # noqa: E402

HERE = Path(__file__).parent
GRADES = ['A', 'BBB', 'BB', 'B', 'CCC']


def load(arm):
    runs = {}
    for p in sorted(glob.glob(str(HERE / f'runs/{arm}-s*.jsonl'))):
        with open(p) as f:
            r = json.loads(f.readline())
        runs[r['seed']] = r
    return runs


def metrics(run):
    m = {}
    ratings = Counter()
    icr1 = pdq = junk = junk_ops = 0
    ib = defaultdict(list)
    for t, f in run['final'].items():
        fin = run['meta'].get(t, {}).get('fin', False)
        for r in f['q']:
            if r.get('rev', 0) <= 0:
                continue
            g = r.get('rating', '?')
            ratings[g] += 1
            if r.get('icr') is not None and r['icr'] < 1.0:
                icr1 += 1
            if (r.get('qid') or 0) > 0:
                pdq += 1
            if r.get('junk'):
                junk += 1
                junk_ops += not fin
            if r.get('debt', 0) > 0 and r.get('int') is not None:
                ib[g].append(4 * r['int'] / r['debt'] * 100)
    n = sum(ratings.values())
    for g in GRADES:
        m[f'% firm-q rated {g}'] = 100 * ratings[g] / n
    m['firms ever in payment default'] = len(run['defaults'])
    m['deaths'] = len(run['dead'])
    m['reorganisations'] = len(run['reorgs'])
    m['icr < 1 firm-quarters'] = icr1
    m['payment-default firm-quarters'] = pdq
    m['junk firm-quarters (all)'] = junk
    m['junk firm-quarters (non-fin)'] = junk_ops
    m['forced delever sweeps (news)'] = sum(run.get('sweeps', {}).values())
    for g in GRADES[:4]:
        m[f'median int/debt % {g}'] = st.median(ib[g]) if ib[g] else float('nan')
    return m


def at(f, year):
    rows = [r for r in f['q'] if r.get('rev', 0) > 0]
    if not rows:
        return None
    return min(rows, key=lambda r: abs(r['t'] - year))


def main():
    a, b = sys.argv[1], sys.argv[2]
    named = sys.argv[3:]
    A, B = load(a), load(b)
    seeds = sorted(set(A) & set(B))
    ma = {s: metrics(A[s]) for s in seeds}
    mb = {s: metrics(B[s]) for s in seeds}
    print(f'{a} vs {b}, seeds {seeds}')
    print(f"{'quantity':34s} {a:>9s} {b:>9s}   diff (se)   t    n")
    for k in ma[seeds[0]]:
        xa = {s: ma[s][k] for s in seeds if ma[s][k] == ma[s][k]}
        xb = {s: mb[s][k] for s in seeds if mb[s][k] == mb[s][k]}
        p = paired(xa, xb)
        print(f'{k:34s} {mean_se(xa.values()).mean:9.2f} {mean_se(xb.values()).mean:9.2f}   '
              f'{p.diff:+7.2f} ({p.se:5.2f}) {p.t:+5.1f} {p.n}')
    print('\nNamed firms, seed means (int = annual interest bn; ROE = 4 NI / equity %; ratings by seed):')
    print(f"{'tick':5s} {'y':>2s} | {'int ' + a:>11s} {'int ' + b:>11s} | {'ROE ' + a:>9s} {'ROE ' + b:>9s} | ratings")
    for t in named:
        for y in (0, 10, 20):
            row = {}
            for arm, R in ((a, A), (b, B)):
                rs = [at(R[s]['final'][t], y) for s in seeds if t in R[s]['final']]
                rs = [r for r in rs if r is not None]
                ints = [4 * r['int'] / 1e9 for r in rs]
                roes = [400 * r['ni'] / r['eq'] for r in rs if r.get('eq', 0) > 0]
                row[arm] = (st.mean(ints) if ints else float('nan'), st.mean(roes) if roes else float('nan'),
                            ''.join(r['rating'].ljust(4) for r in rs))
            print(f'{t:5s} {y:2d} | {row[a][0]:11.2f} {row[b][0]:11.2f} | {row[a][1]:9.1f} {row[b][1]:9.1f} | '
                  f'{row[a][2]}/ {row[b][2]}')


if __name__ == '__main__':
    main()
