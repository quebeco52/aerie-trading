#!/usr/bin/env python3
"""rating_stability.py <armA> <armB> [first_year last_year]: rating stability of R arms (run.sh), paired by seed.

Targets, per seed: letter-grade changes per firm-year and reversals (a move followed by one the other way) within a year
and within a quarter, per firm-year, from the RATING_OUT review rows; the rating-spread Spearman for operating firms and
for financials (rating worse = higher, against the live issuer spread `dspread`, firm-quarters in years first..last,
default 2..19, as analyse_spread.py); failures (bankruptcies + reorganisations) per seed. Guards: the operating firms'
rating mix and the cause split of rating_decompose.py. Prints mean A, mean B, the mean paired difference, its SE and t.
Benchmark for the change rate: S&P one-year letter-level transitions of investment grade, about 0.08-0.10 excluding NR.
"""
import glob, json, math, os, re, statistics as st, sys
from collections import Counter

H = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, H)
from rating_decompose import analyse  # noqa: E402

A, B = sys.argv[1], sys.argv[2]
Y0, Y1 = (int(sys.argv[3]), int(sys.argv[4])) if len(sys.argv) > 4 else (2, 19)
RANK = {'AAA': 7, 'AA': 6, 'A': 5, 'BBB': 4, 'BB': 3, 'B': 2, 'CCC': 1, 'D': 0}


def spearman(x, y):
    def rank(v):
        order = sorted(range(len(v)), key=lambda i: v[i])
        r = [0.0] * len(v)
        i = 0
        while i < len(v):
            j = i
            while j + 1 < len(v) and v[order[j + 1]] == v[order[i]]:
                j += 1
            for k in range(i, j + 1):
                r[order[k]] = (i + j) / 2
            i = j + 1
        return r
    if len(x) < 3:
        return math.nan
    a, b = rank(x), rank(y)
    ma, mb = st.mean(a), st.mean(b)
    den = math.sqrt(sum((p - ma) ** 2 for p in a) * sum((q - mb) ** 2 for q in b))
    return sum((p - ma) * (q - mb) for p, q in zip(a, b)) / den if den else math.nan


def seed_metrics(arm, seed):
    base = f'{H}/runs/{arm}-{seed}'
    d = analyse(base + '.rating.jsonl')
    fy = d['firm_years']
    out = {'changes / firm-yr': d['moves'] / fy, 'reversals <=1y / firm-yr': d['rev_y'] / fy,
           'reversals <=1q / firm-yr': d['rev_q'] / fy}
    rec = json.loads(open(base + '.jsonl').readline())
    op = json.load(open(base + '.open.json'))
    for grp in ('op', 'fin'):
        rr, ss, mix = [], [], Counter()
        for t, fd in rec['final'].items():
            if (grp == 'fin') != bool(op.get(t, {}).get('fin')):
                continue
            for q in fd['q']:
                if Y0 <= q['t'] < Y1 + 1 and q.get('rating') in RANK and q['rating'] != 'D':
                    rr.append(-RANK[q['rating']])
                    ss.append(q.get('dspread', 0.0))
                    mix[q['rating']] += 1
        out[f'{grp} rating-spread Spearman'] = spearman(rr, ss)
        if grp == 'op':
            n = sum(mix.values())
            for g in ('A', 'BBB', 'BB', 'B'):
                out[f'op share {g}'] = mix[g] / max(1, n)
    out['failures / seed'] = len(rec.get('dead', {})) + len(rec.get('reorgs', []))
    tot = sum(d['causes'].values())
    out['cause share anchor'] = d['causes'].get('anchor', 0) / max(1, tot)
    out['cause share dd'] = d['causes'].get('dd', 0) / max(1, tot)
    return out


def seeds(arm):
    return {int(re.search(r'-(\d+)\.rating\.jsonl$', p).group(1)) for p in glob.glob(f'{H}/runs/{arm}-*.rating.jsonl')}


paired = sorted(seeds(A) & seeds(B))
ra = {s: seed_metrics(A, s) for s in paired}
rb = {s: seed_metrics(B, s) for s in paired}
print(f'arms {A} vs {B}, paired seeds n={len(paired)} {paired}, Spearman years {Y0}-{Y1}')
for key in ra[paired[0]]:
    a = [ra[s][key] for s in paired]
    b = [rb[s][key] for s in paired]
    d = [y - x for x, y in zip(a, b)]
    se = st.stdev(d) / math.sqrt(len(d)) if len(d) > 1 else math.nan
    t = st.mean(d) / se if se and se > 0 else math.nan
    print(f'{key:<28} {A} {st.mean(a):8.4f}  {B} {st.mean(b):8.4f}  diff {st.mean(d):+8.4f}  se {se:7.4f}  t {t:6.2f}')
