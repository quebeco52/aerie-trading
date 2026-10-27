#!/usr/bin/env python3
"""ride.py <seeds> ARM...: does an entered (x:) line ride its parent's revenue? From VENTURE rows: (1) line revenue x 4
over its ledger record's capacity (annual) per venture-quarter; (2) per venture with >= 12 four-quarter growth points,
corr of its 4q log revenue growth with its industry's listed non-entrant incumbents' revenue (summed) and with the
parent's other lines; (3) entered lines' share of board revenue at y20 / y40 and firms above 20%. Prints tables only.
"""
import json, math, os, statistics as st, sys
from collections import defaultdict

H = os.path.dirname(os.path.abspath(__file__))
arg = sys.argv[1]
seeds = list(range(int(arg.split('-')[0]), int(arg.split('-')[1]) + 1)) if '-' in arg else [int(x) for x in arg.split(',')]


def q(xs, p):
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(p * len(xs)))] if xs else float('nan')


def corr(x, y):
    mx, my = st.fmean(x), st.fmean(y)
    sx = math.sqrt(sum((a - mx) ** 2 for a in x))
    sy = math.sqrt(sum((b - my) ** 2 for b in y))
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / (sx * sy) if sx > 0 and sy > 0 else float('nan')


for arm in sys.argv[2:]:
    ratios, worst = [], []
    ca, cb = [], []
    shares = {20: [], 40: []}
    over20 = {20: 0, 40: 0}
    for s in seeds:
        g = json.load(open(f'{H}/runs/{arm}-{s}.seg.json'))
        r = json.loads(open(f'{H}/runs/{arm}-{s}.jsonl').read().splitlines()[-1])
        rows = json.load(open(f'{H}/runs/{arm}-{s}.idle.json'))['rows']
        ind_of = {row[1]: row[2] for row in rows}
        V = g.get('ventures', [])
        entrants = defaultdict(set)
        for v in V:
            for k, x in (v.get('kappa') or {}).items():
                if k.startswith('x:') and x > 0:
                    entrants[k[2:]].add(v['tk'])
        # incumbents' revenue per industry and quarter index
        inc = defaultdict(float)
        for tk, f in r['final'].items():
            for x in f['q']:
                inc[ind_of.get(tk), round(x['t'] * 4)] += x['rev']
        series = defaultdict(list)
        for v in V:
            streams = v.get('streams') or {}
            for k, x in (v.get('kappa') or {}).items():
                if not k.startswith('x:') or x <= 0:
                    continue
                line = streams.get(k, 0.0)
                rec = (v.get('xrec') or {}).get(k) or {}
                if line > 0 and rec.get('cap'):
                    ratios.append(line * 4 / rec['cap'])
                    worst.append((line * 4 / rec['cap'], v['tk'], s, v['t'], k))
                other = sum(y for kk, y in streams.items() if kk != k and not kk.startswith('x:'))
                series[v['tk'], k].append((round(v['t'] * 4), line, other, inc.get((k[2:], round(v['t'] * 4)), 0.0)))
        for (tk, k), xs in series.items():
            byq = {x[0]: x for x in xs}
            ga, gb, gv = [], [], []
            for qn, (_, line, other, incr) in byq.items():
                p = byq.get(qn - 4)
                if p and min(line, p[1], other, p[2], incr, p[3]) > 0:
                    gv.append(math.log(line / p[1]))
                    gb.append(math.log(other / p[2]))
                    ga.append(math.log(incr / p[3]))
            if len(gv) >= 12:
                ca.append(corr(gv, ga))
                cb.append(corr(gv, gb))
        for y in (20, 40):
            tot = sum(row[9] for row in g['cap'] if y - 0.25 < row[0] <= y)
            last = {}
            for v in V:
                if y - 0.25 < v['t'] <= y:
                    last[v['tk']] = v
            xs = sum(x for v in last.values() for kk, x in (v.get('streams') or {}).items() if kk.startswith('x:'))
            shares[y].append(xs / tot if tot else float('nan'))
            for v in last.values():
                t_ = sum((v.get('streams') or {}).values())
                if t_ and sum(x for kk, x in v['streams'].items() if kk.startswith('x:')) / t_ > 0.2:
                    over20[y] += 1
    pairs = [(a, b) for a, b in zip(ca, cb) if a == a and b == b]
    print(f'## {arm}')
    print(f'(1) line revenue x 4 / record capacity: p50 {q(ratios, .5):.3f} p90 {q(ratios, .9):.3f} p99 {q(ratios, .99):.3f} max {max(ratios):.3f} (n {len(ratios)})')
    print('    worst: ' + '; '.join(f'{tk} s{s} y{t:.1f} {k} {x:.2f}' for x, tk, s, t, k in sorted(worst, reverse=True)[:5]))
    print(f'(2) ventures with >= 12 points: {len(pairs)}; corr with incumbents median {st.median([a for a, b in pairs]):+.2f}, with parent\'s other lines median {st.median([b for a, b in pairs]):+.2f}; '
          f'parent > incumbents in {sum(1 for a, b in pairs if b > a) / len(pairs):.0%}')
    print(f'(3) entered lines / board revenue: y20 {st.fmean(shares[20]):.2%}, y40 {st.fmean(shares[40]):.2%}; firms above 20% entered per seed: y20 {over20[20] / len(seeds):.2f}, y40 {over20[40] / len(seeds):.2f}')
