#!/usr/bin/env python3
"""analyse_spread.py <armA> <armB> [first_year last_year]: issuer credit spreads for LeverageHarnessTest runs, paired by seed.

Reads the quarterly `dspread` (the issuer's live spread, Stock::getDynamicCreditSpread), `rating`, `icr`, `price`, `eps`
and the macro `ig` / `hy` index columns over firm-quarters with first_year <= t < last_year + 1 (default 2..19).
Populations: operating firms (the opening `fin` flag off, REITs included) and financials. Per seed: median spread by
rating against the ICE BofA-derived class curve at the 130 bp IG / 429 bp HY through-the-cycle levels, Spearman rank
correlation of rating (worse = higher) with spread, rating mix, median ICR and P/E of operating firms, failures and
payment defaults, and the median macro HY spread. Paired rows print mean A, mean B, mean diff, SE and t.
"""
import glob, json, math, os, re, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
A, B = sys.argv[1], sys.argv[2]
Y0, Y1 = (int(sys.argv[3]), int(sys.argv[4])) if len(sys.argv) > 4 else (2, 19)
RANK = {'AAA': 7, 'AA': 6, 'A': 5, 'BBB': 4, 'BB': 3, 'B': 2, 'CCC': 1, 'D': 0}
GRADES = ['AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC']
WATCH = ['SWAN', 'TIER', 'SAFE']
TARGET = {'AAA': 53, 'AA': 74, 'A': 109, 'BBB': 163, 'BB': 262, 'B': 442, 'CCC': 1231}


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


def seed_metrics(path):
    rec = json.loads(open(path).readline())
    op = json.load(open(path.replace('.jsonl', '.open.json')))
    out = {}
    for grp in ('op', 'fin'):
        by, rr, ss, icr, pe = {g: [] for g in GRADES}, [], [], [], []
        n = 0
        for t, fd in rec['final'].items():
            if (grp == 'fin') != bool(op.get(t, {}).get('fin')):
                continue
            for q in fd['q']:
                if not (Y0 <= q['t'] < Y1 + 1) or q.get('rating') not in RANK or q['rating'] == 'D':
                    continue
                s = q.get('dspread', 0.0) * 1e4
                by[q['rating']].append(s)
                rr.append(-RANK[q['rating']])
                ss.append(s)
                n += 1
                if grp == 'op':
                    icr.append(min(q.get('icr', 0.0), 100.0))
                    if q.get('eps', 0) > 0:
                        pe.append(q['price'] / q['eps'])
        for g in GRADES:
            out[f'{grp} med {g}'] = st.median(by[g]) if by[g] else math.nan
            out[f'{grp} share {g}'] = len(by[g]) / max(1, n)
        out[f'{grp} rank corr'] = spearman(rr, ss)
        if grp == 'op':
            out['op median ICR'] = st.median(icr) if icr else math.nan
            out['op median P/E'] = st.median(pe) if pe else math.nan
    macro = [q for fd in rec['final'].values() for q in fd['q'] if Y0 <= q['t'] < Y1 + 1 and q.get('hy')]
    out['macro HY median bp'] = st.median(q['hy'] for q in macro) * 1e4 if macro else math.nan
    out['macro IG median bp'] = st.median(q['ig'] for q in macro) * 1e4 if macro else math.nan
    for t in WATCH:
        q = [x for x in rec['final'].get(t, {}).get('q', []) if Y0 <= x['t'] < Y1 + 1]
        out[f'{t} spread bp'] = st.median(x.get('dspread', 0.0) for x in q) * 1e4 if q else math.nan
    ev = [e['d'] for evs in rec.get('events', {}).values() for e in evs]
    out['failures'] = len(rec.get('dead', {})) + len(rec.get('reorgs', []))
    out['payment defaults'] = sum(d.startswith('Failed to repay') for d in ev)
    return out


def arm(name):
    res = {}
    for p in glob.glob(f'{H}/runs/{name}-*.jsonl'):
        s = int(re.search(rf'{name}-(\d+)\.jsonl$', p).group(1))
        try:
            res[s] = seed_metrics(p)
        except (ValueError, KeyError):
            pass
    return res


ra, rb = arm(A), arm(B)
seeds = sorted(set(ra) & set(rb))
print(f'arms {A} vs {B}, paired seeds n={len(seeds)} {seeds}, years {Y0}-{Y1}')


def row(key, target=None):
    a = [ra[s][key] for s in seeds]
    b = [rb[s][key] for s in seeds]
    pairs = [(x, y) for x, y in zip(a, b) if not (math.isnan(x) or math.isnan(y))]
    fa = [x for x in a if not math.isnan(x)]
    fb = [y for y in b if not math.isnan(y)]
    ma = st.mean(fa) if fa else math.nan
    mb = st.mean(fb) if fb else math.nan
    if len(pairs) > 1:
        d = [y - x for x, y in pairs]
        se = st.stdev(d) / math.sqrt(len(d))
        t = st.mean(d) / se if se > 0 else math.nan
        # Unpaired (Welch) t as well: a same-seed rerun of one tree does not reproduce its path (RUN.md).
        su = math.sqrt(st.variance(fa) / len(fa) + st.variance(fb) / len(fb)) if len(fa) > 1 and len(fb) > 1 else math.nan
        tu = (mb - ma) / su if su and su > 0 else math.nan
        tail = f'diff {st.mean(d):+9.3f} se {se:8.3f} t {t:6.2f} welch t {tu:6.2f}'
    else:
        tail = ''
    tgt = f' target {target:5.0f}' if target is not None else ''
    print(f'{key:<22} {A} {ma:9.3f}  {B} {mb:9.3f}  {tail}{tgt}  (n {len(fa)}/{len(fb)})')


for grp in ('op', 'fin'):
    print(f'-- {grp}: median spread (bp) by rating')
    for g in GRADES:
        row(f'{grp} med {g}', TARGET[g])
    row(f'{grp} rank corr')
    print(f'-- {grp}: rating mix (share of firm-quarters)')
    for g in GRADES:
        row(f'{grp} share {g}')
print('-- guards')
for k in ['op median ICR', 'op median P/E', 'failures', 'payment defaults', 'macro HY median bp', 'macro IG median bp'] + [f'{t} spread bp' for t in WATCH]:
    row(k)
