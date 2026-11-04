#!/usr/bin/env python3
"""rw5x.py <seeds> A B: BRKW price against NAV from the q rows (P/NAVsop mean, p10, p90, share > 1 and < 0.5; P over
marked book mbvps, same definition in every arm), pfv/NAVsop, px/pfv, cash/NAVsop (mean, y10, y25, max, slope y20-25
per year), levered beta, BRKW TSR a year (price + dividends, split-adjusted, OUT record) and NI. Paired B - A."""
import json, math, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, fmt
from analyse import seeds, H
from rw4x import firm


def pct(xs, p):
    xs = sorted(xs)
    i = p * (len(xs) - 1)
    lo = int(math.floor(i))
    return xs[lo] + (xs[min(lo + 1, len(xs) - 1)] - xs[lo]) * (i - lo)


def at(q, y):
    return min(q, key=lambda r: abs(r['t'] - y))


def one(arm, s):
    q, rep = [], []
    for line in open(H / f'{arm}-{s}.brkw.jsonl'):
        r = json.loads(line)
        if r['kind'] == 'q':
            q.append(r)
        elif r['kind'] == 'rep':
            rep.append(r)
    q = [r for r in q if r['nav_sop'] > 0 and r['pfv'] > 0]
    pn = [r['px'] / r['nav_sop'] for r in q]
    pm = [r['px'] / r['mbvps'] for r in q if r['mbvps'] > 0]
    cn = lambda r: r['cash'] / (r['nav_sop'] * r['sh'])
    c = [cn(r) for r in q]
    o = {
        'P/NAV mean': sum(pn) / len(pn), 'P/NAV p10': pct(pn, 0.1), 'P/NAV p90': pct(pn, 0.9),
        'P/NAV>1 share': sum(1 for x in pn if x > 1) / len(pn), 'P/NAV<0.5 share': sum(1 for x in pn if x < 0.5) / len(pn),
        'P/mbv mean': sum(pm) / len(pm), 'P/mbv p10': pct(pm, 0.1), 'P/mbv p90': pct(pm, 0.9),
        'P/mbv>1 share': sum(1 for x in pm if x > 1) / len(pm),
        'pfv/NAV mean': sum(r['pfv'] / r['nav_sop'] for r in q) / len(q),
        'pfv/mbv mean': sum(r['pfv'] / r['mbvps'] for r in q) / len(q),
        'px/pfv mean': sum(r['px'] / r['pfv'] for r in q) / len(q),
        'NAVsop/mbv mean': sum(r['nav_sop'] / r['mbvps'] for r in q) / len(q),
        'cash/NAV mean': sum(c) / len(c), 'cash/NAV y10': cn(at(q, 10)), 'cash/NAV y25': cn(q[-1]),
        'cash/NAV max': max(c), 'cash/NAV slope y20-25/y': (cn(q[-1]) - cn(at(q, 20))) / (q[-1]['t'] - at(q, 20)['t']),
        'lbeta mean': sum(r['lbeta'] for r in q) / len(q),
        'NI bn/y': sum(r['ni'] for r in rep) / 1e9 / (len(rep) / 4),
    }
    out = json.loads(open(H / f'{arm}-{s}.jsonl').readline())
    o['BRKW TSR ann'] = firm(out['final']['BRKW']['q'], False)['TSR ann']
    return o


def main():
    ss, (A, B) = seeds(sys.argv[1]), sys.argv[2:4]
    r = {a: {s: one(a, s) for s in ss} for a in (A, B)}
    print(f'BRKW vs NAV, seeds {sys.argv[1]}; mean {A} | {B}; {B} per seed; paired {B}-{A}: d (se) t n')
    for k in r[B][ss[0]]:
        va, vb = [r[A][s][k] for s in ss], [r[B][s][k] for s in ss]
        per = ' '.join(f'{x:.3g}' for x in vb)
        print(f'{k:24s} {sum(va) / len(va):9.3g} {sum(vb) / len(vb):9.3g}  {per:30s} {fmt(paired(va, vb))}')


if __name__ == '__main__':
    main()
