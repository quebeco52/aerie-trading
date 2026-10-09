"""Paired NEW vs HEAD tree for the public-spending lever. python3 spend_an.py runs/<stage> [burn years]
Expects runs/<stage>/{new,head}/{on,off}_<seed>.jsonl (spend.sh). Prints the paired table; run gate_an.py on <stage>/new for the lever stats."""
import json, math, sys, glob, collections
D = sys.argv[1]; BURN = float(sys.argv[2]) if len(sys.argv) > 2 else 10.0
def load(tree, arm):
    runs = collections.defaultdict(list)
    for f in sorted(glob.glob(f'{D}/{tree}/{arm}_*.jsonl')):
        for l in open(f):
            r = json.loads(l)
            if r['t'] > BURN: runs[r['s']].append(r)
    return runs
def mean(x): return sum(x) / len(x)
def sd(x):
    m = mean(x); return math.sqrt(sum((v - m) ** 2 for v in x) / len(x))
def acf(x, k):
    m = mean(x); v = sum((a - m) ** 2 for a in x)
    return sum((x[i] - m) * (x[i - k] - m) for i in range(k, len(x))) / v
def brakes(rows): return len({r['brake'] for r in rows if r['brake'] >= 0}) / (len(rows) / 4)
stats = [('gap sd', lambda r: sd([x['gap'] for x in r])), ('gap ACF4', lambda r: acf([x['gap'] for x in r], 4)),
         ('debt mean', lambda r: mean([x['debt'] for x in r])), ('brake rounds/yr', brakes),
         ('debt >0.90 share', lambda r: mean([x['debt'] > 0.90 for x in r])), ('tax shift sd', lambda r: sd([x['shift'] for x in r]))]
print(f'burn {BURN}y; NEW - HEAD paired by seed; se = sd(diff)/sqrt(n)')
print(f'{"arm":4s} {"quantity":18s} {"HEAD":>9s} {"NEW":>9s} {"diff":>9s} {"se":>8s} {"t":>6s} {"n":>3s}')
for arm in ('on', 'off'):
    h, n = load('head', arm), load('new', arm)
    seeds = sorted(set(h) & set(n))
    if not seeds: continue
    for name, fn in stats:
        if arm == 'off' and name in ('brake rounds/yr', 'tax shift sd'): continue
        a = [fn(h[s]) for s in seeds]; b = [fn(n[s]) for s in seeds]
        d = [y - x for x, y in zip(a, b)]; se = sd(d) / math.sqrt(len(d)) if len(d) > 1 else float('nan')
        t = mean(d) / se if se > 0 else float('nan')
        print(f'{arm:4s} {name:18s} {mean(a):9.4f} {mean(b):9.4f} {mean(d):+9.4f} {se:8.4f} {t:+6.1f} {len(seeds):3d}')
for arm in ('on', 'off'):
    n = load('new', arm)
    if n and 'g' in next(iter(n.values()))[0]:
        v = [sd([math.log(x['g'] / 100.0) for x in n[s]]) for s in n]
        print(f'NEW-{arm} purchases log sd: mean {mean(v):.4f} se {sd(v) / math.sqrt(len(v)):.4f} range {min(v):.4f}-{max(v):.4f} n {len(v)}')
