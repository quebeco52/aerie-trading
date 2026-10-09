"""Paired gate: the same economy with no politics (off) and with the Diet voting and legislating (on).
python3 gate_an.py runs/<label> [burn years]"""
import json, math, sys, glob, collections
D = sys.argv[1]; BURN = float(sys.argv[2]) if len(sys.argv) > 2 else 10.0
def load(arm):
    runs = collections.defaultdict(list)
    for f in sorted(glob.glob(f'{D}/{arm}_*.jsonl')):
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
def pct(x, p):
    s = sorted(x); return s[min(len(s) - 1, int(p * len(s)))]
arms = {a: load(a) for a in ('off', 'on')}
seeds = sorted(set(arms['off']) & set(arms['on']))
print(f'{len(seeds)} paired seeds, {len(arms["on"][seeds[0]])} quarters each after {BURN}y burn-in')
def stat(name, fn):
    vals = {a: [fn(arms[a][s]) for s in seeds] for a in arms}
    d = [vals['on'][i] - vals['off'][i] for i in range(len(seeds))]
    se = sd(d) / math.sqrt(len(d))
    print(f'  {name:34s} off {mean(vals["off"]):+.4f}  on {mean(vals["on"]):+.4f}  paired diff {mean(d):+.4f} (se {se:.4f})')
col = lambda k: (lambda rows: [r[k] for r in rows])
stat('gap sd', lambda r: sd(col('gap')(r)))
stat('gap ACF1 (q)', lambda r: acf(col('gap')(r), 1))
stat('gap ACF4 (q)', lambda r: acf(col('gap')(r), 4))
stat('gap mean', lambda r: mean(col('gap')(r)))
stat('gap p5', lambda r: pct(col('gap')(r), 0.05))
stat('inflation mean', lambda r: mean(col('infl')(r)))
stat('inflation sd', lambda r: sd(col('infl')(r)))
stat('policy rate mean', lambda r: mean(col('rate')(r)))
stat('policy rate sd', lambda r: sd(col('rate')(r)))
stat('r* mean', lambda r: mean(col('rstar')(r)))
stat('unemployment sd', lambda r: sd(col('unemp')(r)))
stat('10y yield mean', lambda r: mean(col('y10')(r)))
stat('debt mean', lambda r: mean(col('debt')(r)))
stat('debt p95', lambda r: pct(col('debt')(r), 0.95))
stat('primary deficit mean', lambda r: mean(col('def')(r)))
stat('tax rate mean', lambda r: mean(col('tax')(r)))
stat('tax rate sd', lambda r: sd(col('tax')(r)))
stat('net exports sd', lambda r: sd(col('nx')(r)))
stat('house index sd (log)', lambda r: sd([math.log(v) for v in col('house')(r)]))
if 'power' in arms['on'][seeds[0]][0]:
    stat('house index mean (log)', lambda r: mean([math.log(v) for v in col('house')(r)]))
    stat('housing starts sd', lambda r: sd(col('starts')(r)))
    stat('power index mean', lambda r: mean(col('power')(r)))
on = [r for s in seeds for r in arms['on'][s]]
print('levers (on arm):')
print(f'  tax shift: mean {mean([r["shift"] for r in on]):+.4f} sd {sd([r["shift"] for r in on]):.4f} min {min(r["shift"] for r in on):+.4f} max {max(r["shift"] for r in on):+.4f}')
tar = [r['tariff'] for r in on]
print(f'  tariff: mean {mean(tar):.4f}  >0 in {100 * sum(t > 1e-9 for t in tar) / len(tar):.0f}% of quarters, p95 {pct(tar, 0.95):.4f} max {max(tar):.4f}')
lab = [r['lab'] for r in on]
print(f'  labour growth: mean {mean(lab):.4f} sd {sd(lab):.4f} min {min(lab):.4f} max {max(lab):.4f}')
if 'green' in on[0]:
    for key, name in (('green', 'green belt'), ('carbon', 'carbon price $/t'), ('extract', 'extraction rules')):
        v = [r[key] for r in on]
        print(f'  {name}: mean {mean(v):.4f} sd {sd(v):.4f} >0 in {100 * sum(x > 1e-9 for x in v) / len(v):.0f}% of quarters, p95 {pct(v, 0.95):.4f} max {max(v):.4f}')
    belted = [s for s in seeds]
    hi = [r for s in seeds for r in arms['on'][s] if r['green'] > 0.3]
    lo = [r for s in seeds for r in arms['on'][s] if r['green'] < 1e-9]
    if hi and lo:
        print(f'  house index sd (log) across quarters: green belt > 0.3 {sd([math.log(r["house"]) for r in hi]):.4f} vs none {sd([math.log(r["house"]) for r in lo]):.4f}')
pop = [r['pop'] for r in on]
print(f'  immigration population shift: sd {sd(pop):.4f} min {min(pop):+.4f} max {max(pop):+.4f}')
held = 0
for s in seeds:
    rows = arms['on'][s]
    held += len({r['brake'] for r in rows if r['brake'] >= 0})
print(f'  budget rounds the Council brake held: {held} over {len(seeds)} runs ({held / len(seeds) / ((len(arms["on"][seeds[0]])) / 4) :.2f} a year)')
debt_above = sum(r['debt'] > 0.90 for r in on) / len(on)
print(f'  quarters with debt above the 90% line: {100 * debt_above:.0f}%')
