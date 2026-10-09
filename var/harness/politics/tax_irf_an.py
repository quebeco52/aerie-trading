import json, math, sys, glob
rows = [json.loads(l) for f in sys.argv[1:] for l in open(f)]
shock = 0.01
H = len(rows[0]['paths']['base'])
def mean(x): return sum(x) / len(x)
def sd(x):
    m = mean(x); return math.sqrt(sum((v - m) ** 2 for v in x) / len(x))
print(f'{len(rows)} seeds; output (gap) response to a 1pp corporate tax cut, % of GDP (Mertens & Ravn 2013: +0.4 at 1q, peak +0.6 at 4q)')
for q in (1, 2, 4, 6, 8, 12, 16, 20):
    if q > H: break
    d = [100 * (r['paths']['cut'][q - 1]['gap'] - r['paths']['base'][q - 1]['gap']) / (100 * shock) for r in rows]
    tax = [100 * (r['paths']['cut'][q - 1]['tax'] - r['paths']['base'][q - 1]['tax']) for r in rows]
    rate = [100 * (r['paths']['cut'][q - 1]['rate'] - r['paths']['base'][q - 1]['rate']) for r in rows]
    infl = [100 * (r['paths']['cut'][q - 1]['infl'] - r['paths']['base'][q - 1]['infl']) for r in rows]
    print(f'  q{q:2d}: gap {mean(d):+.3f}% (se {sd(d) / math.sqrt(len(d)):.3f})  tax {mean(tax):+.2f}pp  policy rate {mean(rate):+.3f}pp  inflation {mean(infl):+.3f}pp')
