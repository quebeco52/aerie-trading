"""Per-firm HEAD vs working-tree settle comparison for a credit-pricing change.

credit_compare.py <head.jsonl> <new.jsonl> [TICKER ...]
Prints firms whose q0 or q1 net income moves >10%, opening rating moves, the named firms' rows, and board medians.
"""
import json
import sys
from statistics import median


def load(p):
    d = {}
    for line in open(p):
        r = json.loads(line)
        d[(r['ticker'], r['q'])] = r
    return d


def pct(a, b):
    return (b - a) / abs(a) * 100 if a else float('nan')


h, n = load(sys.argv[1]), load(sys.argv[2])
named = sys.argv[3:]
tickers = sorted({t for t, q in h if q == 0})


def g(d, t, q, k):
    r = d.get((t, q))
    return None if r is None else r.get(k)


def ret(d, t, q):
    return g(d, t, q, 'ret_filed')


print('Firms with |dNI| > 10% at q0 (opening) or q1 (first report):')
moved0 = moved1 = 0
for t in tickers:
    a0, b0 = g(h, t, 0, 'ni'), g(n, t, 0, 'ni')
    a1, b1 = g(h, t, 1, 'ni'), g(n, t, 1, 'ni')
    d0 = pct(a0, b0) if a0 else 0.0
    d1 = pct(a1, b1) if a1 else 0.0
    f0, f1 = abs(d0) > 10, abs(d1) > 10
    moved0 += f0
    moved1 += f1
    if f0 or f1:
        print(f'  {t:5s} NI0 {a0/1e9:8.2f} -> {b0/1e9:8.2f}bn ({d0:+6.1f}%)  NI1 {a1/1e9:8.2f} -> {b1/1e9:8.2f} ({d1:+6.1f}%)')
print(f'  count q0: {moved0}, q1: {moved1} of {len(tickers)}')

print('Opening rating moves (q0), and q40 rating moves:')
for q in (0, 40):
    mv = [(t, g(h, t, q, 'rating'), g(n, t, q, 'rating')) for t in tickers
          if g(h, t, q, 'rating') is not None and g(h, t, q, 'rating') != g(n, t, q, 'rating')]
    print(f'  q{q}: {len(mv)} ' + ' '.join(f'{t} {a}->{b}' for t, a, b in mv))

hdr = 'tick  q | int H     int N  (bn) | spr H  spr N (bp) | ret H  ret N | roeB H roeB N | eq H     eq N (bn) | rtg'
print('Named firms (int = annual interest on the health reading; ret = filed ROE (fin) / ROIC (ops), %):')
print(hdr)
for t in named:
    for q in (0, 1, 40):
        if (t, q) not in h:
            continue
        def f(k, s=1.0, d=2):
            a, b = g(h, t, q, k), g(n, t, q, k)
            fa = f'{a*s:.{d}f}' if a is not None else '-'
            fb = f'{b*s:.{d}f}' if b is not None else '-'
            return f'{fa:>7s} {fb:>7s}'
        print(f"{t:5s} {q:2d} | {f('int', 1e-9)} | {f('spread', 1e4, 0)} | {f('ret_filed', 100, 1)} | "
              f"{f('roe_book', 100, 1)} | {f('equity', 1e-9, 1)} | {g(h, t, q, 'rating')}->{g(n, t, q, 'rating')}")

print('Board medians (all firms): q | int/debt H  N (%) | spread H N (bp) | ret H N (%)')
for q in (0, 1, 40):
    def med(d, fn):
        xs = [fn(d, t) for t in tickers if (t, q) in d]
        xs = [x for x in xs if x is not None]
        return median(xs) if xs else float('nan')
    idh = med(h, lambda d, t: g(d, t, q, 'int') / g(d, t, q, 'debt') * 100 if (g(d, t, q, 'debt') or 0) > 0 else None)
    idn = med(n, lambda d, t: g(d, t, q, 'int') / g(d, t, q, 'debt') * 100 if (g(d, t, q, 'debt') or 0) > 0 else None)
    sh, sn = med(h, lambda d, t: g(d, t, q, 'spread') * 1e4), med(n, lambda d, t: g(d, t, q, 'spread') * 1e4)
    rh, rn = med(h, lambda d, t: ret(d, t, q) * 100), med(n, lambda d, t: ret(d, t, q) * 100)
    print(f'  q{q:2d} | {idh:5.2f} {idn:5.2f} | {sh:5.0f} {sn:5.0f} | {rh:5.2f} {rn:5.2f}')
