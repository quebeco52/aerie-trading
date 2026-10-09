import json, math, sys
rows = [json.loads(l) for f in sys.argv[2:] for l in open(f)]
size = float(sys.argv[1])
def mean(x): return sum(x) / len(x)
def se(x):
    m = mean(x); return math.sqrt(sum((v - m) ** 2 for v in x) / len(x)) / math.sqrt(len(x))
print(f'{len(rows)} seeds, lever step {size}')
for q in (1, 2, 4, 8, 12, 16, 20):
    out = {}
    for k, f in (('gap%', lambda c, b: 100 * (c['gap'] - b['gap'])), ('output%', lambda c, b: 100 * math.log(c['pot'] * (1 + c['gap']) / (b['pot'] * (1 + b['gap'])))),
                 ('potential%', lambda c, b: 100 * math.log(c['pot'] / b['pot'])), ('price level%', lambda c, b: 100 * math.log(c['defl'] / b['defl'])),
                 ('import px%', lambda c, b: 100 * (c['imp'] - b['imp'])), ('policy pp', lambda c, b: 100 * (c['rate'] - b['rate'])), ('nx %GDP', lambda c, b: 100 * (c['nx'] - b['nx'])),
                 ('debt pp', lambda c, b: 100 * (c['debt'] - b['debt'])), ('r* pp', lambda c, b: 100 * (c['rstar'] - b['rstar'])), ('house%', lambda c, b: 100 * math.log(c['house'] / b['house']))):
        d = [f(r['paths']['cut'][q - 1], r['paths']['base'][q - 1]) for r in rows]
        out[k] = mean(d)
    print(f'  q{q:2d}: ' + '  '.join(f'{k} {v:+.3f}' for k, v in out.items()))
